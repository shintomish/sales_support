# バックアップ・リストア手順書

> 作成日: 2026-04-29 / 対象: sales-support 本番環境

---

## 1. この手順書の目的

本番障害・操作ミス・ハード障害から **24時間以内に復旧** するための手順をまとめる。
RPO（許容データ損失時間）24時間 / RTO（復旧目標時間）4時間 を当面の基準とする。

---

## 0. 前提条件（必ず最初に確認）

本手順は **Supabase Pro プラン以上** を前提とする。

| 項目 | 必要設定 |
|---|---|
| Supabase プラン | **Pro 以上**（月 $25〜） |
| 理由 | Free プランは自動バックアップが付与されない・7日無操作で Pause される |
| 昇格手順 | Dashboard → Settings → Billing → "Upgrade to Pro" |
| 現在の状態 | **Pro（2026-04-29 昇格完了）** |
| Billing | Business（株式会社アイゼンソリューション・適格請求書発行事業者番号 T8030001082952 で登録） |
| 請求サイクル | 29 Apr 2026 - 29 May 2026 / 月額見積 約 $34.62（Pro $25 + Compute Hours dev分） |

---

## 2. 保護対象と保管場所の対応表

| 対象 | 実体 | 自動バックアップ | 手動バックアップが必要か |
|---|---|---|---|
| Supabase PostgreSQL（本番） | プロジェクト `sales-support` (`qkjceppkrsurrynqsuse`) | ✅ 日次論理バックアップ（プラン依存） | 月次1回・リリース前 |
| Supabase Storage（名刺画像） | バケット `business-cards` 等 | ❌ DBバックアップに**含まれない** | **必須・週次** |
| VPS Laravel コード | `/var/www/sales_support` | GitHub（git push） | git push を励行 |
| `.env`（本番） | VPS `/var/www/sales_support/.env` | なし（gitignore） | **必須・変更時に都度** |
| `docker-compose.yml`（本番） | VPS（skip-worktree 保護済） | なし | **必須・変更時に都度** |
| Supabase OAuth クライアントシークレット等 | Supabase Dashboard | なし | スクリーンショット保管 |

**最大の盲点: Storage オブジェクト（名刺画像）は Supabase の DB バックアップに含まれない。**
DBには `business_cards` テーブルにメタデータと storage path のみが保存されている。

---

## 3. 本番DBの現状（2026-04-29 時点）

| 項目 | 値 |
|---|---|
| プロジェクト ID | `qkjceppkrsurrynqsuse` |
| リージョン | ap-northeast-1（東京） |
| PostgreSQL | 17.6 |
| DBサイズ | 約 363 MB |
| 主要テーブル行数 | emails: 62,206 / engineer_mail_sources: 44,812 / email_attachments: 33,231 / project_mail_sources: 20,202 |
| バックアップ種別 | **論理バックアップ**（15GB未満かつPITR未契約のため） |
| 現在のプラン | **Pro**（2026-04-29 昇格完了） |

> Dashboard → Database → Backups → Scheduled に翌日以降、日次バックアップが7日分蓄積される。

---

## 4. Supabase 自動バックアップの仕様

### 4.1 プラン別の保持期間

| プラン | 自動バックアップ | 保持期間 |
|---|---|---|
| Free | なし | — |
| Pro | 日次論理バックアップ | 7日 |
| Team | 日次論理バックアップ | 14日 |
| Enterprise | 日次論理バックアップ | 30日 |
| PITR add-on（Pro以上） | 物理バックアップ + WAL | 7/14/28日（add-on別） |

### 4.2 PITR（Point-in-Time Recovery）導入判断

| 項目 | 内容 |
|---|---|
| 価格 | 月 $100（7日）〜 $400（28日） |
| 利点 | 2分粒度で任意時点に復旧可能（RPO=2分） |
| 必要条件 | Pro 以上 + Small Compute add-on |
| 推奨判断 | **当面は不要**（β段階・配信ボリュームが本格化するまで） |

→ Phase 2（クローズドβ）開始時に再検討。

### 4.3 バックアップの確認方法

Supabase Dashboard:
```
プロジェクト → Database → Backups → Scheduled
```

Management API:
```bash
export SUPABASE_ACCESS_TOKEN="<personal access token>"
export PROJECT_REF="qkjceppkrsurrynqsuse"

curl -H "Authorization: Bearer $SUPABASE_ACCESS_TOKEN" \
  "https://api.supabase.com/v1/projects/$PROJECT_REF/database/backups"
```

---

## 5. 手動バックアップ手順（自動バックアップの二重化）

> Pro プランの自動バックアップは **7日間しか保持されない**。月単位の遡及や Supabase 障害時の最終防衛線として、手動 pg_dump を Dropbox に保管する。

