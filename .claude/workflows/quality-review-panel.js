/**
 * /quality-review-panel — docs/730 残課題の Judge-panel 多面再評価
 *
 * 背景:
 *   docs/730_quality_review_2026_05_30.md には 42 件の findings がある。直近のコミット
 *   (a757660 / 6302733 / e962f84 / 2cc3b34 / f755ac3) で High 4/5 + Medium 多数が解消
 *   済だが、単一視点の /code-review で順次対応してきたため、複合観点 (security ×
 *   performance × test-coverage) で見ると別の severity 評価になる課題が残っている可能性。
 *
 *   特に #14 (Anthropic API 直叩き 5 サービスに散在) のような横断 refactor は単一視点では
 *   評価しきれない。完了済 Medium にも security 観点でのリグレッション疑義を含めて
 *   一度に多角評価する。
 *
 * 実行:
 *   /quality-review-panel              — 残未対応 (Open) を自動同定 + judge-panel
 *   /quality-review-panel "14,17,21"   — 指定 item 番号のみ panel 評価
 *
 * 出力:
 *   - 残未対応の自動同定リスト (commit log と doc を照合)
 *   - 各課題に security/performance/test-coverage 3 lens の severity 評価
 *   - lens 間で severity が割れている課題の優先順位提案
 *   - 完了済の副作用チェック結果
 */

export const meta = {
  name: 'quality-review-panel',
  description: 'docs/730 残課題を security/performance/test-coverage の 3 lens × parallel で再評価し、修正順序の最適化と完了済の副作用検出を出力',
  whenToUse: 'docs/730 系の品質レビュー追加対応時、四半期 audit、または横断 refactor (#14 Claude API 集約等) の着手前',
  phases: [
    { title: 'Identify',   detail: 'docs/730 と recent commits を照合し残未対応 (Open) を自動同定' },
    { title: 'Judge',      detail: '各 Open を 3 lens (security / performance / test-coverage) で並列評価' },
    { title: 'Synthesize', detail: 'lens 間 severity 一致度 + 修正順序最適化を統合' },
  ],
}

const ITEM_FILTERS = (typeof args === 'string' && args.trim())
  ? args.split(',').map(s => parseInt(s.trim(), 10)).filter(n => !Number.isNaN(n))
  : []

phase('Identify')

const identifyResult = await agent(
  `あなたは sales_support の品質改修ロードマップ管理者です。\n\n` +
  `## タスク\n` +
  `1. \`docs/730_quality_review_2026_05_30.md\` を Read で全文取得 (346 行・42 findings)\n` +
  `2. \`git log --oneline -20 --grep="品質レビュー"\` などで関連コミットを Bash で取得\n` +
  `3. 各 finding (item 1-42) について「実コード/docs を見て対応済か」を判定\n` +
  `   - 完了の根拠: コミットメッセージで item 番号 #N が言及されている + 該当コード変更を Grep で確認\n` +
  `   - Open の根拠: コミット言及無し OR 部分対応のみ\n` +
  `4. ${ITEM_FILTERS.length > 0 ? `※ args フィルタが指定されたので [${ITEM_FILTERS.join(',')}] のみ対象に絞る` : '※ 全 42 件対象'}\n\n` +
  `出力: open / closed / partial に分類して件数 + open item の詳細リスト`,
  {
    label: 'identify-open-items',
    phase: 'Identify',
    schema: {
      type: 'object',
      properties: {
        total: { type: 'integer' },
        closed_count: { type: 'integer' },
        partial_count: { type: 'integer' },
        open_count: { type: 'integer' },
        open_items: {
          type: 'array',
          items: {
            type: 'object',
            properties: {
              item_number:        { type: 'integer' },
              title:              { type: 'string' },
              original_severity:  { type: 'string' },
              dimension:          { type: 'string' },
              summary_for_judge:  { type: 'string', description: '判定に必要な短いコンテキスト (3-5 文)' },
              related_files:      { type: 'array', items: { type: 'string' } },
            },
            required: ['item_number', 'title', 'original_severity', 'summary_for_judge'],
          },
        },
        closed_items: {
          type: 'array',
          items: {
            type: 'object',
            properties: {
              item_number: { type: 'integer' },
              title:       { type: 'string' },
              closed_by:   { type: 'string', description: 'コミット hash または PR' },
            },
            required: ['item_number', 'title', 'closed_by'],
          },
        },
      },
      required: ['total', 'open_count', 'open_items'],
    },
  }
)

