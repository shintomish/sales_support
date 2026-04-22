# WSL + tmux で Claude Code マルチエージェント起動手順

## 1. WSLターミナルを開く
PowerShellまたはWindowsターミナルで：
```
wsl
```

## 2. tmuxセッション起動
```bash
cd ~
tmux new -s claude
```

## 3. 最初のペインでClaude起動
```bash
cd ~/sales_support
claude
```

## 4. ペイン分割
`Ctrl+b` → `%`（左右分割）

## 5. 右ペインでもClaude起動
```bash
cd ~/sales_support
claude
```

---

## よく使うtmuxショートカット

| 操作 | キー |
|------|------|
| 左右分割 | `Ctrl+b` → `%` |
| 上下分割 | `Ctrl+b` → `"` |
| ペイン移動 | `Ctrl+b` → `←→↑↓` |
| ペイン最大化/戻す | `Ctrl+b` → `z` |
| セッション切断（バックグラウンド継続） | `Ctrl+b` → `d` |
| セッション再接続 | `tmux attach -t claude` |
| ペインを閉じる | `Ctrl+b` → `x` |

## 次回以降の起動
```bash
wsl
tmux attach -t claude  # 既存セッションに再接続
```
