"""
SES Inbound → Laravel 転送 Lambda (B-light / Phase 1)

トリガ: SES 受信ルール `inbound-to-s3` が S3 (aizen-sol-inbound-mail) に生メールを保存
        → S3 PutObject イベント (suffix なし / prefix は受信ルールの ObjectKeyPrefix に合わせる)
動作:   S3 から生 RFC822 を取得 → base64 化 → 共有シークレットヘッダ付きで
        POST https://<API>/api/v1/inbound/email
        Laravel 側 InboundEmailController@store が storeRawFromSes で取込
        (arrived_at = SES 受信時刻 → Kagoya 配送遅延を回避)

ランタイム: python3.12 / 標準ライブラリのみ (依存パッケージ不要)
タイムアウト: 30s / メモリ: 256MB

環境変数:
  INBOUND_API_URL    = https://<本番API>/api/v1/inbound/email
  INBOUND_SECRET     = Laravel の INBOUND_EMAIL_SECRET と同値 (config services.inbound.secret)

IAM ロール権限: s3:GetObject (対象バケットのみ) + 基本実行ロール (CloudWatch Logs)

非同期呼び出し (S3 イベントは Event 型) のため失敗時は Lambda が自動リトライ (既定2回)。
Laravel 側は rfc_message_id dedup で再送に冪等。最終的なバックアップは IMAP 取込。
"""
import base64
import json
import os
import urllib.request
import urllib.error
import urllib.parse  # unquote_plus に必要 (urllib.request だけでは未保証)

import boto3

s3 = boto3.client("s3")

API_URL = os.environ["INBOUND_API_URL"]
SECRET = os.environ["INBOUND_SECRET"]


def lambda_handler(event, context):
    records = event.get("Records", [])
    # 診断: イベントに S3 レコードが何件あるか (s3:TestEvent や別ソースだと 0)
    print(f"[inbound] records={len(records)} api_url={API_URL} secret_len={len(SECRET)}")
    if not records:
        print(f"[inbound] no S3 records, event keys={list(event.keys())}")

    results = []
    for record in records:
        bucket = record["s3"]["bucket"]["name"]
        # S3 キーは URL エンコードされて届くため decode する
        key = urllib.parse.unquote_plus(record["s3"]["object"]["key"])

        obj = s3.get_object(Bucket=bucket, Key=key)
        raw_bytes = obj["Body"].read()
        # SES 受信時刻 = S3 オブジェクトの LastModified (UTC)。これを arrived_at に使う。
        received_at = obj["LastModified"].strftime("%Y-%m-%dT%H:%M:%SZ")
        # ses_message_id: SES の messageId は S3 キー末尾 (受信ルールが付与) と一致する。
        ses_message_id = key.rsplit("/", 1)[-1]
        print(f"[inbound] key={key} bytes={len(raw_bytes)} msg_id={ses_message_id} received_at={received_at}")

        payload = json.dumps({
            "ses_message_id": ses_message_id,
            "received_at": received_at,
            "raw_base64": base64.b64encode(raw_bytes).decode("ascii"),
        }).encode("utf-8")

        req = urllib.request.Request(
            API_URL,
            data=payload,
            method="POST",
            headers={
                "Content-Type": "application/json",
                # Accept: JSON を明示しないと Laravel はバリデーション失敗時に
                # JSON 422 ではなく 302 リダイレクトを返す (expectsJson() が Accept を見るため)。
                "Accept": "application/json",
                "X-Inbound-Secret": SECRET,
            },
        )
        try:
            with urllib.request.urlopen(req, timeout=25) as resp:
                body = resp.read().decode("utf-8")
                print(f"[inbound] POST ok status={resp.status} body={body}")
                results.append({"key": key, "status": resp.status, "body": body})
        except urllib.error.HTTPError as e:
            err_body = e.read().decode("utf-8")
            print(f"[inbound] POST http_error status={e.code} body={err_body}")
            # 5xx は再スロー → Lambda 非同期リトライに委ねる (dedup で冪等)
            if e.code >= 500:
                raise
            # 4xx (401/422 等) はリトライしても無駄なのでログのみ
            results.append({"key": key, "status": e.code, "error": err_body})
        except urllib.error.URLError as e:
            # 接続/DNS/SSL エラー (HTTPError 以外)。原因可視化のため出力してから再スロー。
            print(f"[inbound] POST url_error reason={e.reason}")
            raise

    print(f"[inbound] done results={json.dumps(results, ensure_ascii=False)}")
    return {"processed": results}
