# 運用セットアップ手順（CLAUDE.md から退避）

作業中ほぼ参照しない初回セットアップ系の手順を、毎セッションの固定トークン削減のため CLAUDE.md から分離。
必要になった時にこのファイルを開く。

## 本番初回セットアップ（または compose 構成変更時）
```bash
ssh root@v133-18-42-139.vir.kagoya.net
cd /var/www/sales_support
# 既存 docker-compose.yml が skip-worktree されている場合は解除
git update-index --no-skip-worktree docker-compose.yml
git pull origin main
# 本番固有設定を override.yml として配置（git 管理外）
cp docker-compose.override.yml.example docker-compose.override.yml
# マージ結果を確認してから反映
docker compose config | head
docker compose up -d
```

## 開発環境（職場・自宅 併用）
- 職場・自宅ともに WSL2 + Docker 環境
- コード共有: GitHub（git push/pull）
- `.env` 共有: 手動同期（gitには含めない）
- `memory.db` 共有: Dropbox経由でリアルタイム同期（職場・自宅ともにシンボリックリンク）
  - 実体: `/mnt/c/Users/<user>/Dropbox/Public/Book/03_Aizen/990_Sales_Support/memory_engine/memory.db`
  - 職場・自宅共通: `ln -s "/mnt/c/Users/<user>/Dropbox/.../memory_engine/memory.db" ~/memory_engine/memory.db`
  - `<user>` は WSL2 Windows ユーザー名（自宅: `NAKA-MINI` / 職場は別）
- Claude Code auto-memory 共有: 同じく Dropbox 経由で symlink 化
  - 実体: `/mnt/c/Users/<user>/Dropbox/Public/Book/03_Aizen/990_Sales_Support/claude_memory/`
  - 職場・自宅共通: `ln -s "/mnt/c/Users/<user>/Dropbox/.../claude_memory" ~/.claude/projects/-home-shintomi-sales-support/memory`
  - 既存 memory ディレクトリがある場合は `mv ... memory.bak.YYYYMMDD` で退避してから symlink を貼る
- Claude Code 設定 (`settings.json` / `statusline-command.sh`) 共有: Dropbox 経由 symlink (2026-05-23〜)
  - 実体: `/mnt/c/Users/<user>/Dropbox/Public/Book/03_Aizen/990_Sales_Support/.claude/{settings.json,statusline-command.sh}`
  - 職場・自宅共通: `ln -s "/mnt/c/Users/<user>/Dropbox/.../.claude/settings.json" ~/.claude/settings.json` （statusline-command.sh も同様）
  - 既存ファイルは `mv ... .bak.YYYYMMDD` で退避してから symlink を貼る
  - **注意**: 編集は即両環境に反映。環境別に分けたい場合は symlink を外す。Dropbox 同期遅延中の両環境同時編集はコンフリクトファイルを生むので片側編集に統一

## トラブルシュート

### 職場ローカルで全認証 API が 401（+ ブラウザ Invalid Refresh Token）— 2026-06-05
**症状**: ローカルで `/api/v1/me` 等すべて 401。フロントは `Invalid Refresh Token: Refresh Token Not Found`。ログイン(token 200)・リフレッシュ(200)は成功するのに 401。
**診断**: `storage/logs/audit-YYYY-MM-DD.log`（日次・`audit.log` ではない）に真因が出る。今回は `JWT decode failed {"err":"could not find driver (Connection: pgsql ... cache ...)"}`。`SupabaseAuth` ミドルウェアは詳細を audit チャネルへ出し、外向きは固定 401 を返すため nginx/アプリログだけでは誤診しやすい。
**真因**: app コンテナの PHP に `pdo_pgsql` が無い（`docker compose exec app php -r 'print_r(PDO::getAvailableDrivers());'` で pgsql 欠落）。`CACHE_STORE=database`(pgsql) の JWKS 取得がドライバ不在で例外 → 全認証 401。稼働イメージが pgsql 追加前の古いキャッシュから来ていた。
**緊急復旧（GitHub 不要・数分）**:
```bash
docker compose exec -u root -T app sh -c 'apt-get update -qq && apt-get install -y -qq libpq-dev && docker-php-ext-install pdo_pgsql pgsql'
docker compose exec -u root -T app sh -c 'kill -USR2 1'      # php-fpm reload
docker compose exec -T app php artisan cache:clear            # 古い JWKS/user cache 掃除
docker commit sales_support_app sales_support-app:latest      # イメージに焼き込み永続化
```

### 職場で docker build の composer install が timeout（再ビルド不能）— 2026-06-05
**症状**: `docker build` の `composer install` が `curl error 28 / SSL connection timeout`（codeload.github.com の大DL）で失敗。`api.github.com` の小応答は通るのに zipball 取得だけ固まる。`docker compose build` は exit 0 でもイメージを更新しない罠あり。
**真因**: 職場 VPN 経路 `eth3` の MTU=1420 なのに docker ブリッジ/コンテナが 1500 → NAT 越しに ICMP(frag-needed) が戻らず **PMTU ブラックホール**。大きい転送だけ落ちる（GitHub ブロックでもレート制限でもない＝トークン無関係）。`ip link show eth3` で 1420 を確認。
**恒久対策**: `docker-compose.override.yml`（git 管理外・職場限定）で network MTU を 1420 に固定。
```yaml
networks:
  sales_support_network:
    driver_opts:
      com.docker.network.driver.mtu: "1420"
```
`docker compose down && up -d` で網再作成。**ビルド時も 1420 に乗せる**には BuildKit が `--network <custom>` 非対応のため:
```bash
DOCKER_BUILDKIT=0 docker build --network sales_support_sales_support_network -t sales_support-app:latest .
docker compose up -d --force-recreate app
```
（自宅は VPN MTU が異なるため override は職場のみ。git 管理外で隔離される）

### Vercel Preview ビルドが `supabaseKey is required` で失敗 — 2026-06-05
**症状**: main(Production) は Ready なのに PR ブランチの Preview だけ Error。ログは `Error: supabaseKey is required.`（`src/lib/supabase.ts` のビルド時 createClient 評価）。
**真因**: `NEXT_PUBLIC_SUPABASE_URL/_ANON_KEY` が Vercel の **Production スコープのみ**で Preview スコープに無い。
**恒久対策（実施済み）**: `src/lib/supabase.ts` を Proxy で遅延初期化し、ビルド時のモジュール評価で createClient を呼ばないようにした（env 未設定でも `next build` が通る）。別解として Vercel 側で env を Preview スコープにも追加でも可。
