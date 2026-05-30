# sales_support 全体品質レビュー

## 概要

### Severity 別件数 (adjusted_severity ベース)

| Severity | 件数 |
|----------|------|
| Critical | 0 |
| High     | 4 |
| Medium   | 18 |
| Low      | 20 |
| **合計** | **42** |

備考: 元の severity ベースでは critical=1 / high=15 / medium=18 / low=8 だったが、確認時の調整 (adjusted_severity) によって critical→high に降格 (1件)、high→medium/low に降格 (12件) などが行われている。

### 観点 (dimension) 別件数

| Dimension | 件数 |
|-----------|------|
| be-security | 4 |
| be-correctness | 4 |
| be-performance | 5 |
| be-design | 5 |
| fe-correctness | 5 |
| fe-performance | 6 |
| fe-ux | 8 |
| consistency | 5 |
| **合計** | **42** |

---

## Critical

該当なし (adjusted_severity ベース)。

---

## High

### 1. Gmail OAuth callback の state がユーザーID直値で改ざん検証なし (アカウント乗っ取り / テナント越境)
- **file:line**: `app/Http/Controllers/Api/GmailOAuthController.php:43`
- **dimension**: be-security
- **description**: callback は state を user_id 整数のままで信用し、`User::find((int) $state)` で関連付けるユーザーを決めている。GmailService::getAuthUrl も `state = $userId` を生のまま付ける (`Services/GmailService.php:39`)。HMAC/nonce/CSRF トークンが無いため、攻撃者は被害者の user_id を当てて自分の Google アカウントを被害者のテナントに紐づける (GmailToken.tenant_id が被害者の tenant_id になる) ことができ、結果として受信メールの取り込みや既読化操作などが攻撃者のアカウントから行われ得る。callback は signed-URL でも CSRF middleware でもガードされていない (`routes/api.php:45`)。
- **suggestion**: state を `signature(user_id|nonce|exp)` の HMAC 形式 (sha256 + APP_KEY) にし、callback 側で署名検証 + nonce 使い捨てチェックを行う。少なくとも Cache に `oauth_state:{random}` → user_id を保存し、ランダム不可逆 token を state に使う。

### 2. EngineerMailScoringService::rescoreAll で no_unit_price / unit_price_too_low 除外が漏れる
- **file:line**: `app/Services/EngineerMailScoringService.php:188`
- **dimension**: be-correctness
- **description**: score() ルートでは save() (900-913行) が unit_price_min/max を見て 35万未満や記載なしを 'excluded' にする (CLAUDE.md「確定済み設計判断」)。しかし rescoreAll() は save() を経由せず `ems->update([...status...])` で直接 match(true) に流し込むため、価格による除外判定が完全にスキップされる。再スコア後、本来除外されるべき技術者が 'new' / 'review' として営業画面・マッチング候補に再浮上する。日次バッチで全件 rescore する設計なので影響範囲が広い。
- **suggestion**: rescoreAll() ループ内で extract() 後に save() と同じ `unit_price_min ?? unit_price_max` 判定を追加するか、save() を共通呼び出し化する (現状 update のキー集合が違うため、shared helper の抽出が安全)。

### 3. DealController::index の orWhereHas が AND 条件をすり抜ける (Customer/Email にも同パターン)
- **file:line**: `app/Http/Controllers/Api/DealController.php:38`
- **dimension**: be-correctness
- **description**: `when($search, fn($q,$s) => $q->where('title', ilike, X)->orWhereHas('customer', ...))` という書き方は外側の AND 条件 (deal_type='general', status, customer_id, amount_min/max) と OR で結合され、生成 SQL は実質 `(deal_type='general' AND title ilike X) OR (EXISTS customer match AND status=Y)` となる。結果、検索ワードが顧客名に当たれば deal_type / status / 金額レンジの絞り込みを跨いだ別種データが混ざる。同一パターンが `CustomerController::index 31-34行` (company_name OR industry の後に industry フィルタ) にも存在し、複数控除でテナント越境はないが意図しないレコードを返す。
- **suggestion**: 検索句を `$q->where(function($w) use($s){ $w->where('title','ilike',"%$s%")->orWhereHas(...); })` のようにクロージャでラップして AND グループを保つ。`EmailController::index` は同等パターンを既に正しくラップ済 (60-68行) なので参考にする。

### 4. 鮮度マッチング: 対照表 Promise.all が件数無制限で Claude API 呼出を爆発させる
- **file:line**: `/home/shintomi/sales_support_next/src/app/engineer-mails/[id]/page.tsx:1012`
- **dimension**: fe-performance
- **description**: useMatchFilter=ON のとき、過去 N 日の PMS items (上限なし、容易に 50〜200 件) を `Promise.all + axios.get('/requirement-match')` で同時実行する。`/requirement-match` は Claude API を叩く重いエンドポイントで、(a) ブラウザ→API 同時接続上限を食い潰し、(b) Claude API のレート/コストを線形で爆発させる。1.5K行上の matching/[id] の handleBatchMatch は『逐次・上限5件』にしてあるのと不整合。
- **suggestion**: items を `slice(0, 上限)` (例: 10〜20件) で切るか、p-limit 等で同時実行数を 3〜5 に制限する。可能なら BE 側に bulk endpoint を新設して 1 リクエストに集約。

