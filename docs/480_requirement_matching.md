# 480 案件要件 × 技術者スキル 対照表（◯/△/×）生成機能 実装計画

ステータス: 計画策定済 / 着手前
作成日: 2026-05-19
起案: 営業（松村）からの要望 — キャリアビート社のような項目別 ◯/× 判定を AI で自動生成したい

関連:
- `docs/470_fresh_mail_matching.md` — 鮮度マッチング機能（本機能の前提）
- `docs/520_matching_flow_qa.md` — マッチングフロー全体
- `docs/530_engineer_mail_flow.md` — 技術者メールフロー
- `docs/540_project_mail_flow.md` — 案件メールフロー

---

## 1. 背景・目的

営業（松村）が、提携 SES 会社「キャリアビート」から受け取った返信メールに以下のような **要件項目別 ◯/× 判定** が含まれていた:

```
【必須】Java で詳細設計～テストまで1人称（java経験4年以上）：◯
【必須】HTML/CSS/JavaScript の実務経験（2年以上）：◯
【必須】能動的、学習意欲、コミュニケーション能力：◯
【必須】勤怠良好（フル出社）：◯
【尚可】C# 開発経験：×
【尚可】金融基幹/証券業務システム経験：×
【尚可】React：×
【尚可】Git：◯
```

松村いわく「AIに丸投げみたいなことできないの？」 — これに応える機能として、案件メールから抽出した要件 と 技術者メール/スキルシート を **項目別に対照** し、Claude で ◯/△/× を判定して **提案メール本文に自動挿入** する機能を実装する。

### キャリアビートメールの分析（参考）

- 2026-05-18 受信、3 名の技術者を提案
- 各技術者ごとに ◯/× 対照表が完全に同じ並びで繰り返し → **AI 補助 + テンプレ運用のハイブリッド**と推定
- 評価項目数: ◯ 18 / × 14（3 名分集計）

---

## 2. アーキテクチャ判断

### 結論: 独立 Service `RequirementMatchingService` を新設

既存 `ProjectMailScoringService` には統合しない。

### 理由

| 観点 | 理由 |
|---|---|
| 責務分離 | `ProjectMailScoringService` は 15分毎 cron で全件回す重い処理。Claude を足すと運用不安定化 |
| タイミング | スコアリング = 受動的・全件 / 対照表生成 = 能動的・選択件のみ |
| 既存運用への影響回避 | `score_reasons` jsonb は既存 UI で使用中。混在させると意味論が崩れる |
| スコアリング非破壊 | 「鮮度マッチングのスコア計算ロジックは触らない」原則を満たす |
| 段階的 AI 移行 | `engine='rule' \| 'ai'` の枠組みが既にあり、将来統合の道筋を塞がない |

---

## 3. データモデル変更

### 3.1 既存カラム追加

`project_mail_sources` テーブル:

| カラム | 型 | 用途 |
|---|---|---|
| `ai_requirements` | jsonb nullable | Claude 抽出した要件配列（必須/尚可付き）。PMS 単位で 1 回生成し永続化 |
| `ai_requirements_generated_at` | timestamp nullable | 生成日時 |

### 3.2 新規テーブル `requirement_match_results`

```
- id (PK)
- tenant_id (FK)
- project_mail_source_id (FK, indexed)
- engineer_mail_source_id (FK nullable, indexed)  -- 鮮度マッチ EMS 用
- engineer_id (FK nullable, indexed)              -- 登録済 Engineer 用
- requirements_json (jsonb)   -- 抽出した要件配列（must/want, label, condition）
- matches_json (jsonb)        -- 判定結果配列（label, judgment ◯△×, evidence, manual_override）
- model (string, 30)          -- 使った Claude モデル名
- input_tokens (int nullable), output_tokens (int nullable)
- generated_at (timestamp)
- edited_by (FK users nullable)  -- 営業が上書きした場合
- edited_at (timestamp nullable)
- timestamps + softDeletes
- unique(tenant_id, project_mail_source_id, engineer_mail_source_id)
- unique(tenant_id, project_mail_source_id, engineer_id)
```

### 3.3 EMS 拡張

| カラム | 用途 |
|---|---|
| `engineer_mail_sources.parsed_skill_sheet_text` text nullable | 添付スキルシートの抽出済テキスト（Stage 2 判定精度向上のため） |

