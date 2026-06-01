/**
 * /idor-audit — Controller 横断の cross-tenant IDOR 監査
 *
 * 背景:
 *   Supabase Data API デフォルト権限が 2026-10-30 に変わり、テナント分離の最終防衛線が
 *   外部に晒される可能性がある (前段の /rls-grant-audit で REVOKE 済だが、Controller 層で
 *   TenantScope を抜ける/exists ルールが BelongsToTenant を無視する/Route binding が tenant
 *   検証無し等の IDOR が残っていると、Laravel API 経由で他テナント情報漏洩につながる)。
 *
 *   docs/730 Medium #6 で「exists: が TenantScope を無視」が判明済。これは氷山の一角と
 *   想定し、13 controllers (Contact/Task/Activity/Deal/Customer/Engineer/Email/ProjectMail/
 *   EngineerMail/Matching/SesContract/WorkRecord/DeliveryCampaign) を網羅監査する。
 *
 * 実行:
 *   /idor-audit                       — 全 controller を Fan-out 監査
 *   /idor-audit "Engineer,Email"      — (args) controller 名 substring フィルタ (CSV)
 *
 * 出力:
 *   - severity 別 finding リスト
 *   - 各 finding に再現テスト雛形 (tests/Pgsql 形式)
 *   - 修正パッチ案 (TenantExistsRule 適用 / GlobalScope 確認 / Auth ガード追加)
 *   - 全体 takeaway と 10/30 デッドラインへの優先順位
 */

export const meta = {
  name: 'idor-audit',
  description: '13 controllers の cross-tenant IDOR を read-only で並列監査し、severity 順に修正パッチ + Pgsql テスト雛形を出力',
  whenToUse: '新規 controller の PR レビュー時 / 四半期 audit / 10-30 デッドライン前の最終チェック',
  phases: [
    { title: 'Audit',      detail: '13 controllers を 1:1 並列で IDOR 観点監査' },
    { title: 'Synthesize', detail: 'severity 順に統合し再現テスト雛形を整形' },
  ],
}

// 監査対象 controller の概要 (路線図)
const CONTROLLERS = [
  { key: 'ContactController',           path: 'app/Http/Controllers/Api/ContactController.php',           model: 'Contact',          tenant_column: 'tenant_id', description: '取引先担当者。customer/deal 経由の参照あり。' },
  { key: 'TaskController',              path: 'app/Http/Controllers/Api/TaskController.php',              model: 'Task',             tenant_column: 'tenant_id', description: 'タスク。customer/deal 紐付けあり。Realtime 購読対象。' },
  { key: 'ActivityController',          path: 'app/Http/Controllers/Api/ActivityController.php',          model: 'Activity',         tenant_column: 'tenant_id', description: '活動履歴。customer/deal 紐付けあり。Realtime 購読対象。' },
  { key: 'DealController',              path: 'app/Http/Controllers/Api/DealController.php',              model: 'Deal',             tenant_column: 'tenant_id', description: '商談。assignees / customer 関係あり。Realtime 購読対象。' },
  { key: 'CustomerController',          path: 'app/Http/Controllers/Api/CustomerController.php',          model: 'Customer',         tenant_column: 'tenant_id', description: '取引先マスタ。Contact/Deal/Invoice 親テーブル。' },
  { key: 'EngineerController',          path: 'app/Http/Controllers/Api/EngineerController.php',          model: 'Engineer',         tenant_column: 'tenant_id', description: '技術者マスタ。EngineerProfile/Skill 親。' },
  { key: 'EmailController',             path: 'app/Http/Controllers/Api/EmailController.php',             model: 'Email',            tenant_column: 'tenant_id', description: 'メール。Realtime 購読対象。添付 download エンドポイントあり。' },
  { key: 'ProjectMailController',       path: 'app/Http/Controllers/Api/ProjectMailController.php',       model: 'ProjectMailSource',tenant_column: 'tenant_id', description: '案件メール。score/status/thread/proposal 等多機能。' },
  { key: 'EngineerMailController',      path: 'app/Http/Controllers/Api/EngineerMailController.php',      model: 'EngineerMailSource',tenant_column: 'tenant_id', description: '技術者メール。proposal / matching 連携。' },
  { key: 'MatchingController',          path: 'app/Http/Controllers/Api/MatchingController.php',          model: 'RequirementMatchResult', tenant_column: 'tenant_id', description: 'マッチング結果一覧。Engineer×Project の cross 表示。' },
  { key: 'SesContractController',       path: 'app/Http/Controllers/Api/SesContractController.php',       model: 'SesContract',      tenant_column: 'tenant_id', description: 'SES 契約台帳。estimate/invoice の起点。' },
  { key: 'WorkRecordController',        path: 'app/Http/Controllers/Api/WorkRecordController.php',        model: 'WorkRecord',       tenant_column: 'tenant_id', description: '稼働実績。deal_id 経由でテナント間接特定。' },
  { key: 'DeliveryCampaignController',  path: 'app/Http/Controllers/Api/DeliveryCampaignController.php',  model: 'DeliveryCampaign', tenant_column: 'tenant_id', description: '一斉配信。delivery_send_histories / addresses 関連。' },
]