### 5. メール選択の async race — 連続クリックで古いレスポンスが新しい選択を上書き
- **file:line**: `src/app/engineer-mails/page.tsx:417`
- **dimension**: fe-correctness
- **description**: handleSelect / handleSelectEmail が複数の `await axios.get` を直列発火しながら setSelected / setMatchedProjects / setThreadItems を更新する。ユーザがリスト上で別アイテムを素早くクリックすると、前のリクエストの response が後から到着して新しい選択を上書きする (詳細・マッチ案件・スレッドがバラバラの id 由来になる)。同じパターンが `src/app/emails/page.tsx の handleSelectEmail (line 189)`、`src/app/project-mails/page.tsx の handleSelect (line 286)`、`SelfMailsView.tsx openMail (line 186)` にもあり計 4 箇所。engineer-mails の matched_projects は「まとめて提案」モーダルの送信対象を構成するため、ズレが誤送信に直結し得る。
- **suggestion**: AbortController を選択ごとに作成し、handleSelect 冒頭で前の `controller.abort()` を呼ぶか、選択 id を ref に保存して response 到着時に「現在の選択 id と一致」を確認してから setState する。

---

## Medium

### 6. `exists:` バリデーションが TenantScope を無視し他テナント FK を許容 (cross-tenant IDOR)
- **file:line**: `app/Http/Controllers/Api/ContactController.php:95`
- **dimension**: be-security
- **description**: `'customer_id' => 'required|exists:customers,id'` はクエリビルダで直接 SQL を発行するため Eloquent の TenantScope が効かず、他テナントの customer_id を渡しても通る。BelongsToTenant が tenant_id を自動付与するので作成された Contact は自テナント扱いだが、customer_id は他テナントの実 ID を参照したまま登録される (他テナント顧客の存在/ID 列挙 + クロステナント連結データの注入)。同パターンが `ContactController.php:160`, `DealController.php:89/143`, `ActivityController.php:94-96/145-147`, `TaskController.php:106-107/157-158`, `EmailController.php:285-287` にも (まとめて 1 件)。
- **suggestion**: `Rule::exists('customers','id')->where('tenant_id', Auth::user()->tenant_id)` あるいは FormRequest 共通基底で TenantScope 相当の where 条件を必ず付けるヘルパを用意し、全 `exists:` 系を置換する。

### 7. 請求書 duplicate() の Carbon::createFromFormat('Y-m', ...) が月跨ぎでズレる
- **file:line**: `app/Http/Controllers/Api/InvoiceController.php:1101`
- **dimension**: be-correctness
- **description**: `Carbon::createFromFormat('Y-m', $invoice->year_month)` は日付欄を「今日の日」で補完するため、当日が28〜31日かつ対象月にその日が存在しない場合 (例: 今日5/30で year_month='2026-02') に翌月へロールオーバーする。直後の `addMonthNoOverflow()->format('Y-m')` は本来「翌月」を返すべきところを「翌々月」を返し、複写時の year_month が1ヶ月先にズレる。実機検証で `createFromFormat('Y-m','2026-02')` が '2026-03-02 06:52:21' を返すことを確認済み。同じ式が 1106 行にもあり、startOfMonth() で日は救えるが月のロールオーバーは救えない。
- **suggestion**: `Carbon::createFromFormat('!Y-m-d', $invoice->year_month . '-01')` (前置 ! で時刻もクリア) もしくは `Carbon::create((int)$y, (int)$m, 1)` で明示的に月初日として組み立てる。1106 行も同様修正。

### 8. MatchingService::recommendEngineers/Projects がレコメンド一覧表示のたびに N 件分の updateOrCreate を発行
- **file:line**: `app/Services/MatchingService.php:54`
- **dimension**: be-performance
- **description**: recommendEngineers / recommendProjects が候補ごとに calculate() を呼び、その中で `MatchingScore::updateOrCreate` を実行している。1 リクエスト = 候補件数 (テナント全 Engineer 数) 分の SELECT+UPDATE が走り、`/matching/projects/{id}/engineers` 等の GET エンドポイントが書き込み多発エンドポイントになっている。スコアキャッシュは表示用とは別ジョブで非同期化すべき。
- **suggestion**: calculate() からの永続化を切り離し、表示時は in-memory スコアのみ返す。永続化が必要ならバックグラウンドジョブ／バッチで upsert (`DB::table()->upsert`) に置換。最低でも GET 時の書込みは廃止。

### 9. MatchingService / ProjectMailMatchingService がテナント全 Engineer を毎回メモリに展開
- **file:line**: `app/Services/ProjectMailMatchingService.php:30`
- **dimension**: be-performance
- **description**: matchEngineers() / recommendEngineers() / recommendProjects() がテナント内 Engineer (またはオープン PublicProject) を engineerSkills.skill / profile と全件 with-load し PHP 側で sortByDesc->take する。Engineer 数が数百〜数千になると毎回 PHP 側で全件スコアリングが走り、メモリ・CPU・p99 が線形悪化する。鮮度マッチ側 (FreshMailMatchingService) のように事前 SQL 絞り込み (skill jsonb overlap / unit_price 範囲 / score floor / HARD_LIMIT) を持たない。
- **suggestion**: DB 側で (unit_price_max range, skill 重複, profile.is_public) で事前絞り込み + HARD_LIMIT を入れてから PHP スコアリングに渡す。中長期ではマッチングスコアを RescoreJob で事前計算し、表示時は MatchingScore テーブルから `ORDER BY score DESC LIMIT` で返す形に。

### 10. EngineerMailController::matchedProjects が顧客ごとに ProjectMailSource を個別クエリで取得 (N+1)
- **file:line**: `app/Http/Controllers/Api/EngineerMailController.php:316`
- **dimension**: be-performance
- **description**: `foreach ($customers as $cust) { ProjectMailSource::where('customer_name', $cust->name)->orderByDesc('received_at')->first(); }` で顧客ごとに 1 クエリ発生。さらに customer_name には index が無く orderByDesc(received_at) するため Seq Scan + Sort が顧客数回繰り返される。また 288 行目で PublicProject を `::with(['requiredSkills.skill','postedByCustomer.contacts'])->published()->open()->get()` でテナント内全件取得 (ページングなし) しており、案件数 / 顧客数増加で線形悪化する。
- **suggestion**: (1) 顧客名リストを集めて 1 本の SQL で取得する: `SELECT DISTINCT ON (customer_name) ... ORDER BY customer_name, received_at DESC`、または latest_pms_id を public_projects に集計列で持たせる。(2) project_mail_sources.customer_name + received_at の複合 index 追加。(3) PublicProject 一覧取得自体に paginate を入れ、テナント絞り込みを明示。