const openItems = ITEM_FILTERS.length > 0
  ? (identifyResult.open_items ?? []).filter(i => ITEM_FILTERS.includes(i.item_number))
  : (identifyResult.open_items ?? [])

log(`Identify phase: ${openItems.length} open items (closed=${identifyResult.closed_count ?? 0}, partial=${identifyResult.partial_count ?? 0})`)

if (openItems.length === 0) {
  return {
    summary: 'No open items detected — docs/730 is fully addressed.',
    closed_count: identifyResult.closed_count,
  }
}

phase('Judge')

const LENS_DEFINITIONS = [
  {
    key: 'security',
    label: 'Security lens',
    prompt: 'cross-tenant leak / IDOR / auth bypass / token exposure / RCE / XSS / CSRF / supply chain / supabase RLS bypass / Claude API key exposure / PII handling 観点で再評価。',
  },
  {
    key: 'performance',
    label: 'Performance lens',
    prompt: 'N+1 / 大量メモリ展開 / Disk IO 増幅 / blocking I/O / 不必要な polling / cache 設計欠陥 / レイテンシ tail / WAL 圧 / GIN index 書込みコスト / Anthropic API レート消費 観点で再評価。',
  },
  {
    key: 'test-coverage',
    label: 'Test-coverage lens',
    prompt: 'pgsql テストの不在 / regression risk / 修正後の振る舞い証明手段 / fixture の cross-tenant 表現可否 / test corpus 不足 / E2E 不在 / 手動 QA 依存度 観点で再評価。',
  },
]

const JUDGE_SCHEMA = {
  type: 'object',
  properties: {
    item_number:    { type: 'integer' },
    lens:           { type: 'string' },
    severity:       { type: 'string', enum: ['critical', 'high', 'medium', 'low', 'ok'] },
    rationale:      { type: 'string', description: 'この lens から見て本当に問題か。根拠 (実コード or docs 引用)' },
    blast_radius:   { type: 'string', description: '万一発火した場合の影響範囲' },
    recommended_action: { type: 'string', description: '具体的に何をすべきか (パッチ案 / 設計変更 / テスト追加)' },
    effort:         { type: 'string', enum: ['S', 'M', 'L', 'XL'], description: '対応工数 (S=半日 / M=1-2日 / L=3-5日 / XL=1週超)' },
  },
  required: ['item_number', 'lens', 'severity', 'rationale', 'recommended_action', 'effort'],
}

// 各 item × 3 lens = 並列 jobs
const judgeJobs = openItems.flatMap(item =>
  LENS_DEFINITIONS.map(lens => ({ item, lens }))
)

log(`Judge phase: ${openItems.length} items × ${LENS_DEFINITIONS.length} lens = ${judgeJobs.length} parallel evaluations`)

const judgeResults = await parallel(judgeJobs.map(({ item, lens }) => () =>
  agent(
    `あなたは sales_support の ${lens.label} 専任レビュアーです。\n\n` +
    `## 評価対象 (docs/730 item #${item.item_number})\n` +
    `- タイトル: ${item.title}\n` +
    `- 元の severity (docs 記載): ${item.original_severity}\n` +
    `- 元の dimension: ${item.dimension || '(不明)'}\n` +
    `- コンテキスト: ${item.summary_for_judge}\n` +
    `- 関連ファイル: ${(item.related_files ?? []).join(', ') || '(自分で Grep で特定)'}\n\n` +
    `## 評価視点\n${lens.prompt}\n\n` +
    `## タスク\n` +
    `1. 必要に応じて関連ファイルを Read / Grep で確認\n` +
    `2. ${lens.label} の観点で severity を再評価:\n` +
    `   - critical: 即時対応必須 / 既に発火している or 1 リクエストで発火可能\n` +
    `   - high: 6 月中対応推奨 / 既知シナリオで発火\n` +
    `   - medium: 7-9 月対応 / 条件が揃えば発火\n` +
    `   - low: 防御深層化レベル / 単独では発火しない\n` +
    `   - ok: この lens では問題なし\n` +
    `3. 元の severity との乖離を blast_radius に記述\n` +
    `4. effort (S/M/L/XL) で対応コスト見積もり\n\n` +
    `投機的でなく実コードを確認。lens に該当しない場合は severity='ok' で良い。`,
    {
      label: `judge-#${item.item_number}-${lens.key}`,
      phase: 'Judge',
      schema: JUDGE_SCHEMA,
    }
  )
))

