---
name: new-extraction
description: メール抽出ロジックの追加・修正手順（EmailExtractionService / ProjectMailScoringService）
---
# メール抽出ロジック追加スキル

## 概要
案件メール・技術者スキルメールから新しい項目を抽出する、または既存抽出の精度を改善するときの手順。

## いつ使うか
- 「〇〇を抽出できるようにして」と言われたとき
- 抽出結果が間違っている（誤検出・抜け漏れ）とき
- スキル判定・日付・場所・会社名の精度改善をするとき

## 関連ファイル一覧

| ファイル | 役割 |
|---------|------|
| `app/Services/EmailExtractionService.php` | 技術者スキルメールからの抽出 |
| `app/Services/EmailClassificationService.php` | メールの種別分類 |
| `app/Services/ProjectMailMatchingService.php` | 案件メールのマッチング |
| `app/Services/EmailMatchPreviewService.php` | マッチングプレビュー生成 |
| `app/Services/ClaudeService.php` | Claude APIの呼び出し共通処理 |
| `app/Models/Email.php` | メールモデル |
| `app/Models/ProjectMailSource.php` | 案件メールソース |

## 抽出ロジック追加の手順

### 1. 既存の抽出メソッドを確認
```bash
grep -n "function extract" app/Services/EmailExtractionService.php
```

### 2. 新しい抽出メソッドを追加
- `extractXxx(string $text): ?string` の形式で追加
- URLを除去してから処理（URL内の文字列誤検出防止）
- 正規表現は具体的なパターンから試す

### 3. Claude APIを使う場合
`ClaudeService::extractStructured()` を使う（プロンプトでJSONを要求する）

### 4. テスト方法
```bash
# ローカルでメール同期を実行して抽出結果を確認
docker compose exec app php artisan tinker
# >>> app(\App\Services\EmailExtractionService::class)->extractXxx("テストテキスト")
```

## よくある抽出バグのパターン

| バグ | 原因 | 対処 |
|-----|------|------|
| URL内の文字列を抽出してしまう | URL除去が先になっていない | 処理前に `preg_replace(URL_PATTERN, '', $text)` |
| 役職名が混入する | 正規表現の区切りが甘い | 単語境界 `\b` や行末 `$` を使う |
| 数字が誤って年として認識される | 範囲チェックなし | `$year >= 2020 && $year <= 2030` など |
| スキル名の誤検出（Go, C等） | 部分一致 | `skillFound()` で単語境界マッチ |

## 注意事項
- 無効なUTF-8バイト列はDB insertでエラーになる → `cleanUtf8()` を通す
- `extract*` 系はnullを返す場合があるので呼び出し側でnullチェック必須