### 11. DashboardController::index が 5+ 本の COUNT/SUM を別クエリで発行 (1 本に集約可能)
- **file:line**: `app/Http/Controllers/Api/DashboardController.php:31`
- **dimension**: be-performance
- **description**: kpi 構築で Customer::count / Deal::whereNotIn->count / Deal where status='成約' whereMonth->count / 同じ条件で sum / Deal::count と 5 本のクエリを直列発行。さらに pipeline / wonDeals / monthlyRevenue / upcomingTasks / recentActivities まで含めると 1 リクエストで 10+ クエリ。whereMonth/whereYear は EXTRACT() 化されるため deals(updated_at) があっても index が効かない。ダッシュボードは access 頻度が高くキャッシュもされていない。
- **suggestion**: kpi は `SELECT SUM(CASE WHEN ...) ... FROM deals` で 1 本に集約。won_this_month/revenue_this_month は `updated_at >= startOfMonth` の範囲条件にして index 活用。レスポンス全体を `Cache::remember` で 30-60 秒キャッシュ (kpi セクションだけでも良い)。

### 12. 提案メール送信ロジックが 6 箇所のコントローラ method に重複コピペされている
- **file:line**: `app/Http/Controllers/Api/ProjectMailController.php:404`
- **dimension**: be-design
- **description**: DeliveryCampaign 作成 → Mail::send → DeliverySendHistory 作成 → success/failed count 更新 → ログ出力という 50〜80 行の同一手続きが `ProjectMailController::sendProposal(L404)/sendBulk(L486)/sendProposalFromEms(L768)`、`EngineerMailController::sendProposal(L703)/sendBulkToBp(L943)`、`MatchingController::sendProposal(L291)` に丸ごとコピペされている。send_type 文字列と engineer_id/project_id/engineer_mail_source_id の有無だけが差分。既に `DeliveryCampaignService::sendCampaign(L71)` があるにも関わらずバイパスされており、変更時に 6 箇所同期が必要で実害が出ている (CLAUDE.md でも whereIn 同期 4 箇所と注記済み)。
- **suggestion**: DeliveryCampaignService に `sendSingleProposal($context)/sendBulkProposals(...)` を追加し、send_type と紐づけ ID (project_mail_id/engineer_mail_source_id/engineer_id/public_project_id) を context として受け取って一本化。各 controller は service 呼び出し+レスポンス整形のみに薄くする。

### 13. InvoiceController storeEstimate / storePurchaseOrder / duplicate がトランザクション無しで Invoice + InvoiceLine + 関連更新を行っている
- **file:line**: `app/Http/Controllers/Api/InvoiceController.php:204`
- **dimension**: be-design
- **description**: store() は InvoiceCreationService 経由で DB::transaction されているが、storeEstimate (L204 Invoice::create → L260 InvoiceLine::create → L275 ses_contract->save → L280 invoice->save) と storePurchaseOrder (L364〜427)、duplicate (L1142〜1160) は素のまま並べられている。InvoiceLine 作成や quote_number フィードバックが失敗すると、合計金額 0 の Invoice ヘッダだけ残るゴミデータができ、後段の recalcAmounts/save も中途半端な状態で commit される。同レイヤ内で書き込みの整合性が方針バラバラ。
- **suggestion**: estimate/purchase_order/duplicate を InvoiceCreationService に集約 (createEstimate/createPurchaseOrder/duplicateFromExisting) し、全パスを DB::transaction で囲む。controller は validate と DTO 化のみ担当。

### 14. Anthropic API 直叩きが 5 サービスに散在しており ClaudeService の存在意義が崩壊
- **file:line**: `app/Services/ClaudeService.php:29`
- **dimension**: be-design
- **description**: `ClaudeService::ask (L29)` と `generateProposal (L91)` はモデルをハードコード ('claude-haiku-4-5-20251001') しており、CLAUDE.md が定める `config('services.anthropic.model')` 経由ルールから外れている。さらに `EmailExtractionService (L283)/MatchingService (L177)/RefinitivPoParserService (L69)` が ClaudeService を経由せず anthropic-version/x-api-key/Http::post を独自に書き、ClaudeService::postWithRetry のリトライ・overloaded 判定の恩恵を一切受けていない。モデル切替や Rate Limit 対応の度に複数箇所同期が必要。
- **suggestion**: ClaudeService に `sendMessages(model, payload, timeout, withRetry)` を公開 API として一本化し、postWithRetry を全 caller に強制する。ハードコード model も config 経由に統一。

### 15. Office ファイル (PDF/XLSX/DOCX) テキスト抽出ロジックが 3 箇所に重複実装されている
- **file:line**: `app/Http/Controllers/Api/EngineerController.php:497`
- **dimension**: be-design
- **description**: SkillSheetTextExtractor (`app/Services/SkillSheetTextExtractor.php:23`) が既にあるのに、`EngineerController::extractTextFromFile(L497-553)` と `EngineerMailScoringService::extractTextFromTempFile(L465-528)` が同じ Smalot\PdfParser / PhpSpreadsheet / PhpWord の組み合わせを独立に書いている。さらに extractWordElementText の再帰実装も `EngineerController(L564)` と `EngineerMailScoringService(L530)` に 2 重存在。Excel の memory_limit 引き上げ・行数キャップ (300/200/なし)・サイズ閾値 (1MB/3MB/なし) 等の防御策が片方にしか無い場合があり、OOM 対策の更新漏れが発生しやすい。
- **suggestion**: SkillSheetTextExtractor を UploadedFile / tempPath / EmailAttachment いずれも受けられる入力アダプタ付きで拡張し、3 箇所すべて service に集約。

