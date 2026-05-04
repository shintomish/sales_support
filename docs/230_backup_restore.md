# バックアップ・リストア手順書

> 作成日: 2026-04-29 / 最終更新: 2026-05-03 / 対象: sales_support システム 本番環境

---

## 1. この手順書の目的

本番障害・操作ミス・ハード障害から **24時間以内に復旧** するための手順をまとめる。
RPO（許容データ損失時間）24時間 / RTO（復旧目標時間）4時間 を当面の基準とする。

---

## 0. 前提条件（必ず最初に確認）

### 0.1 ⚠️ プロジェクト ref と Dashboard 表示名の逆転（2026-05-01 スワップ実施）

forgot password 二重化解消（Issue #13）で本番/dev のプロジェクトを入れ替えた結果、**Dashboard 上の表示名と実用途が逆転している**。本書で参照する ref は必ず以下の対応表に従うこと。

| ref | Dashboard 表示名 | 実用途 | 確認方法 |
|---|---|---|---|
| `smzoqpvaxznqcwrsgjju` | sales-support-dev | **本番** | 本番VPS `.env` の `DB_USERNAME=postgres.smzoqpvaxznqcwrsgjju` |
| `qkjceppkrsurrynqsuse` | sales-support | **dev** | ローカル/職場PCの `.env` |

> Dashboard で操作する際は **表示名ではなく ref（URL に含まれる）で識別する** こと。誤操作で dev に対して本番リストアを実行すると重大事故になる。
> 表示名のリネーム（dev → prod）は別タスクとして検討（影響範囲: SES SMTP 設定の表示・OAuth リダイレクト URI 等）。

### 0.2 Supabase プラン

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
| Supabase PostgreSQL（本番） | プロジェクト `sales-support-dev`（表示名・実体は本番） / ref `smzoqpvaxznqcwrsgjju` | ✅ 日次論理バックアップ（プラン依存） | 月次1回・リリース前 |
| Supabase Storage（名刺画像） | バケット `business-cards` 等 | ❌ DBバックアップに**含まれない** | **必須・週次** |
| VPS Laravel コード | `/var/www/sales_support` | GitHub（git push） | git push を励行 |
| `.env`（本番） | VPS `/var/www/sales_support/.env` | なし（gitignore） | **必須・変更時に都度** |
| `docker-compose.yml`（本番） | VPS（skip-worktree 保護済） | なし | **必須・変更時に都度** |
| Supabase OAuth クライアントシークレット等 | Supabase Dashboard | なし | スクリーンショット保管 |

**最大の盲点: Storage オブジェクト（名刺画像）は Supabase の DB バックアップに含まれない。**
DBには `business_cards` テーブルにメタデータと storage path のみが保存されている。

---

## 3. 本番DBの現状（2026-05-03 時点）

| 項目 | 値 |
|---|---|
| プロジェクト ID | `smzoqpvaxznqcwrsgjju` |
| リージョン | ap-northeast-1（東京） |
| PostgreSQL | 17.6.1 |
| DBサイズ | 約 363 MB（2026-04-29 時点） |
| 主要テーブル行数 | emails: 62,206 / engineer_mail_sources: 44,812 / email_attachments: 33,231 / project_mail_sources: 20,202（2026-04-29 時点） |
| バックアップ種別 | **物理バックアップ（WAL-G ベース）** — Management API で `is_physical_backup: true` / `walg_enabled: true` を確認済み |
| PITR | 未契約（`pitr_enabled: false`） |
| 現在のプラン | **Pro**（2026-04-29 昇格完了） |
| 直近確認 | 2026-04-26〜2026-05-02 の 7 日分すべて COMPLETED（2026-05-03 確認） |

> Dashboard → Database → Backups → Scheduled に直近 7 日分の日次バックアップが蓄積される。生成タイミングは概ね 02:53〜02:54 JST。

---

## 4. Supabase 自動バックアップの仕様

### 4.1 プラン別の保持期間

| プラン | 自動バックアップ | 保持期間 |
|---|---|---|
| Free | なし | — |
| Pro | 日次バックアップ（物理 / WAL-G） | 7日 |
| Team | 日次バックアップ（物理 / WAL-G） | 14日 |
| Enterprise | 日次バックアップ（物理 / WAL-G） | 30日 |
| PITR add-on（Pro以上） | 上記 + WAL を 2分粒度で保持 | 7/14/28日（add-on別） |

> Supabase は近年バックアップを論理（pg_dump）から物理（WAL-G ベース）に移行済み。リストアは Dashboard 操作で日付単位の復元として提供されるため、ユーザー視点の手順は変わらない。

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
export PROJECT_REF="smzoqpvaxznqcwrsgjju"

curl -sS -H "Authorization: Bearer $SUPABASE_ACCESS_TOKEN" \
  "https://api.supabase.com/v1/projects/$PROJECT_REF/database/backups" | jq '.'
```

レスポンス例（抜粋）:
```json
{
  "region": "ap-northeast-1",
  "pitr_enabled": false,
  "walg_enabled": true,
  "backups": [
    { "is_physical_backup": true, "status": "COMPLETED", "inserted_at": "2026-05-02T17:54:07.268Z" },
    ...
  ]
}
```

- `backups[].status` がすべて `COMPLETED` であること
- 直近 7 日分が連続で並んでいること（欠損があれば Supabase 側に要問い合わせ）

---

## 5. 手動バックアップ手順（自動バックアップの二重化）

> Pro プランの自動バックアップは **7日間しか保持されない**。月単位の遡及や Supabase 障害時の最終防衛線として、手動 pg_dump を Dropbox に保管する。

### 5.1 PostgreSQL（論理バックアップ）

**月次1回・リリース前に実施。**

```bash
# 接続情報（Session Pooler 経由）
export PGHOST="aws-1-ap-northeast-1.pooler.supabase.com"
export PGPORT="5432"
export PGUSER="postgres.smzoqpvaxznqcwrsgjju"
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
  -p smzoqpvaxznqcwrsgjju \
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

