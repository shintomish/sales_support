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
# PHP プロセスを起動する docker exec は必ず --user www-data（後述 Gotchas）
docker exec --user www-data sales_support_app composer install --no-dev --optimize-autoloader
docker exec --user www-data sales_support_app php artisan migrate --force
docker exec --user www-data sales_support_app php artisan config:clear
docker exec --user www-data sales_support_app php artisan cache:clear
```

### Step 3: 動作確認
```bash
# ログにエラーがないか確認（ファイル名は laravel.log ではなく日付ローテーション）
docker exec --user www-data sales_support_app tail -f storage/logs/sales_sup-$(date +%Y-%m-%d).log
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

## Gotchas（このプロジェクト固有のハマりどころ）
- **本番 docker exec で PHP を叩く時は必ず `--user www-data`**。root で `php artisan` / `tinker` を実行するとログ等が root 所有で作られ、以降 PHP-FPM(www-data) から追記不能 → Schedule ジョブのログが全て `Permission denied` で死ぬ（2026-05-14 実際に発生）。`migrate` / `config:clear` / `config:cache` / `db:seed` / `view:clear` 全て対象。
  - 既に root 所有で作られたら復旧: `docker exec sales_support_app chown -R www-data:www-data /var/www/storage/`
- **ログファイル名は `laravel.log` ではなく `sales_sup-YYYY-MM-DD.log`**（日付ローテーション）。`tail` する時は `storage/logs/sales_sup-$(date +%Y-%m-%d).log`。
- **`migrate:fresh` は本番で絶対NG**（DB全消失）。`docker-compose.yml` は skip-worktree 保護済（git pull で上書きされない）。
- **本番は `config:cache` 可（2026-05-13 解禁）**。env() 直呼び全廃済。ただし新規 env 追加時は `config/*.php` のエントリ追加も必須（cache 後 env() が null を返す）。