### 16. ポーリング setTimeout の id を保持せず unmount 後も走る
- **file:line**: `src/app/emails/page.tsx:138`
- **dimension**: fe-correctness
- **description**: pollMarkReadStatus が `setTimeout(pollMarkReadStatus, 3000)` で再帰しているが timer id を保存していないため、ユーザーがページを離脱しても 3秒ごとに `/mark-read-status` を叩き続け、setMarkingAllRead/setSyncMessage を unmount 後の component に発火する。同じ構造が `project-mails (line 380/413)` と `engineer-mails (line 637/670)` の pollRescoreStatus にもある。React の state warning + 無駄な API コール + 完了時に意図しない `emails:mark-all-read` event dispatch までついてくる。
- **suggestion**: useRef でタイマー id を持ち、useEffect の cleanup で clearTimeout する。または「mounted フラグ」を useRef で持って、レスポンス到着時に mounted=false なら setState/再 setTimeout を抑止する。

### 17. Sidebar: useNotifications/useUnreadEmailCount の Realtime チャネルが tenant 横断
- **file:line**: `src/hooks/useUnreadEmailCount.ts:29`
- **dimension**: fe-correctness
- **description**: supabase channel 名が固定 ('sidebar-emails-unread' / 'notifications-tasks') かつ postgres_changes に `filter: tenant_id=eq.X` を指定していないため、同じ Supabase プロジェクト上の全テナントの emails INSERT / tasks UPDATE で fetchUnreadCount / fetch が発火する。SaaS のテナント数が増えるほど無駄な /unread-count / /notifications コールが指数的に増える (取得結果は RLS でフィルタされるので画面表示は壊れないが、本番 API 負荷の根原因になる)。
- **suggestion**: useRealtimeNotifications.ts と同じく user.tenant_id を取り `channel(\`emails-unread:${tenant_id}\`)` と `filter: tenant_id=eq.${tenant_id}` を付ける。

### 18. マスター選択ドロップダウンが page=1 (最大 50 件) で打切り
- **file:line**: `src/app/activities/create/page.tsx:47`
- **dimension**: fe-correctness
- **description**: activities/contacts/deals/tasks の create + edit 計 9 画面で `apiClient.get('/api/v1/customers', { params: { page: 1 } })` 等を使用しているが、CustomerController#index のデフォルト per_page は 50 (ContactController/DealController は paginate(20) ハードコードでさらに厳しい)。顧客数が 50 を超えるテナントでは 51 件目以降が顧客 select に出ず、契約相手として選べない / 編集画面で既存の customer_id が未表示になる。同パターンが `/api/v1/contacts /api/v1/deals` でも全 page=1 のため、子マスターも欠落する。
- **suggestion**: page=1 と一緒に per_page=500 (Customer Controller の max) を必ず付ける、もしくは検索付きの async combobox (estimates/purchase-orders で既に使用) に統一する。
 
### 19. SWR/React Query 未導入 — 全 list ページが毎回 fetch (cache 無し)
- **file:line**: `/home/shintomi/sales_support_next/src/lib/axios.ts:1`
- **dimension**: fe-performance
- **description**: src 配下に useSWR / react-query / @tanstack の import が 1 件も無く、全 list ページが useEffect + axios.get でマウントごとに再取得し、戻る/タブ切替でも cache を効かせられない (package.json には @tanstack/react-query が依存に入っているが未使用)。docs/720 roadmap でも /delivery-campaigns・/project-mails・/emails・/ses-contracts 等で SWR 化を改善策として複数回挙げており、フロント側の主要パフォーマンスボトルネック。
- **suggestion**: swr or @tanstack/react-query を導入し、まず /emails・/project-mails・/engineer-mails・/proposal-threads・/delivery-campaigns の一覧 fetch を `useSWR(key, fetcher, { staleTime: 30s, keepPreviousData: true })` に置換する (roadmap §Quick Wins / §Medium と同方針)。

### 20. ses-contracts: 同一エンドポイントを 2 回 (本体 + per_page=200) + summary を毎フィルタ変更で 3 並列
- **file:line**: `/home/shintomi/sales_support_next/src/app/ses-contracts/page.tsx:325`
- **dimension**: fe-performance
- **description**: fetchData が `/ses-contracts` (本体 page 50件) と `/ses-contracts` (allRes per_page=200) と `/ses-contracts/summary` を毎回並列で打つ。依存配列が [search, statusFilter, includeExpired, page, userFilter, sortField, sortOrder] と広く、ページ移動/ソート切替ごとに per_page=200 の重い anti-window fetch が走る。docs/720 roadmap §/ses-contracts でも 3 連打が指摘されている。
- **suggestion**: allRes (集計用) は page/sort 変更時に再 fetch せず、フィルタ条件 [search,statusFilter,includeExpired,userFilter] のみを依存とする別 useEffect に分離。/summary は SWR 化して staleTime 60s で重複排除し、可能なら BE で集計エンドポイントに統合する。

### 21. 却下理由入力に native prompt() を使用 (UX不適切・ブラウザにより無効化される)
- **file:line**: `src/app/invoices/page.tsx:164`
- **dimension**: fe-ux
- **description**: 請求書/注文書/請求書詳細の3箇所 (`invoices/page.tsx:164, purchase-orders/page.tsx:263, invoices/[id]/page.tsx:630`) で却下理由を `prompt()` で入力させている。native prompt は (1) Chrome の cross-origin iframe で既にブロック対象、(2) 改行入力不可、(3) 必須/最大文字数バリデーション不可、(4) 業務系SaaSとしての見栄えが悪い。コメントは監査ログに残るため業務上重要。
- **suggestion**: 却下理由入力用の自前モーダル (textarea + 必須バリデーション + 最大文字数表示) を作って差し替える。3箇所共通の RejectionModal コンポーネントとして components/ に置く。

