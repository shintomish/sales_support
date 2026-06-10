# SES Inbound 受信インフラ移設 (B-light: サブドメイン転送)

Kagoya の「Postfix 受理後 → メールボックス最終配送」区間で発生する ~2.5h の配送遅延
([project_kagoya_gmail_delivery]) を、受信経路を SES Inbound に移して回避する。

## 方針: B-light (apex MX は触らない)

- `aizen-sol.co.jp` の MX は**不変**（全社メールに無リスク・完全可逆）。
- **サブドメイン `in.aizen-sol.co.jp` の独立 MX だけ** SES Inbound (Tokyo) に向ける。
- Kagoya 側で `outsource@aizen-sol.co.jp` を `capture@in.aizen-sol.co.jp` へ**転送（コピー）**。
  - ※ ローカル配送は残す＝ Kagoya IMAP 取込をバックアップとして温存。
- 転送（SMTP relay 経路）は遅い local delivery キューを通らない見込み。**速いかは Phase 0 で実測**。

```
送信元各社 → outsource@aizen-sol.co.jp ──(Kagoya受理: 即時)
                                          ├─ ローカル配送(従来) → IMAP取込(バックアップ)
                                          └─ forward → capture@in.aizen-sol.co.jp
                                                 (in.aizen-sol.co.jp MX → SES Tokyo)
                                                 → S3(raw) → Lambda → POST /api/v1/inbound/email
                                                 → storeRawMessage (dedup=rfc_message_id) → classify/score
```

## 確認済み前提
- SES Inbound は ap-northeast-1 (Tokyo) 対応 (2023/09〜)。送信 SES と同リージョン。
- 現状: MX=`10 dmail.kagoya.net` 単一 / NS=`ns0,ns1.kagoya.net` (DNS も Kagoya) / SPF=`v=spf1 a:mss-g2-140.kagoya.net include:amazonses.com ~all`。
- apex の MX はローカルパート単位で分割不可 → サブドメイン MX で回避するのが本方針の肝。
- **dedup の落とし穴**: 現状 dedup は `gmail_message_id='imap-{UID}'` のみ
  (`KagoyaMailService::syncEmails` L72-79)。SES 経路 (`ses-{id}`) と IMAP が二重登録するため
  Phase 1 で `rfc_message_id` dedup + index が必須。

---

## Phase 0 — 実現性テスト（可逆・低コスト・まず実測）

目的: Kagoya 転送が遅延キューを回避して速いかを実データで確認。コードは作らない。

### AWS (ap-northeast-1 / Tokyo)
1. **S3 バケット作成** (例 `aizen-inbound-mail`)。SES が PutObject できるバケットポリシーを付与
   (SES サービスプリンシパル `ses.amazonaws.com` + `aws:Referer`=アカウントID 条件)。
2. **SES でサブドメイン検証**: `in.aizen-sol.co.jp` を Verify。表示される検証 TXT と DKIM CNAME×3 を
   Kagoya DNS パネルに追加。
3. **受信ルールセット作成**（既存 active ルールセットがあればルール追記）:
   - Recipient condition: `in.aizen-sol.co.jp`
   - Action: **Deliver to S3** (`aizen-inbound-mail`)。← Phase 0 は S3 保存のみ。Lambda はまだ。
   - ルールセットを **Active** に設定。

### Kagoya DNS パネル
4. **サブドメイン MX 追加**:
   ```
   in.aizen-sol.co.jp.  MX  10  inbound-smtp.ap-northeast-1.amazonaws.com.
   ```
   (apex の MX レコードは触らない)
5. (任意) `in` サブドメイン SPF: `in TXT "v=spf1 include:amazonses.com ~all"`

### Kagoya メール設定
6. **`outsource@aizen-sol.co.jp` に転送を追加**: 転送先 `capture@in.aizen-sol.co.jp`。
   **「サーバーに残す/コピーを保持」を ON**（ローカル配送＝ IMAP バックアップを壊さない）。

### 計測
7. 実トラフィック数十通で 3 時刻を比較:
   - `Received: by mss-g2-140.kagoya.net (Postfix)` の時刻（真の受理）
   - SES 受信時刻（S3 オブジェクトの LastModified / SES のタイムスタンプ）
   - 旧 IMAP INTERNALDATE（従来の見え方）
   - **判定**: SES 受信が受理から数秒〜数分なら B-light 成立 → Phase 1 へ。
     受理から ~2.5h なら転送も同キュー → B-heavy(全社 MX 移管) を再検討。

