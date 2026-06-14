---
name: save-session
description: 現在の会話セッションを memory_engine の memory.db に保存して終了する
---
# セッション保存スキル

## 概要
今の会話セッションのトランスクリプト（jsonl）を memory_engine の `save_memory.py` に渡し、
チャンク化して `~/memory_engine/memory.db` に保存する。後日 `search_memory.py` で
semantic / 全文検索できるようになる。

## いつ使うか
- 「/save-session」と言われたとき
- セッションを終える前に、今回の議論・設計判断・トラブル対応を長期記憶に残したいとき

## 前提
- memory.db は Dropbox 共有。自宅↔職場は **同時に Docker/Claude を動かさない**運用なので、
  片側のみ稼働している前提で書き込む（同時起動は rescore 競合・API 二重消費の原因）。
- `migrate_int8.py` は **再実行禁止**（量子化済み DB を壊す）。このスキルは `save_memory.py` のみ使う。
- 保存は重複排除あり（chunk_hash UNIQUE）。同セッションを 2 回流しても新規分だけ増える。

## 手順
1. 現在セッションの transcript jsonl を特定する（このプロジェクトの最新更新ファイル）:
   ```bash
   ls -t /home/shintomi/.claude/projects/-home-shintomi-sales-support/*.jsonl | head -1
   ```
   ※ 進行中セッションが「最新更新」になる。複数を最近触っていれば日時で取り違えないこと。

2. その jsonl を project 名 `sales_support` で保存:
   ```bash
   cd /home/shintomi/memory_engine
   uv run python save_memory.py <上で得た jsonl のフルパス> sales_support
   ```
   出力の「保存完了: N 件」で新規保存数を確認する（重複はスキップされる）。

3. 単発のメモだけを残したい場合は `--text` モードも使える:
   ```bash
   cd /home/shintomi/memory_engine
   uv run python save_memory.py --text "残したい内容" sales_support
   ```

## 確認方法
```bash
cd /home/shintomi/memory_engine
uv run python search_memory.py "保存した内容のキーワード" --project sales_support
```

## 注意
- jsonl は `-rw-------`（本人のみ）。パスは環境依存なので、別環境では project slug
  ディレクトリ名（`-home-shintomi-sales-support`）が変わり得る点に注意。
- このスキル自体は git 管理（GitHub 経由同期）。Dropbox 同期対象は settings.json /
  statusline-command.sh のみなので、スキルの追加・変更は **commit & push しないと別環境に来ない**。