### 22. 情報抽出ボタンが破壊的・課金ありなのに確認ダイアログなし
- **file:line**: `src/app/project-mails/page.tsx:420`
- **dimension**: fe-ux
- **description**: `handleReextractAll` (`project-mails:420`、ボタンは `:578/663`) は全件ループで抽出を実行する処理 (`/reextract-all` を offset 進めて呼び続ける) にも関わらず、隣の「全件再スコア」(`:394`) と違い `confirm()` ダイアログがない。意図せずクリックで抽出情報が自動上書きされ手動編集が失われるリスク (※ extract は regex 実装で Claude API は呼ばないため "課金リスク" 部分は誤り。手動編集上書き/隣接ボタンとの不整合が本質)。
- **suggestion**: handleReextractAll の先頭で『情報抽出を全件再実行します。手動編集内容が上書きされます。よろしいですか？』の confirm を追加。理想は再スコアと同じバッチジョブ化＋進捗バー化。

### 23. 技術者個別削除に エラーハンドリングと in-flight 状態が両方なし
- **file:line**: `src/app/engineers/[id]/page.tsx:334`
- **dimension**: fe-ux
- **description**: `handleDelete` は `apiClient.delete` を await しているが try/catch がなく、失敗時に黙って fall through → `router.push` してしまう (ユーザは『削除された』と誤認)。さらに busy state がないため二度押しで2回 DELETE が飛び、2回目は 404 で uncaught → UI が壊れる可能性。
- **suggestion**: try/catch で失敗時 Toast 表示、削除中は `deleting` state で button disabled に。成功遷移は `await` 完了後に。

### 24. 97件の alert() / 40+ 件の confirm() — 業務SaaS として UX が劣化、A11y/Toast 既存なのに未活用
- **file:line**: `src/app/business-cards/page.tsx:289`
- **dimension**: fe-ux
- **description**: 業務系の成功/失敗通知が `alert()` で実装されている箇所が 97 件 (`business-cards:289, activities:122, tasks:134, customers, settings/* など`)、削除/承認/再送など破壊的操作の確認も native `confirm()` が 40+ 件。既に Toast コンポーネント (`components/Toast.tsx`) が存在し `invoices/page.tsx` 等で利用しているのに横展開されていない。native dialog は (1) iframe 内では消える/ブロックされる、(2) ブラウザ翻訳が割り込み、(3) スタイルが OS 依存で業務感が損なわれる。
- **suggestion**: Toast を全画面共通の context provider 化し、`useToast()` で alert を全置換。confirm については 削除/承認/送信など分類別の ConfirmDialog コンポーネントを 1 本作って差し替える。一気にやらず matching/deliveries/invoices など『送信系』から優先。

### 25. engineers?source=mail は FE 選択肢に存在するが BE が黙殺 (match default => null)
- **file:line**: `app/Http/Controllers/Api/EngineerController.php:109`
- **dimension**: consistency
- **description**: FE `/engineers` ページの区分フィルタには 'メール登録' (value='mail') があるが (`engineers/page.tsx L159`)、BE の match 文は 'self' と 'bp' しかケースを持たず default => null。'mail' を選んでも一切絞り込まれず、ユーザは『全件』と同じ結果を『メール登録分だけ』と誤認する。バグ報告として上がりにくい類の不整合。
- **suggestion**: BE 側に `'mail' => $query->whereNotNull('engineer_mail_source_id')` を追加するか、FE の選択肢を 'self' / 'bp' に限定する。EngineerMailController::registerEngineer が engineer_mail_source_id を埋めていない点も併せて要修正 (L241-246)。

### 26. EngineerController index の source='bp' は engineer_mail_source_id IS NULL を要求するが、メール由来 Engineer はそのカラムを埋めない
- **file:line**: `app/Http/Controllers/Api/EngineerController.php:112`
- **dimension**: consistency
- **description**: `source=bp` は `affiliation_type != self` かつ `engineer_mail_source_id IS NULL` を AND。しかし `EngineerMailController::registerEngineer (L240-263)` で Engineer を作る際に engineer_mail_source_id を一切セットしていないため、メールから登録した BP 技術者は問答無用で source=bp に含まれる。一方で 'メール登録' (source=mail) は default で何もしない (上の項目)。3 区分が排他にならないので、絞り込み結果が UI 上の意図と乖離する。
- **suggestion**: registerEngineer で `'engineer_mail_source_id' => $ems->id` を渡し、bp/mail の区別を実体化する。または分類軸を affiliation_type のみに統一して source フィルタを再設計する。

---

## Low

### 27. ApplicationController::sendMessage の receiver_user_id が他テナント宛で通る
- **file:line**: `app/Http/Controllers/Api/ApplicationController.php:178`
- **dimension**: be-security
- **description**: `'receiver_user_id' => 'required|integer|exists:users,id'` はテナント絞り込みなし。攻撃者は他テナントの user_id を受信者に指定して Message を作成でき、`file_path` (max:500 の任意文字列) も検証なしで保存される。Message 自体は BelongsToTenant で自テナントに付くため受信側からは読まれないものの、users.id の存在列挙と将来の連携機能 (通知 / 監査ログ流出) で被害が拡大しやすい。
- **suggestion**: `Rule::exists('users','id')->where('tenant_id', $tenantId)` で受信者をテナント内に制限。`file_path` はサーバ側で発行したトークン (UUID + Storage path) しか受け付けないように変更。

