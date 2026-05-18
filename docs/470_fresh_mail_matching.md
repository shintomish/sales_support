# 鮮度マッチング機能（過去 N 日メールから候補抽出）

**ステータス**: 仕様策定中（設計フェーズ）
**起票日**: 2026-05-18
**起票者**: 営業要望（事務経由）
**関連**: docs/420_matching_requirements.md（基本マッチング）

---

## 1. 背景・目的

### 営業現場の要望
- 案件マッチング画面 (`/matching/[project_mail_id]`) は SES 営業の主戦場
- 古い技術者プロフィールに埋もれた候補ではなく **「直近で来た技術者メール」だけを素早く確認** したい
- 受信した技術者メールから即提案するフローを 1 画面で完結させたい
- 技術者メール画面 (`/engineer-mails/[engineer_mail_id]`) でも対称な機能（直近の案件メールから提案）が欲しい

### 既存機能との関係
- 現状 `/matching/[id]` のヘッダーに「全て / 自社 / BP / メール」のソースフィルタがある
- 「メール」は `engineer_mail_source_id` を持つ Engineer レコードを絞る既存機能
- **この既存「メール」フィルタを本機能に置き換える**

---

## 2. 機能仕様（合意済み）

### 共通
- **対象期間**: ユーザー指定（1〜30 日）。**デフォルト 7 日**
- **マッチング対象**: 受信メール側を全件スキャン
  - 案件マッチング画面 → `engineer_mail_sources`（技術者メール）
  - 技術者マッチング画面 → `project_mail_sources`（案件メール）
- **未登録メールも提案可能**
  - 提案メール送信時に Engineer / Project レコードを自動作成
  - **重複検出キー: メールアドレス + 氏名 + 所属 の 3 項目完全一致**
  - 一致した既存レコードがあれば再利用、なければ新規作成
- **既存「メール」フィルタは本機能に置換**（並列ではない）
- **登録済バッジ**: 既に `engineers` レコードに変換済の候補は「登録済」バッジで明示
  - 未変換は「新規」バッジ（提案送信時に自動変換）

### 利用シーン
- 営業が朝イチで「昨日〜直近3日に来た技術者メール」を案件と突き合わせて即提案
- 鮮度の高い候補だけ集中して捌くフロー

---

## 3. UI 案

### `/matching/[project_mail_id]` ヘッダー変更

**変更前:**
```
[全て] [自社] [BP] [メール]
```

**変更後:**
```
[全て] [自社] [BP] [📨 受信メールから:  7 日 ▼ ]
```

- 「📨 受信メールから」ボタン押下でフィルタモード切替
- 隣接する数値セレクトで対象日数（1, 3, 7, 14, 30 など）を選ぶ
- アクティブ時、技術者一覧の表示ロジックが切り替わる:
  - 通常: MatchingService 上位 20 名
  - 受信メールモード: 過去 N 日の `engineer_mail_sources` を案件要件でスコアリングし、結果を一覧表示

### `/engineer-mails/[engineer_mail_id]` 側
- 対称 UI で「📨 案件メールから: N 日 ▼」ボタンを追加
- 過去 N 日の `project_mail_sources` をこの技術者要件でスコアリングし、結果を一覧表示

---

## 4. バックエンド設計（案）

### 新規 API

**案件マッチング側:**
```
GET /api/v1/project-mails/{id}/fresh-engineer-mails?days=3
レスポンス: 過去N日の engineer_mail_sources をスコアリングした候補一覧
```

**技術者マッチング側:**
```
GET /api/v1/engineer-mails/{id}/fresh-project-mails?days=3
レスポンス: 過去N日の project_mail_sources をスコアリングした候補一覧
```

### スコアリング
- 既存 `MatchingService` のロジックを流用したい
- ただし入力が「Engineer モデル」ではなく「EngineerMailSource の抽出済データ」になるため、内部メソッドのインタフェース調整が必要
- もしくは「EngineerMailSource をその場で仮想 Engineer に変換 → 既存 score 関数に流す」アダプタを書く

### 自動レコード作成（提案メール送信時）
**提案送信フロー（未登録の場合）:**
1. ユーザーが候補をチェック → 「📤 提案メール送信」
2. バックエンドで該当 EngineerMailSource を Engineer として転記
   - 既存の「技術者メール → Engineer 変換」ロジックを流用（EngineerMailExtractor / EngineerImporter 系）
3. 重複チェック: 同一 EngineerMailSource から既に変換済の Engineer があれば再利用
4. 変換した Engineer に対して既存の `send-proposal` フローを実行

