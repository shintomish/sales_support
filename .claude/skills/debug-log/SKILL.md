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
docker compose exec app tail -f storage/logs/laravel.log
# または直近100行
docker compose exec app tail -n 100 storage/logs/laravel.log
```

### 本番
```bash
ssh root@v133-18-42-139.vir.kagoya.net
docker exec sales_support_app tail -n 100 storage/logs/laravel.log
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