### 28. JWT 検証失敗時に内部例外メッセージをそのままレスポンスへ返却
- **file:line**: `app/Http/Middleware/SupabaseAuth.php:62`
- **dimension**: be-security
- **description**: `return response()->json(["message" => "Token invalid: " . $e->getMessage()], 401);` で firebase/php-jwt が出す具体的な失敗理由 ("Expired token", "Signature verification failed", "kid invalid" 等) を呼び出し元に晒している。攻撃者の JWT 偽造試行を助ける情報源になる。
- **suggestion**: 外向きメッセージは `"Unauthenticated."` 等の固定文言にし、例外詳細は `Log::warning('JWT decode failed', ['err' => $e->getMessage()])` で audit/storage に閉じる。

### 29. EngineerMailScoringService::isExcluded のドメイン判定が部分一致で過剰除外
- **file:line**: `app/Services/EngineerMailScoringService.php:278`
- **dimension**: be-correctness
- **description**: EXCLUDE_DOMAIN=['aizen-sol.co.jp'] に対し `str_contains(strtolower($from), $domain)` を使っているため、from が `foo@evil-aizen-sol.co.jp.attacker.tld` のように 'aizen-sol.co.jp' を文字列として含むだけで一律 excluded になる。`ProjectMailScoringService 878 行` は `str_ends_with($from, '@'.$domain)` で正しくドメイン末尾一致しているので、技術者メール側だけ整合性が崩れている。
- **suggestion**: ProjectMailScoringService と揃え `str_ends_with(strtolower($from), '@'.$domain)` で末尾一致に直す。アドレスは小文字化済みなので追加コストなし。

### 30. PublicProjectController::index が favoriteByUsers を全件 eager load
- **file:line**: `app/Http/Controllers/Api/PublicProjectController.php:61`
- **dimension**: be-performance
- **description**: `with('favoriteByUsers')` で各案件のお気に入りユーザー全件を読み出し、PHP 側で `where('user_id', $userId)` して isNotEmpty() のみ判定している。テナントのユーザー数 × お気に入り案件数だけ FavoriteProject 行がペイロードに乗る。
- **suggestion**: `withExists(['favoriteByUsers as is_favorite' => fn($q) => $q->where('user_id', $userId)])` もしくは withCount で 1 sub-query 化。formatProject 内の collection 走査を廃止しペイロードからも除外。

### 31. EngineerMailController::matchedProjects が matching ロジックを controller 内に丸抱えしている
- **file:line**: `app/Http/Controllers/Api/EngineerMailController.php:278`
- **dimension**: be-design
- **description**: `ProjectMailController::matchedEngineers(L672)` は ProjectMailMatchingService::matchEngineers に薄く委譲しているのに対し、`EngineerMailController::matchedProjects(L278-391)` は『EMS スキル小文字化 → 全 PublicProject 取得 → project_mail_source_id / posted_by_customer_id 経由の 2 系統 PMS prefetch → スコア計算 → 単価フィルタ』を全部 controller に書き下している。マッチング 4 サービス (MatchingService/ProjectMailMatching/EngineerMailMatching/FreshMailMatching) の責務境界も合わせて整理対象。
- **suggestion**: matchedProjects のロジックを `EngineerMailMatchingService::matchProjects(EngineerMailSource, limit)` として切り出し、controller は service 呼び出し + JSON 整形のみにする。

### 32. KagoyaMailService::storeRawMessage がパース・分類・保存・Storage IO・返信紐付けを 1 つの 190 行メソッドに詰め込んでいる
- **file:line**: `app/Services/KagoyaMailService.php:121`
- **dimension**: be-design
- **description**: `storeRawMessage(L121-314)` はヘッダ解析・bounce判定・self判定・body parse・Email::create・添付 Storage アップロード (外部 IO)・In-Reply-To マッチング・campaign の replied_count インクリメントを 1 メソッドで処理している。Email::create / EmailAttachment::create / Storage アップロード / DeliverySendHistory.update / DeliveryCampaign.increment がトランザクション外で順次走るため、Storage アップロード途中で例外が出ると Email 行は残るが添付 storage_path が空、または返信紐付けだけ未完了といった部分成功状態が発生する。
- **suggestion**: (a) bounce/self 判定を私的 helper or BounceDetector に切り出す、(b) 添付保存を AttachmentIngestor service へ、(c) 返信紐付けを ReplyLinker service へ分割し、Email::create と添付メタの create を DB::transaction で囲って Storage アップロードはトランザクション外 (失敗時は再試行ジョブ) に振る。

### 33. beforeunload で returnValue 未設定 — 未保存警告が出ない (6 ファイル)
- **file:line**: `src/app/contacts/[id]/edit/page.tsx:65`
- **dimension**: fe-correctness
- **description**: isDirty 時の beforeunload ハンドラが `e.preventDefault()` のみで `e.returnValue = ''` を設定していない。現行の主要ブラウザは preventDefault 単独で離脱確認が出るものもあるが、Safari 互換と防御的記述の観点で改善余地あり。customers/contacts/deals/tasks/activities/business-cards の 6 つの edit ページで同じパターン。
- **suggestion**: `const handler = (e: BeforeUnloadEvent) => { e.preventDefault(); e.returnValue = ''; }` に書き換える (6 ファイルで同様の修正が必要)。

### 34. matching/[id]: matchedEmsIds / visibleEngineers などを毎 render 再生成し子へ伝播
- **file:line**: `/home/shintomi/sales_support_next/src/app/matching/[id]/page.tsx:1680`
- **dimension**: fe-performance
- **description**: `matchedEmsIds = new Set(batchProgress.filter(...).map(...))` (L1680)、`visibleEngineers = engineers.filter(...)` (L1704)、`selectableFreshItems = freshItems.filter(...)` (L1729) がすべて useMemo 無しで毎 render 再計算され、Set/配列の参照が毎回変わる。matchedEmsIds は FreshEmsList の prop として渡されるため、無関係な state 変更でも FreshEmsList 全体 (最大 200 件の table/card) が再 render される。
- **suggestion**: matchedEmsIds / visibleEngineers / selectableFreshItems を useMemo で依存配列に縛る。さらに FreshEmsList を memo() でラップする。

