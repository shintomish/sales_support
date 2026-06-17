# 740 テスト観点カタログ — テスト観点を軸にした品質サイクル

> 出典の考え方: 「テスト観点を軸にした品質サイクルのすすめ」(Bitkey, 日下部聡久)
> https://zenn.dev/bitkey_dev/articles/test-viewpoint-cycle
>
> 本書は sales_support に散在していた品質知見（CLAUDE.md の制約・feedback メモリ・
> 監査スキル・既存テスト）を **再利用可能なテスト観点** として 1 枚に集約したもの。
> 「テストケースを消化する」のではなく「観点を育てる」運用へ移すための土台。

最終更新: 2026-06-17

---

## 0. この方式の考え方（二重ループ）

テスト観点カタログ（＝本書）を中心に 2 つのループを回す。

```
            ┌──────────── テスト観点カタログ (本書) ────────────┐
            │                                                  │
  [左ループ: テスト活動]                        [右ループ: QC活動]
   観点選定 → 設計 → 実行 → 記録                 分析 → 評価 → 改善 → カタログ更新
   (スプリント/PR 単位)                          (NG 発生時・四半期 audit)
            │                                                  │
            └──────────────────────────────────────────────────┘
```

- **左ループ**: 変更に対しリスクの高い観点を選び、テスト/レビューを設計・実行・記録する。
- **右ループ**: バグ（NG）や監査で「見落としていた観点」を言語化し、カタログへ追加する（§6）。
- カバレッジ 100% は狙わない。**リスク密度の高い観点に資源を集中**する（§3 の ★ 印）。

### 表記法

各観点は 3 要素で書く（記事の定義に準拠）。

| 要素 | 意味 | 例 |
|---|---|---|
| 機能要素 | 何をテストするか | 顧客一覧 index / 請求書 due_date 計算 |
| 検証アングル | どの視点か | テナント分離 / 境界値 / 異常系リトライ |
| パラメータ | どんな値を与えるか | 他テナントID / 締め日±1日 / HTTP 529 |

---

## 1. リスクドメイン一覧（CLAUDE.md「本質の番人」3軸との対応）

| ID | リスクドメイン | 対応する軸 | 一言 |
|---|---|---|---|
| D1 | テナント分離 / IDOR | 軸1(信用) | GlobalScope 突破は他社データ漏洩。最頻出・最重要 |
| D2 | 認証・認可・ロール | 軸1 | super_admin / tenant_admin / tenant_user の境界 |
| D3 | **中立性（自社/外部 完全中立 = score 順のみ）** | 軸2(中立) | affiliation がランキングに混入しないこと |
| D4 | 同期・防御制約 | 軸3(制約) | send_type 4箇所同期 / RLS+GRANT / emails LIMIT バッチ |
| D5 | メール取込の冪等性・重複・anchor | 軸3 | 二重登録防止・UID anchor 前進/後退・bounce stub |
| D6 | 入力バリデーション・境界値 | 軸1 | required / enum / 範囲 / 重複 |
| D7 | 外部API 異常系・リトライ | 軸1 | Claude/SES/Gmail の 429/529/400/失敗 |
| D8 | 破壊的操作の可逆性 | 軸1 | soft delete / body purge / cleanup の不可逆性 |
| D9 | 金額・帳票の計算正確性 | 軸1 | invoice due_date / 控除・超過 / 月次集計 |
| D10 | 検索 (ilike / pgroonga) | — | 大小文字・本文有無・全文索引 |

★ = 高リスク・優先観点（資源集中対象）

---

## 2. 観点カタログ本体

凡例: 既存テスト = ○ 十分 / △ 部分的 / ✗ 不在（§5 のギャップへ）

### D1 テナント分離 / IDOR ★
| 観点 (アングル × パラメータ) | 既存テスト |
|---|---|
| index は自テナントのみ返す（他テナント行は混ざらない） | ○ 各 Controller `only_returns_own_tenant_*` |
| show/update/destroy は他テナントIDで 404 | ○ 各 Controller `*_returns_404_for_other_tenant` |
| 集計/横断系は自テナントにスコープ | ○ `MonthlySalesAggregation::cross_tenant_scope` / `SesContractSummary` / `ProjectMailReextractAll` |
| 一括操作は他テナントに波及しない | ○ `mark_all_read_does_not_affect_other_tenants` / `reextract_all_does_not_affect` |
| 同一 message_id の返信が他テナント DSH に誤紐付けしない | ○ `KagoyaStoreRawMessageCrossTenant` |

