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