### 3.4 RLS / GRANT

新規テーブル `requirement_match_results` には:

```sql
ALTER TABLE public.requirement_match_results ENABLE ROW LEVEL SECURITY;
GRANT SELECT, INSERT, UPDATE, DELETE ON public.requirement_match_results TO service_role;
-- フロントから読まないため authenticated への GRANT は不要
```

### 3.5 Migration（3 本）

1. `2026_XX_XX_add_ai_requirements_to_project_mail_sources.php`
2. `2026_XX_XX_add_parsed_skill_sheet_text_to_engineer_mail_sources.php`
3. `2026_XX_XX_create_requirement_match_results_table.php`

すべて nullable・新規テーブルなので破壊的変更なし。

---

## 4. バックエンドサービス設計

### 4.1 新規 `RequirementMatchingService`

責務:
- `extractRequirements(ProjectMailSource): array` — PMS 本文から要件配列を Claude で抽出
- `judgeMatches(array $requirements, EMS|Engineer): array` — 各要件に対する ◯/△/× 判定
- `getOrGenerate(PMS, EMS|Engineer): RequirementMatchResult` — キャッシュ優先で取得、無ければ生成
- `regenerate(PMS, EMS|Engineer): RequirementMatchResult` — 強制再生成

### 4.2 Claude プロンプト設計（2 段階方式）

**理由**: 要件抽出は PMS 単位で 1 回キャッシュ → 後段は EMS 切り替え毎に呼ぶ → 合計コスト最小化。

#### Stage 1: 要件抽出（PMS 単位、1 回限り）

入力:
- 案件メール本文（`emails.body_text`）
- 件名

出力 JSON:
```json
{
  "requirements": [
    {
      "type": "must",
      "label": "Java で詳細設計〜テストまで1人称",
      "condition": "java経験4年以上",
      "category": "skill",
      "weight": 10
    }
  ]
}
```

`type`: `must` | `want`
`category`: `skill` | `attitude` | `location` | `language` | `other`

#### Stage 2: 判定（PMS×EMS 単位）

入力:
- Stage 1 の `requirements`
- 技術者情報: 氏名・年齢・所属・スキル配列・自己 PR（`emails.body_text` + `parsed_skill_sheet_text`）

出力 JSON:
```json
{
  "matches": [
    {
      "label": "Java で詳細設計〜テストまで1人称",
      "judgment": "circle",
      "evidence": "Java 5年経験、詳細設計フェーズ含む",
      "confidence": "high"
    }
  ]
}
```

`judgment`: `circle` (◯) | `triangle` (△) | `cross` (×)
`confidence`: `high` | `medium` | `low` — `low` の場合は UI で △ 表示に降格を選択可

### 4.3 ClaudeService への追加メソッド

既存パターン踏襲（`postWithRetry()` + `parseResponse()`）:

- `ClaudeService::extractRequirements(string $subject, string $body): array`
- `ClaudeService::judgeRequirementMatches(array $requirements, array $engineerData, ?string $skillSheetText): array`

⚠️ **モデルは `config('services.anthropic.model')` 経由で統一**（既存 `generateProposal` のハードコード `claude-haiku-4-5-20251001` には引きずられない）。

### 4.4 既存 Service との分担

```
ProjectMailScoringService::extract()  ← 既存通り正規表現抽出（受信時 cron, 全件）
  ↓ （触らない）
RequirementMatchingService::extractRequirements(PMS)
  ← 営業がボタン押下時のみ Claude 呼出 → PMS.ai_requirements に保存
  ↓
RequirementMatchingService::judgeMatches(PMS, EMS)
  ← 鮮度マッチで対照表が必要になった時のみ Claude 呼出
  ← requirement_match_results にキャッシュ
```

---

## 5. API エンドポイント設計

| Method | Path | 用途 |
|---|---|---|
| GET | `/v1/project-mails/{id}/requirements` | PMS の構造化要件取得（無ければ自動生成） |
| POST | `/v1/project-mails/{id}/requirements/regenerate` | 強制再生成 |
| GET | `/v1/project-mails/{id}/requirement-match?ems_id=N` | PMS×EMS 対照表取得（無ければ生成） |
| GET | `/v1/project-mails/{id}/requirement-match?engineer_id=N` | PMS×登録済技術者 |
| POST | `/v1/project-mails/{id}/requirement-match/regenerate` | 強制再生成 |
| PATCH | `/v1/requirement-match-results/{id}` | 営業の ◯/× 手動上書き |