### 5.1 PostgreSQL（論理バックアップ）

**月次1回・リリース前に実施。**

```bash
# 接続情報（Session Pooler 経由）
export PGHOST="aws-1-ap-northeast-1.pooler.supabase.com"
export PGPORT="5432"
export PGUSER="postgres.qkjceppkrsurrynqsuse"
export PGPASSWORD="<本番パスワード（.envのDB_PASSWORD）>"
export PGDATABASE="postgres"

# ダンプ取得（compressed custom format）
DATE=$(date +%Y%m%d_%H%M%S)
pg_dump -Fc --no-owner --no-acl \
  --schema=public \
  -f "sales_support_${DATE}.dump"

# サイズ確認
ls -lh sales_support_${DATE}.dump
```

**保管先**: Dropbox `Public/Book/03_Aizen/990_Sales_Support/backups/db/`（職場・自宅で同期）

**保持ローテーション**: 直近12ヶ月分のみ保持。それ以前は削除。

### 5.2 Supabase Storage（名刺画像）

**週次・日曜深夜に実施推奨。**

```bash
# Supabase CLI が必要
supabase login
supabase storage download \
  --recursive \
  -p qkjceppkrsurrynqsuse \
  ss://business-cards \
  ./storage_backup_$(date +%Y%m%d)/
```

**保管先**: Dropbox `Public/Book/03_Aizen/990_Sales_Support/backups/storage/`

**保持ローテーション**: 直近4週分のみ保持。

> Supabase CLI のインストール: `brew install supabase/tap/supabase` または `npm i -g supabase`

### 5.3 .env と docker-compose.yml

**変更があった都度・変更直後にコピーする。**

```bash
# VPS から取得
ssh root@v133-18-42-139.vir.kagoya.net \
  'cat /var/www/sales_support/.env' \
  > ~/Dropbox/Public/Book/03_Aizen/990_Sales_Support/backups/env/env_$(date +%Y%m%d).txt

ssh root@v133-18-42-139.vir.kagoya.net \
  'cat /var/www/sales_support/docker-compose.yml' \
  > ~/Dropbox/Public/Book/03_Aizen/990_Sales_Support/backups/env/docker-compose_$(date +%Y%m%d).yml
```

---

## 6. リストア手順

### 6.1 ケースA: テーブル単位の誤削除（操作ミス復旧）

**条件**: テーブル1つを削除した、特定行を誤更新した、など局所的な事故。
**所要時間**: 30〜60分。

#### 手順

1. **影響確認** — Supabase SQL Editor で該当テーブル状態を確認。
2. **直近の自動バックアップを取得**:
   - 7日以内の事故 → Dashboard → Database → Backups → Scheduled → 該当日の **Download**
   - 7日超の遡及が必要な場合 → Dropbox の月次手動バックアップ（5.1）から取得
3. **ローカルで部分復元準備**:
   ```bash
   # ダンプを展開
   gunzip backup_YYYYMMDD.sql.gz

   # 必要なテーブルだけ抽出（例: business_cards）
   pg_restore -l backup_YYYYMMDD.dump | grep "TABLE DATA.*business_cards" \
     > restore_list.txt
   pg_restore -L restore_list.txt -d temp_db backup_YYYYMMDD.dump
   ```
4. **本番への適用**: 影響テーブルをTRUNCATEしてからCOPYで投入、または個別行のINSERT/UPDATEで復旧。
5. **検証**: 行数・主要レコードを確認。

> **必ず先に dev 環境（`sales-support-dev` / `smzoqpvaxznqcwrsgjju`）で同手順を試してから本番適用すること。**

### 6.2 ケースB: DB全体ロールバック（広域事故）

**条件**: スキーマ破壊、大規模誤データ投入、ランサムウェア等。
**所要時間**: 1〜3時間（DBサイズ依存）。

#### 手順

1. **配信を即停止**:
   ```bash
   ssh root@v133-18-42-139.vir.kagoya.net
   docker exec sales_support_app php artisan down
   ```
2. **Supabase Dashboard で復元実行**:
   ```
   Database → Backups → Scheduled → 復元したい日付を選択 → Restore
   ```
   復元中はプロジェクトがアクセス不可になる旨の警告が出る → 確認して実行。
3. **完了通知をDashboardで確認**。
4. **アプリの設定キャッシュをクリア**:
   ```bash
   docker exec sales_support_app php artisan config:clear
   docker exec sales_support_app php artisan cache:clear
   ```
5. **smoke test**:
   - `/api/v1/customers` 一覧が返ること
   - `/api/v1/delivery-campaigns` 一覧が返ること
   - 配信担当ユーザーでログイン → ダッシュボード表示