### 35. FreshEmsList: items のソートをコンポーネント body で毎 render 実行
- **file:line**: `/home/shintomi/sales_support_next/src/app/matching/[id]/page.tsx:1435`
- **dimension**: fe-performance
- **description**: `const sorted = [...items].sort((a, b) => b.score - a.score)` を関数 body 直下で毎 render 走らせている (FreshEmsList: line 1435)。親の参照変化で頻繁に再 render されるため、items が数十〜数百件のとき毎回 O(n log n) コピー + ソートを払う。
- **suggestion**: sorted を `useMemo(() => [...items].sort(...), [items])` で memo 化。可能ならソート済みを上位で 1 度だけ計算して props 化する。

### 36. engineer-mails/project-mails: handleSelect が詳細/マッチ/スレッドを逐次 await
- **file:line**: `/home/shintomi/sales_support_next/src/app/engineer-mails/page.tsx:417`
- **dimension**: fe-performance
- **description**: engineer-mails/page.tsx の handleSelect は `axios.get(detail)` → `axios.get(matched-projects)` → `axios.get(thread)` を順番に await している (L425/L437/L445)。matched-projects と thread は互いに独立なので Promise.all 化で 1 RTT 削減可能。project-mails/page.tsx:286 にも同じ直列 pattern が存在。
- **suggestion**: detail を先に await して画面遷移後、matched-projects と thread を Promise.all で並列化するか、各 finally で個別 setState して逐次描画する。

### 37. deliveries(送信タブ): 案件メール 100件 + 技術者メール 200件 を初期ロードし select でクライアント filter
- **file:line**: `/home/shintomi/sales_support_next/src/app/deliveries/page.tsx:723`
- **dimension**: fe-performance
- **description**: tab='send' に切り替わるたびに `/project-mails per_page=100` と `/engineer-mails per_page=200` を取得し、ユーザー入力 (pmSearch) は L2163/L2227 でフロント側 .filter().map() による `<option>` 全件描画で行っている。配信種別を切替えるたびに `<select>` が 200 option を再構築し、検索キーストロークごとに 200 件 lower()/includes() が走る。将来件数増で線形劣化。
- **suggestion**: select を Combobox 化し、ユーザー入力 (pmSearch) で `/project-mails?search=&per_page=20` のサーバー側 search に置換 (debounce 300ms)。少なくとも options の生成は useMemo([projectMails, pmSearch]) で memo 化する。

### 38. 案件メール 個別 再スコア ボタンに in-flight 制御なし (二重送信可)
- **file:line**: `src/app/project-mails/page.tsx:897`
- **dimension**: fe-ux
- **description**: 右ペインの個別『再スコア』ボタン (`project-mails:897`) は `handleRescore` (444行) を呼ぶが、ローカル loading state がなく button も disabled にしない。Claude API を叩く処理を連打で同時起動できる。失敗時のフィードバックも saveMsg にエラー表示するだけでスクロール位置によっては見えない。
- **suggestion**: `const [rescoringOne, setRescoringOne] = useState(false)` を追加し finally でリセット、button に `disabled={rescoringOne}` と spinner を付ける。完了時は Toast で feedback。

### 39. ユーザー招待再送ボタン に二重送信防止がない
- **file:line**: `src/app/admin/users/page.tsx:218`
- **dimension**: fe-ux
- **description**: 招待再送ボタンは confirm → POST `/users/{id}/resend-invite` → alert で完了通知するが、リクエスト中の disabled 制御がない。Supabase Auth の招待メールはレート制限があり、連打すると2通目以降が 429 で失敗するだけでなく、ユーザにも誤認招待メールが2通届く可能性がある。
- **suggestion**: `resendingId: number | null` state を持ち、対象行のみ disabled に。完了/失敗フィードバックは alert ではなく既存の Toast コンポーネントに置換。

### 40. マッチング/技術者メール詳細が inline style + メディアクエリ無しでモバイル崩れ
- **file:line**: `src/app/matching/[id]/page.tsx:1444`
- **dimension**: fe-ux
- **description**: matching/[id]/page.tsx (2237行) と engineer-mails/[id]/page.tsx (1432行) は 99% inline style で組まれ、Tailwind の md:/sm: も @media も使われていない。freshMode の list 表示は 10カラムの table を `overflow: hidden` の親に入れているため SP で列が切れる、ProposalModal/BulkSendModal は maxWidth: 560〜620 + 100% で SP では狭く textarea が触りにくい、ボタン群は flexWrap で行数が爆発し本文が見えない。営業現場での SP 利用 (5/25 打合せで言及) では実用に支障。
- **suggestion**: list ビューは SP で card ビューに自動フォールバック (`window.matchMedia('(max-width: 768px)')`)、モーダルは `max-w-[95vw]` + 上下 padding を縮める。中期的には Tailwind class への置換を検討。

### 41. AuthController::login / logout は Sanctum ベースで Supabase 認証と矛盾 (デッドコードかつ仕様の罠)
- **file:line**: `app/Http/Controllers/Api/AuthController.php:12`
- **dimension**: consistency
- **description**: `POST /api/v1/login` が `Auth::attempt()` と `createToken()` (Sanctum) で実装されているが、他の全エンドポイントは middleware 'supabase.auth' で Supabase JWT を検証している。FE (authStore.login) は `supabase.auth.signInWithPassword` を使い `/login` は呼ばない。logout も `currentAccessToken()->delete()` と Sanctum 前提で書かれており、Supabase セッションでは null 参照で 500 必至。
- **suggestion**: `/api/v1/login` は完全削除し、logout は Supabase 側 signOut だけで成立するよう FE 側 (authStore) を担当に一本化。ルートからも routes/api.php L42, L51 を外す。