### 5.1 提案メール生成エンドポイント拡張

既存 `POST /v1/project-mails/{id}/generate-proposal`:
- request に `include_match_table: bool` 追加
- response に `match_table_markdown: "..."` を含める
- フロントで初期 body に concat

---

## 6. フロント UX

### 6.1 `/matching/[id]` （案件 → 候補技術者）

- 鮮度マッチカードに **「対照表」ボタン**追加
- アコーディオン展開で ◯/△/× テーブル表示
- 「提案メール」ボタン押下時に対照表を取得 → `body` 初期値末尾に Markdown で連結
- ProposalModal 内に **「対照表を本文に含める」チェックボックス（デフォルト ON）**
- ◯/× 上書きトグル

### 6.2 `/engineer-mails/[id]` （技術者 → 候補案件）

- `freshProjectMails` の各 PMS カードに「対照表」ボタン
- 1 名 × 複数案件の並列表示
- バルク送信（`send-bulk-to-bp`）は **対照表挿入対象外**

### 6.3 新規コンポーネント

`/home/shintomi/sales_support_next/src/components/RequirementMatchTable.tsx`

props:
```ts
{
  requirements: Requirement[],
  matches: Match[],
  editable: boolean,
  onEdit: (label: string, judgment: 'circle'|'triangle'|'cross') => void
}
```

UI:
- 1 列目: 【必須】【尚可】バッジ + ラベル
- 2 列目: ◯（緑）/ △（黄）/ ×（赤） — クリックで編集
- 3 列目: evidence ツールチップ

---

## 7. コスト管理

### 7.1 試算（claude-sonnet-4-6）

| Stage | 入力 tokens | 出力 tokens | 単価 |
|---|---|---|---|
| Stage 1（要件抽出） | 500-2000 | 300-500 | ≈ $0.003 / call |
| Stage 2（判定） | 1500-3500 | 500 | ≈ $0.005 / call |

### 7.2 鮮度マッチでの上限ガード

`FreshMailMatchingService` は最大 50 件返す。全件で対照表生成すると 50 × $0.005 = $0.25 / リクエスト → ガード必須。

| 制御 | 内容 |
|---|---|
| 初期表示 | 対照表は生成しない（ボタン押下時のみ） |
| 「全候補に対照表」 | 上位 **3 件**に制限 |
| Stage 1 キャッシュ | PMS 単位で 1 回 → N 件分は Stage 2 のみ |
| 設定 | `config('services.anthropic.requirement_match_max_per_request', 5)` |
| ユニーク制約 | `(tenant_id, pms_id, ems_id)` で重複生成阻止 |

### 7.3 バルクは完全無効化

| send_type | 対照表 |
|---|---|
| `proposal` | ✅ 対象 |
| `matching_proposal` | ✅ 対象 |
| `engineer_proposal` | ✅ 対象 |
| `bulk` | ❌ 無効化 |
| `engineer_proposal_bulk` | ❌ 無効化 |
| `delivery` | ❌ 無効化 |

---

## 8. ロールアウト計画

### 8.1 Feature Flag

- `config('features.requirement_matching', false)` 設定追加
- `tenants.feature_requirement_matching boolean` カラム追加（テナント別オン/オフ）
- API レスポンス・フロントで feature 無効時は対照表ボタン非表示

### 8.2 段階リリース

1. **アルファ**: 松村テナントのみ ON
2. **ベータ**: 他テナント招待制
3. **GA**: デフォルト ON

### 8.3 フォールバック

- Claude が `ClaudeOverloadedException` を投げた場合、対照表なしで提案メール送信は **成功させる**
- フロントで「対照表生成に失敗しました（送信は可能です）」トースト表示
- リトライボタン設置

---

## 9. テスト戦略

### 9.1 Pgsql testsuite (`tests/Pgsql/RequirementMatching/`)

