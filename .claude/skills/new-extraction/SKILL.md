---
name: new-extraction
description: メールの抽出・分類・スコアリング・マッチングの追加修正手順（EmailExtractionService / EmailClassificationService / EngineerMailScoringService / ProjectMailScoringService / ProjectMailMatchingService）
---
# メール抽出ロジック追加スキル

## 概要
案件メール・技術者スキルメールから新しい項目を抽出する、または既存抽出の精度を改善するときの手順。メール分類（engineer/project）やスコアリングの修正もこのスキルで扱う。

## いつ使うか
- 「〇〇を抽出できるようにして」と言われたとき
- 抽出結果が間違っている（誤検出・抜け漏れ）とき
- スキル判定・日付・場所・会社名の精度改善をするとき
- **メール分類が間違っている**（案件メールが技術者と判定された／その逆）とき
- **スコアリングの閾値・配点を見直す**とき

## 関連ファイル一覧

| ファイル | 役割 |
|---------|------|
| `app/Services/EmailExtractionService.php` | 技術者スキルメールからの抽出 |
| `app/Services/EmailClassificationService.php` | メールの種別分類 |
| `app/Services/EngineerMailScoringService.php` | 技術者メールのスコア計算 |
| `app/Services/ProjectMailScoringService.php` | 案件メールのスコア計算 |
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

## 分類ルールを追加する場合

技術者紹介メールが project に誤分類されるなどの問題は、`EmailClassificationService` のキーワード未網羅が原因のことが多い。

1. `EmailClassificationService::ENGINEER_SUBJECT_KEYWORDS` / `ENGINEER_BODY_KEYWORDS` に該当フレーズを追加
2. `tests/Unit/Services/EmailClassificationServiceTest` に該当ケースを追加し engineer/project として判定されることを担保
3. 本番反映後、**ピンポイント再分類**で過去の誤分類を救済（全件 `reclassifyAll()` は事故リスクが高いので避ける）
   - 例: 追加したキーワードを ilike OR で絞り込み → category=null に更新 → `classifyPending()` で再分類
   - 必ず `whereNull('registered_at')` を併用して、登録済みメールには触れない

## よくある抽出バグのパターン

| バグ | 原因 | 対処 |
|-----|------|------|
| URL内の文字列を抽出してしまう | URL除去が先になっていない | 処理前に `preg_replace(URL_PATTERN, '', $text)` |
| 役職名が混入する | 正規表現の区切りが甘い | 単語境界 `\b` や行末 `$` を使う |
| 数字が誤って年として認識される | 範囲チェックなし | `$year >= 2020 && $year <= 2030` など |
| スキル名の誤検出（Go, C等） | 部分一致 | `skillFound()` で単語境界マッチ |
| 技術者紹介メールが project に分類される | キーワードリスト未網羅 | 本文の典型フレーズを `ENGINEER_BODY_KEYWORDS` に追加し Unit テストで担保 |

## 注意事項
- 無効なUTF-8バイト列はDB insertでエラーになる → `cleanUtf8()` を通す
- `extract*` 系はnullを返す場合があるので呼び出し側でnullチェック必須

## Gotchas（採点・再採点の破壊的な罠）
- **`rescoreAll` / `score()` は本文 purge 済みメールに破壊的**。`CleanupEmails` が classified_at 30日超の body_text/body_html を NULL化するため、本文が消えた古いメールを件名のみで再採点すると保存スコアが崩落する。`rescoreAll`/`rescoreAllShadow`/`score()` には「本文(text/html)とも空なら保存値を温存（再スコアしない）」ガードが入っている。**採点ロジックを触る時はこのガードを壊さないこと**（isExcluded は本文不要なので従来どおり先に判定）。
- **engineer の `no_unit_price` 除外は過剰になりやすい**。本文正規表現で単価が取れないと excluded になるが成功率が低い。是正済の方針: 本文に無ければ `parsed_skill_sheet_text` から救済 → それでも不明なら excluded ではなく **review（手動トリアージ）** に留める。`unit_price_too_low` のみ excluded。save / rescoreAll / rescoreAllShadow の **3箇所をヘルパー共通化**して divergence を防ぐ。
- **project 側に no_unit_price ロジックは無い**（status は score 基準のみ、domainBonus は -20..+20）。単価チェックが status に効くのは engineer のみ。
- **並び・鮮度は `received_at`(送信時刻) ではなく `arrived_at`(Kagoya 着信=INTERNALDATE)**。Kagoya 配送遅延が常態化（~数h）しているため、`save()` で EMS/PMS に `arrived_at = $email->arrived_at` をコピーし、一覧 `index()` は `orderByDesc('arrived_at')`。新規に並び・表示を足す時は arrived_at 基準に揃える。
- **全件再分類 `reclassifyAll()` / 全件 rescore は事故リスク大**。ピンポイント救済（ilike 絞り込み→対象のみ再処理）を優先。emails への一括 UPDATE/DELETE は statement timeout を避けるため LIMIT 200〜1000 のバッチループ必須。