const FILTERS = (typeof args === 'string' && args.trim())
  ? args.split(',').map(s => s.trim()).filter(Boolean)
  : []

const TARGETS = FILTERS.length > 0
  ? CONTROLLERS.filter(c => FILTERS.some(f => c.key.toLowerCase().includes(f.toLowerCase())))
  : CONTROLLERS

const AUDIT_CONTEXT = `
## IDOR 監査の観点

### A. Route Model Binding が tenant チェック付きか
- \`public function show(Contact $contact)\` で TenantScope (GlobalScope) が動いていれば
  他テナント id では 404 になる
- 抜けるパターン: \`findOrFail($id)\` を直接呼び GlobalScope を抜ける、\`withoutGlobalScopes()\` 等

### B. validation の exists: ルール
- \`'customer_id' => ['exists:customers,id']\` は GlobalScope を経由せず素通り
- 正解は \`['exists', App\\Support\\TenantExistsRule::for('customers')]\` (これは戻り値 Rules\\Exists)
  もしくは \`Rule::exists('customers', 'id')->where(fn($q) => $q->where('tenant_id', Auth::user()->tenant_id))\`

### C. Eager loading 経路
- \`->with(['customer.deals'])\` 等で子関連を読み込む時、GlobalScope が relation にも効くか
- BelongsToTenant trait 適用済の Model を経由していれば自動的に tenant フィルタが効く

### D. super_admin 経路
- \`role === 'super_admin'\` の場合 tenant 制限を緩める実装があると、誤って外部公開する可能性

### E. download / 添付エンドポイント
- \`/emails/{id}/attachments/{attachment_id}/download\` 等で attachment_id を別テナントから推測可能か
- Email 経由で attachment を引く if it lacks tenant check

### F. その他
- \`Model::find($request->customer_id)->something()\` のようなチェイン
- raw SQL や DB::table() の使用 (GlobalScope 無効)
- DELETE/UPDATE 系の where (\`where('id', $id)\` のみで tenant 検証なし)

## テスト雛形フォーマット
出力する Pgsql テスト雛形は次の形:
\`\`\`php
public function test_user_a_cannot_access_user_b_contact(): void
{
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $userA   = User::factory()->create(['tenant_id' => $tenantA->id]);
    $contactB = Contact::factory()->create(['tenant_id' => $tenantB->id]);

    $res = $this->actingAs($userA)->getJson("/api/v1/contacts/{$contactB->id}");
    $res->assertStatus(404);
}
\`\`\`
`

const FINDING_SCHEMA = {
  type: 'object',
  properties: {
    controller: { type: 'string' },
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          method:          { type: 'string', description: 'index/show/store/update/destroy 等' },
          severity:        { type: 'string', enum: ['critical', 'high', 'medium', 'low', 'ok'] },
          observation:     { type: 'string', description: '実コード根拠 (file:line / 抜粋 1-2 行)' },
          risk:            { type: 'string', description: 'どんな漏洩シナリオが想定されるか' },
          recommended_fix: { type: 'string', description: '修正パッチ案 (具体的なコード差分)' },
          test_template:   { type: 'string', description: 'Pgsql tests/Feature 形式の再現テスト雛形 (10-30 行)' },
        },
        required: ['method', 'severity', 'observation', 'risk', 'recommended_fix'],
      },
    },
    overall_severity: { type: 'string', enum: ['critical', 'high', 'medium', 'low', 'ok'] },
  },
  required: ['controller', 'findings', 'overall_severity'],
}

phase('Audit')