- `ExtractRequirementsTest` — PMS から要件抽出 → 永続化 → 2回目はキャッシュヒット
- `JudgeMatchesTest` — PMS×EMS で対照表生成 → ユニーク制約検証
- `ManualOverrideTest` — PATCH で営業上書き → `edited_by/edited_at` 記録
- `FallbackOnClaudeFailureTest` — Claude 529 でもメール送信成功
- `FeatureFlagTest` — flag OFF で 404/403

### 9.2 Claude API モック

既存パターン `Http::fake()` を踏襲。

### 9.3 フロント

- `RequirementMatchTable.tsx` の Storybook
- E2E は Phase 3 以降

---

## 10. 段階的実装の Phase 分け

| Phase | 範囲 | 工数 |
|---|---|---|
| **Phase 1** | バックエンド API（read-only）<br>- migration 3 本<br>- `RequirementMatchingService` 新設<br>- `ClaudeService::extractRequirements` / `judgeRequirementMatches`<br>- GET/POST エンドポイント<br>- feature flag<br>- 統合テスト | 3〜4 日 |
| **Phase 2** | フロント表示<br>- `RequirementMatchTable.tsx`<br>- `/matching/[id]`, `/engineer-mails/[id]` への組み込み<br>- 編集 UI | 2〜3 日 |
| **Phase 3** | 提案メール本文への自動挿入<br>- `ProposalModal` 拡張<br>- `generate-proposal` レスポンス拡張<br>- 「対照表だけコピー」ボタン | 1〜2 日 |
| **Phase 4** | 鮮度マッチング画面（複数候補）展開<br>- 上位 N 件一括生成ボタン<br>- コスト上限ガード徹底<br>- バルク送信での無効化確認 | 1〜2 日 |

各 Phase は独立リリース可能。

---

## 11. リスクと対処

