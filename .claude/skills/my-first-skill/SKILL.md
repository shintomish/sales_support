---
name: my-first-skill
description: REST API設計のコーディング規約
---
# Laravel APIエンドポイント追加スキル

## 概要
このプロジェクト（sales_support）のLaravel側に新しいAPIエンドポイントを
追加する際の標準手順。

## いつ使うか
- 「〇〇APIを作って」と言われたとき
- 新しいCRUD機能を追加するとき

## 前提
- Laravel 11 / PHP
- 認証: SupabaseAuth ミドルウェア（`supabase_auth`）
- テナント分離: `BelongsToTenant` trait + `SetTenantContext` middleware
- DBはSupabase PostgreSQL（Session Pooler）

## 手順
1. Modelを作成（`BelongsToTenant` traitを忘れずに付ける）
2. Migration作成・実行
3. Controllerを `Http/Controllers/Api/` に作成
4. `routes/api.php` にルート追加（`supabase_auth` middleware適用）
5. ローカル動作確認: `curl http://localhost:8090/api/v1/...`

## 注意事項
- `tenant_id` は GlobalScope で自動付与されるため手動セット不要
- `migrate:fresh` は絶対に実行しない（本番DBが消える）
- レスポンスは必ず `response()->json()` で返す

## コマンド
```bash
docker compose exec app php artisan make:model XXX -m
docker compose exec app php artisan make:controller Api/XXXController
docker compose exec app php artisan migrate
```
