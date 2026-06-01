/**
 * /rls-grant-audit — Supabase RLS + Data API GRANT 抜けの一括監査
 *
 * 背景:
 *   - 2026-10-30 Supabase は public スキーマのデフォルト権限を廃止する。それまでに
 *     新規テーブル作成 migration で ENABLE ROW LEVEL SECURITY と GRANT を明示しないと
 *     フロント (Realtime / supabase-js) からの読み取りが落ちる、もしくは PostgREST 経由で
 *     RLS 抜けのまま外部公開され続けるリスクがある。
 *   - CLAUDE.md と [[feedback_supabase_data_api_grant]] に判定基準が文書化されている。
 *
 * 実行:
 *   /rls-grant-audit                  ← 全 migration を監査
 *   /rls-grant-audit "auth,email"     ← (args) ファイル名 substring フィルタ (CSV)
 *
 * 出力:
 *   - 未対応 migration の優先度付きリスト
 *   - migration 修正パッチ案 (DB::statement 追加例)
 *   - 未対応 Model (BelongsToTenant trait 未適用) 一覧
 *   - 四半期再走推奨 (10/30 デッドライン直前まで)
 */

export const meta = {
  name: 'rls-grant-audit',
  description: 'Supabase RLS と Data API GRANT (2026-10-30 強制適用) の抜けを migration/Model 横断で監査',
  whenToUse: '新規 migration の PR レビュー時、または四半期 audit / 10-30 デッドライン直前の最終チェック',
  phases: [
    { title: 'Filter',     detail: '監査対象 migration を絞り込み (Schema::create / tenant_id 追加 / 既知テーブル系)' },
    { title: 'Audit',      detail: '対象を chunk し parallel agent で RLS/GRANT/Model trait を判定' },
    { title: 'Synthesize', detail: '優先度付きパッチリストに統合 (Realtime 使用テーブルを高優先)' },
  ],
}

// CLAUDE.md / feedback memory に基づく Realtime / supabase-js から触る既知テーブル
// (これらは authenticated への SELECT GRANT が必須)
const REALTIME_TABLES = [
  'tasks', 'deals', 'activities', 'business_cards', 'emails',
  // 通知バッジ・カウント系
  'invoice_notification_reads',
]

const GRANT_RULE_CONTEXT = `
## 判定基準 (CLAUDE.md / [[feedback_supabase_data_api_grant]] より)

### A. RLS 必須 (Schema::create で新規テーブル作成する全 migration)
\`\`\`php
DB::statement('ALTER TABLE public.{table} ENABLE ROW LEVEL SECURITY');
\`\`\`
理由: Supabase PostgREST 経由で外部公開されるのを防ぐ。policy は作らず default deny で運用。
Laravel は service_role でバイパス。

### B. Realtime / supabase-js で触るテーブル: authenticated GRANT 必須 (2026-10-30 強制適用)
\`\`\`php
DB::statement('GRANT SELECT ON public.{table} TO authenticated');
\`\`\`
既知の Realtime 使用テーブル: ${REALTIME_TABLES.join(', ')}
それ以外でも \`useRealtimeXxx\` / \`supabase.channel\` で購読しているかが基準。

### C. Laravel 経由のみのテーブル: service_role GRANT を念のため明示 (任意だが推奨)
\`\`\`php
DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON public.{table} TO service_role');
\`\`\`

### D. テナント分離 (tenant_id を持つテーブルの Model)
\`\`\`php
use App\\Traits\\BelongsToTenant;
class Foo extends Model {
    use BelongsToTenant;
}
\`\`\`
理由: GlobalScope で tenant_id WHERE を自動付与。漏れると cross-tenant IDOR の原因に。
`

// args: CSV ファイル名 substring フィルタ (例: "auth,email")
const FILTERS = (typeof args === 'string' && args.trim())
  ? args.split(',').map(s => s.trim()).filter(Boolean)
  : []

phase('Filter')

