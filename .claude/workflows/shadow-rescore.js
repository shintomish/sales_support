/**
 * /shadow-rescore — ScoringService の変更が status 遷移にどう響くかを pre-deploy 検証
 *
 * 背景:
 *   ルール変更 (例: 5/25 営業部決定の「スコア0点化/年齢許容/表示閾値70+」) を
 *   本番に投入する前に、どれだけの行が status (new/review/excluded) を変えるかを
 *   定量化したい。半日かかる本番 rescoreAll → 後追い修正のサイクルを避けるため。
 *
 *   /api/v1/project-mails/rescore-all-shadow (および engineer-mails 版) を
 *   pagination で叩いて diff stats を集計する。POST のため tenant_admin/super_admin 認証が必要。
 *
 * 実行:
 *   /shadow-rescore                          — project + engineer 両方を 1000 件サンプル
 *   /shadow-rescore "project"                — project のみ
 *   /shadow-rescore "engineer,full"          — engineer を全件 (full=対象テナント全件)
 *
 * 注意:
 *   - 本ワークフローは Read-only (UPDATE 無し)。
 *   - チェックアウト中のルール変更ブランチを本番ビルドにデプロイ→ shadow 実行→ 結果確認
 *     のフロー (= 「本番投入前の影響範囲スナップショット」用途) を想定。
 *   - 検証だけしたいなら ローカル/dev branch で limit=1000 程度を推奨 (1000件 ≒ 数十秒)。
 */

export const meta = {
  name: 'shadow-rescore',
  description: 'ScoringService ルール変更の status 遷移影響を Pre-deploy で定量化する診断ワークフロー',
  whenToUse: 'スコアリング閾値・除外ロジック・単価チェック等の変更を本番投入する前。半日かかる rescoreAll の失敗を避ける安全弁',
  phases: [
    { title: 'Run',       detail: 'project-mails / engineer-mails の shadow rescore を並列で実行' },
    { title: 'Synthesize', detail: '結果を 1 表に整形' },
  ],
}

const FILTERS = (typeof args === 'string' && args.trim()) ? args.split(',').map(s => s.trim()) : []
const DO_PROJECT  = FILTERS.length === 0 || FILTERS.includes('project')
const DO_ENGINEER = FILTERS.length === 0 || FILTERS.includes('engineer')
const FULL_SCAN   = FILTERS.includes('full')

const SAMPLE_LIMIT = FULL_SCAN ? null : 1000   // null = 全件

const STATS_SCHEMA = {
  type: 'object',
  properties: {
    kind:                      { type: 'string', description: 'project | engineer' },
    total:                     { type: 'integer' },
    unchanged:                 { type: 'integer' },
    changed_score:             { type: 'integer' },
    changed_status:            { type: 'integer' },
    crossed_review_threshold:  { type: 'integer', description: '40点 (SCORE_REVIEW) を跨いだ件数' },
    crossed_ok_threshold:      { type: 'integer', description: '60点 (SCORE_OK) を跨いだ件数' },
    transitions:               { type: 'object', description: '"old->new" 形式の status 遷移件数 map' },
    sample_changes_count:      { type: 'integer', description: 'sample_changes (個別 row diff) の件数。最大 20' },
    elapsed_ms:                { type: 'integer' },
    raw_sample_changes:        { type: 'array', items: { type: 'object' }, description: '個別 row diff 上位 20 件 (pms_id/ems_id, old_score, new_score, old_status, new_status)' },
  },
  required: ['kind', 'total', 'unchanged', 'changed_score', 'changed_status', 'transitions'],
}

phase('Run')

const jobs = []
if (DO_PROJECT) {
  jobs.push({
    kind: 'project',
    endpoint: '/api/v1/project-mails/rescore-all-shadow',
  })
}
if (DO_ENGINEER) {
  jobs.push({
    kind: 'engineer',
    endpoint: '/api/v1/engineer-mails/rescore-all-shadow',
  })
}

log(`Run phase: ${jobs.length} shadow rescore (${SAMPLE_LIMIT ? `limit=${SAMPLE_LIMIT}` : 'full scan'})`)