6. **配信再開**:
   ```bash
   docker exec sales_support_app php artisan up
   ```
7. **失われたデータの調査** — バックアップ取得時刻〜事故発生時刻のメール受信ログ・送信ログを `delivery_send_histories` 等で確認し、対応をユーザーに通知。

### 6.3 ケースC: Supabase プロジェクト自体の喪失

**条件**: アカウント停止、Supabase 側の障害、リージョン全断。
**所要時間**: 4〜8時間。

#### 手順

1. **新規 Supabase プロジェクトを作成**（ap-northeast-1 / PG17）。
2. **手動論理バックアップを復元**:
   ```bash
   pg_restore --no-owner --no-acl \
     -h aws-1-ap-northeast-1.pooler.supabase.com \
     -U postgres.<新プロジェクトref> \
     -d postgres \
     sales_support_YYYYMMDD.dump
   ```
3. **マイグレーションの状態を同期**:
   ```bash
   docker exec sales_support_app php artisan migrate:status
   # 必要に応じて migrations テーブルを手動修正
   ```
4. **Storage バケット作成 + 名刺画像復元**:
   - Dashboard で `business-cards` バケット作成（public 設定を本番と一致させる）
   - 5.2 で取得したバックアップを `supabase storage upload` で投入
5. **VPS 側 `.env` を新プロジェクトの値で更新**:
   ```
   SUPABASE_URL=
   SUPABASE_ANON_KEY=
   SUPABASE_SERVICE_ROLE_KEY=
   DB_HOST=aws-1-ap-northeast-1.pooler.supabase.com
   DB_USERNAME=postgres.<新プロジェクトref>
   DB_PASSWORD=
   ```
6. **Auth ユーザーの再登録** — service_role 経由で再作成、または Supabase Migration 機能で auth スキーマを移行。
7. **Realtime 購読・Edge Functions の再設定**。
8. **smoke test → 配信再開**。

---

## 7. 定期検証スケジュール

| 頻度 | 内容 | 担当 |
|---|---|---|
| 月次（毎月第1月曜） | 5.1 の手動 pg_dump を実施 → Dropbox保管 | PM |
| 週次（毎週日曜） | 5.2 の Storage バックアップ実施 | PM |
| 四半期（年4回） | dev 環境で 6.2 の全体リストアをリハーサル | PM + バックエンド |
| 半期（年2回） | 6.3 の新プロジェクト復元を dev で実施し RTO 計測 | PM + インフラ |

---

## 8. 障害時の連絡先

| 障害種別 | 一次対応 |
|---|---|
| Supabase 側障害 | https://status.supabase.com/ で状況確認 → Dashboard 右下チャットで起票 |
| VPS（KAGOYA）障害 | KAGOYA サポート + UptimeRobot アラート確認 |
| SES（メール送信）障害 | AWS Console → SES → ap-northeast-1 のアラート確認 |
| Sentry エラー急増 | Sentry Dashboard で stacktrace 確認 → 該当コミットを特定 |

---

## 9. 未対応事項（2026-04-29 時点）

- [x] ~~Supabase Pro へ昇格~~ — **2026-04-29 完了**（Business / インボイス番号登録済）
- [ ] **2026-04-30 以降に Dashboard で日次バックアップが生成されているか確認**
- [x] ~~Supabase CLI を自宅PCにインストール~~ — **2026-04-29 完了**（v2.95.4）
- [ ] Supabase CLI を職場PCにインストール
- [x] ~~Dropbox `backups/db/` `backups/storage/` `backups/env/` ディレクトリを作成~~ — **2026-04-29 完了**
- [x] ~~初回 .env バックアップ~~ — **2026-04-29 完了**（`prod_env_20260429_123640.txt`）
- [x] ~~初回手動 pg_dump を実施~~ — **2026-04-29 完了**（91MB / 44テーブル / 68インデックス）
- [x] ~~初回 Storage バックアップを実施~~ — **2026-04-29 完了**（1029ファイル / 104MB）
- [ ] dev 環境で 6.2（全体リストア）リハーサル実施
- [ ] バックアップ自動化スクリプト（cron）の整備 — 当面は手動運用で十分
- [ ] dev プロジェクトの Pause 検討 — 不要なら停止して Compute Hours を削減

---

## 10. 関連ドキュメント

- `050_pm_plan.md` — PM計画書（本タスクの位置づけ）
- `220_sentry_setup.md` — エラー監視
- `CLAUDE.md` — DB接続情報・本番デプロイ手順
- [Supabase Database Backups](https://supabase.com/docs/guides/platform/backups)
