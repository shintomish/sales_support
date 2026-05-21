# スーパーPM計画書 — 10人チームで営業支援プロジェクトをリリースする

> 作成日: 2026-04-09 / 最終更新: 2026-05-21（要件マッチング機能 安定化 + UI 改修。詳細は §17）

---

## 1. チーム構成（10人の役割分担）

| 役割 | 人数 | 担当範囲 |
|------|------|---------|
| PM | 1 | 全体管理・優先順位・顧客折衝 |
| バックエンドエンジニア | 2 | Laravel API・Supabase・バグ修正 |
| フロントエンドエンジニア | 2 | 画面実装・UX改善 |
| AI/データエンジニア | 1 | Claude連携・スコアリング精度改善 |
| インフラ/SRE | 1 | Docker・本番VPS・監視 |
| QA | 1 | テスト設計・バグ管理 |
| 営業担当 | 1 | SES企業へのヒアリング・要件フィードバック |
| デザイナー | 1 | UI/UX・画面設計 |

---

## 2. リリース前に埋めるべきギャップ（現状の課題）

### 技術面

- ~~テストコードがほぼ存在しない（PHPUnit / Feature Test）~~ **✅ 2026-04-11 完了** → 下記「テスト実装状況」参照
- ~~エラー監視が `storage/logs` のみ（Sentry等なし）~~ **✅ 2026-04-11 完了** → Sentry 導入済み
- ~~APIドキュメントが未整備（OpenAPI / Swagger）~~ **✅ 2026-04-11 完了** → PHPアトリビュート方式で28エンドポイント対応済み
- ~~メール送信機能が未実装（現状コピペ運用）~~ **✅ 2026-04-15 完了** → SendGrid SMTP で本番稼働中(outsource@aizen-sol.co.jp)
- ~~配信停止(Unsubscribe)機能がない~~ **✅ 2026-04-15 完了** → `delivery_addresses.unsubscribe_token` + `/unsubscribe/{token}` 実装済み
- ~~バウンス処理が手動~~ **✅ 2026-04-22 完了** → 自動無効化が稼働中(35/38件を自動処理した1週間実績あり)

### 運用面