> **必ず先に dev 環境（`qkjceppkrsurrynqsuse`、Supabase Dashboard 上の表示名は `sales-support` だが実体は dev）で同手順を試してから本番適用すること。**

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

## 9. 未対応事項（2026-05-04 時点）

- [x] ~~Supabase Pro へ昇格~~ — **2026-04-29 完了**（Business / インボイス番号登録済）
- [x] ~~日次バックアップ生成の確認~~ — **2026-05-03 完了**（4/26〜5/2 の 7 日分すべて COMPLETED / 物理バックアップ）
- [x] ~~Supabase CLI を自宅PCにインストール~~ — **2026-04-29 完了**（v2.95.4）
- [ ] Supabase CLI を職場PCにインストール
- [x] ~~Dropbox `backups/db/` `backups/storage/` `backups/env/` ディレクトリを作成~~ — **2026-04-29 完了**
- [x] ~~初回 .env バックアップ~~ — **2026-04-29 完了**（`prod_env_20260429_123640.txt`）
- [x] ~~初回手動 pg_dump を実施~~ — **2026-04-29 完了**（91MB / 44テーブル / 68インデックス）
- [x] ~~初回 Storage バックアップを実施~~ — **2026-04-29 完了**（1029ファイル / 104MB）
- [x] ~~dev 環境で 6.2（全体リストア）リハーサル実施~~ — **2026-05-04 完了**（§11 参照）
- [x] ~~5/1 スワップ後の本番 pg_dump を取り直し~~ — **2026-05-04 完了**（`sales_support_prod_20260504_163350.dump` 4.1MB / Dropbox `backups/db/` 保管）
- [ ] バックアップ自動化スクリプト（cron）の整備 — 当面は手動運用で十分
- [ ] dev プロジェクトの Pause 検討 — 不要なら停止して Compute Hours を削減
- [ ] Supabase Dashboard 上のプロジェクト名リネーム検討（表示名と用途の逆転を解消する場合）

---

## 11. リストアリハーサル実施記録（2026-05-04）

### 11.1 概要
Issue #21 リハーサル。検証用 Supabase プロジェクト `sales-support-restore-test`（ref `ifsoqmsfefhjasblzjxu` / ap-northeast-1 / Pro $10/月）を新規作成し、4/29 取得の手動 pg_dump（91MB / custom format）を `pg_restore -j 4` で復元、件数を本番と比較。

### 11.2 RTO 計測

| 工程 | 所要時間 |
|---|---|
| 検証用 Supabase プロジェクト作成 + ACTIVE_HEALTHY まで | 約 2〜3 分（自動）|
| dump ファイル host → container コピー | 3.8 秒 |
| **`pg_restore` 並列 4 ジョブ（91MB / 約 16 万行）** | **45.1 秒** ✅ |
| **DB 復元のみのトータル** | **約 49 秒** |

§1 の RTO 目標「4 時間」 に対して大幅に余裕。DB 復元自体は 1 分以内。

### 11.3 件数照合結果（重要発見）

| テーブル | 復元（4/29 dump）| 本番（5/4 現在）|
|---|---|---|
| customers | 55 | 56 |
| emails | 62,232 | 2,993 |
| email_attachments | 33,243 | 1,163 |
| project_mail_sources | 20,214 | 875 |
| engineer_mail_sources | 44,826 | 2,167 |
| delivery_campaigns | 7 | 0 |
| delivery_send_histories | 2,239 | 0 |
| engineers | 56 | 57 |

→ **復元行数の方が圧倒的に多い**。これは 4/29 dump 取得時点では旧本番（現 dev `qkjceppkrsurrynqsuse`）が運用中で、5/1 のプロジェクトスワップで「新側（現本番 `smzoqpvaxznqcwrsgjju`）のメール/配信/名刺データ削除 (DB 63,925 行 + Storage 3,047 ファイル)」を意図的に実施したため（`memory/project_forgot_password_handoff.md` 参照）。

### 11.4 含意・残課題

- **4/29 dump は現本番のリストア用としては古い**。今すぐ本番障害が発生した場合、復元しても 5/1 以降の更新（メール取込・配信履歴）は失われる。
- **対応**: 本番 `smzoqpvaxznqcwrsgjju` から手動 pg_dump を取り直して Dropbox `backups/db/` に保管する。
- 自動バックアップ（Supabase 物理バックアップ・WAL-G 7 日分）は Dashboard から復元可能（§6.2）なので、緊急時はそちらが先。手動 dump は月次の最終防衛線。

### 11.5 検証用プロジェクトの後始末

リハーサル直後に Dashboard から削除：
https://supabase.com/dashboard/project/ifsoqmsfefhjasblzjxu/settings/general → 「Delete Project」

放置すると月 $10 課金。日割りでも数日で $1 程度なので忘れず削除する運用ルール。

---

## 10. 関連ドキュメント

- `050_pm_plan.md` — PM計画書（本タスクの位置づけ）
- `220_sentry_setup.md` — エラー監視
- `CLAUDE.md` — DB接続情報・本番デプロイ手順
- [Supabase Database Backups](https://supabase.com/docs/guides/platform/backups)