---

## ラグ判定結果（2026-06-10・B-light 成立）

本番 (smz) `emails` の `arrived_at − received_at`（= IMAP 経路の送信→INBOX 配送ラグ）を測定（直近7日 n=28,074）:

| 指標 | ラグ |
|---|---|
| 平均 | 177.6 分 (≈3.0h) |
| p50 | 139.9 分 (≈2.3h) |
| p90 | 328.0 分 (≈5.5h) |
| p99 | 1161.5 分 (≈19h) |

バケット分布: 0–5分=10% / 5–30分=9.5% / 30–60分=7.6% / 60–150分=27% / 150–360分=40% / 360分超=6%。
**80%以上が30分超のラグ**。一方 SES forward は Phase 0 実測で **≈1秒**。差は決定的 → **B-light 成立・Phase 1 着手**。

---

## Phase 1 実装状況（2026-06-10・Laravel 側完了）

Laravel 受信パスを実装・テスト済（commit 参照）。**残るは AWS Lambda のデプロイのみ**（新冨さん作業）。

実装済:
- `config/services.php` → `services.inbound.secret` / `services.inbound.tenant_id`(既定1)
- `KagoyaMailService::storeRawFromSes($raw, $sesMessageId, $sesReceivedAt, $tenantId=null)`
  — `storeRawMessage` を再利用し uid=`ses-{id}` / `arrived_at`=SES受信時刻（`$internalDate` 引数に渡す）。
- `App\Http\Controllers\Api\InboundEmailController@store` — `X-Inbound-Secret` を `hash_equals` 検証 →
  JSON `{ses_message_id, received_at, raw_base64}` を受けて取込。store 失敗は 500 で Lambda リトライに委譲。
- route: `POST /api/v1/inbound/email`（認証不要グループ・共有シークレット認証）。
- テスト: `tests/Pgsql/Feature/InboundEmailTest.php`（arrived_at=SES時刻 / 401 / 取込）。

本番 `.env` に追記（デプロイ時）:
```
INBOUND_EMAIL_SECRET=<openssl rand -hex 32 で生成し Lambda と共有>
# INBOUND_EMAIL_TENANT_ID=1  # 既定1。変更不要
```

### AWS Lambda デプロイ手順（新冨さん作業・コンソール想定）

**確定値（コピペ用）**
| 項目 | 値 |
|---|---|
| リージョン | `ap-northeast-1`（東京） |
| S3 バケット | `aizen-sol-inbound-mail` |
| AWS アカウント | `856480643523` |
| `INBOUND_API_URL` | `https://sales.ai-mon.net/api/v1/inbound/email` |
| `INBOUND_SECRET` | 本番 `.env` の `INBOUND_EMAIL_SECRET` と**同値**（VPS `/var/www/sales_support/.env` 参照） |
| Lambda コード | `docs/ses-inbound-lambda.py`（python3.12・boto3 のみ） |

> このコードは **S3 PutObject イベント**形式の `event` を読む。トリガは「SES の Lambda アクション」ではなく **S3→Lambda 通知**にすること（SES アクションだと event 形状が違い動かない）。

**手順**
1. **関数作成**（Lambda コンソール・東京リージョン）
   - 「一から作成」/ 名前 `ses-inbound-forward` / ランタイム **Python 3.12** / アーキ x86_64。
2. **コード貼付**: `docs/ses-inbound-lambda.py` の中身を `lambda_function.py` に貼り「Deploy」。
   ハンドラは既定の `lambda_function.lambda_handler` のまま。boto3 はランタイム同梱で追加不要。
3. **環境変数**（設定 → 環境変数）
   - `INBOUND_API_URL` = `https://sales.ai-mon.net/api/v1/inbound/email`
   - `INBOUND_SECRET` = 本番 `.env` の `INBOUND_EMAIL_SECRET` 値
