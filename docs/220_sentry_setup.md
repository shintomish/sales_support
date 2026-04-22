# Sentry エラー監視 導入手順書

> 対象: sales_support（Laravel 11 + Docker）  
> 作成日: 2026-04-09

---

## 概要

本番環境で発生するエラー・例外をリアルタイムで検知・通知するために Sentry を導入する。  
現状は `storage/logs/laravel.log` のみで監視しており、エラーの見落としリスクがある。

---

## Step 1: Sentry アカウント作成

1. [https://sentry.io/signup/](https://sentry.io/signup/) にアクセス
2. メールアドレスでアカウント作成（無料プランで運用可能）
3. Organization 名を入力（例: `aizen-sales`）
4. 「Create Project」をクリック
   - Platform: **Laravel** を選択
   - Project name: `sales_support`
   - Alert frequency: **On every new issue**
5. **DSN をコピーする**（後の手順で使用）
   - 形式: `https://xxxxxxxx@oyyyyyyy.ingest.sentry.io/zzzzzz`

---

## Step 2: パッケージインストール

```bash
cd ~/sales_support
docker compose exec app composer require sentry/sentry-laravel
```

---

## Step 3: 設定ファイル生成

DSN を指定して Sentry の設定ファイルを生成する。

```bash
docker compose exec app php artisan sentry:publish --dsn=<コピーしたDSN>
```

`config/sentry.php` が生成される。

---

## Step 4: `.env` に追記

```env
# Sentry
SENTRY_LARAVEL_DSN=https://xxxxxxxx@oyyyyyyy.ingest.sentry.io/zzzzzz
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_ENVIRONMENT=production
```

> `SENTRY_TRACES_SAMPLE_RATE=0.1` はパフォーマンストレースの10%サンプリング。  
> コスト抑制のため最初は低めに設定する。

`.env.example` にも項目だけ追記しておく（値は空）:

```env
SENTRY_LARAVEL_DSN=
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_ENVIRONMENT=local
```

---

## Step 5: 例外ハンドラーに登録（Laravel 11）

`bootstrap/app.php` の `withExceptions` ブロックに追記する:

```php
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->report(function (Throwable $e) {
        if (app()->bound('sentry')) {
            app('sentry')->captureException($e);
        }
    });
})->create();
```

---

## Step 6: 動作確認

```bash
docker compose exec app php artisan sentry:test
```

Sentry ダッシュボードの **Issues** にテストイベントが届けば導入完了。

---

## Step 7: 本番環境への反映

```bash
# VPS にSSH
ssh root@v133-18-42-139.vir.kagoya.net
cd /var/www/sales_support

# .env に DSN を追記
vi .env
# → SENTRY_LARAVEL_DSN= を追記

# パッケージインストール
docker exec sales_support_app composer require sentry/sentry-laravel
docker exec sales_support_app php artisan sentry:publish --dsn=<DSN>
docker exec sales_support_app php artisan config:clear
docker exec sales_support_app php artisan sentry:test
```

---

## アラート設定（推奨）

Sentry ダッシュボード → **Alerts** → **Create Alert Rule**

| 条件 | 推奨設定 |
|------|---------|
| 通知タイミング | 新規 Issue 発生時 |
| 通知先 | メール or Slack |
| 環境フィルタ | `production` のみ |
| エラー頻度閾値 | 5分以内に10回以上（ノイズ抑制） |

---

## 無料プランの制限

| 項目 | 無料プラン |
|------|-----------|
| イベント数 | 5,000件/月 |
| メンバー数 | 無制限 |
| データ保持期間 | 90日 |
| アラート | メールのみ（Slack連携は有料） |

> 月5,000件を超える場合は Team プラン（$26/月〜）を検討。  
> SES営業支援の規模では当面無料プランで十分。

---

## 関連ファイル

- `config/sentry.php` — Sentry設定（生成後）
- `bootstrap/app.php` — 例外ハンドラー登録
- `.env` — DSN・環境設定