const auditResults = await parallel(TARGETS.map(c => () =>
  agent(
    `あなたは sales_support の Laravel API セキュリティ監査担当です。\n\n` +
    `## 担当 controller\n` +
    `- File: \`/home/shintomi/sales_support/${c.path}\`\n` +
    `- Model: \`App\\Models\\${c.model}\` (tenant_column: ${c.tenant_column})\n` +
    `- 概要: ${c.description}\n\n` +
    `${AUDIT_CONTEXT}\n\n` +
    `## タスク\n` +
    `1. Read で controller ファイルを全文読む (大きい場合は分割)\n` +
    `2. 各 method (index/show/store/update/destroy + サブ action) を観点 A-F で監査\n` +
    `3. 関連 Model (app/Models/${c.model}.php) を Read し BelongsToTenant trait の有無確認\n` +
    `4. routes/api.php で該当 route 定義を Grep し Route binding が tenant 検証有りか確認\n` +
    `5. findings に列挙:\n` +
    `   - severity:\n` +
    `     - critical: 他テナント情報を 1 リクエストで漏洩可能 (raw find + tenant 無検証)\n` +
    `     - high: exists: ルールが TenantScope 無視\n` +
    `     - medium: super_admin 経路が想定外に広い / eager loading で関連だけ tenant 漏れ\n` +
    `     - low: GlobalScope が効いているが防御過剰 (false positive 候補)\n` +
    `     - ok: 問題なし (記録のため空でない場合のみ列挙)\n` +
    `   - observation: 該当 method 名 + ファイル行番号 + 抜粋\n` +
    `   - risk: 想定シナリオ (誰がどう操作するとどう漏れるか)\n` +
    `   - recommended_fix: 具体的コード差分 (TenantExistsRule::for() への置換等)\n` +
    `   - test_template: Pgsql tests/Feature/Api 形式 (上記フォーマット)\n\n` +
    `投機的でなく、必ず Read で実コードを確認した上で判定すること。controller が大きい場合は\n` +
    `重要 method (show/update/destroy/sub-action) を優先。OK な method は省略可。`,
    {
      label: `audit-${c.key}`,
      phase: 'Audit',
      schema: FINDING_SCHEMA,
    }
  )
))

const validResults = auditResults.filter(Boolean)
const allFindings = validResults.flatMap(r => (r.findings ?? []).map(f => ({ ...f, controller: r.controller })))
const bySev = {
  critical: allFindings.filter(f => f.severity === 'critical').length,
  high:     allFindings.filter(f => f.severity === 'high').length,
  medium:   allFindings.filter(f => f.severity === 'medium').length,
  low:      allFindings.filter(f => f.severity === 'low').length,
  ok:       allFindings.filter(f => f.severity === 'ok').length,
}
log(`Audit phase: ${allFindings.length} findings across ${validResults.length}/${TARGETS.length} controllers (critical=${bySev.critical} high=${bySev.high} medium=${bySev.medium} low=${bySev.low})`)

phase('Synthesize')

const synthesis = await agent(
  `13 controllers の IDOR 監査が完了しました。findings (${allFindings.length} 件):\n\n` +
  JSON.stringify(allFindings, null, 2) + `\n\n` +
  `## タスク\n` +
  `これらを 2026-10-30 Supabase Data API デフォルト権限廃止 (= テナント分離が外部公開リスクに直結する) に向けた` +
  `対応ロードマップとして統合してください。\n\n` +
  `出力:\n` +
  `- summary: severity 別件数と全体評価 (3-5 文)\n` +
  `- critical_findings: critical/high の即時対応リスト。各項目に controller / method / fix\n` +
  `- medium_findings: medium 一覧 (短く)\n` +
  `- low_priority_count: low の件数と「対応不要 / 後日見直し」の判断\n` +
  `- consolidated_test_file: 主要 critical/high findings の test_template をまとめた tests/Pgsql/Idor/CrossTenantIdorTest.php 風の 1 ファイル例\n` +
  `- recommended_pr_split: PR を分けるとしたらどう分割すべきか (controller 別 / severity 別)\n` +
  `- next_audit: 四半期再走の推奨タイミング`,
  {
    label: 'synthesize',
    phase: 'Synthesize',
    schema: {
      type: 'object',
      properties: {
        summary: { type: 'string' },
        critical_findings: { type: 'array', items: {
          type: 'object',
          properties: {
            controller:      { type: 'string' },
            method:          { type: 'string' },
            severity:        { type: 'string' },
            fix_summary:     { type: 'string' },
          },
          required: ['controller', 'method', 'severity', 'fix_summary'],
        } },
        medium_findings: { type: 'array', items: { type: 'string' } },
        low_priority_count: { type: 'object', properties: {
          count: { type: 'integer' },
          recommendation: { type: 'string' },
        } },
        consolidated_test_file: { type: 'string' },
        recommended_pr_split: { type: 'string' },
        next_audit: { type: 'string' },
      },
      required: ['summary', 'critical_findings'],
    },
  }
)

return {
  audit_summary: synthesis.summary,
  totals: { ...bySev, total: allFindings.length, controllers_audited: validResults.length },
  critical_findings:      synthesis.critical_findings,
  medium_findings:        synthesis.medium_findings,
  low_priority_count:     synthesis.low_priority_count,
  consolidated_test_file: synthesis.consolidated_test_file,
  recommended_pr_split:   synthesis.recommended_pr_split,
  next_audit:             synthesis.next_audit,
}