### D2 認証・認可・ロール ★
| 観点 | 既存テスト |
|---|---|
| 認証必須エンドポイントは未認証で 401 | ○ 各 Controller `requires_authentication` |
| ロール別の閲覧範囲（super/admin/user） | ○ `AdminStats` / `UserCrud` |
| 自己昇格・自己ロール変更・自己削除の禁止 | ○ `UserCrud::self_role_change_is_forbidden` / `self_delete_is_forbidden` |
| 他テナントへのユーザー作成/更新/削除の禁止 | ○ `UserCrud::*_other_tenant` |

### D3 中立性（自社/外部 完全中立）★★ ← 最重要・回帰テスト不在
| 観点 | 既存テスト |
|---|---|
| マッチングスコアは skill/price/location/availability の加重和のみ | ○ `MatchingService::total_score_uses_weighted_sum`（構成要素を網羅）|
| **affiliation_type（自社/bp/個人）がスコア・並び順に影響しない** | ○ `MatchingService::score_is_identical_across_affiliation_types` / `recommend_engineers_ranks_by_score_not_affiliation`（G1 解決・ミューテーション検証済）|
| domain bonus / penalty が自己強化ループにならない | ✗ **G5** |

> 構造上は affiliation はスコア入力に含まれていない（＝現状は中立）。
> だが「将来うっかり混ぜる」変更を止める回帰テストが無い。軸2 の核心なので最優先で追加。

### D4 同期・防御制約 ★★
| 観点 | 既存テスト |
|---|---|
| send_type の提案スレッド系 whereIn が 4 箇所で一致（index/proposalThreads/ProjectMail::thread/EngineerMail::thread）| ○ DeliveryCampaign 定数に集約（PROJECT/ENGINEER → PROPOSAL_THREAD=和集合 → EXCLUDE=+self_reply）+ `DeliveryCampaignSendTypeSyncTest`（G2 解決・2026-06-17）|
| `self_reply` は exclude_proposals に含まれ「返信履歴」タブのみ表示 | ○ `EmailController::reply` + `DeliveryCampaign::excludes_proposals` |
| 新規テーブルに RLS 有効がある | ○ `RlsGrantAuditTest`（全 public テーブルの relrowsecurity を検査・G3 解決）|
| 新規テーブルに適切な GRANT (authenticated/service_role) がある | △ runtime 検査不可（test-postgres に Supabase ロール非存在）→ `rls-grant-audit` スキルで PR レビュー時に判断ベース監査 |
| emails 一括書き込みは LIMIT バッチで statement_timeout を回避 | △ **G4**（markAllRead のジョブ生成はテスト済、バッチ境界は未検証）|

### D5 メール取込の冪等性・重複・anchor ★
| 観点 | 既存テスト |
|---|---|
| 同一 message_id の再配送で二重登録しない | ○ `KagoyaStoreRawMessageDedup::同一_message_id` |
| IMAP 重複時に UID anchor が前進、古い UID では後退しない | ○ 同上 `uid_anchor_が前進` / `後退させない` |
| SES 経路の重複が既存 IMAP anchor を奪わない | ○ 同上 `ses_経路の重複` |
| 別テナントの同一 message_id は別行 | ○ 同上 `別テナント` |
| SES 取込の arrived_at は SES 受信時刻 / received_at は Date ヘッダー優先 | △ `InboundEmail::arrived_at`（Date ヘッダー優先の単体は別途）|
| 添付は part_index ベースで一括保存・同名衝突しない | ○ `InboundEmail::添付は_part_index` |
| 取込エンドポイントは共有シークレットを検証 | ○ `InboundEmail::共有シークレット` |
| bounce silent-drop でも category='bounce' の stub を必ず insert（再処理ループ防止）| ✗ **G6**（feedback メモリのみ）|

### D6 入力バリデーション・境界値 ★
| 観点 | 既存テスト |
|---|---|
| required 項目欠落で 422 | ○ 各 `store_requires_*` |
| enum 範囲外を拒否 | ○ `validates_*_enum`（status/gender/priority/category/affiliation）|
| 数値・日付の範囲（probability 0-100 / close_date > expected）| ○ `Deal::validates_probability_range` / `actual_close_date_after_expected` |
| 形式（email / phone / URL）拒否 | ○ `Customer` / `Contact` / `ProjectMail` |
| 重複拒否（会社名・deal+月の請求書・skill 名）| ○ `Customer::duplicate` / `Invoice::duplicate_for_same_deal_and_month` / `Matching::store_skill_rejects_duplicate` |
| 自テナント内での同名は許可（重複の境界）| ○ `Customer::allows_same_company_name_for_self` |