| リスク | 影響 | 対処 |
|---|---|---|
| Claude が判定根拠を捏造 | ◯ なのに該当情報なし | プロンプトで `evidence` を引用形式強制、`confidence: low` を UI で △ 降格 |
| 必須/尚可ラベルの誤分類 | × なのに ◯ 扱い | Stage 1 出力に `condition` 原文保持、UI で営業確認、手動上書き容易化 |
| モデルハードコード問題 | 新機能だけ sonnet 利用で混乱 | 新メソッドは `config('services.anthropic.model')` で統一 |
| コスト爆発 | 月額急増 | 上限ガード、Phase 4 まで「ボタン押下時のみ」厳守 |
| 編集後の再生成で営業の編集消失 | UX 事故 | `edited_at > generated_at` の場合は確認ダイアログ必須＋差分マージ |
| 既存 `required_skills` との二重管理 | UI 混乱 | `ai_requirements` は別カラムで併存。統合は別 issue |
| 添付スキルシート生テキスト未保存 | Stage 2 判定精度低 | `engineer_mail_sources.parsed_skill_sheet_text` 追加（Phase 1） |
| マルチテナント越境 | RLS 設定漏れ | `requirement_match_results` に RLS ポリシー必須 |
| haiku と sonnet の JSON フォーマット差 | parseResponse 失敗 | 既存 `parseResponse()` の ``` 除去ロジック流用 + リトライ時 |
| 並列 Claude 呼出でレート制限 | 提案メール生成事故 | フロントで「対照表生成 → 提案生成」直列化、もしくは 1 リクエストにまとめる |

---

## 12. Critical Files

### Backend

- `app/Services/ClaudeService.php` — 新規メソッド 2 つ追加
- `app/Services/RequirementMatchingService.php` — 新規作成
- `app/Http/Controllers/Api/ProjectMailController.php` — エンドポイント追加
- `app/Http/Controllers/Api/RequirementMatchResultController.php` — 新規作成（PATCH 用）
- `app/Models/ProjectMailSource.php` — `ai_requirements` cast 追加
- `app/Models/RequirementMatchResult.php` — 新規作成
- `app/Models/EngineerMailSource.php` — `parsed_skill_sheet_text` 追加
- `database/migrations/202X_*_add_ai_requirements_to_project_mail_sources.php` — 新規
- `database/migrations/202X_*_add_parsed_skill_sheet_text_to_engineer_mail_sources.php` — 新規
- `database/migrations/202X_*_create_requirement_match_results_table.php` — 新規
- `routes/api.php` — エンドポイント定義
- `config/services.php` — `anthropic.requirement_match_max_per_request` 追加
- `config/features.php`（新規 or 既存）— `requirement_matching` flag
- `tests/Pgsql/RequirementMatching/*` — 新規

### Frontend

- `src/components/RequirementMatchTable.tsx` — 新規作成
- `src/app/matching/[id]/page.tsx` — 対照表ボタン + ProposalModal 拡張
- `src/app/engineer-mails/[id]/page.tsx` — 同上
- `src/lib/api.ts` — API クライアント追加

---

## 13. 次のアクション（Phase 1 着手前チェック）

1. ☐ Issue 起票（Phase 1 単位で 1 つ）
2. ☐ 松村さんに本ドキュメントを共有し、要件項目の事前合意
3. ☐ `tenants.feature_requirement_matching` カラムを先に migration（既存テナント全 false）
4. ☐ Claude プロンプトの初回サンプル設計と松村による評価（モック実行で良し悪し判断）
5. ☐ コスト試算の上限承認（月額 $50 程度を想定）

---

## 14. メモ

- 既存 `ClaudeService::generateProposal()` がモデル `claude-haiku-4-5-20251001` をハードコードしている既知の technical debt が見つかった → 本機能では同じ轍を踏まない
- `engineer_mail_sources.affiliation`（2026-05 追加済）はスキルシート補足として Stage 2 プロンプト入力に含める
- 既存 `required_skills` / `preferred_skills` jsonb と `ai_requirements` jsonb は **併存**。既存 UI 表示はそのまま、対照表は新ビューでのみ使用。将来統合は別 issue

---

## 15. プロトタイプ検証結果反映 (2026-05-21)

`docs/481_requirement_matching_prototype_report.md` で実 PMS/EMS 検証を実施。松村レビュー結果を踏まえ以下を確定:

### 15.1 モデル選定
- **Sonnet 4.6 で固定**。Haiku 4.5 は Angular.js / Angular のバージョン違いを見逃し、テックリード判定が緩いなど精度低下が顕著で営業ミスを誘発するため不採用
- 実装は `config('services.anthropic.model')` 経由 (haiku ハードコードしない)

### 15.2 カテゴリ表記
- 内部 enum: `skill` / `experience` / `attitude` / `location` / `language` / `contract` / `other` (英語維持・DB 互換性)
- UI 表示: 「スキル / 経験 / 姿勢 / 勤務地 / 言語 / 契約 / その他」(和文)
- フロント側で enum → ラベルマッピング (`src/lib/requirementCategoryLabel.ts` 想定)

### 15.3 UI 分離: スキル対照表 vs 契約条件チェック
要件は category で 2 グループに分けて表示:

| グループ | category 値 | 用途 |
|---|---|---|
| **スキル対照表** | `skill` / `experience` / `attitude` / `language` / `other` | 技術者本人の能力・経験を judge する表 (◯/△/× 中心) |
| **契約条件チェック** | `contract` / `location` | 年齢上限・国籍・単価・商流・勤務地などのフィルタ条件 (?/◯/× 中心) |

提案メール本文には両セクションを `## スキル対照表` / `## 契約条件チェック` の見出しで挿入 (§481 報告書 §8 サンプル参照)。

### 15.4 コスト最終試算 (Prompt Caching 適用後)

| 項目 | 試算 |
|---|---|
| Stage 1 (PMS 単位 1 回) | $0.017 |
| Stage 2 1回目 (cache write) | $0.026 |
| Stage 2 2-5回目 (cache read) | 4 × $0.020 = $0.080 |
| **5 候補/案件 合計** | **$0.123** |
| **月 360 件** | **約 $44** |

ephemeral cache 5min で完結する想定 (1 提案フロー内で全候補を判定)。出力 token を絞る (evidence を短く / confidence 省略) で $30/月台まで圧縮可能。

### 15.5 ClaudeService 実装方針
- `cache_control: ephemeral` を system prompt + 要件 block に付与
- max_tokens 2500 設定 (Sonnet 4.6 デフォルトは小さい)
- リトライ・タイムアウトは既存 `postWithRetry` を流用

### 15.6 計画書 §5 API への補足
- `GET /v1/project-mails/{id}/requirement-match-batch?engineer_ids=...&ems_ids=...` の **bulk endpoint** も検討。フロントで複数候補を 1 度に表示する場合、1 リクエストで cache を確実に同一接続にできる
- Stage 1 結果は `project_mail_sources.ai_requirements` で永続化 (キャッシュとは別レイヤ)

### 15.7 残課題 (Phase 1 実装前に追加検討)
- [ ] スキルシート添付の PDF/Excel から `parsed_skill_sheet_text` を抽出する処理 (Phase 1 で migration 追加だが、抽出パイプラインは Phase 4 でも可)
- [ ] 提案メール送信履歴 (`delivery_send_histories`) に `requirement_match_result_id` を FK で紐づけるか検討

---

## 16. Phase 4 後の安定化 + UI 改修 (2026-05-21)

Phase 1〜4 完了後、本番運用で発覚した課題への対応 + 提案フローの UX 改善を実施。コミット: backend `47225ed` / frontend `c876403` → `4583068` → `61faf7a` (本番反映済)。

### 16.1 Claude API 安定化 (`ClaudeService.php`)

| 課題 | 対応 |
|---|---|
| cURL error 28 (30s timeout) | `extractRequirements` / `judgeRequirementMatches` の timeout を 30s → **120s** に拡張。PMS 27196 等の長文メールで Anthropic 応答に 30+ 秒かかる事象に対応 |
| 要件 100+ 件で max_tokens 切れ | Stage1 system prompt に **「★最重要ルール: 重要な要件 上位 5 件程度に絞る (最大 8 件)」** を追加。複数案件まとめメール (案件1/案件2/...) で 100+ 件が抽出され 6000 トークンでも切れる事象に根本対策 |
| silent な truncate | `stop_reason='max_tokens'` 時に明示 Exception 化 (silent な JSON 不整合を防ぐ) |
| max_tokens | Stage1: 2500→**3000** / Stage2: **4000** (絞り込みプロンプトで十分) |

新プロンプトのコア追加部分:
```
★最重要ルール: 要件は重要度の高い上位 5 件程度に絞る (最大 8 件)
- 案件のコア要件のみ。瑣末な条件は除外
- 複数案件をまとめたメール (案件1/案件2/...) の場合は、判定対象となる案件 1 件分を選び、その上位 5 件程度を抽出
- 必須 (must) 要件を優先。尚可は特に重要な場合のみ
```

### 16.2 Next.js dev rewrites proxyTimeout

`next.config.ts` に `experimental.proxyTimeout: 180_000` 追加。デフォルト 30s で 499 切断されていた問題を解消。

### 16.3 必須要件×案件の自動除外フィルタ (新設)

`/engineer-mails/[id]` と `/matching/[id]` のヘッダーセレクト列に **「☐ 📊 対照表」** チェックボックスを追加。

| 仕様 | 内容 |
|---|---|
| デフォルト | **OFF** (件数が多い時の Claude API 負荷を考慮、ユーザーが必要な時のみ ON) |
| ON 時の挙動 | 鮮度マッチング結果の全 PMS で `/v1/project-mails/{id}/requirement-match?ems_id=N` を**並列取得** → 必須要件 × 案件は `freshItems` から除外して setState |
| ローディング表示 | `FreshLoadingIndicator` コンポーネント: 48px スピナー + フェーズ表示 + 進捗バー + 件数 (例 `3/10件 30%`) + 補足説明 |
| 対照表生成失敗時 | 安全側に倒して **除外せず表示** (営業判断に委ねる) |
| 対象 | feature_requirement_matching ON のテナントのみ |

### 16.4 対照表 toggle ロジックの全面再設計

#### 旧実装の問題
- `baseBodyRef.current` (初回 `draft.body`) を保持し、OFF 時に丸ごと書き戻していた
- 結果: 宛先名変更 (handleToNameChange) や本文編集が toggle で失われる、ON/OFF 繰り返しでバグ
- Claude API 応答待ち中の OFF (race condition) で「OFF なのに対照表入り」状態が発生

#### 新実装 (`removeMatchTableFromBody` 導入)
`lib/requirementCategoryLabel.ts`:
```ts
export function removeMatchTableFromBody(body: string): string {
  const sep = '─'.repeat(48)
  const re = new RegExp(`\\n*${sep}\\n[\\s\\S]*?\\n${sep}\\n[^\\n]*\\n*`)
  return body.replace(re, '\n\n').replace(/\n{3,}/g, '\n\n')
}
```

toggle 関数:
```ts
const includeMatchRef = useRef(includeMatchTable)
includeMatchRef.current = includeMatchTable
const handleToggleMatchTable = async (checked: boolean) => {
  setIncludeMatchTable(checked)
  if (!checked) {
    setBody(prev => removeMatchTableFromBody(prev))
    return
  }
  const md = matchTableMd ?? (await fetchMatchTable())
  if (!includeMatchRef.current) return  // fetch 完了時に OFF ならスキップ
  if (!md) { setIncludeMatchTable(false); return }
  setBody(prev => insertMatchTableIntoBody(removeMatchTableFromBody(prev), md))
}
```

ポイント:
- 「現在の本文をベースに挿入/除去」 = 編集や宛先名変更が保持される
- ON 時は **一旦除去してから挿入し直し** = 重複防止
- `includeMatchRef` で race condition guard
- 置換結果が `\n\n` = 段落区切りの空行を保持

### 16.5 insertMatchTableIntoBody のマーカー優先度修正

旧 marker リスト:
```
'ご面談', 'お気軽にご返信', 'お忙しいところ', 'ご検討...', '何卒...', '_/_/_/', '━━━', '─────'
```

新 marker リスト:
```
'_/_/_/', '━━━', '─────', 'お忙しいところ', 'ご検討いただけます', 'ご検討のほど', '何卒よろしくお願い'
```

「お気軽にご返信」「ご面談」を除外。理由: 「面談やスキルシートのご要望がございましたら、お気軽にご返信ください。」のような **本文中の一文** に現れやすく、その直前に対照表を入れると **文を分断していた** ため。

### 16.6 スキルシート / 技術者添付 DL & 添付対応

`/v1/engineer-mails/{id}` API が `email.attachments` を既に eager load 済 (バックエンド変更不要)。

| 場所 | 機能 |
|---|---|
| `/engineer-mails/[id]` 緑ヘッダー直下 | `📎 履歴書.xlsx (245KB)` チップを表示。クリックで Blob ダウンロード (`downloadEngineerAttachment`) |
| 個別提案モーダル (両画面 ProposalModal) | 「📎 技術者スキルシート (N件) を送信添付に追加」ボタン。一度押すと `✓ 追加済` (二重追加防止)。各添付の **確認用 DL チップ** を別途並置 (送信添付には影響しない純粋確認用) |
| ✕ 削除同期 | 送信添付欄から ✕ で削除すると `addedEngineerAttIds` セットからも除外 → 「追加済」が「📎 …を添付」に戻り再追加可能 |
| `BulkSendModal` (まとめて提案) | 技術者単体スキルシート対応外 (複数技術者まとめのため非対象)。`engineerAttachments=[]` で固定 |

ファイル変換: `axios.get(... { responseType: 'blob' })` → `new File([blob], filename, { type: mime })` で送信添付に流用。

### 16.7 宛先名の自動抽出 (`extractSenderNameFromBody`)

`/matching/[id]` の鮮度マッチング個別提案で、宛先名を **技術者本人** (`FreshEms.name` = `Y.H` 等のイニシャル) ではなく、**メール本文の挨拶文** から BP 担当者名を抽出するよう変更。

```
「いつもお世話になっております。
 株式会社キャリアビートの雨宮 昂平と申します。」
                  ↓
                "雨宮 昂平"
```

抽出パターン優先度:
1. `(株式会社|有限会社|合同会社|（株）|㈱)XX **の YY と申します/でございます**`
2. `YY と申します`
3. `YY でございます`
4. 「営業」「弊社」「担当」「担当者」など一般語は除外

抽出不可なら挨拶は「営業ご担当者様」。

### 16.8 残課題 / Phase 5 候補
- **スコア・点数の出し方の見直し** (2026-05-21 ユーザー言及あり) — 鮮度マッチング `score` 計算ロジックの再設計
- **複数案件まとめメール** に対する対照表生成 (1 メール 1 案件前提の現設計を拡張)
- **`BulkSendModal` (まとめ提案) のスキルシート添付** — 複数技術者対応の UI 設計
- **登録済技術者 (Engineer ID 経由) の添付対応** — 現状は EMS 経由でのみ動作
- **本番 EMS のバックフィル実行** (60k 件あり夜間バッチで分割) — Phase 4 残課題
