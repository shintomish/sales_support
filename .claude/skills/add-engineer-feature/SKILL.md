---
name: add-engineer-feature
description: 技術者管理機能（Engineer/EngineerProfile/EngineerSkill）への機能追加手順
---
# 技術者機能追加スキル

## 概要
技術者管理機能に新しい項目・機能を追加するときの標準手順。モデル構成と関連ファイルを把握した上で実装する。

## いつ使うか
- 「技術者に〇〇を追加して」と言われたとき
- 技術者の検索・絞り込み条件を変更するとき
- スキルシート読込・ステータスフロー変更をするとき

## モデル構成

```
Engineer（技術者本体）
├── EngineerProfile（詳細プロフィール・稼働情報）
├── EngineerSkill（スキル一覧）
│    └── Skill（スキルマスタ）
├── Application（案件応募）
└── MatchingScore（マッチングスコア）
```

## 関連ファイル一覧

| ファイル | 役割 |
|---------|------|
| `app/Models/Engineer.php` | 技術者モデル |
| `app/Models/EngineerProfile.php` | プロフィール詳細 |
| `app/Models/EngineerSkill.php` | 技術者-スキル中間テーブル |
| `app/Models/Skill.php` | スキルマスタ |
| `app/Http/Controllers/Api/MatchingController.php` | マッチング処理 |
| `app/Services/MatchingService.php` | スコアリングロジック |

## 機能追加の手順

### 1. DBカラムを追加する場合
```bash
docker compose exec app php artisan make:migration add_xxx_to_engineers_table
docker compose exec app php artisan migrate
```

### 2. モデルに追加
- `$fillable` にカラムを追加
- `BelongsToTenant` trait が付いているか確認（テナント分離必須）

### 3. APIレスポンスに追加
- `app/Http/Resources/` に対応するResourceがあれば追加
- なければ `response()->json()` で直接返す

### 4. コントローラに処理を追加
- `app/Http/Controllers/Api/` 配下の該当コントローラに追加
- ミドルウェア: `supabase_auth`（認証）+ `SetTenantContext`（テナント分離）が必要

## 技術者ステータスフロー（把握しておくこと）
```
登録待ち → 稼働中 → 稼働終了予定 → 稼働終了
```

## 所属区分（8区分）
正社員 / 契約社員 / 業務委託 / 派遣 / フリーランス / 協力会社 / 試用期間 / その他

## 注意事項
- `tenant_id` はGlobalScopeで自動付与されるため手動セット不要
- 稼働可能日ソートはJOINをサブクエリにする（`tenant_id` 曖昧エラー回避）
- `migrate:fresh` は絶対に実行しない