const validJudges = judgeResults.filter(Boolean)
log(`Judge phase: ${validJudges.length}/${judgeJobs.length} evaluations completed`)

// item ごとに 3 lens の評価をまとめる
const byItem = {}
for (const j of validJudges) {
  byItem[j.item_number] = byItem[j.item_number] || { item_number: j.item_number, lenses: [] }
  byItem[j.item_number].lenses.push(j)
}
const consolidatedItems = Object.values(byItem).map(entry => {
  const sevRank = { critical: 4, high: 3, medium: 2, low: 1, ok: 0 }
  const maxSev = entry.lenses.reduce((max, l) => sevRank[l.severity] > sevRank[max] ? l.severity : max, 'ok')
  const sevSet = new Set(entry.lenses.map(l => l.severity))
  return {
    ...entry,
    consolidated_severity: maxSev,
    severity_disagreement: sevSet.size > 1,
    lens_summary: entry.lenses.map(l => `${l.lens}=${l.severity}`).join(' / '),
  }
})

phase('Synthesize')

const synthesis = await agent(
  `docs/730 残未対応 ${consolidatedItems.length} 件を 3 lens で多面評価しました。\n\n` +
  `## 評価結果\n${JSON.stringify(consolidatedItems, null, 2)}\n\n` +
  `## タスク\n` +
  `これらを修正順序ロードマップとして統合してください。\n\n` +
  `観点:\n` +
  `1. consolidated_severity (3 lens の max) を主指標とし、severity_disagreement が true の項目は\n` +
  `   優先度を 1 段上げる (lens 間で割れているのは未知のリスク = 注意深く扱うべき)\n` +
  `2. 同 severity 内では effort 小さい順 (Quick Win を先に)\n` +
  `3. PR 分割の提案 (関連 item をグルーピング)\n` +
  `4. 完了済の副作用としてリグレッション懸念がある項目は別枠で警告\n\n` +
  `出力:\n` +
  `- summary: severity 別件数と全体評価 (3-5 文)\n` +
  `- top_priorities: severity ↓ + effort ↑ 順の Top 5\n` +
  `- pr_groups: 関連項目をまとめた PR 提案 (3-5 グループ)\n` +
  `- lens_disagreements: lens 間で評価が割れた項目 + その意味\n` +
  `- regression_warnings: 完了済の副作用懸念 (もしあれば)`,
  {
    label: 'synthesize',
    phase: 'Synthesize',
    schema: {
      type: 'object',
      properties: {
        summary:        { type: 'string' },
        top_priorities: { type: 'array', items: {
          type: 'object',
          properties: {
            rank:        { type: 'integer' },
            item_number: { type: 'integer' },
            title:       { type: 'string' },
            severity:    { type: 'string' },
            effort:      { type: 'string' },
            action:      { type: 'string' },
          },
          required: ['rank', 'item_number', 'severity', 'action'],
        } },
        pr_groups: { type: 'array', items: {
          type: 'object',
          properties: {
            name:         { type: 'string' },
            items:        { type: 'array', items: { type: 'integer' } },
            rationale:    { type: 'string' },
          },
          required: ['name', 'items'],
        } },
        lens_disagreements: { type: 'array', items: {
          type: 'object',
          properties: {
            item_number: { type: 'integer' },
            disagreement: { type: 'string' },
            recommended_resolution: { type: 'string' },
          },
          required: ['item_number', 'disagreement'],
        } },
        regression_warnings: { type: 'array', items: { type: 'string' } },
      },
      required: ['summary', 'top_priorities'],
    },
  }
)

return {
  summary: synthesis.summary,
  open_items_evaluated: consolidatedItems.length,
  closed_count: identifyResult.closed_count,
  top_priorities: synthesis.top_priorities,
  pr_groups: synthesis.pr_groups,
  lens_disagreements: synthesis.lens_disagreements ?? [],
  regression_warnings: synthesis.regression_warnings ?? [],
  // 詳細
  consolidated_items: consolidatedItems,
}