const filterResult = await agent(
  `あなたは sales_support プロジェクトの migration 棚卸し担当です。\n\n` +
  `タスク:\n` +
  `1. \`/home/shintomi/sales_support/database/migrations/\` 配下の全 \`.php\` migration ファイルを Bash の \`ls\` で列挙。\n` +
  `2. 各ファイルを Grep で軽量スキャンし、以下のいずれかに該当するものを抽出:\n` +
  `   (a) \`Schema::create\` を含む (新規テーブル作成)\n` +
  `   (b) \`->after('id')\` などで \`tenant_id\` カラムを追加している\n` +
  `   (c) Migration 名に \`grant\` / \`rls\` / \`row level security\` / \`policy\` を含む\n` +
  `3. ${FILTERS.length > 0 ? `さらにファイル名に次のいずれかを含むもののみに絞り込み: ${JSON.stringify(FILTERS)}` : '(追加フィルタ無し)'}\n` +
  `4. 各 migration について以下を JSON で出力:\n` +
  `   - file_name (basename)\n` +
  `   - creates_tables: 作成するテーブル名の配列 (Schema::create から正規表現抽出)\n` +
  `   - touches_tenant_id: tenant_id を追加/変更しているか (bool)\n` +
  `\n` +
  `${GRANT_RULE_CONTEXT}\n\n` +
  `判定の参考: 上記 A-D は監査本体 (次フェーズ) で行います。ここではターゲット抽出のみ。`,
  {
    label: 'filter-migrations',
    phase: 'Filter',
    schema: {
      type: 'object',
      properties: {
        total_migrations: { type: 'integer' },
        targets: {
          type: 'array',
          items: {
            type: 'object',
            properties: {
              file_name:        { type: 'string' },
              creates_tables:   { type: 'array', items: { type: 'string' } },
              touches_tenant_id: { type: 'boolean' },
            },
            required: ['file_name', 'creates_tables', 'touches_tenant_id'],
          },
        },
      },
      required: ['total_migrations', 'targets'],
    },
  }
)

const targets = filterResult.targets ?? []
log(`Filter phase: ${targets.length}/${filterResult.total_migrations} migrations targeted for audit`)

if (targets.length === 0) {
  return {
    audit_summary: 'No target migrations found',
    total_migrations: filterResult.total_migrations,
  }
}

phase('Audit')

// chunk 関数 (Math.random 不使用なので決定的)
function chunk(arr, size) {
  const out = []
  for (let i = 0; i < arr.length; i += size) out.push(arr.slice(i, i + size))
  return out
}

// 1 agent あたり 4-6 migration を担当 (16 並列上限を意識し、 chunk size を調整)
const CHUNK_SIZE = Math.max(4, Math.ceil(targets.length / 12))
const chunks = chunk(targets, CHUNK_SIZE)
log(`Audit phase: ${chunks.length} agents × ~${CHUNK_SIZE} migrations each`)

const AUDIT_SCHEMA = {
  type: 'object',
  properties: {
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          file_name:                       { type: 'string' },
          tables:                          { type: 'array', items: { type: 'string' } },
          has_rls_enable:                  { type: 'boolean', description: 'up() 内に ENABLE ROW LEVEL SECURITY が含まれるか' },
          has_authenticated_grant:         { type: 'boolean', description: 'authenticated への GRANT があるか' },
          has_service_role_grant:          { type: 'boolean', description: 'service_role への GRANT があるか' },
          is_realtime_table:               { type: 'boolean', description: 'Realtime / supabase-js から触る既知テーブルか (REALTIME_TABLES に該当)' },
          has_tenant_id:                   { type: 'boolean', description: 'tenant_id カラムを持つか (テナント分離対象)' },
          model_file:                      { type: 'string', description: '対応 Model ファイル (app/Models/Foo.php) または "(unknown)"' },
          model_uses_belongs_to_tenant:    { type: 'boolean', description: 'Model が BelongsToTenant trait を use しているか (tenant_id 持ち時のみ意味あり)' },
          severity:                        { type: 'string', enum: ['high', 'medium', 'low', 'ok'] },
          severity_reason:                 { type: 'string' },
          recommended_patch:               { type: 'string', description: '不足箇所を補う DB::statement 例 / use BelongsToTenant; の追加例' },
        },
        required: ['file_name', 'tables', 'has_rls_enable', 'has_authenticated_grant', 'has_service_role_grant', 'severity', 'severity_reason'],
      },
    },
  },
  required: ['findings'],
}