const results = await parallel(jobs.map(j => () =>
  agent(
    `あなたは sales_support のスコアリングルール変更影響を検証する診断担当です。\n\n` +
    `## タスク\n` +
    `1. Bash で sales_support コンテナ経由 (docker compose exec -T app) で artisan tinker を使い、\n` +
    `   下記サービスメソッドを直接呼び出す:\n` +
    (j.kind === 'project'
      ? `   app(\\\\App\\\\Services\\\\ProjectMailScoringService::class)->rescoreAllShadow(${SAMPLE_LIMIT ?? 'null'}, 0, 1)`
      : `   app(\\\\App\\\\Services\\\\EngineerMailScoringService::class)->rescoreAllShadow(${SAMPLE_LIMIT ?? 'null'}, 0, 1)`) +
    `\n   ※ tenant_id=1 (aizen) 固定。Auth context が必要なため Auth::login() で tenant_admin user を最初にログインさせる。\n` +
    `2. 結果 (PHP 連想配列) を JSON エンコードしてキャプチャ\n` +
    `3. 出力スキーマに従い構造化\n` +
    `   - kind: "${j.kind}"\n` +
    `   - total/unchanged/changed_score/changed_status/crossed_* は数値\n` +
    `   - transitions は {"old->new": count} のオブジェクト\n` +
    `   - sample_changes_count は sample_changes 配列の長さ (最大 20)\n` +
    `   - raw_sample_changes に個別 row diff (pms_id/ems_id ベース) を返す\n` +
    `   - elapsed_ms は実行時間(ms)\n\n` +
    `## tinker サンプルコマンド (参考)\n` +
    `\`\`\`\ndocker compose exec -T app php artisan tinker --execute='\n` +
    `\\\\Illuminate\\\\Support\\\\Facades\\\\Auth::login(\\\\App\\\\Models\\\\User::where("role","tenant_admin")->first());\n` +
    `$svc = app(\\\\App\\\\Services\\\\${j.kind === 'project' ? 'ProjectMailScoringService' : 'EngineerMailScoringService'}::class);\n` +
    `$t0 = microtime(true);\n` +
    `$stats = $svc->rescoreAllShadow(${SAMPLE_LIMIT ?? 'null'}, 0, 1);\n` +
    `$stats["elapsed_ms"] = (int) round((microtime(true)-$t0)*1000);\n` +
    `echo json_encode($stats);\n` +
    `'\n\`\`\`\n`,
    {
      label: `shadow-${j.kind}`,
      phase: 'Run',
      schema: STATS_SCHEMA,
    }
  )
))

phase('Synthesize')

const valid = results.filter(Boolean)
log(`Synthesize: ${valid.length}/${jobs.length} kinds completed`)

const synthesis = await agent(
  `Shadow rescore 結果が ${valid.length} 件届きました:\n\n` +
  JSON.stringify(valid, null, 2) + `\n\n` +
  `## タスク\n` +
  `これを「ルール変更を本番投入してよいか」の判断材料として整形してください。\n\n` +
  `出力:\n` +
  `- summary: 全体評価 (3-5 文。changed_status 件数 / threshold 跨ぎ / 想定リスク)\n` +
  `- by_kind: project / engineer ごとに total/changed_status/crossed_review/crossed_ok と評価\n` +
  `- transition_top: status 遷移を多い順に Top 5 (例: "new -> review (15件)")\n` +
  `- risk_signals: 警戒すべきパターン (excluded→new で大量に false positive 化 等)\n` +
  `- go_no_go: 'go' (差分小・本番投入推奨) / 'caution' (要レビュー) / 'no_go' (大量変動・要再設計)\n` +
  `- recommended_action: 次の一手 (ルール再調整・パイロット範囲縮小・追加検証等)`,
  {
    label: 'synthesize',
    phase: 'Synthesize',
    schema: {
      type: 'object',
      properties: {
        summary: { type: 'string' },
        by_kind: { type: 'array', items: {
          type: 'object',
          properties: {
            kind:                    { type: 'string' },
            total:                   { type: 'integer' },
            changed_status:          { type: 'integer' },
            crossed_review_threshold: { type: 'integer' },
            crossed_ok_threshold:    { type: 'integer' },
            evaluation:              { type: 'string' },
          },
          required: ['kind', 'total', 'changed_status', 'evaluation'],
        } },
        transition_top:      { type: 'array', items: { type: 'string' } },
        risk_signals:        { type: 'array', items: { type: 'string' } },
        go_no_go:            { type: 'string', enum: ['go', 'caution', 'no_go'] },
        recommended_action:  { type: 'string' },
      },
      required: ['summary', 'by_kind', 'go_no_go'],
    },
  }
)

return {
  shadow_results: valid,
  summary:             synthesis.summary,
  by_kind:             synthesis.by_kind,
  transition_top:      synthesis.transition_top,
  risk_signals:        synthesis.risk_signals,
  go_no_go:            synthesis.go_no_go,
  recommended_action:  synthesis.recommended_action,
}