4. **基本設定**（設定 → 一般設定）: タイムアウト **30秒** / メモリ 256MB。
5. **実行ロールに S3 読取権限**（設定 → アクセス権限 → ロール名クリックで IAM へ）
   - インラインポリシー追加: `s3:GetObject` を `arn:aws:s3:::aizen-sol-inbound-mail/*` に許可。
   - CloudWatch Logs 権限（`AWSLambdaBasicExecutionRole`）は作成時に自動付与済。
6. **S3 トリガ追加**（Lambda コンソール → トリガーを追加 → S3）
   - バケット `aizen-sol-inbound-mail` / イベントタイプ **PUT（すべてのオブジェクト作成）**。
   - **プレフィックス**: SES 受信ルール `inbound-to-s3` の S3 アクションで ObjectKeyPrefix を設定して
     いる場合は同じ値を入れる（未設定ならプレフィックス空欄）。
   - これで S3→Lambda の invoke 許可（リソースベースポリシー）が自動付与される。
7. **疎通テスト**: `outsource@aizen-sol.co.jp` 宛に実メール送信（または Gmail から）→
   - CloudWatch Logs で `{"stored": true}` を確認。
   - 営業支援アプリの一覧に当該メールが出て、**「受信」時刻が送信直後（≈数秒）**になっていれば成功
     （従来の IMAP 経路だと数十分〜数時間ラグ）。
   - IMAP 経路でも同じメールが届くが `rfc_message_id` dedup で二重登録されない。

**ロールバック**: S3 トリガを削除すれば Lambda 休眠（API も叩かれない）。さらに戻すなら
サブドメイン MX と Kagoya 転送を外して IMAP のみの従来運用へ（完全可逆）。

**切替え運用**: Lambda 有効化後しばらく IMAP と二重取込（dedup で安全）。SES 経路が安定したら Phase 2 で IMAP を低頻度化。

---

## Phase 1 — 取込パス構築（Phase 0 合格後・コード作業）

8. **Lambda (Tokyo)**: SES 受信トリガ（S3 action にチェーン or S3 イベント）。S3 から raw 取得 →
   `POST /api/v1/inbound/email` に raw RFC822 + 共有シークレットヘッダで送信。async (Event) 呼び出し。
9. **Laravel: 受信エンドポイント**
   - route + `InboundEmailController@store`。共有シークレットを `hash_equals` で検証。
   - `KagoyaMailService::storeRawMessage` を **public 化 or 抽出**して raw+tenant+arrivedAt override を受ける
     共通メソッドにし、IMAP / SES 両経路で再利用。
   - `arrived_at` = **SES 受信時刻**（真の準リアルタイム着信）。`received_at` は従来通り Date ヘッダ。
   - **dedup は `rfc_message_id` ベースで実装済み（先行作業）**: `storeRawMessage` で tenant+
     rfc_message_id 既存チェック → skip。imap- 重複は anchor を新しい UID へ前進（再フェッチ
     ループ防止）。SES 重複は imap anchor を奪わない。index `emails_rfc_message_id_idx` は既存。
     テスト: `tests/Pgsql/Feature/KagoyaStoreRawMessageDedupTest.php`。
   - `gmail_message_id` は `ses-{sesMessageId}` で保存（IMAP は `imap-{UID}` のまま）。
     ※ `gmail_message_id` はグローバル UNIQUE（`emails_gmail_message_id_unique`）なので SES id も一意であること。
   - 取込後に classify/score を即時 enqueue（鮮度最大化）or 既存 10 分スケジューラに委譲。
10. **IMAP は残す**（バックアップ＋ dedup anchor）。

## Phase 2 — 安定後の整理（任意）
- SES 経路が一定期間安定したら IMAP 取込頻度を下げる（恒久バックアップとして低頻度維持）。

## ロールバック
- サブドメイン MX と Kagoya 転送を削除 → IMAP のみの従来運用に即復帰。API は休眠。完全可逆。

## コスト
- SES Inbound $0.10/1000通 + S3/Lambda 微小。月数千〜万通規模では無視できる。

## 関連メモリ
[[project_kagoya_gmail_delivery]] [[project_received_at_date_header_2026_05_28]]
[[project_kagoya_uid_incremental_fetch_2026_05_29]] [[feedback_concurrently_index_swap]]
[[project_gmail_intake_stopped_2026_05_14]] (過去 Gmail 転送 SPF Softfail の教訓)
