---
name: deploy
description: sales_supportの本番デプロイを安全に実行する
---
# 本番デプロイスキル

## 概要
git push → SSH接続 → pull → migrate → キャッシュクリアまでの本番デプロイ手順。

## いつ使うか
- 「デプロイして」「本番に反映して」と言われたとき
- 機能実装・バグ修正が完了したとき

## 前提確認（必ずやること）
1. ローカルで動作確認済みか確認
2. `git status` で未コミットの変更がないか確認
3. migrationファイルがあれば内容を確認（`migrate:fresh` は絶対NG）

## デプロイ手順

### Step 1: pushする
```bash
git push origin main
```

### Step 2: VPSにSSH接続してデプロイ
```bash
ssh root@v133-18-42-139.vir.kagoya.net
cd /var/www/sales_support
git pull origin main
docker exec sales_support_app composer install --no-dev --optimize-autoloader
docker exec sales_support_app chown -R www-data:www-data /var/www/storage/
docker exec sales_support_app php artisan migrate --force
docker exec sales_support_app php artisan config:clear
docker exec sales_support_app php artisan cache:clear
```

### Step 3: 動作確認
```bash
# ログにエラーがないか確認
docker exec sales_support_app tail -f storage/logs/laravel.log
```

## 注意事項
- `migrate:fresh` は絶対に実行しない（本番DBが消える）
- docker-compose.ymlはskip-worktreeで保護済み（git pullで上書きされない）
- エラーが出たらすぐログを確認してユーザーに報告する

## よくあるデプロイ後エラー

| エラー | 対処 |
|--------|------|
| config:clear後に500 | `php artisan config:cache` を試す |
| migration失敗 | ログ確認→カラム競合がないか確認 |
| DB接続エラー | Session Pooler（aws-1-ap-northeast-1.pooler.supabase.com:5432）の設定確認 |
