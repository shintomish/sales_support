---
name: debug-log
description: Laravelのエラーログを確認してバグを診断・修正するフロー
---
# ログ確認・バグ診断スキル

## 概要
「エラーが出た」「動かない」というときにログを確認し、原因を特定して修正提案するまでの標準フロー。

## いつ使うか
- 「エラーが出た」「500が返ってくる」と言われたとき
- APIのレスポンスがおかしいとき
- デプロイ後に不具合が出たとき

## Step 1: ログを確認する

### ローカル
```bash
# ログ名は laravel.log ではなく日付ローテーション sales_sup-YYYY-MM-DD.log
docker compose exec app tail -f storage/logs/sales_sup-$(date +%Y-%m-%d).log
# または直近100行
docker compose exec app tail -n 100 storage/logs/sales_sup-$(date +%Y-%m-%d).log
# 監査ログ（USER_ACTIVITY）は別チャネル
docker compose exec app tail -n 100 storage/logs/audit.log
```

### 本番
```bash
ssh root@v133-18-42-139.vir.kagoya.net
docker exec sales_support_app tail -n 100 storage/logs/sales_sup-$(date +%Y-%m-%d).log
```

## Step 2: エラーの種類を特定する

| エラーパターン | 疑うべき場所 |
|--------------|------------|
| `Undefined variable` / `Call to undefined method` | コントローラ・サービスのロジック |
| `SQLSTATE` / `QueryException` | マイグレーション漏れ・カラム名ミス |
| `JWT` / `401 Unauthorized` | SupabaseAuthミドルウェア・トークン期限切れ |
| `invalid byte sequence` / `UTF-8` | テキスト入力の文字コード（cleanUtf8処理漏れ） |
| `Undefined index` / `null` | メール抽出の戻り値がnullのケース |

## Step 3: 該当ファイルを探す

```bash
# エラーメッセージのクラス名からファイルを探す
grep -r "クラス名" app/ --include="*.php" -l
```

## Step 4: 修正する

- 修正後はローカルで `curl http://localhost:8090/api/v1/...` で動作確認
- 修正内容を `fix:` プレフィックスでコミット

## このプロジェクト固有の注意点
- `tenant_id` がnullになるエラー → `SetTenantContext` ミドルウェアが適用されているか確認
- DB接続エラー → Session Pooler設定（.envのDB_HOSTがpooler.supabase.comか確認）
- メール抽出系のエラー → `ClaudeService` のレスポンスがnullでないか確認

## Gotchas（このプロジェクト固有のハマりどころ）
- **本番でログ書き込みを伴う `php artisan` / `tinker` を叩く時は `--user www-data`**（root実行でログが root 所有化→以降 PHP-FPM から `Permission denied`）。詳細は deploy スキル参照。
- **`UnexpectedValueException: Permission denied`（ログ書込）を見たら**、storage/logs 配下に root 所有ファイルが混じっていないか確認 → `docker exec sales_support_app chown -R www-data:www-data /var/www/storage/`。
- **`statement timeout` 系**: `emails` への一括 UPDATE/DELETE は index 約10本の保守で 2min タイムアウト（trgm GIN 等）。LIMIT 200〜1000 のバッチループ必須。bounce 大量削除は FK cascade(project_mail_sources) で別途 timeout → 同じくバッチ。
- **`JWT iat prior to` / 「Enterで401・リロードで通る」**: コードより先にサーバー時計を疑う（`date -u`）。leeway=60秒。
- **スコアが急に崩れた/件名だけで採点された疑い**: `CleanupEmails` が30日超の body を NULL化 → rescore で件名のみ採点される構造的罠。現在は本文空ガードで保存値温存済（崩れていたら回帰を疑う）。