**対称: 案件側自動作成**
- 案件メール候補から提案する場合、ProjectMailSource → Deal/ProjectMail 変換が必要
- 同様に既存変換ロジックを流用

---

## 5. 残課題

### 設計フェーズで決定（着手前にもう一段詰める）
- **N 日の選択肢**: ドロップダウンの値候補 `1, 3, 7, 14, 30`（推奨）
- **スコアの下限**: 候補一覧に出す最低スコアは？（既存は 0 点でも表示）
- **抽出データ不完全な EngineerMailSource の扱い**:
  - スキル抽出失敗 → スコア計算不能。除外する？低スコアで表示？
- **提案メールの宛先**:
  - EngineerMailSource の `from_address`（メール送信元）に返信する形でよいか
  - 同様に案件側は ProjectMailSource の `from_address` に提案
- **権限**: テナント分離は既存通り `tenant_id` フィルタで十分か（基本は OK の想定）

### UI 細部
- **日数セレクト UI**: ドロップダウン採用（候補値固定）
- **アクティブ時の一覧見出し**: 「過去 7 日の受信メールから N 名」のような表示
- **バッジデザイン**: 「登録済」=グレー / 「新規」=オレンジ等（既存配色と整合）

---

## 6. 実装ロードマップ（B プラン進行中）

### Phase 1: 仕様確定 ✓ 完了 (2026-05-18)
- [x] 営業要望ヒアリング（合意済み 5 項目）
- [x] 主要決定事項: デフォルト 7 日 / 登録済バッジ / 重複検出 メール+氏名+所属

### Phase 2: 設計 ✓ 完了 (2026-05-18)
- [x] スコアリング流用方針の技術検証（仮想 Engineer + setRelation アダプタ）
- [x] API 設計詳細（§8.4）
- [x] 自動レコード作成のトランザクション境界設計（§8.5）
- [x] 残課題確定（§8.6, §8.7）
- [x] 3 つの判断確定: (B) affiliation カラム追加 / 同時リリース / 提案済まで判定

### Phase 3: 実装（次タスク）
- [ ] migration: `engineer_mail_sources.affiliation` text 追加
- [ ] EngineerMailExtractor (Claude プロンプト) に affiliation 抽出追加
- [ ] EngineerMailMatchingService 新規（engineer_mail → project_mail スコアリング）
- [ ] FreshMailMatchingService（仮想 Engineer 変換アダプタ）
- [ ] バックエンド API 4 本（fresh-* 取得 ×2 + send-proposal-from-* ×2）
- [ ] 「提案済」判定ヘルパー（delivery_send_histories ベース）
- [ ] フロント UI 改修（matching / engineer-mails 両方）+ 3 値バッジ
- [ ] 既存「メール」フィルタ削除（matching ヘッダー / EngineerController::index）

### Phase 4: 検証 / リリース
- [ ] ローカル動作確認
- [ ] 本番デプロイ
- [ ] 営業へのアナウンス

---

## 7. 関連ファイル

- `app/Services/MatchingService.php` — PublicProject × Engineer の既存スコアリング（本機能では未使用）
- `app/Services/ProjectMailMatchingService.php` — **本機能の流用元**。`matchEngineers(ProjectMailSource, limit)` と `score(ProjectMailSource, Engineer)`
- `app/Http/Controllers/Api/ProjectMailController.php:485-522` — `matched-engineers` 既存エンドポイント
- `app/Http/Controllers/Api/EngineerMailController.php:111-155` — `registerEngineer` (EMS → Engineer 変換の既存ロジック)
- `app/Models/EngineerMailSource.php` / `ProjectMailSource.php` — 受信メールソース
- `src/app/matching/[id]/page.tsx:1224-1240` — 既存ソースフィルタ UI（置換対象）
- `src/app/engineer-mails/[id]/page.tsx` — 対称機能の追加先

---

## 8. Phase 2 設計詳細（2026-05-16 追記）

### 8.1 既存実装の調査結果

| 観点 | 実装状況 |
|---|---|
| 案件メール → 技術者スコアリング | `ProjectMailMatchingService::score(ProjectMailSource, Engineer)` あり。100点満点で `engineer` を入力に取る |
| 技術者メール → 案件メールスコアリング | **未実装**。対称サービス無し |
| EMS → Engineer 変換 | `EngineerMailController::registerEngineer` あり。但し **重複チェック無し**・`affiliation_email` のみセット |
| EMS の email アドレス取得経路 | `EngineerMailSource->email->from_address` (`emails` テーブル経由) |

### 8.2 **重要な仕様ギャップ：EngineerMailSource に `affiliation` フィールドが無い**