- ~~マルチテナント本番検証が不十分~~ **✅ 2026-05-02 完了** → 全Controllerで越境404検証(#2)
- ~~バックアップ・リストア手順書がない~~ **✅ 2026-05-04 完了**（手順書 + dev リストアリハーサル + Pro 昇格・週次手動運用稼働中 #1）
- 障害時のオンコール体制がない（Phase 2 / #5）

### ビジネス面

- 実際のSES企業による受け入れテスト（UAT）が未実施（βユーザー獲得 #3 が律速）
- ~~価格・契約モデルが未定~~ **✅ 2026-05-06 確定** → §9.1 参照（成約手数料 5〜10% / ハイブリッド）

---

## 3. リリースロードマップ（3フェーズ）

### Phase 1（4〜6週）: 品質安定化 — **大半クローズ済み**

- ~~Feature Test追加~~ **✅ 2026-04-11 完了**（Customer/Engineer/Deal/Matching, 2026-05-02 で Contact/Task も追加・Tests 147 passed）
- ~~Sentry導入~~ **✅ 2026-04-11 完了**
- ~~APIドキュメント整備~~ **✅ 2026-04-11 完了**（PHPアトリビュート方式・28エンドポイント）
- ~~メール送信（SendGrid）実装~~ **✅ 2026-04-15 完了**（→ 2026-04-17 AWS SES 本番切替済）
- ~~本番環境の監視ダッシュボード構築~~ **✅ 2026-04-11 完了** → UptimeRobot（死活監視）+ Sentry（エラー監視）
- 残：**勤務表・請求書管理 (#8)** — ✅ ほぼ完了(2026-05-13)。見積書/注文書/請求書を `invoices.doc_type` で統合管理、送信履歴 (doc_type 別画面)・メールテンプレ (doc_type 別タブ)・英文モード・電子印・承認ワークフローを全て本番反映。残作業は **billing-summaries 画面のスマホ対応 (#29)** のみ

### Phase 2（2〜4週）: クローズドβ

- SES企業1〜3社でUAT
- フィードバックをもとに優先度の高い修正
- パフォーマンステスト（1社あたりのデータ量想定）

### Phase 3（2週）: 正式リリース

- 本番インフラ安定化（VPS → スケール可能な構成検討）
- オンボーディング手順書・サポート体制
- 課金・契約フロー

---

## 4. PMとして今すぐやること

1. **GitHubのIssue/Projectボードを立ち上げる** — タスクの可視化
2. **週次スプリントを設定** — 2週間サイクルで優先度管理
3. **SES企業1社をβユーザーとして巻き込む** — 最速のフィードバックループ
4. **「リリース定義」を文書化** — 何が揃えばリリースOKかをチームで合意

---

## 5. このプロジェクト特有の注意点

### Claude APIコスト管理
スコアリング頻度が増えると費用が跳ね上がる。バッチ戦略の最適化が必要。

### Gmail OAuth制限
テナントが増えると認証管理が複雑化。Gmailアカウントごとの管理フロー整備が必須。

### マルチテナント境界
GlobalScopeの抜け穴は本番事故に直結。QAでの重点検証が必要。

---

## 6. テスト実装状況（2026-04-11時点）

### 実行結果サマリー

```
Tests: 68 passed, 1 skipped（ilike はPostgreSQL環境でパス）
Assertions: 216
```

### カバレッジ

| テストファイル | 件数 | 主なカバー内容 |
|---|---|---|
| `CustomerControllerTest` | 17 | CRUD・バリデーション・検索・テナント分離 |
| `EngineerControllerTest` | 16 | CRUD・スキル管理・プロフィール・テナント分離 |
| `DealControllerTest` | 19 | CRUD・ステータス/金額フィルタ・SES除外・テナント分離 |
| `MatchingControllerTest` | 17 | レコメンド・スコア詳細・スキルマスタ・Claude APIモック |

### テスト基盤の設計方針

- **認証**: `actingAs()` + `withoutMiddleware(SupabaseAuth::class)` で JWT 検証をバイパス
- **テナント分離**: `forceFill()` で他テナントのデータを作成し、GlobalScope の遮断を検証
- **外部API**: `MatchingService` をモックして Claude API 呼び出しを分離
- **DB**: SQLite in-memory（`phpunit.xml` の設定済み）
- **PostgreSQL固有構文**: `ilike` を使うテストは `skipIfSqlite()` でスキップ、本番DB環境でパス

### 今後追加すべきテスト

- `ContactController` / `TaskController`（基本CRUD）
- `EmailController` / `GmailOAuthController`（Gmail連携は統合テスト扱い）
- `ProjectMailController` / `DeliveryCampaignController`（配信系）
- `MatchingService` の Unit テスト（スコア計算ロジック個別検証）

---

## 7. 優先度マトリクス(2026-05-02 時点)

> GitHub Issue/Project: https://github.com/users/shintomish/projects/2

| 項目 | 重要度 | 緊急度 | 優先順位 | 状態 | Issue |
|------|--------|--------|---------|------|-------|
| βユーザー獲得 | 高 | 高 | 最優先 | 未着手 | [#3](https://github.com/shintomish/sales_support/issues/3) |
| Feature Test追加 | 高 | 高 | 最優先 | ✅ 完了(2026-04-11) | — |
| 監視基盤（Sentry） | 高 | 高 | 最優先 | ✅ 完了(2026-04-11) | — |
| UptimeRobot死活監視 | 高 | 高 | 最優先 | ✅ 完了(2026-04-11) | — |
| メール送信（SendGrid） | 高 | 中 | Phase 1 | ✅ 完了(2026-04-15) | — |
| 配信停止機能(Unsubscribe) | 高 | 中 | Phase 1 | ✅ 完了(2026-04-15) | — |
| バウンス自動処理 | 高 | 中 | Phase 1 | ✅ 完了(2026-04-22) | — |
| APIドキュメント | 中 | 中 | Phase 1 | ✅ 完了(2026-04-11) | — |
| **AWS SES本番切替** | 高 | 中 | Phase 1 | ✅ 完了(2026-04-17承認・本番運用中) | — |
| 提案スレッド機能(送受信履歴一元化) | 高 | 中 | Phase 1 | ✅ 完了(2026-04-25) | — |
| 配信先管理(一括有効/無効・スナップショット復元) | 中 | 中 | Phase 1 | ✅ 完了(2026-04-26) | — |
| メール分類精度向上(自社/技術者/案件の判定強化) | 中 | 中 | Phase 1 | ✅ 完了(2026-04-29) | — |
| **勤務表・請求書管理(work_records活用)** | 高 | 中 | Phase 1 | ✅ 完了(2026-05-13 doc_type 統合 A〜G 全完了・Refinitiv 注文書取込本番反映) | [#8](https://github.com/shintomish/sales_support/issues/8) |
| バックアップ手順書 | 高 | 中 | Phase 1 | ✅ 完了(2026-05-04 dev リストアリハーサル含む / 週次手動運用稼働中) | [#1](https://github.com/shintomish/sales_support/issues/1) |
| 追加テスト実装(Contact/Task他) | 中 | 中 | Phase 1 | ✅ 完了(2026-05-02 Contact/Task) | [#4](https://github.com/shintomish/sales_support/issues/4) |
| **forgot password (パスワード再設定)** | 高 | 中 | Phase 1 | ✅ 完了(2026-05-01) | [#13](https://github.com/shintomish/sales_support/issues/13) |
| **管理画面: テナント別ユーザー管理(CRUD)** | 高 | 中 | Phase 1 | ✅ 完了(2026-05-02) | [#14](https://github.com/shintomish/sales_support/issues/14) |
| **管理画面: 機能別データ統計ダッシュボード** | 中 | 低 | Phase 1 | ✅ 完了(2026-05-02) | [#15](https://github.com/shintomish/sales_support/issues/15) |
| **管理画面: メール署名設定** | 中 | 低 | Phase 1 | ✅ 完了(既存実装) | [#16](https://github.com/shintomish/sales_support/issues/16) |
| マルチテナント本番検証 | 高 | 中 | Phase 1-2 | ✅ 完了(2026-05-02) | [#2](https://github.com/shintomish/sales_support/issues/2) |
| オンコール体制整備 | 中 | 中 | Phase 2 | 未着手 | [#5](https://github.com/shintomish/sales_support/issues/5) |
| 課金・契約フロー | 高 | 低 | Phase 3 | 未着手 | [#6](https://github.com/shintomish/sales_support/issues/6) |
| インフラスケール対応 | 中 | 低 | Phase 3 | 未着手 | — |

### 本日(2026-05-02)時点の注目点

- **AWS SES 本番承認済(2026-04-17)・運用中**。日次12,000件・月次240,000件の配信枠を確保
- 4/22以降の1週間は **配信機能の磨き込み** が中心(提案スレッドAPI・配信先管理UI改善・メール検索/詳細の性能改善・メール分類精度向上)
- 4/30: GitHub Issue/Projectでタスクを可視化開始(Project ID 2)。新たに4項目をPhase 1に追加 — forgot password / ユーザー管理CRUD / 機能別データ統計 / メール署名設定
- 5/1: forgot password 完了(本番=新Supabaseプロジェクト smzoqpvaxznqcwrsgjju への完全移行込み)。パスワード強度チェッカー(8文字+大小文字+数字)+漏洩検出(HIBP)も実装
- 5/2: テナント別ユーザー管理 CRUD (#14) 完了。Supabase Auth Admin 連携で招待→パスワード設定→ログインまで一気通貫で動作。Invite テンプレも日本語化
- 5/2: 機能別データ統計ダッシュボード (#15) 完了。13 カード + 期間切替(7/30/90/365日) + テナント切替 + Storage 容量(business_cards JOIN でテナント別) + 15 分キャッシュ
- 5/2: ContactController/TaskController テスト追加 (#4 partial) + 全 Controller のテナント越境 404 検証で本番マルチテナント検証 (#2) も完了。Tests 全 147 passed
- メール署名設定 (#16) は既存実装済（`/settings/email-template`）と判明・クローズ予定
- Phase 1 残タスクは **2件**: 勤務表・請求書管理 / バックアップ手順書（残テスト実装は配信系・Matching が未対応だが優先度低）
- **βユーザー獲得** が最優先(重要度:高×緊急度:高)のまま未着手。技術面はリリース可能水準に達しており、次はビジネス面の進捗が律速

---

## 8. 2026-04-24 社内会議メモ

> 主題: SES内部ツールから「外部公開プラットフォーム」へのピボット可能性を検討

### 8.1 議論された論点（すべて検討段階）

1. **案件マーケットとしての一般公開**
   - 本システムを一般公開し、案件情報のみを配信する「案件マーケット」展開の議論
   - 公開時は会員登録制など、情報閲覧に制限を設ける方法の検討が必要

2. **フリーランス技術者向け配信・マッチング**
   - フリーランス技術者へ案件配信・マッチングするビジネスモデルについて協議
   - 収益化方法（サイト利用料 / 技術者紹介料）の検討が必要

3. **運用フェーズでのバグ報告ルート**
   - 開発の運用フェーズでは、バグ報告は口頭やLINE等「記録が残る形」で随時実施

### 8.2 既存設計との接続点

| 議題 | 既存資産 | 追加が必要なもの |
|---|---|---|
| 案件マーケット公開 | `public_projects` テーブル / `/public-projects` API CRUD一式 | 認証分離・公開フロント・閲覧制限・課金 |
| フリーランス向け | `engineers` / `engineer_profiles.is_public` / `applications` | 自己登録フロー・公開検索・通知配信 |
| バグ報告 | （なし） | 社内フィードバックフォーム |

---

## 9. 2026-05-06 対応方針確定（4/24 会議の続き）

### 9.1 収益モデル

| 項目 | 確定内容 |
|---|---|
| **主軸モデル** | **B（成約手数料）+ D（ハイブリッド = 技術者プレミアム併用）** |
| **手数料率** | **案件単位で可変 5〜10%**（`public_projects.commission_rate` 列で個別管理する想定） |
| **発注企業向け** | 掲載無料・成約時に手数料を徴収 |
| **技術者向け** | 登録/応募無料・将来プレミアム表示等のオプション課金 |
| **自社/外部 案件の表示** | **完全中立**（score ベースのみ。自社案件を優遇するロジックは持たない） |

#### 戦略的位置づけ
- 業界相場（15〜30%）に対し **大幅に低い水準**。低価格・市場シェア優先・乗り換え抑止が狙い。
- 月単価 60万円 × 7% = 4.2万/成約。業界標準 20% 比でおよそ 1/3 の手数料。同売上を出すには 2〜4倍の成約数が必要だが、その分掲載側・応募側の心理障壁が下がる。
- 自社（Aizen）が SES 会社であるため、自社案件と外部発注案件の利益相反を避ける目的で「完全中立」を選択。

### 9.2 社内バグ・要望フィードバック仕組み（**実装済 2026-05-06**）

4/24 会議では「LINE / 口頭で記録が残る形」と決まったが、Slack 未導入かつ LINE は組織共有が弱いため、5/6 に **社内フォーム化** に切り替え。記録性は DB + メール通知で担保。

| 項目 | 内容 |
|---|---|
| 投稿入口 | `/settings/feedback`（フローティングボタンなし） |
| 通知先 | y-shintomi@aizen-sol.co.jp（環境変数 `FEEDBACK_NOTIFY_TO` で管理） |
| 管理画面 | `/admin/feedback`（super_admin 専用・全テナント横断） |
| DB | `feedback_reports` テーブル（type / subject / body / url / UA / status） |
| 経路 | AWS SES（既存配信基盤を流用） |
| URL 自動付与 | 直前訪問URLを sessionStorage に記録 → フォームでプリフィル（編集可） |

### 9.3 案件マーケット公開化 — 中期検討

会議で議論された「一般公開」は **大きな方向転換**を伴うため、社内合意・収益モデル・法務（利用規約・特商法表記）が固まってからの着手とする。

#### 着手前に決めるべきこと
- ドメイン分離方針（例: `app.ai-mon.net` 社内 / `market.ai-mon.net` 公開）
- 初期ターゲット優先度（フリーランス技術者 / 法人発注 のどちらから集客するか）
- 認証フロー（既存 Supabase Auth との分離 or 共存）
- 閲覧制限の段階設計（未ログイン: タイトル・概要のみ / ログイン後: 単価・詳細）

#### PoC の最短経路（提案）
- 既存 `public_projects` + `engineer_profiles.is_public` を使い、認証なしの read-only 公開ページを別ドメインに 1〜2週間でデプロイ
- 法務・課金は後回しで、**意思決定に必要な数字を最短で取る**
- 反応次第で本格設計に進む / 撤退判断する

### 9.4 フリーランス技術者向けマッチング — 中期検討

`engineers` / `engineer_profiles` は既に存在するが、現状は **自社所属 SES 技術者前提** のスキーマ。フリーランス登録者を混在させる場合は以下の確認が必要。

- 技術者登録ロート（職務経歴書アップロード → 自動 OCR/構造化）
- マッチした案件の自動メール通知（既存配信機能を流用可能）
- テナント分離の境界変更（フリーランスは「公開テナント」または専用カラム）

### 9.5 優先度マトリクス追加項目

| 項目 | 重要度 | 緊急度 | 優先順位 | 状態 | 備考 |
|------|--------|--------|---------|------|-----|
| **社内バグ・要望フィードバック** | 高 | 高 | Phase 1 | ✅ 完了(2026-05-06) | API + フロント + 本番デプロイ済 |
| 案件マーケット公開化 PoC | 中 | 低 | Phase 2-3 | 未着手 | 収益モデル/法務確定後に着手 |
| フリーランス技術者マッチング | 中 | 低 | Phase 2-3 | 未着手 | スキーマ拡張の前段検討が必要 |
| `public_projects.commission_rate` 列追加 | 中 | 低 | Phase 2 | 未着手 | 公開化着手時にセットで対応 |
| **スマホ対応(レスポンシブUI全面適用)** | 中 | 中 | Phase 2 | ✅ 大半完了(2026-05-15/16) 残: 名刺 OCR モーダル / 44px タッチ / 公開ページ | [#29](https://github.com/shintomish/sales_support/issues/29) |

### 9.6 本日(2026-05-06)時点の総括

- **会議の続きとして収益モデルが確定**: B+D / 5〜10%可変 / 完全中立
- **社内フィードバック導線が稼働開始**: 運用中の不満・要望を取りこぼさない仕組みを敷いた
- **案件マーケット公開・フリーランスマッチング** は中期テーマとして温存。社内運用の仕上げ（勤務表・請求書管理）と並走しつつ、PoC で市場検証する選択肢が残る
- 朝の日次レポートの target_date が JST ズレで 1 日早まっていた件を修正（`Carbon::today('Asia/Tokyo')` で固定）

---

## 10. 2026-05-11 配信機能 UX 改善・運用フィードバック反映

> 営業担当（松村悠大）からの運用要望と再送信時の事故防止策をまとめて実装

### 10.1 配信再送信ドメイン警告モーダル（完了 2026-05-11）

| 項目 | 内容 |
|---|---|
| 課題 | 案件メール起点のキャンペーンで、再送信時に「同一ドメインの受信者」へ誤って送信するリスク（発注元への逆送付） |
| 対応 | キャンペーン詳細の再送信時、受信者ドメイン = 入手元ドメイン だったら警告モーダルを表示してダブルチェック |
| ドメイン導出 | 1) 紐づき案件メールの `from_address` / 2) 手動入力の `source_email` / 3) なし（警告対象外） |
| 影響範囲 | フロント `/deliveries/campaigns/[id]` 再送信フロー / API `show()` レスポンスに `source_domain` を追加 |

### 10.2 手動入手元アドレス `source_email`（完了 2026-05-11）

紐づき案件・技術者がない「手動一斉配信」でも 10.1 の警告を効かせるため、新規配信作成時に **入手元アドレス（任意）** 欄を追加。

| 項目 | 内容 |
|---|---|
| DB | `delivery_campaigns.source_email VARCHAR(255) NULL` |
| 保存条件 | 紐づき案件 / 紐づき技術者 のいずれも未選択時のみ保存（紐づき選択時はサーバ側で NULL に上書き） |
| バリデーション | `nullable\|email\|max:255` |
| UI | 紐づき選択時は完全非表示。手動配信時のみ表示 |
| 既存データ | バックフィルなし（紐づきなし & `source_email` NULL のキャンペーンは警告対象外で従前どおり） |

### 10.3 キャンペーン詳細・一覧 UI 改善（完了 2026-05-11）

ノートPC（〜13inch クラス）での視認性低下フィードバックに対応。

| 改善 | 内容 |
|---|---|
| 検索／サマリーパネル折りたたみ | `localStorage` でユーザーごとに開閉状態を記憶 |
| 行アコーディオン | 一覧クリックで詳細展開時、**送信 / 受信を個別にアコーディオン** 化（一括ドンと出さない） |
| 横スクロール撲滅 | 件名 / 紐づき案件 列の幅を絞る + 数値系列（分類・送信数・成功・失敗・返信率・詳細）に `whitespace-nowrap` + `px-2` |
| 列名整理 | 「最終再送信日時」→「再送信日時」 |
| レスポンシブ | md（768px）境界で **テーブル ⇄ カード** の二段構え（ノートPC＝テーブル / タブレット未満＝カード） |
| 将来対応 | スマホ完全対応は別 Issue #29 として切り出し（次期開発） |

### 10.4 スマホ対応の切り出し（Issue #29 起票）

- 本日の改善で「md 以上でストレスなく動く」までは到達。完全なスマホ運用（外出先からの返信確認・再送信判定）は次期開発のスコープに分離
- Phase 2-3 の中期テーマに登録（Section 9.5 マトリクス参照）
- 着手前に決めること: モバイルナビゲーション（ハンバーガー/ボトムタブ）/ 主要画面の優先度（配信履歴・タスク・通知）/ 認証フロー（PWA 化要否）
- **積み残し**: `billing-summaries`（請求書作成）画面の横スクロール／「操作」列見切れ問題。2026-05-11 時点では `min-w-[1220px]` + `overflow-auto` で横スクロール許容の暫定対応のみ。狭幅対応（カードレイアウト化など）はスマホ対応フェーズ（#29）で本対応する

> **【追記 2026-05-16】上記の積み残しは §14・§15 で本対応完了**:
> - モバイルナビゲーションは **Sidebar drawer + ハンバーガー** に決着（ボトムタブは見送り、PWA 化も保留）
> - 主要画面の優先度は CRM 一覧 → メール → 帳票詳細 → ダッシュボード の順で着手し、10 画面で md 未満カード化を完了
> - `billing-summaries` を含む帳票系画面はカード化・モーダル mobile 対応済み
> - 残作業は名刺 OCR モーダル / フォーム 44px タッチターゲット / 案件マーケット公開ページのみ（#29 は open のまま）

### 10.5 本日(2026-05-11)時点の総括

- **再送信事故の予防策** を 10.1 + 10.2 のペアで実装完了。手動配信でも入手元ドメインをトレースできるようになった
- **ノートPC運用ストレス** は今回の UX 改善で解消。営業現場からの一次フィードバックを当日中に反映
- 残る大物は **Phase 1: 勤務表・請求書管理 (#8)** と **Phase 2-3: スマホ対応 (#29)・案件マーケット公開**（バックアップ #1 は週次手動運用に移行・クローズ）
- βユーザー獲得（#3）はやはり律速。技術側の整備は十分なので、次は営業/PM が前に出るフェーズ

---

## 11. 2026-05-13 doc_type 拡張ロールアウト・Refinitiv 取引対応・本番パフォーマンス改善

> 営業（松村悠大）からの英文見積/注文書/請求書要望と、Refinitiv（リフィニティブ・ジャパン）案件の SAP Business Network 経由注文書 PDF 取込を起点に、見積書／注文書／請求書まわりを一括で本番反映。あわせて本番 VPS のパフォーマンス改善・ログ整備・Supabase 権限整備まで実施。

### 11.1 doc_type 拡張ロールアウト (A〜G 完了)

`invoices` テーブルに `doc_type` (`invoice` / `estimate` / `purchase_order`) 列を持たせ、見積書／注文書／請求書を 1 テーブルで統合管理する設計が本日完了。残課題は H（`billing-summaries` のスマホ対応 = #29）のみ。

| ブロック | 内容 | 状態 |
|---|---|---|
| A | 共通基盤（`doc_type` 列・スナップショット・PDF テンプレ統合） | ✅ |
| B | 見積書フロー（SES台帳起点・通常 / 例外モード両対応） | ✅ |
| C | 注文書フロー（仕入先絞り込み・所属会社宛先） | ✅ |
| D | 承認通知 UX（doc_type 別バッジ＋承認後トースト＋削除制限） | ✅ |
| E | 送信履歴 doc_type 別画面（`/estimate-send-histories` 新設、請求書既存と並列） | ✅ |
| F | メールテンプレ doc_type 別タブ（`/settings/invoice-issuer` に 3 タブ） | ✅ |
| G | 英文モード（`language='en'`・英文社名・英文住所・英文銀行情報） | ✅ |
| H | `billing-summaries` のスマホ対応 (#29) | 🔴 別管理（次期） |

### 11.2 Refinitiv (LSEG) 注文書 PDF → 請求書ドラフト発行フロー（新規）

> 詳細は `070_refinitiv_invoice_flow.md` に分離。本節は経緯と意思決定のみ。

- **背景**: Refinitiv は注文書を SAP Business Network 経由の PDF で送付してくる。「その他の情報」欄に申請者・申請番号・Plant.ID・分類コード等の必須情報があり、これを請求書側にもれなく転記する手間が事故源だった
- **対応**: PDF を Claude Sonnet 4 で構造化（`RefinitivPoParserService`）し、抽出結果をフロントで確認・編集してから `/api/v1/invoices/refinitiv/issue` で **英文モードの請求書ドラフト** を発行する 2 ステップ運用に
- **DB**: `invoices.vendor_metadata` (JSONB) を追加（NULL でないレコードは Refinitiv 専用 PDF レイアウトで出力）
- **画面**: `/billing-summaries` のオレンジボタン「📋 Refinitiv注文書から請求書発行」モーダル

### 11.3 監査ログ audit チャネル分離・JST ログローテーション

| 改善 | 内容 |
|---|---|
| `audit` チャネル | `LogUserActivity` ミドルウェアの監査出力を `storage/logs/audit.log` に分離。`LOG_LEVEL` 設定とは独立して常に info で記録 |
| JST 基準ローテーション | アプリログのファイル名 / ローテーションを JST 基準に変更（`JstDailyFactory` / `JstRotatingFileHandler`）。`storage/logs/sales_sup-YYYY-MM-DD.log` の日付ズレを解消 |

### 11.4 Supabase Data API GRANT 整備（2026-10-30 強制適用に先行対応）

Supabase が 2026-10-30 に `public` スキーマのデフォルト権限を廃止する変更を予告。Realtime 購読対象テーブル（`tasks` / `deals` / `activities` / `business_cards` / `emails` 等）に対し `authenticated` / `service_role` への明示 GRANT を migration 化（`2026_05_14_003130_grant_data_api_access_for_realtime_tables.php`）。

> 以後の新規テーブル migration では **RLS と並んで GRANT も明示** を CLAUDE.md にルール化済。

### 11.5 本番パフォーマンス改善

| 改善 | 内容 |
|---|---|
| env→config 全廃 | `app/` 配下の `env()` 直呼出しを廃止し `config/*.php` 経由に統一 → `php artisan config:cache` が本番で安全に使えるようになった |
| SupabaseAuth キャッシュ | `SupabaseAuth` ミドルウェアの User lookup を 60 秒キャッシュ |
| Puppeteer 固定 | Dockerfile で puppeteer をバージョン pin + `chrome/current` シンボリックリンクで `.env` パス安定化 |
| 添付ファイル名復号 | RFC 5987 / RFC 2231 形式の添付ファイル名を正しくデコード（一部メールクライアント対応） |

### 11.6 承認通知バッジ既読フラグ（D ブロック）

サイドバーの「承認待ち／差戻し」バッジを **誰がいつ消したか** を追跡する `invoice_notification_reads` テーブルを新設。承認サマリーリンクをクリックすると `POST /api/v1/notifications/mark-read` で既読化を発行する。

### 11.7 本日(2026-05-13)時点の総括

- **Phase 1 #8（勤務表・請求書管理）が事実上クローズ**。残るは H ブロックの `billing-summaries` スマホ対応のみで、これは #29 に統合
- **Refinitiv 取引対応** が完了し、注文書受信から請求書発行まで PDF 1 枚→ドラフト発行までの 2 クリック運用に短縮
- **本番パフォーマンス改善** で体感速度が向上。同時にログ整備・Supabase 権限整備など運用基盤も同時に底上げ
- 残課題は **βユーザー獲得 (#3)** と **スマホ対応 (#29)**。技術側は十分にリリース水準

---

## 12. 2026-05-14 請求書・注文書 電子印（丸印）廃止

### 12.1 経緯と方針

社長より「請求書・注文書の電子印（丸印）は使用しない」との通達を受領。承認ワークフロー自体は記録のため継続するが、承認時の電子印自動押印を廃止し、紙ベース運用へ統一する。

| 帳票 | 電子印 | 承認ワークフロー |
|---|---|---|
| 請求書 | ✗ 廃止（丸印自動押印を停止） | ◯ 継続（承認 / 却下 / 通知バッジ） |
| 注文書 | ✗ 廃止（丸印自動押印を停止） | ◯ 継続 |
| 見積書 | ◯ 継続（作成時に角印を押印） | — （承認フロー対象外） |
| 注文請書 | ✗ なし（変更なし。取引先押印欄を空ける） | — |

### 12.2 紙運用フロー（承認後の業務）

1. 請求書／注文書を作成 → 内容確認
2. 管理者が **「承認」** ボタンをクリック → 承認フラグが立ち PDF が再生成される（**電子印は付かない**）
3. PDF をダウンロードして印刷
4. **物理的に承認印を押印**（紙ベース）
5. 押印済の紙をスキャンして PDF 化
6. 請求書詳細画面の「メール送信」または「郵送記録」モーダルから添付して送付

### 12.3 実装対応（2026-05-14）

| 範囲 | 変更内容 |
|---|---|
| バックエンド | `InvoicePdfService::renderAndUpload()` の `$skipSeal` を `$invoice->doc_type !== 'estimate'` に簡略化。請求書・注文書では `approved` の有無に関わらず印影を null 化 |
| フロント (設定) | `/settings/invoice-issuer` の **丸印アップロード UI を非表示**（角印のみ残す）。説明文も紙運用に書き換え |
| フロント (承認ダイアログ) | 「電子印付き PDF を再生成」→「PDF を再生成」に文言修正（請求書一覧・注文書一覧・詳細画面の 3 箇所） |
| DB | `tenants.invoice_issuer_round_seal_path` / `invoices.issuer_round_seal_snapshot` のカラム自体は残置（将来の方針変更に備える）。データは Supabase Storage から削除済 |
| ドキュメント | 500 §5.4・§10.2 を紙運用に書き換え、本セクション(§12)を新設 |

### 12.4 検証ポイント

- [ ] 請求書を承認 → PDF 再生成 → 印影が出ないことを確認（本番）
- [ ] 注文書を承認 → PDF 再生成 → 印影が出ないことを確認（本番）
- [ ] 見積書を新規作成 → PDF 生成 → 角印が押印されることを確認（本番・既存挙動の維持確認）
- [ ] `/settings/invoice-issuer` を開き、丸印アップロードセクションが非表示になっていることを確認
- [ ] 承認ボタン押下時のダイアログ文言が「電子印付き」を含まないことを確認

### 12.5 補足

- 「電子印を完全に廃止」ではなく「**請求書・注文書では使わない**」というスコープ。見積書の角印（`invoice_issuer_square_seal_path`）は **担当者ベース運用** のためそのまま継続
- 既存テナント（アイゼン・ソリューション）の Storage 上の丸印画像は事前に削除済（B 案）
- DB スキーマは変更しないため、ロールバック時はコードを戻すだけで丸印自動押印が復活する

## 13. UI/UX 微調整 + PDF 金額ズレ修正（2026-05-14）

### 13.1 背景

2026-05-14 朝に現場（管理部）から複数の要望と 1 件のバグ報告が同時に上がった。いずれも本番運用で日常的に当たる箇所のため当日内に対応し、リリース済。

### 13.2 要望・修正内容

| # | 種別 | 範囲 | 内容 |
|---|---|---|---|
| ① | 要望 | 見積書発行モーダル（SES台帳→見積書発行） | 英文モード時は「契約終了済の SES契約」もリストに含める（過去案件の英文見積を発行できるよう、`contract_period_end_from` フィルタを英文時のみ外す） |
| ② | 要望 | 請求書/見積書/注文書 PDF | 超過控除セクションのラベルから「(中間割)」「(10円未満切り捨て)」を削除。SES台帳に超過控除データ（`client_deduction_*` / `client_overtime_*`）が何もなければ **セクション自体を出力しない**（`$hasOvertimeDeduction` 条件で囲む） |
| ③ | 要望 | 勤務表編集モーダル（`/ses-contracts/{id}/timesheets`） | 「□請求書あり」チェックボックスと条件表示の「請求書受領日」フィールドを撤去（運用で使われなかったため） |
| ④ | 要望 | 請求書一覧 / 見積書一覧 / 注文書一覧 | 操作列に **【複写】ボタン** を追加。当月扱いで番号を採番（`InvoiceNumberService` 経由）→ `status='draft'`・`approved=false`・PDF/承認関連フィールド全リセット → 明細を全コピーして下書きを開く。`due_date=null`、履歴リンクは持ち越さない |
| ⑤ | 要望 | 下書き編集画面（請求書/見積書/注文書 共通） | 摘要明細行の表記から「【基本月額】」「基本月額：」を削除。`description` は **金額のみ**（例：`350,000円`）を保持し、PDF 側で「・基本月額：」ラベルを付与する設計に統一 |
| ⑥ | バグ修正 | 請求書 PDF | 一度 PDF を発行後、明細を編集して保存し、PDF を再生成すると **金額欄が下の行にズレる**事象。`pdf.blade.php` の basicLine 検出が `description` の文字列「基本月額」キーワードに依存していたため、ユーザー編集でキーワードが消えると `extraLines` 側へ流れていた |

### 13.3 実装対応

| 範囲 | ファイル | 変更内容 |
|---|---|---|
| フロント (見積モーダル) | `sales_support_next/src/app/estimates/page.tsx` | 英文モード時は `contract_period_end_from` を送信しないよう三項分岐に変更 |
| バックエンド (PDF) | `sales_support/resources/views/invoices/pdf.blade.php` | basicLine 検出を `sort_order === 0 && !is_expense` のみに簡素化。description キーワード判定を廃止。超過控除セクション全体を `$hasOvertimeDeduction` で条件付け／ラベルから補足文言を削除 |
| フロント (勤務表) | `sales_support_next/src/app/ses-contracts/[id]/timesheets/page.tsx` | 「請求書あり」Field とその条件表示「請求書受領日」Field を削除 |
| フロント (一覧3画面) | `sales_support_next/src/app/{estimates,invoices,purchase-orders}/page.tsx` | `useRouter` + `busyId` state + `handleDuplicate()` を追加。操作列に【複写】ボタン（操作列幅 70→110px） |
| バックエンド (複写API) | `app/Http/Controllers/Api/InvoiceController.php` + `routes/api.php` | `POST /api/v1/invoices/{invoice}/duplicate` を新設。doc_type ごとに `estimate` / `purchase_order` / `invoice` を判定して新番号採番、`replicate()` で 13 フィールドをリセットして明細を `InvoiceLine::create()` で全コピー、`recalcAmounts()` 実行 |
| バックエンド (基本月額表記) | `app/Services/InvoiceCreationService.php` / `app/Http/Controllers/Api/InvoiceController.php` | 明細 description の `'%s円 【基本月額】'` → `'%s円'` に統一（新規発行・控除/超過/交通費の追加処理含む） |
| フロント (下書きガイド) | `sales_support_next/src/app/invoices/[id]/page.tsx` | 基本月額行のガイド文を金額のみ表記前提に更新 |

### 13.4 検証ポイント

- [x] 英文モードの見積モーダルで、契約終了済 SES契約が候補に出ること
- [x] PDF の超過控除セクションがラベル簡素化されていること／超過控除データなしの SES案件で **セクション自体が出ない** こと
- [x] 勤務表モーダルから「請求書あり」チェックが消えていること
- [x] 請求書/見積書/注文書 一覧の【複写】ボタンで、当月の新番号で下書きが開くこと（明細・取引先がコピーされ、`due_date=null` で承認・PDF 関連が全クリア）
- [x] 下書き編集画面の摘要明細行が金額のみ表記であること
- [x] 既存の PDF を再生成しても金額欄が basicLine 行に表示され、extraLines にズレ落ちないこと（バグ⑥ 再現テスト）

### 13.5 補足

- ⑥ のバグは「description のフリーテキスト判定」を「sort_order 番号判定」に置き換えたことで根治。ユーザーが摘要欄を自由編集しても basicLine が見失われない
- ④ の複写は **当月採番＋下書き状態** が前提。月跨ぎで複写された結果として年月をまたぐ番号体系が壊れることはない
- ⑤ の表記簡素化により、PDF と編集画面で「基本月額：」ラベルの責務が PDF 側に統一された

---

## 14. 2026-05-15 Issue #29 スマホ対応 第1弾 + 月別売上集計設計

### 14.1 Issue #29 第1弾: Sidebar 折りたたみ + 一覧/モーダル系の mobile 化

ノートPC 24inch / 27inch 環境差での「画面が収まらない」問題と外出先スマホ運用を見据え、UI 層を一気に整備。

| 範囲 | 対応内容 |
|---|---|
| Sidebar | デスクトップ折りたたみ (`w-64` ↔ `w-16`) + localStorage 永続化、md 未満は **off-canvas drawer + ハンバーガーボタン** |
| 配信管理 | `max-w-7xl` 撤廃、タブ固定 + 各タブ scroll + sticky ページネーション、横スクロール対応 |
| 帳票一覧 (請求書/見積書/注文書/送信履歴/billing-summaries) | カード化 + `max-w-7xl` 撤廃 + 作成モーダル grid 1列化 + Sticky フッター |
| 送信履歴 | 「件名」列が郵送時 `(郵送)` 固定 → `invoice.subject_name` 表示に変更し、検索対象にも追加 |
| 承認操作 UX | 一覧で承認/却下/申請完了時にトースト通知 + `approval_status=pending` フィルタを自動リセットして結果可視化 |
| SES台帳 Excel インポート | Excel 未掲載の SES 案件を自動 `期限切れ` 更新。一覧は既定で `期限切れ` 除外 + 「期限切れも表示」チェックボックス |

**docs/120 `モバイルレスポンシブ デザイン指針`** を新設し、共通パターン（テーブル横スクロール / アコーディオン / モーダル / Sticky フッター / ページネーション）を集約。以後の改修はチェックリスト適用方式。

### 14.2 mobile drawer 実装の試行錯誤（撤退ログ）

初版は `<aside fixed -translate-x-full md:sticky>` で全体構造を組み替える方針で実装したが、Next.js 16 + Turbopack + WSL2 環境で hydration が完全停止する事象が発生。`git stash` で完全破棄し、後日 **既存 sticky 構造を維持した最小差分**（ハンバーガーボタン + 既存 aside の `fixed` 切替のみ）で再実装した。

**学び**: Sidebar 共通コンポーネントの構造大変更はリスクが高い。最小差分・段階的に進める方針を `feedback_*` メモリにも反映。

### 14.3 月別売上集計 設計メモ (`docs/460_monthly_sales_aggregation.md`)

半期決算会議で参照する月別売上が、現状 `deals.updated_at` 基準で不正確という問題提起。実装は **業務側ヒアリング待ちで未着手**だが、論点 5 つ（集計粒度・月の判定基準・月またぎ按分・テーブル設計・ダッシュボード切替時期）を整理して `docs/460` に保留。

Phase 3 で 経理「入金表」（売上先入金サイト日／仕入先支払日 順）の CSV エクスポート → 弥生会計取込が別スコープで控える。

### 14.4 本日(2026-05-15)時点の総括

- Issue #29 の **第1弾（Sidebar + 一覧/モーダル）が本番反映完了**。docs/120 で共通パターンを固定化したため、以後の画面追加コストは低い
- mobile drawer の初版失敗で 1 セッション分の時間を浪費。再現性のある教訓として `feedback_*` 化
- 月別売上集計は「設計検討フェーズ」として温存。業務側に Excel 集計ロジックの確認が必要

---

## 15. 2026-05-16 Claude Sonnet 4.6 移行 + Issue #29 第2弾完了 + 細目修正

### 15.1 Claude モデル ID 移行 (`claude-sonnet-4-20250514` → `claude-sonnet-4-6`)

Anthropic 公式で 2026-06-15 9AM PT に `claude-sonnet-4-20250514` 系が retire される予告に対応。

| 範囲 | 内容 |
|---|---|
| 設定 | `config/services.php` に `anthropic.model = env('CLAUDE_MODEL', 'claude-sonnet-4-6')` を新設 |
| ハードコード除去 | `ClaudeService` / `EmailExtractionService` / `MatchingService` / `RefinitivPoParserService` の 5 箇所を `config('services.anthropic.model')` 経由に置換 |
| ドキュメント | `CLAUDE.md` / `docs/410` / `docs/070` のモデル表記更新 |
| 本番デプロイ | `.env` に `CLAUDE_MODEL=claude-sonnet-4-6` 明示追加 + `config:clear` |

検証: ローカル tinker で `app(ClaudeService::class)->ask('Reply with just the word: ready')` を実行し、`ready` が返ることを確認。

### 15.2 Issue #29 第2弾: 帳票詳細 + SES編集 + CRM/メール/名刺/ダッシュボード

10 画面を順次 mobile 対応:

| # | 画面 | 対応 |
|---|---|---|
| 1 | ダッシュボード | KPI/グリッド/フォントの md 切替（既存 `grid-cols-N md:grid-cols-N` 微調整） |
| 2 | タスク一覧 | カード化（期限超過/今日バッジ付き） |
| 3 | 顧客 / 担当者 | カード化（区分バッジ + 業種 + 電話） |
| 4 | 商談 / 活動 | カード化 |
| 5 | 名刺管理 | 画像サムネ付きカード化 |
| 6 | メール一覧 / 案件メール / 技術者メール | split-pane を mobile では選択時に list↔detail 切替・戻るボタン追加。内部 grid を md 切替 |
| 7 | 帳票詳細 (invoices/[id]) | 3 帳票共通の grid/padding/モーダル mobile 対応（estimates/purchase-orders は薄いラッパ） |
| 8 | SES契約編集 | header/footer flex-wrap + タブバー横スクロール |

**特殊対応 (案件/技術者メール 要確認モード)**:
- sticky top-0 が SidebarWrapper の overflow-auto コンテナと組み合わさり mobile で破綻 → `flex flex-col h-full` + `flex-shrink-0` ヘッダー + `flex-1 overflow-y-auto` リストに変更
- ReviewRow を mobile で 2 段組（上=スコアバッジ+アクション / 下=タイトル幅広）にリファクタして件名表示領域を確保

### 15.3 ローカル Kagoya スケジュール無効化

ローカル `.env` に `KAGOYA_POP3_*` が無いため、`sync-kagoya-pop3` schedule が 15 分ごとに DNS 失敗エラーをログに残していた。`routes/console.php` の該当 `Schedule::call` に `->environments(['production'])` を付与し、ローカル/dev では起動しないように変更。本番運用は無影響。

### 15.4 細目バグ修正・微調整

| 範囲 | 内容 |
|---|---|
| `approve` / `reject` API | 電子印廃止 (§12) に伴い PDF 再生成を撤廃。フラグ更新のみに簡素化。承認確認ダイアログから「電子印付き PDF を再生成」文言を除去 |
| 配信管理 キャンペーン表 | テーブル幅 `1100px` 固定 → `100%` (`minWidth: 1100px` 維持) で PC の右側隙間解消。送信日時カラム `w-[130px]`・再送信日時 `w-[110px]` に拡張してソートバッジ「▼ 降順」が次列に被るのを解消 |
| 一覧 6 本のカードスクロール | `md:hidden` カードコンテナに `flex-1 min-h-0 overflow-y-auto` を追加。親 `h-full` のため最後のカードが見切れる問題を解消 |
| SES台帳テーブル | 4 列グループ（basic/amount/settlement/work）でヘッダー table とボディ table が分離しており横スクロールでズレていた → 単一テーブル + `<thead sticky top-0>` に統合 |
| `.github/agents/sales-support.agent.md` | IDE エージェント定義ファイルを `.gitignore` に追加（個人設定はリポに含めない） |

### 15.5 Issue #29 進捗まとめ

- **完了**: Sidebar drawer / 配信履歴 / 請求書系一覧と詳細 / CRM (顧客/担当者/商談/活動/タスク) / メール 3 画面 / 名刺一覧 / ダッシュボード / 帳票詳細 / SES編集 / スマホ実機確認
- **残**: 名刺 OCR プレビューモーダル / フォームタッチターゲット 44px / 公開ページ (案件マーケット — #26)
- Issue #29 は **open のまま** だが、外出先で日常的に触れる主要画面はカバー完了

### 15.6 本日(2026-05-16)時点の総括

- **Claude モデル移行が本番完了**。6/15 retire の deadline 前に余裕を持って対応。今後同様の API モデル変更は config 経由なので 1 行差し替えで済む
- **Issue #29 のロングテール残作業を一気に消化**。1 セッション内で 10 画面 + 細目修正 + 実機確認 + Issue コメント更新まで完了
- 残るリリース律速は **βユーザー獲得 (#3)** のままで、技術側は十分に水準達成
- 月別売上集計 / 案件マーケット公開 / フリーランスマッチング / 課金システムは依然 Phase 3 で控える

---

## 16. 2026-05-18 セッション (Sentry 残課題 + 配信管理 UX 強化 + 取込安定化)

朝に Sentry 週次レポートを基点に DB パフォーマンス改善を実施し、夕方〜深夜に matching/engineer-mails の提案フロー残課題と配信管理の重複警告を仕上げた。

### 16.1 DB パフォーマンス改善 (Sentry 残課題)

| 項目 | 結果 | commit |
|---|---|---|
| `/api/v1/emails/unread-count` partial index | 149ms → 6ms (約25倍) | `bf9dfbd` |
| `score-engineer-mails` slow query (1日窓 + VACUUM) | 4441ms → 67ms (約66倍) | (5/12 既存 + 本日 VACUUM ANALYZE) |
| autovacuum 閾値引下げ (emails / engineer_mail_sources / project_mail_sources) | 0.2/0.1 → 0.05 で発火頻度4倍 | `5819c65` |

### 16.2 配信管理 UX 強化 (元請けドメイン重複警告)

**目的**: SES 案件メールを 元請け企業に送り返すと「抜き額（マージン）」が露呈する事故を防ぐ。`/deliveries/campaigns/[id]/page.tsx` の再送信フローには 5/12 時点で実装済だったが、新規配信・matching/engineer-mails のまとめて提案では未実装だった。

| 範囲 | 内容 | commit |
|---|---|---|
| matching/[id] / engineer-mails/[id] まとめて提案 | 「to を編集 && 元メールと同一ドメイン残存」で赤警告モーダル | `736c2b2` |
| /deliveries 新規配信 | source ドメインが active な配信先と一致したら警告 | backend `89b47b2` / frontend `d8c21a8` |
| 重複警告から除外送信 (exclude_address_ids) | チェックボックス「今回これら N 件を除外して配信」既定 ON。is_active を**変更せず** WHERE 句で除外 | backend `82ad98d` / frontend `716c4a1` |
| 配信先編集の反映遅延修正 | `fetchAddresses()` を await | `c408ddd` |

`src/lib/mailDomain.ts` を新設し `extractDomain` / `isSameDomain` を 3 画面で共通化。

### 16.3 提案フロー 連携バグ修正 (送信系 4 メソッド ↔ 提案スレッド)

`DeliveryCampaignController::proposalThreads` / `ProjectMailController::thread` / `EngineerMailController::thread` の `whereIn('send_type', [...])` から **3 種が抜けていた**:
- `matching_proposal` (sendProposalFromEms)
- `engineer_proposal_bulk` (sendBulkToBp 新規)
- `bulk` (sendBulk 既存)

→ `7b48634` で 4 箇所同期。さらに 5/18 夜のテストで「一斉配信 (`'delivery'`) が提案スレッドに混在する」既存挙動を発見、`a9fbb06` で `'delivery'` も同 4 箇所から除外し「1対多 配信は一斉配信履歴のみ」に整理。`CLAUDE.md` の送信タイプ一覧と同期 4 箇所のメモも更新。

### 16.4 Kagoya 取込 安定化 + ローカル復活

| 課題 | 内容 | commit |
|---|---|---|
| バウンス UID の無限再処理ループ | `storeRawMessage` がバウンス判定で何も保存せず return しており、毎回 IMAP から同じ約 303 件が新着扱いで返り CPU と Kagoya API を浪費。stub Email 行 (`category='bounce'`, `is_read=true`, 本文/添付スキップ) を保存して dedup 用 anchor とする設計に修正 | `b938915` |
| Kagoya POP3 同期の本番限定撤去 | `environments(['production'])` を撤去し `config('services.kagoya_pop3.host')` が null の環境で silent skip するガードに置換。.env を持つ環境 (本番・職場・自宅) で並列取込が可能になる (IMAP EXAMINE が read-only なため衝突なし) | `20d3d90` |
| 配信先メール検索の拡張 | `/project-mails`・`/engineer-mails` 検索ボックスに email (例: sales@tektek.co.jp) や差出人名を入れてもヒットしなかったのを修正。`email.from_address` / `from_name` / `subject` を OR 条件に追加 | `f277309` |

### 16.5 メモリ・ドキュメント整備

- `feedback_bounce_stub_dedup_anchor.md` (新規) — silent-drop でも dedup anchor 行は必ず insert する設計指針
- `reference_kagoya_imap_local_parallel.md` (新規) — 本番並列取込の安全性と復活手順
- `project_sentry_followup.md` — 対応済の整理 + 観察待ち項目
- `project_handoff_2026_05_18_evening.md` — ① ② 完了状態に書き換え
- `CLAUDE.md` — send_type 4種→6種、whereIn 同期 4 箇所、'delivery' を提案スレッドに含めない方針を追記 (`704905d` + `a9fbb06`)

### 16.6 本日(2026-05-18)時点の総括

- **Sentry の Perf 残課題は 1 セッションで全消化**。score-engineer-mails / unread-count が既存 index でも Heap Fetches 蓄積で遅くなる事象を理解し、partial index + autovacuum 閾値で恒常的に低位維持できる構成にした
- **配信管理の事故防止 UX を 3 画面で揃えた**。再送信のみだったドメイン警告を、まとめて提案 (案件側・技術者側) と新規一斉配信にも展開。除外送信は `is_active` を触らない設計で「送信失敗→復元漏れ」リスクを排除
- **Kagoya 取込の長年の無駄処理を解消**。POP3 経由 303 件/15分の再処理ループが消え、ローカル取込も復活して開発時の動作確認が容易に
- 残るリリース律速は依然 **βユーザー獲得 (#3)**。技術面で目立つ残課題は無し

---

## 17. 要件マッチング機能 (docs/480) 安定化 + 提案フロー UI 大改修 (2026-05-21)

朝の職場で完了済の **PhpSpreadsheet OOM 防御** (`7261fe3`) に続き、自宅夜に要件マッチング (docs/480) の Phase 4 後の安定化と提案フロー UI を大幅改修。フロントは `c876403` → `4583068` (TS build fix) → `61faf7a` (toggle fix) の 3 連コミットで本番反映済。

### 17.1 Claude API 安定化 (backend `47225ed`)

| 課題 | 内容 |
|---|---|
| cURL error 28 (30s timeout) | `ClaudeService::extractRequirements` / `judgeRequirementMatches` の timeout を 30s → **120s** に拡張 (PMS 27196 等の長文メールで Anthropic 応答に 30+ 秒かかる事象に対応) |
| max_tokens truncate | Stage1 system prompt に「★最重要ルール: 重要な要件 **上位 5 件程度に絞る (最大 8 件)**」追加。複数案件まとめメール (案件1/案件2/...) で要件 100+ 件が抽出され 6000 トークンでも切れる事象に根本対策 |
| silent な truncate | `stop_reason='max_tokens'` 時に明示 Exception 化。silent な部分結果 (JSON 不整合) を防ぐ |
| max_tokens 拡張 | Stage1: 2500→3000 / Stage2: 4000 (要件絞り込みプロンプトで十分) |

### 17.2 Next.js dev rewrites の proxyTimeout 拡張

`next.config.ts` に `experimental.proxyTimeout: 180_000` を追加。デフォルト 30s で 499 切断されていた問題を解消。

### 17.3 対照表 (docs/480) UI 大幅改修 (frontend `c876403`)

#### 必須要件×案件の自動除外フィルタ (新設)
- `/engineer-mails/[id]` と `/matching/[id]` のヘッダーセレクト列に **「☐ 📊 対照表」** チェックボックスを追加
- **デフォルト OFF** (件数が多い時の Claude API 負荷を考慮)
- ON 時は鮮度マッチング結果の全 PMS で対照表を並列取得 → 必須×案件は最初から非表示
- 進捗バー付きローディング (`FreshLoadingIndicator`): 48px スピナー + フェーズ表示 + 件数表示 (3/10件 30%) + 補足説明

#### 対照表 toggle 再現性の修正
- `baseBodyRef` を廃止し、**本文に対するセパレータ (─×48) 検出による挿入/除去方式** へ
- `removeMatchTableFromBody()` を `lib/requirementCategoryLabel.ts` に新設
- `includeMatchRef` で **fetch 中の OFF race condition** を guard (Claude API 応答待ち 5〜30秒の間に OFF された場合に古いクロージャが setBody 呼び続けるバグ防止)
- 空行潰れ対策 (`61faf7a`): 置換結果を `\n` → `\n\n` に変更
- ON/OFF を繰り返しても確実に動作。宛先名変更や本文編集が toggle で失われない

#### insertMatchTableIntoBody のマーカー優先度修正
- 「お気軽にご返信」を marker から **除外**
  - 根本原因: 「面談やスキルシートのご要望がございましたら、お気軽にご返信ください。」のような **本文中の一文** に現れやすく、その直前に対照表を入れると文を分断していた
- 残った marker: 「お忙しいところ」「ご検討」「何卒よろしくお願い」「_/_/_/」「━━━」「─────」 (締めの定型句と署名区切りのみ)

### 17.4 スキルシート / 技術者添付ファイル DL & 添付対応

| 場所 | 機能 |
|---|---|
| `/engineer-mails/[id]` 緑ヘッダー直下 | 添付ファイルチップを表示 (`📎 履歴書.xlsx (245KB)`)。クリックで Blob ダウンロード |
| 個別提案モーダル (両画面 ProposalModal) | **「📎 技術者スキルシート (N件) を送信添付に追加」**ボタン。一度押すと `✓ 追加済` 表示 (二重追加防止)。各添付の確認用 DL チップを別途並置 |
| ✕ 削除同期 | 送信添付欄から ✕ で削除すると `addedEngineerAttIds` セットからも除外 → 「追加済」が「📎 …を添付」に戻り再追加可能 |
| `BulkSendModal` (まとめて提案) | 技術者単体スキルシート対応外 (複数技術者まとめのため)。共通 UI 構造を維持しつつ `engineerAttachments=[]` で固定 |

`/v1/engineer-mails/{id}` API が `email.attachments` を既に eager load 済のためバックエンド変更不要。

### 17.5 宛先名の自動抽出 (extractSenderNameFromBody)

`/matching/[id]` の鮮度マッチング個別提案で、宛先名を **技術者本人** (`FreshEms.name` = `Y.H` 等) ではなく、**メール本文の挨拶文** から BP 担当者名を抽出するよう変更。

```
「いつもお世話になっております。株式会社キャリアビートの雨宮 昂平と申します。」
                  ↓
                "雨宮 昂平"
```

抽出パターン優先度:
1. `(株式会社|有限会社|（株）|㈱)XX **の YY と申します/でございます**`
2. `YY と申します`
3. `YY でございます`
4. 「営業」「弊社」「担当」「担当者」など一般語は除外

抽出不可なら挨拶は「営業ご担当者様」。

### 17.6 ビルドエラー → 修正

`c876403` で Vercel ビルドが TS エラーで失敗:
```
Type error: Parameter 'prev' implicitly has an 'any' type.
setAddedEngineerAttIds(prev => { ... })
```

`BulkSendModal` に追加した no-op の `setAddedEngineerAttIds = (_: unknown) => {}` で型推論が `any` になっていた。`4583068` で `(updater: (prev: Set<number>) => Set<number>) => void` を明示して解決。

### 17.7 本日 (2026-05-21) 時点の総括

- **要件マッチング機能を「フェーズ4 完了 → 実運用可能水準」へ引き上げ**。timeout / max_tokens / プロンプト最適化で長文・複数案件まとめメールでも安定動作。対照表 toggle や挿入位置の微妙な UI バグも掃除
- **提案フロー全体のスキルシート扱いを統一**。技術者ヘッダー DL + モーダルからのワンクリック添付で営業の「ダウンロード→手動添付」手数を削減
- **宛先名の自動抽出**で「Y.H 様」のような不適切な宛名を解消。BP担当者向けの自然な挨拶文に
- 残課題: **スコア・点数の出し方** は別途確認 (2026-05-21 ユーザー言及あり)。鮮度マッチング score 計算ロジックの見直しが Phase 5 候補