### 42. BusinessCard OCR の制限値が OpenAPI 仕様 (20枚) と実 validation (50枚) で食い違い
- **file:line**: `app/Http/Controllers/Api/BusinessCardController.php:76`
- **dimension**: consistency
- **description**: OA\Property images[] description が '最大20枚' と明記 (L76) だが、直下の `$request->validate` は 'max:50' (L95)。FE business-cards/create でも上限はクライアント側で制限していない。OpenAPI ドキュメントが BE 実体と乖離しており、外部連携を始めた瞬間に契約違反になる。バリデーションメッセージも '50枚まで' (L101) で OA と不一致。
- **suggestion**: 20 / 50 のいずれかに統一し、OpenAPI 注釈・validation・エラーメッセージを同じ定数 (`config('limits.business_card_upload_max')`) から参照する形に。

---

## 即着手すべき Quick Wins

severity が high で suggestion が 1 PR で完結する 4 件。いずれも 1 ファイル〜数ファイルの局所修正で着手できる。

1. **Gmail OAuth state HMAC 化** (`app/Http/Controllers/Api/GmailOAuthController.php` + `app/Services/GmailService.php`)
   - 影響範囲は OAuth フローのみ。state を `Cache::put('oauth_state:'.Str::random(48), $userId, 600)` で発行し、callback で検証 + 削除。最小 PR で重大なセキュリティ穴を塞げる。

2. **EngineerMailScoringService::rescoreAll に価格除外を追加** (`app/Services/EngineerMailScoringService.php`)
   - L188 のループ内で `save()` の判定ロジックを共通 helper として抽出し再利用。バグ修正のみで仕様変更なし。

3. **DealController::index の orWhereHas クロージャラップ** (`app/Http/Controllers/Api/DealController.php` + `CustomerController.php`)
   - EmailController に既に正しい実装例があり、それを写すだけ。`$q->where(function($w) ... { ... });` で囲うだけの局所修正。

4. **engineer-mails 詳細 /requirement-match の concurrency 制限** (`src/app/engineer-mails/[id]/page.tsx`)
   - `p-limit` 導入か手書きセマフォで同時 3〜5 に絞る。`items.slice(0, 20)` の暫定 cap だけならさらに小さい PR で済む。

5. **メール選択 race の AbortController** (`src/app/engineer-mails/page.tsx` ほか 3 ファイル)
   - 各ファイルで `useRef<AbortController | null>(null)` を 1 つ追加、handleSelect 冒頭で `abort()` → 新規生成 → `axios.get(..., { signal })`。誤送信リスクの大きい engineer-mails から優先で 1 PR にまとめられる。

---

## 中長期改修

high or critical で複数 PR / アーキ変更を伴うもの。

1. **`exists:` バリデーションのテナント絞り込み統一**
   - Finding 6 (medium だが横断改修)。`Rule::exists(...)->where('tenant_id', ...)` を共通 trait/FormRequest 基底にまとめ、Contact/Deal/Activity/Task/Email の 5 コントローラ × 平均 2 メソッドを順次置換。テナント分離の "もう一段下の防御" として方針確立 + 段階的置換が必要。

2. **提案メール送信フローの DeliveryCampaignService 集約 (Finding 12)**
   - 6 メソッドのコピペ統合 + send_type 同期コストの根絶。`sendSingleProposal($context)/sendBulkProposals(...)` の API 設計 → 各 controller 移行 → CLAUDE.md に記載されている whereIn 4 箇所同期ルールの撤廃まで、PR は最低 3 本 (Service 拡張 / 段階移行 / 旧コード削除 + テスト)。

3. **マッチング系サービスの責務再設計と事前計算化 (Finding 8, 9, 31)**
   - MatchingService / ProjectMailMatchingService / EngineerMailMatchingService / FreshMailMatchingService の 4 サービス整理 + SQL 事前絞り込み + GET 時の updateOrCreate 撤廃 + RescoreJob による事前計算テーブル化。スキーマ追加 (MatchingScore upsert 経路) と GET エンドポイントのレスポンス互換性確保が必要なため複数 PR 必須。

4. **Office ファイルテキスト抽出の SkillSheetTextExtractor 一本化 (Finding 15)**
   - EngineerController / EngineerMailScoringService の重複実装を Service に集約。UploadedFile / tempPath / EmailAttachment 3 種の入力アダプタを設計してから順次差し替え、OOM 防御策 (行数 cap / size threshold) を統一する。最低 2 PR (Service 拡張 / 2 caller 移行)。

5. **InvoiceCreationService への estimate/purchase_order/duplicate 集約 (Finding 13)**
   - storeEstimate / storePurchaseOrder / duplicate を Service 化し全パスを DB::transaction で囲む。`recalcAmounts` / `ses_contract->save` / `quote_number` の順序整理を含むため、テストフィクスチャ整備込みで 2〜3 PR 必要。

6. **SWR / React Query 導入 (Finding 19)**
   - /emails・/project-mails・/engineer-mails・/proposal-threads・/delivery-campaigns・/ses-contracts を段階的に SWR 化。auth トークン injection の fetcher 共通化 → 高頻度ページから順次置換 → 既存 useEffect 削除、というロードマップで複数 PR。docs/720 と同方針。

7. **alert() / confirm() の Toast + ConfirmDialog 全置換 (Finding 24)**
   - 97 + 40 件の native dialog を Toast Context + ConfirmDialog コンポーネントで置換。提案で言及されている通り、matching/deliveries/invoices などの送信系から段階的に進めるべきで、PR は機能カテゴリ単位 (送信系 / 削除系 / 設定系) で 3〜5 本に分割が現実的。