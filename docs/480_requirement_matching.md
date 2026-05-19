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