### D7 外部API 異常系・リトライ ★
| 観点 | 既存テスト |
|---|---|
| Claude 529/429/overloaded body はリトライ、3回で throw | ○ `ClaudeService::retries_on_529` / `429` / `overloaded` |
| Claude 400 はリトライしない | ○ `ClaudeService::does_not_retry_on_400` |
| 送信失敗・部分失敗の記録 | ○ `DeliveryCampaignService::records_failure` / `partial_failure` |
| バウンス DSN の hard/expired 分類と enforce 時の抑制 | ○ `BounceSuppressionService`（14 観点）|
| Gmail トークン無しで 422 | ○ `EmailController::sync_returns_422_when_no_gmail_token` |

### D8 破壊的操作の可逆性 ★
| 観点 | 既存テスト |
|---|---|
| destroy は soft delete（復元可能）| ○ 各 `destroy_soft_deletes_*` |
| 本文 purge 済みメールの再スコアで件名のみ崩落しない（空本文ガード）| ○ `MailScoringBodyPurgeGuard`（5 観点）|
| 発行済み請求書も回収目的で削除可 | ○ `Invoice::destroy_allows_issued_invoice_for_recovery` |
| CleanupEmails 30日 NULL化の境界（29日は残る/31日は消える）| ✗ **G7** |

### D9 金額・帳票の計算正確性 ★
| 観点 | 既存テスト |
|---|---|
| 請求書 due_date = 締め月末 + payment_site | ○ `Invoice::due_date_uses_end_of_billing_month_plus_payment_site` |
| 請求書番号が顧客×月で連番・月跨ぎでリセット | ○ `Invoice::increments_per_customer_and_month` / `resets_per_month` |
| 控除（基準時間未満）・超過（基準時間超）・null スキップ | ○ `BillingSummary::deduction` / `overtime` / `null_actual_hours` |
| 混在税率の合計再計算 | ○ `Invoice::recalculates_totals_with_mixed_tax_rates` |
| 契約期間の月内重複で月次集計に含める・複数月は各月グロス計上・冪等 | ○ `MonthlySalesAggregation`（5 観点）|
| 満了境界（当日・30日後を含む）| ○ `SesContractSummary::expiring_boundary` |

### D10 検索 ★
| 観点 | 既存テスト |
|---|---|
| 件名・名前・スキル ilike 大小文字非依存 | ○ `EmailSearch` / `EngineerSearch` / `MatchingSkillsSearch` 他 |
| 本文検索の有無で結果が変わる | ○ `EmailBodySearch`（body 有無で 2 観点）|

---

## 3. 既存テスト → 観点カバレッジ サマリ

- 全 35 テストファイル / 約 270 メソッドを 10 ドメインへマッピング済（§2）。
- **強い領域**: D1 テナント分離・D6 バリデーション・D7 外部API異常系・D9 金額計算（網羅的）。
- **弱い領域（ギャップ）**: D3 中立性・D4 同期/防御制約・一部 D5/D8（→ §5）。
- 観点が手動スキル頼みの領域: D4 GRANT（rls-grant-audit。RLS は自動化済）・D1 横断（idor-audit）・スコア変更影響（shadow-rescore）。これらは「自動回帰テスト化」が右ループの改善候補。

---

## 4. スプリント / PR 運用フロー（左ループ）

| フェーズ | ループ | やること |
|---|---|---|
| 計画・設計 | テスト | 変更が触るドメイン(§1)を特定 → そのドメインの ★ 観点を必ず選ぶ |
| 実行・記録 | テスト | 観点単位でテスト追加 or 監査スキル実行。PR 説明に「カバーした観点ID」を書く |
| 分析・評価 | QC | レビューで「未カバーの高リスク観点」を指摘（§5 と突き合わせ）|
| 改善・更新 | QC | NG が出たら §6 でカタログへ観点を追加 |

変更ドメイン別の最低ライン（迷ったらこれ）:
- 新 Controller / エンドポイント追加 → **D1 + D2 + D6** は必須。`idor-audit` スキルも回す。
- 新規 migration（テーブル作成）→ **D4 RLS/GRANT**。`rls-grant-audit` スキルを回す。
- ScoringService / MatchingService 変更 → **D3 中立性 + shadow-rescore** で status 遷移影響を定量化。
- メール取込・分類 変更 → **D5 冪等性**。`new-extraction` スキル手順。
- send_type 増減 → **D4 の 4箇所同期**（CLAUDE.md 明記の 4 箇所）。