合意済の重複検出キー「メール + 氏名 + **所属**」を技術的に成立させるためのフィールドが、現状 `engineer_mail_sources` テーブルに存在しない:

| 項目 | Engineer (master) | EngineerMailSource (受信メール) |
|---|---|---|
| email | `email` カラム | `email` リレーション経由 `from_address` のみ |
| 氏名 | `name` | `name` |
| 所属（テキスト） | `affiliation` | **無し**（`affiliation_type` enum のみ） |

**判断が必要（A/B/C のいずれか）:**
- **(A) 重複検出キーを「メール + 氏名」のみに緩める** — 実装最小。所属違い別人を取り違える可能性は低い（メール一致で十分）
- **(B) `engineer_mail_sources` に `affiliation` text カラムを追加する migration** — 抽出 AI (`EngineerMailExtractor` 系) も追加修正必要。過去データは NULL のままで運用
- **(C) 提案送信時にメール本文から都度抽出する** — Claude API 呼び出し追加コスト

→ **推奨: (A)**。メールアドレスは個人を一意に特定するため、所属まで一致を要求すると同一人物が所属移動した場合に別レコードが作られ、むしろ Engineer マスタが汚れる。Phase 3 着手前にユーザー判断要。

### 8.3 スコアリング流用方針

`ProjectMailMatchingService::score(ProjectMailSource, Engineer)` の入力 Engineer を、**EMS から作る仮想 Engineer (DB 未保存)** に差し替えるアダプタを実装する。

**仮想 Engineer 構築ロジック（イメージ）:**
```
$virtualEngineer = new Engineer([
    'tenant_id'        => $ems->tenant_id,
    'name'             => $ems->name,
    'email'            => $ems->email?->from_address,
    'affiliation_type' => $ems->affiliation_type,
    'nearest_station'  => $ems->nearest_station,
]);
// EngineerProfile を仮想セット (希望単価)
$profile = new EngineerProfile([
    'desired_unit_price_min' => $ems->unit_price_min,
    'desired_unit_price_max' => $ems->unit_price_max,
    'available_from'         => $ems->available_from,
    'age'                    => $ems->age,
]);
$virtualEngineer->setRelation('profile', $profile);
// EngineerSkill を仮想セット (skills 配列から)
$skills = collect($ems->skills ?? [])->map(function ($skillName) {
    $skill = Skill::firstOrCreate(['name' => $skillName], ['category' => 'other']);
    return new EngineerSkill(['skill_id' => $skill->id])->setRelation('skill', $skill);
});
$virtualEngineer->setRelation('engineerSkills', $skills);
```

→ こうすれば既存 `score()` がそのまま動く。**スキルマスタ (Skill) は副作用で作られる** 点だけ注意（既存の registerEngineer も同じ挙動なので追従でOK）。

**対称（engineer_mail → project_mail）:**
- 既存サービス無しのため、新規 `EngineerMailMatchingService` を新設
- 当面は `ProjectMailMatchingService::score` のロジックを参考に「受信案件メール × 技術者」のスコアリング関数を逆向きで書く（PMS × ProjectMailSource）
- スコープが大きい場合は **Phase 3 着手時に分離検討**（最低限 matching 側だけ先行リリースもアリ）

### 8.4 API 設計

**案件マッチング側（matching/[id]）:**
```
GET /api/v1/project-mails/{id}/fresh-engineer-mails?days=7
  → [
      {
        "engineer_mail_source_id": 123,
        "score": 85,
        "breakdown": {...},
        "reasons": [...],
        "ems": {
          "name": "...", "skills": [...], "unit_price_max": ...,
          "received_at": "2026-05-14T10:00:00Z",
          "email": { "from_address": "...", "subject": "..." }
        },
        "registered_engineer_id": null  // 既に Engineer 化済ならその id
      },
      ...
    ]
```

**技術者マッチング側（engineer-mails/[id]）:**
```
GET /api/v1/engineer-mails/{id}/fresh-project-mails?days=7
  → 上記対称
```

- フロントは既存「matched-engineers」と同形に近いレスポンスを期待。**`registered_engineer_id` の有無で「登録済」「新規」バッジを描画**
- パラメータバリデーション: `days` は `integer|min:1|max:30`、デフォルト 7

### 8.5 提案送信フロー（auto-create + 重複検出）

既存 `send-proposal` エンドポイントの直前に「Engineer 化フェーズ」を挟む:

```
[フロント: 候補にチェック → 提案メール送信]
   ↓
1. EMS ID と一緒に POST
2. バックエンドで Engineer 存在チェック
   - 8.2 で確定したキー（推奨: email + name）で既存 Engineer を検索
   - あれば再利用
   - なければ EngineerMailController::registerEngineer 相当のロジックで Engineer 作成
3. EMS.status = 'registered'、EMS.engineer_id を記録（カラム追加検討）
4. 既存 send-proposal フロー実行
```

