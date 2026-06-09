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
