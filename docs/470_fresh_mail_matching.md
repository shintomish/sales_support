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

### Phase 2: 設計（次タスク）
- [ ] スコアリング流用方針の技術検証（MatchingService の入力アダプタ）
- [ ] API 設計詳細（レスポンス schema、登録済フラグの返し方）
- [ ] 自動レコード作成のトランザクション境界設計（重複検出 + 作成 + 提案送信を1トランザクションにするか）
- [ ] 残課題（スコア下限・抽出失敗の扱い・宛先）を確定

### Phase 3: 実装
- [ ] バックエンド API 2 本（`/fresh-engineer-mails`, `/fresh-project-mails`）
- [ ] 自動変換ロジック流用 + 重複防止（メール+氏名+所属キー）
- [ ] フロント UI 改修（matching / engineer-mails 両方）
- [ ] 既存「メール」フィルタ削除

### Phase 4: 検証 / リリース
- [ ] ローカル動作確認
- [ ] 本番デプロイ
- [ ] 営業へのアナウンス

---

## 7. 関連ファイル

- `app/Services/MatchingService.php` — 既存スコアリング
- `app/Http/Controllers/Api/ProjectMailController.php:490` — 既存 matched-engineers
- `app/Models/EngineerMailSource.php` / `ProjectMailSource.php` — 受信メールソース
- `src/app/matching/[id]/page.tsx:1224-1240` — 既存ソースフィルタ UI（置換対象）
- `src/app/engineer-mails/[id]/page.tsx` — 対称機能の追加先