**トランザクション境界:** Engineer 作成と EMS.status 更新は同一 DB トランザクション。提案メール送信（AWS SES API 呼び出し）はトランザクション外（送信失敗時に Engineer は作られたまま残るが、次回提案時に再利用可能なので問題なし）。

### 8.6 残課題への暫定方針

| 課題 | 暫定方針 |
|---|---|
| スコアの下限 | 既存 matched-engineers と同じく `score > 0`（除外条件にヒットしたものだけ落とす） |
| 抽出失敗 EMS の扱い | スキル空でもスコア計算自体は走るので一覧表示。スキル0点のため自然と下位に沈む |
| 提案メール宛先 | EMS.email.from_address（既存 registerEngineer も同じ参照） |
| 権限 / テナント分離 | 既存通り `BelongsToTenant` グローバルスコープで OK |

### 8.7 Phase 3 着手前のユーザー判断事項（2026-05-18 確定）

1. **重複検出キー → (B) `engineer_mail_sources` に `affiliation` カラム追加**
   - migration で text カラム追加
   - `EngineerMailExtractor` (Claude プロンプト) も所属抽出フィールド追加
   - 過去データは NULL のまま運用（NULL 同士は不一致扱い＝再変換）
   - 検索キー: `email + name + affiliation` 3項目完全一致
   - 案件側 (`project_mail_sources`) は既に `customer_name` あり → 流用
2. **対称機能 → matching / engineer-mails 同時リリース**
   - 新規 `EngineerMailMatchingService` も Phase 3 で実装
3. **「登録済」バッジ → 提案済まで判定する**
   - 単に Engineer マスタにあるだけでなく、当該案件メール / 技術者メールに対し既に提案送信済かをチェック
   - ソース: `delivery_send_histories` (send_type = `matching_proposal` / `engineer_proposal`)
   - 状態の表現: 「新規」「登録済（提案未送）」「提案済」の 3 値

---

## 9. Phase 3 実装タスク（着手準備）

### 9.1 マイグレーション

```
add_affiliation_to_engineer_mail_sources_table
  - engineer_mail_sources.affiliation (text, nullable)
```

過去レコードは NULL のまま。再抽出バッチは走らせない方針。

### 9.2 バックエンド

| # | 種類 | 内容 |
|---|---|---|
| 1 | Service 改修 | `EngineerMailExtractor` (Claude プロンプト) に affiliation 抽出フィールド追加 |
| 2 | Service 新規 | `EngineerMailMatchingService::matchProjects(EngineerMailSource, limit)` + `score(EngineerMailSource, ProjectMailSource)` |
| 3 | Service 新規 | `FreshMailMatchingService`（仮）— EMS → 仮想 Engineer 変換アダプタ + 期間絞り込み |
| 4 | API | `GET /api/v1/project-mails/{id}/fresh-engineer-mails?days=7` |
| 5 | API | `GET /api/v1/engineer-mails/{id}/fresh-project-mails?days=7` |
| 6 | API | `POST /api/v1/project-mails/{id}/send-proposal-from-ems` (重複検出 + Engineer 作成 + 既存 send-proposal 流用) |
| 7 | API | `POST /api/v1/engineer-mails/{id}/send-proposal-from-pms` (対称) |
| 8 | Helper | 「提案済」判定: `DeliverySendHistory` を `engineer_id`/`project_mail_id` で結合し send_type 一致を確認 |

### 9.3 フロントエンド

| # | 画面 | 内容 |
|---|---|---|
| 1 | `/matching/[id]` | ヘッダー `[全て][自社][BP][メール]` の「メール」を `[📨 受信メールから: N 日 ▼]` に置換 |
| 2 | `/matching/[id]` | アクティブ時のリスト表示分岐（受信メール候補 → score 順） |
| 3 | `/engineer-mails/[id]` | 対称 UI 新設（`[📨 案件メールから: N 日 ▼]` ボタン） |
| 4 | 共通 | バッジ 3 値表示（新規/登録済/提案済） |
| 5 | 共通 | 「📤 提案メール送信」モーダル — 既存 ProposalModal を流用、EMS ID を payload に追加 |

### 9.4 削除対象

- `EngineerController::index` の `source=mail` フィルタ分岐（`engineer_mail_source_id` IS NOT NULL）
- `matching/[id]/page.tsx:1224-1240` のソースフィルタ UI 内「メール」ボタン

→ 削除タイミングは Phase 3 リリースと同時。