const auditResults = await parallel(chunks.map((batch, idx) => () =>
  agent(
    `あなたは sales_support の Supabase 設定監査を担当するエンジニアです。\n\n` +
    `## 監査基準\n${GRANT_RULE_CONTEXT}\n\n` +
    `## 担当 migration 群 (${batch.length} 件)\n` +
    batch.map(t => `- ${t.file_name} (creates: ${t.creates_tables.join(', ') || '(none)'}, tenant_id: ${t.touches_tenant_id})`).join('\n') +
    `\n\n## タスク\n` +
    `各 migration について以下の手順で監査:\n` +
    `1. Read で \`database/migrations/{file_name}\` を読む\n` +
    `2. up() 内の DB::statement / Schema 関連を抽出\n` +
    `3. テーブル名から対応する Model (app/Models/{StudlyCase}.php) を Read。複数形→単数形・スネークケース→StudlyCase で類推 (例: business_cards → BusinessCard, project_mail_sources → ProjectMailSource)\n` +
    `4. tenant_id を持つテーブルなら Model に \`use BelongsToTenant;\` があるか確認\n` +
    `5. 判定:\n` +
    `   - severity='high': Schema::create で RLS 無し AND テーブル名が REALTIME_TABLES に該当、または既に Realtime/supabase-js から読まれている形跡\n` +
    `   - severity='medium': Schema::create で RLS 無し (Realtime 未使用)、または tenant_id 持ちなのに BelongsToTenant 未適用\n` +
    `   - severity='low': service_role GRANT が明示されていないだけ (機能影響薄)\n` +
    `   - severity='ok': 全条件満たす、または対象外 (data/seed 系・jobs/cache などフレームワーク標準テーブル)\n` +
    `6. recommended_patch には不足箇所を補う具体的なコード片を出力 (DB::statement(...) / use BelongsToTenant; の挿入位置含む)\n\n` +
    `## Realtime 既知テーブル: ${REALTIME_TABLES.join(', ')}\n` +
    `これらは authenticated GRANT が必須。それ以外でも \`useRealtime\`/\`supabase.channel\` で購読されている形跡があれば high。\n` +
    `Frontend 確認は \`/home/shintomi/sales_support_next/src/hooks/\` や \`/home/shintomi/sales_support_next/src/components/\` を Grep で \`supabase.channel\` 検索すれば速い。\n\n` +
    `投機的でなく、必ず Read で実コードを確認した上で判定すること。`,
    {
      label: `audit-batch-${idx + 1}`,
      phase: 'Audit',
      schema: AUDIT_SCHEMA,
    }
  )
))

const allFindings = auditResults.filter(Boolean).flatMap(r => r.findings ?? [])
const byseverity = {
  high:   allFindings.filter(f => f.severity === 'high').length,
  medium: allFindings.filter(f => f.severity === 'medium').length,
  low:    allFindings.filter(f => f.severity === 'low').length,
  ok:     allFindings.filter(f => f.severity === 'ok').length,
}
log(`Audit phase: ${allFindings.length} findings (high=${byseverity.high} medium=${byseverity.medium} low=${byseverity.low} ok=${byseverity.ok})`)

phase('Synthesize')

const synthesis = await agent(
  `sales_support の Supabase RLS+GRANT 監査結果が ${allFindings.length} 件揃いました:\n\n` +
  JSON.stringify(allFindings, null, 2) + `\n\n` +
  `## タスク\n` +
  `これらを 2026-10-30 デッドラインに向けた対応ロードマップにまとめてください。\n\n` +
  `出力:\n` +
  `- summary: severity 別件数と全体評価 (3-5 文)\n` +
  `- urgent_patches: severity=high の対応リスト。各項目に file_name / tables / why_urgent / patch_snippet\n` +
  `- standard_patches: severity=medium の対応リスト (同上の構造)\n` +
  `- low_priority: severity=low の件数と「いつ着手すべきか」の判断\n` +
  `- missing_belongs_to_tenant: BelongsToTenant trait 未適用の Model リスト\n` +
  `- next_audit: 四半期再走の推奨タイミング (deadline まで何ヶ月か明示)\n` +
  `- consolidated_migration: 不足項目を 1 本の追補 migration にまとめる場合の up() コード例 (DB::statement の集約)`,
  {
    label: 'synthesize',
    phase: 'Synthesize',
    schema: {
      type: 'object',
      properties: {
        summary:                     { type: 'string' },
        urgent_patches:              { type: 'array', items: {
          type: 'object',
          properties: {
            file_name:     { type: 'string' },
            tables:        { type: 'array', items: { type: 'string' } },
            why_urgent:    { type: 'string' },
            patch_snippet: { type: 'string' },
          },
          required: ['file_name', 'why_urgent', 'patch_snippet'],
        } },
        standard_patches:            { type: 'array', items: {
          type: 'object',
          properties: {
            file_name:     { type: 'string' },
            tables:        { type: 'array', items: { type: 'string' } },
            reason:        { type: 'string' },
            patch_snippet: { type: 'string' },
          },
          required: ['file_name', 'reason', 'patch_snippet'],
        } },
        low_priority:                { type: 'object', properties: {
          count: { type: 'integer' },
          when_to_address: { type: 'string' },
        } },
        missing_belongs_to_tenant:   { type: 'array', items: { type: 'string' } },
        next_audit:                  { type: 'string' },
        consolidated_migration:      { type: 'string', description: '不足項目を集約した 1 本の migration の up() コード例' },
      },
      required: ['summary', 'urgent_patches', 'standard_patches', 'next_audit'],
    },
  }
)

return {
  audit_summary: synthesis.summary,
  totals: { ...byseverity, total: allFindings.length, scanned: filterResult.total_migrations },
  urgent_patches:            synthesis.urgent_patches,
  standard_patches:          synthesis.standard_patches,
  low_priority:              synthesis.low_priority,
  missing_belongs_to_tenant: synthesis.missing_belongs_to_tenant ?? [],
  next_audit:                synthesis.next_audit,
  consolidated_migration:    synthesis.consolidated_migration,
}