---

## 5. ギャップ（高リスク・未ガード観点）と優先度

| ID | 観点 | ドメイン | 優先 | 提案テスト |
|---|---|---|---|---|
| ~~G1~~ | ~~affiliation_type がマッチング順位に影響しないこと~~ → **解決済 (2026-06-17)** | D3 中立性 | — | `tests/Unit/Services/MatchingServiceTest::test_calculate_score_is_identical_across_affiliation_types`（全8 enum で score 一致）+ `::test_recommend_engineers_ranks_by_score_not_affiliation`（所属でフィルタせず score 順）。自社+5点ボーナス注入で fail することをミューテーション検証済 |
| ~~G2~~ | ~~send_type 提案スレッド系 whereIn の 4 箇所一致~~ → **解決済 (2026-06-17)** | D4 同期 | — | DeliveryCampaign に4定数を集約し5箇所のインライン配列を置換（挙動不変）。`tests/Unit/Models/DeliveryCampaignSendTypeSyncTest` が派生関係（和集合・+self_reply・delivery非含有・サブセット非重複）と各コントローラの定数参照継続を検証 |
| ~~G3~~ | ~~新規テーブルに RLS 有効 + GRANT~~ → **RLS 解決済 (2026-06-17) / GRANT はスキル運用** | D4 防御 | — | `tests/Pgsql/Feature/RlsGrantAuditTest`: 全 public テーブルの `pg_class.relrowsecurity` を検査し RLS 無効を fail（framework テーブルは除外・ポジティブコントロール付きで非vacuous）。GRANT は test-postgres に Supabase ロールが無く runtime 検査不可のため `rls-grant-audit` スキルで継続 |
| G4 | markAllRead の LIMIT バッチ境界（200件超で複数バッチ）| D4 制約 | 中 | 250 件 unread を作り、ジョブ完走後に全件既読 + バッチが複数回回ることを assert |
| G5 | domain penalty が自己強化ループにならない | D3 中立性 | 中 | ペナルティ適用→再集計でペナルティが累積発散しないことを assert（GFD 事例の回帰）|
| G6 | bounce silent-drop でも stub insert される | D5 冪等性 | 中 | 取込不可メールでも category='bounce', is_read=true の stub が 1 行残ることを assert（再処理ループ防止アンカー）|
| G7 | CleanupEmails 30日 NULL化の境界 | D8 可逆性 | 低 | 29日前=本文残る / 31日前=NULL化 の境界テスト |

> 着手順の推奨: ~~G1~~（完了）→ ~~G2~~（完了）→ ~~G3 RLS~~（完了）。
> 高優先 G1/G2/G3 は対応済。残る G4〜G7（中〜低優先）は今後の右ループで順次。

---

## 6. NG 学習サイクル（右ループの運用ルール）

バグ（NG）や本番事故が出たら、**修正だけで終わらせず観点を 1 つ言語化**する。これがカタログ育成と個人成長を同時に進める核心。

手順:
1. **なぜ NG か掘り下げる** — 「どの観点があれば事前に防げたか」を一文で書く。
2. **観点を §2 の該当ドメインへ追加**（無ければギャップ §5 へ）。3 要素（機能要素 × アングル × パラメータ）で書く。
3. **回帰を残す** — 可能なら最小テストを追加。難しければ監査スキル or feedback メモリに退避。
4. **メモリ化** — 再発防止の判断根拠は auto-memory の `feedback_*` として保存（既存運用と統合）。

この運用は既に部分的に回っている（feedback メモリ群が観点の蓄積になっている）。本書はその出口を「カタログ」に一本化するもの。

過去の NG → 観点化の実例（参考）:
- env 汚染で誤送信 → 「送信系テストは MAIL_DELIVERY_TEST_TO を無効化する」観点 (D7)
- 架空のコード値を記述 → 「コード値は grep で実装検証してから書く」観点（本書 §5 検証にも適用）
- anti-join の Seq Scan 激遅 → 「whereNotExists の対側カラムに index」観点（性能観点 / docs 720 系）
- emails 全件 UPDATE で timeout → 「一括書き込みは LIMIT バッチ」観点 (D4 / G4)

---

## 7. メンテナンス

- 本書は四半期 audit（idor-audit / rls-grant-audit / quality-review-panel）の結果を取り込んで更新する。
- ギャップ §5 を消化したら ○ に更新し、新たに見つかった観点を追記する。
- 関連: docs/240（スモークテストチェックリスト）/ docs/730（品質レビュー）/ docs/720（性能ロードマップ）。
