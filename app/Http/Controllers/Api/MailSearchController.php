<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ClaudeService;
use App\Services\SkillDictionary;
use App\Models\AiMatchJudgment;
use App\Models\Engineer;
use App\Models\EngineerMailSource;
use App\Models\ProjectMailSource;
use App\Models\PublicProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * メール横断 検索マッチング（/mail-search）。
 *
 * 「このスキル・この単価で探す」をフリー検索で実現する。重要な方針:
 *   - スコアで足切り・並べ替えしない（低スキル/低単価も拾えるように）。
 *   - 条件一致で抽出し、並び替えはユーザー選択（既定: 単価 昇順）。
 *   - 検索元4種: 案件メール(PMS) / 登録案件(PublicProject) / 技術者メール(EMS) / 登録技術者(Engineer 自社・BP)。
 *
 * kind=project → 案件側（PMS + PublicProject）、kind=engineer → 技術者側（EMS + Engineer）。
 * テナント分離は各モデルの GlobalScope が担当。
 */
class MailSearchController extends Controller
{
    private const PER_PAGE   = 50;
    private const SOURCE_CAP = 300; // 各ソースの取得上限（メモリ保護。条件で絞れば十分）

    /** スキル語の結合方式: 'or'=いずれか含む(既定) / 'and'=すべて含む。search() で確定しリクエスト内で共有 */
    private string $skillMode = 'or';

    public function search(Request $request): JsonResponse
    {
        $v = $request->validate([
            'kind'       => ['required', 'in:project,engineer'],
            'category'   => ['nullable', 'in:all,mail,self,bp'], // 全て/メール/自社/BP
            'skill'      => ['nullable', 'string', 'max:200'],
            'skill_mode' => ['nullable', 'in:or,and'],           // スキル複数語の結合: OR(既定)/AND
            'keyword'    => ['nullable', 'string', 'max:200'],
            'price_min'  => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'price_max'  => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'sort'       => ['nullable', 'in:price_asc,price_desc,recent,skill_match'],
            'page'       => ['nullable', 'integer', 'min:1'],
        ]);
        $category = $v['category'] ?? 'all';
        $this->skillMode = $v['skill_mode'] ?? 'or';

        $terms    = $this->splitTerms($v['skill'] ?? '');
        $keyword  = trim((string) ($v['keyword'] ?? ''));
        $priceMin = isset($v['price_min']) ? (float) $v['price_min'] : null;
        $priceMax = isset($v['price_max']) ? (float) $v['price_max'] : null;
        $sort     = $v['sort'] ?? 'price_asc';
        $page     = max(1, (int) ($v['page'] ?? 1));

        // 分類フィルタ: メール=PMS/EMS、自社=登録案件+自社技術者、BP=BP技術者
        $rows = [];
        if ($v['kind'] === 'project') {
            if (in_array($category, ['all', 'mail'], true)) {
                $rows = array_merge($rows, $this->searchProjectMails($terms, $keyword, $priceMin, $priceMax, $sort));
            }
            if (in_array($category, ['all', 'self'], true)) {
                $rows = array_merge($rows, $this->searchPublicProjects($terms, $keyword, $priceMin, $priceMax, $sort));
            }
            // bp は案件側に該当なし（空）
        } else {
            if (in_array($category, ['all', 'mail'], true)) {
                $rows = array_merge($rows, $this->searchEngineerMails($terms, $keyword, $priceMin, $priceMax, $sort));
            }
            if (in_array($category, ['all', 'self', 'bp'], true)) {
                $affiliation = $category === 'self' ? 'self' : ($category === 'bp' ? 'bp' : 'all');
                $rows = array_merge($rows, $this->searchEngineers($terms, $keyword, $priceMin, $priceMax, $sort, $affiliation));
            }
        }

        $rows = $this->sortRows($rows, $sort);

        $total    = count($rows);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $items    = array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        return response()->json([
            'data'         => array_values($items),
            'total'        => $total,
            'current_page' => $page,
            'last_page'    => $lastPage,
        ]);
    }

    /**
     * POST /api/v1/mail-search/parse — 自然文の検索文を条件(JSON)に解釈（AI/haiku）。
     * 例: 「Javaできて即日・70万くらい」→ {skills:["Java"], price_min:60, price_max:80, keyword:"即日"}
     */
    public function parse(Request $request): JsonResponse
    {
        // 自然文だけでなく、案件/技術者メールの本文を丸ごと貼り付けても抽出できるよう長文を許容。
        $v = $request->validate(['text' => ['required', 'string', 'max:8000']]);
        $text = mb_substr($v['text'], 0, 8000);
        $prompt = <<<PROMPT
あなたはSES(技術者派遣)の検索アシスタントです。次の入力（検索文、または案件/技術者メールの本文をそのまま貼り付けたもの）から、マッチング検索に使う条件を抽出し、厳密なJSONのみを出力してください（前後に説明やコードフェンスは禁止）。
形式:
{"skills":["..."],"price_min":数値またはnull,"price_max":数値またはnull,"keyword":"..."またはnull}
ルール:
- skills: 技術名/スキル(Java, TypeScript, AWS, PM, NW運用 等)。本文中の必須・尚可・保有スキルを拾う。複数可。無ければ []。
- price_min/price_max: 単価(単位=万)。「70万以上」→price_min=70。「60万以下/まで」→price_max=60。「35万」「70万くらい」→±0〜10万程度の妥当な範囲。無ければ null。
- keyword: スキル・単価以外の絞り込み語(勤務地・最寄駅・即日・リモート・常駐 等を1〜2語)。無ければ null。
- メール本文の場合は、挨拶/署名/会社名は無視し、技術者または案件の要件のみを対象にする。
入力:
{$text}
PROMPT;
        try {
            $json = $this->parseJsonLoose(app(ClaudeService::class)->ask($prompt));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'AI解釈に失敗しました'], 502);
        }
        return response()->json([
            'skills'    => array_values(array_filter(array_map('strval', (array) ($json['skills'] ?? [])))),
            'price_min' => is_numeric($json['price_min'] ?? null) ? (float) $json['price_min'] : null,
            'price_max' => is_numeric($json['price_max'] ?? null) ? (float) $json['price_max'] : null,
            'keyword'   => isset($json['keyword']) && is_string($json['keyword']) ? $json['keyword'] : null,
        ]);
    }

    /** 1リクエストで新規にAI判定する最大件数（キャッシュ済みは無制限）。コスト/レイテンシのガード。 */
    private const BULK_AI_CAP = 30;

    /** AI判定キャッシュの有効期限（日）。これより古い判定は無視して再判定する（陳腐化対策）。 */
    private const JUDGMENT_TTL_DAYS = 14;

    /**
     * POST /api/v1/mail-search/judge — 候補1件が検索意図にどの程度合うかAI判定（haiku・オンデマンド）。
     * キャッシュ(ai_match_judgments)を参照・保存する。
     */
    public function judge(Request $request): JsonResponse
    {
        $v = $request->validate([
            'query'         => ['required', 'string', 'max:500'],
            'item.type'     => ['nullable', 'string', 'max:50'],   // source enum（キャッシュキー）
            'item.label'    => ['nullable', 'string', 'max:50'],   // 表示用 種別（プロンプト用）
            'item.id'       => ['nullable', 'integer'],
            'item.title'    => ['nullable', 'string', 'max:300'],
            'item.skills'   => ['nullable', 'string', 'max:1000'],
            'item.price'    => ['nullable', 'string', 'max:50'],
            'item.sub'      => ['nullable', 'string', 'max:200'],
            'item.location' => ['nullable', 'string', 'max:200'],
            'refresh'       => ['nullable', 'boolean'],
        ]);
        $it      = $v['item'] ?? [];
        $query   = trim($v['query']);
        $qHash   = $this->queryHash($query);
        $type    = $it['type'] ?? null;
        $id      = isset($it['id']) ? (int) $it['id'] : null;
        $refresh = (bool) ($v['refresh'] ?? false);

        // キャッシュ参照（type/id があり、かつ refresh でない場合）
        if (!$refresh && $type && $id && in_array($type, AiMatchJudgment::TYPES, true)) {
            $hit = AiMatchJudgment::where('query_hash', $qHash)->where('target_type', $type)->where('target_id', $id)
                ->where('updated_at', '>=', now()->subDays(self::JUDGMENT_TTL_DAYS))->first();
            if ($hit) {
                return response()->json(['verdict' => $hit->verdict, 'reason' => $hit->reason, 'cached' => true]);
            }
        }

        try {
            $text = app(ClaudeService::class)->ask($this->buildJudgePrompt($query, $it));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'AI判定に失敗しました'], 502);
        }
        [$verdict, $reason] = $this->parseJudge($text);
        $this->storeJudgment($qHash, $type, $id, $verdict, $reason);

        return response()->json(['verdict' => $verdict, 'reason' => $reason, 'cached' => false]);
    }

    /**
     * POST /api/v1/mail-search/judge-bulk — 複数候補を一括AI判定。
     * キャッシュ優先 → 未判定のみ並列(haiku)で判定（1回あたり最大 BULK_AI_CAP 件）→ 保存。
     */
    public function judgeBulk(Request $request): JsonResponse
    {
        $v = $request->validate([
            'query'             => ['required', 'string', 'max:500'],
            'items'             => ['required', 'array', 'max:60'],
            'items.*.type'      => ['required', 'in:project_mail,public_project,engineer_mail,engineer'],
            'items.*.label'     => ['nullable', 'string', 'max:50'],
            'items.*.id'        => ['required', 'integer'],
            'items.*.title'     => ['nullable', 'string', 'max:300'],
            'items.*.skills'    => ['nullable', 'string', 'max:1000'],
            'items.*.price'     => ['nullable', 'string', 'max:50'],
            'items.*.sub'       => ['nullable', 'string', 'max:200'],
            'items.*.location'  => ['nullable', 'string', 'max:200'],
            'refresh'           => ['nullable', 'boolean'],
        ]);
        $query   = trim($v['query']);
        $qHash   = $this->queryHash($query);
        $items   = $v['items'];
        $refresh = (bool) ($v['refresh'] ?? false);

        // 1) キャッシュ一括取得（TTL内のみ。期限切れは未判定扱い→再判定）
        $cached = [];
        if (!$refresh) {
            $rows = AiMatchJudgment::where('query_hash', $qHash)
                ->where('updated_at', '>=', now()->subDays(self::JUDGMENT_TTL_DAYS))->get();
            foreach ($rows as $r) {
                $cached["{$r->target_type}:{$r->target_id}"] = ['verdict' => $r->verdict, 'reason' => $r->reason, 'cached' => true];
            }
        }

        // 2) 未判定を抽出（最大 BULK_AI_CAP 件だけ新規判定）
        $toJudge = [];
        foreach ($items as $it) {
            $key = "{$it['type']}:{$it['id']}";
            if (isset($cached[$key]) || isset($toJudge[$key])) continue;
            $toJudge[$key] = $it;
        }
        $capped = array_slice($toJudge, 0, self::BULK_AI_CAP, true);

        // 3) 並列AI判定 → 保存
        $fresh = [];
        if (!empty($capped)) {
            $prompts = [];
            foreach ($capped as $key => $it) $prompts[$key] = $this->buildJudgePrompt($query, $it);
            $texts = app(ClaudeService::class)->askMany($prompts);
            foreach ($capped as $key => $it) {
                $text = $texts[$key] ?? null;
                if ($text === null) continue; // 失敗は未判定のまま（再試行可）
                [$verdict, $reason] = $this->parseJudge($text);
                $fresh[$key] = ['verdict' => $verdict, 'reason' => $reason, 'cached' => false];
                $this->storeJudgment($qHash, $it['type'], (int) $it['id'], $verdict, $reason);
            }
        }

        // 4) items 順にマージして返す
        $out = [];
        foreach ($items as $it) {
            $key = "{$it['type']}:{$it['id']}";
            $r = $cached[$key] ?? $fresh[$key] ?? null;
            $out[] = [
                'type'    => $it['type'],
                'id'      => (int) $it['id'],
                'verdict' => $r['verdict'] ?? null,
                'reason'  => $r['reason'] ?? null,
                'cached'  => $r['cached'] ?? false,
                'judged'  => $r !== null,
            ];
        }
        return response()->json(['data' => $out, 'ai_calls' => count($fresh)]);
    }

    /** 検索意図文字列 → キャッシュ用ハッシュ（小文字化・空白圧縮で表記揺れを吸収）。 */
    private function queryHash(string $query): string
    {
        $norm = mb_strtolower(trim($query));
        $norm = preg_replace('/\s+/u', ' ', $norm);
        return hash('sha256', $norm);
    }

    /** 判定プロンプト（種別は label があれば優先・無ければ type）。 */
    private function buildJudgePrompt(string $query, array $it): string
    {
        $label = $it['label'] ?? ($it['type'] ?? '');
        $title = $it['title'] ?? '';
        $skills = $it['skills'] ?? '';
        $price = $it['price'] ?? '';
        $sub = $it['sub'] ?? '';
        $location = $it['location'] ?? '';
        return <<<PROMPT
次の「探している条件」に対し、候補がどの程度合うかを判定し、厳密なJSONのみ出力してください（説明やコードフェンス禁止）。
形式: {"verdict":"◯"または"△"または"×","reason":"40字以内の理由"}
判定基準: ◯=よく合う / △=一部合う・情報不足 / ×=合わない。
探している条件: {$query}
候補: 種別={$label} / 名称={$title} / スキル={$skills} / 単価={$price} / 所属={$sub} / 勤務地={$location}
PROMPT;
    }

    /** AI応答 → [verdict, reason]。verdict は ◯/△/× に正規化（不明は △）。 */
    private function parseJudge(string $text): array
    {
        $json = $this->parseJsonLoose($text);
        $verdict = in_array($json['verdict'] ?? '', ['◯', '△', '×'], true) ? $json['verdict'] : '△';
        $reason  = isset($json['reason']) && is_string($json['reason']) ? mb_substr($json['reason'], 0, 60) : '';
        return [$verdict, $reason];
    }

    /** 判定をキャッシュに保存（type/id があるときのみ）。 */
    private function storeJudgment(string $qHash, ?string $type, ?int $id, string $verdict, string $reason): void
    {
        if (!$type || !$id) return;
        if (!in_array($type, AiMatchJudgment::TYPES, true)) return;
        AiMatchJudgment::updateOrCreate(
            ['tenant_id' => Auth::user()->tenant_id, 'query_hash' => $qHash, 'target_type' => $type, 'target_id' => $id],
            ['verdict' => $verdict, 'reason' => $reason],
        );
    }

    /** Claude 応答からJSONを緩く抽出（コードフェンス・前後文を許容） */
    private function parseJsonLoose(string $text): array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?|```$/m', '', $text);
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) return $decoded;
        }
        return [];
    }

    /** スキル入力を語に分割（空白・カンマ・読点・スラッシュ区切り） */
    private function splitTerms(string $skill): array
    {
        $parts = preg_split('/[\s,、，\/／]+/u', trim($skill)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn($t) => $t !== ''));
    }

    /** 検索語を辞書で全表記揺れに展開（ILIKE の OR 用）。未知語は自身のみ。 */
    private function expandTerms(array $terms): array
    {
        if (empty($terms)) return [];
        $dict = app(SkillDictionary::class);
        $out = [];
        foreach ($terms as $t) {
            $out[] = $t;
            foreach ($dict->expand($t) as $f) $out[] = $f;
        }
        return array_values(array_unique(array_filter($out, fn($x) => trim((string) $x) !== '')));
    }

    /** 行のスキル配列に対し、検索語のうち何件含むか（辞書で名寄せ・語境界一致・大小無視） */
    private function countMatched(array $terms, array $skillNames): array
    {
        if (empty($terms)) return [];
        $dict = app(SkillDictionary::class);
        $canonNames = array_map(fn($n) => $dict->canonical((string) $n), $skillNames);
        $matched = [];
        foreach ($terms as $t) {
            $tc = $dict->canonical($t);
            if ($tc === '') continue;
            foreach ($canonNames as $i => $cn) {
                if ($cn !== '' && $this->skillCanonMatch($cn, $tc)) {
                    $matched[] = $skillNames[$i] ?? $t;
                    break;
                }
            }
        }
        return array_values(array_unique($matched));
    }

    /**
     * スキル名の正規化名($cn)が検索語の正規化名($tc)に一致するか。
     * SQL 側(addSkillForm)と同じトークン境界一致（+#.- は語構成・それ以外は区切り）。
     * "java"≠"javascript"、"c"≠"c++"、"core java" は "java" でヒット。
     */
    private function skillCanonMatch(string $cn, string $tc): bool
    {
        if ($cn === $tc) return true;
        if ($tc === '') return false;
        $sep = '[^' . self::SKILL_WORD . ']';
        $pattern = '/(^|' . $sep . ')' . preg_quote($tc, '/') . '(' . $sep . '|$)/iu';
        return (bool) preg_match($pattern, $cn);
    }

    /** 単価フィルタ: 既知価格は範囲判定、不明(null)は除外しない（取りこぼし防止） */
    private function priceOk(?float $val, ?float $min, ?float $max): bool
    {
        if ($val === null) return true;
        if ($min !== null && $val < $min) return false;
        if ($max !== null && $val > $max) return false;
        return true;
    }

    /**
     * SQL 段階の単価フィルタ（cap+ORDER BY より前に効かせる）。
     * これが無いと price_desc で cap が高額順の上位300件を取り、PHPの範囲外除外で0件になる不具合が出る。
     * 不明(null)は除外しない（取りこぼし防止）。Engineer は希望単価が profile 別表のため PHP 側で処理。
     */
    private function applyPriceFilter($q, ?float $min, ?float $max, string $col = 'unit_price_max'): void
    {
        if ($min !== null) $q->where(fn($w) => $w->where($col, '>=', $min)->orWhereNull($col));
        if ($max !== null) $q->where(fn($w) => $w->where($col, '<=', $max)->orWhereNull($col));
    }

    /**
     * 取得(cap)段階のSQL並び順。件数が多いソースで cap 内に「正しい行」を残すため。
     * price系は unit_price_max を採用（最安/最高を確実に拾う）。それ以外は新着(created_at)。
     */
    private function applyOrder($q, string $sort, string $priceCol = 'unit_price_max'): void
    {
        switch ($sort) {
            case 'price_asc':  $q->orderByRaw("{$priceCol} ASC NULLS LAST");  break;
            case 'price_desc': $q->orderByRaw("{$priceCol} DESC NULLS LAST"); break;
            default:           $q->orderByDesc('created_at');                 break; // recent / skill_match
        }
    }

    // ── 案件メール（PMS）────────────────────────────────
    private function searchProjectMails(array $terms, string $keyword, ?float $min, ?float $max, string $sort): array
    {
        $q = ProjectMailSource::query()->with('email:id,received_at,arrived_at');
        $this->applySkillJson($q, $terms, ['required_skills', 'preferred_skills']);
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $q->where(fn($w) => $w->where('title', 'ilike', $like)->orWhere('customer_name', 'ilike', $like)->orWhere('work_location', 'ilike', $like));
        }
        $this->applyPriceFilter($q, $min, $max);
        $this->applyOrder($q, $sort);
        $out = [];
        foreach ($q->limit(self::SOURCE_CAP)->get() as $p) {
            $price = $p->unit_price_max !== null ? (float) $p->unit_price_max : null;
            if (!$this->priceOk($price, $min, $max)) continue;
            $skills = array_merge((array) ($p->required_skills ?? []), (array) ($p->preferred_skills ?? []));
            $out[] = [
                'source' => 'project_mail', 'source_label' => '案件メール', 'is_registered' => false,
                'id' => $p->id, 'title' => $p->title ?: '(件名なし)', 'sub' => $p->customer_name,
                'skills' => array_values($skills), 'matched_skills' => $this->countMatched($terms, $skills),
                'unit_price_min' => $p->unit_price_min !== null ? (float) $p->unit_price_min : null,
                'unit_price_max' => $price, 'location' => $p->work_location,
                'date' => optional($p->email)->received_at?->toIso8601String() ?? $p->created_at?->toIso8601String(),
                'detail_url' => "/project-mails?select={$p->id}",
            ];
        }
        return $out;
    }

    // ── 登録案件（PublicProject）─────────────────────────
    private function searchPublicProjects(array $terms, string $keyword, ?float $min, ?float $max, string $sort): array
    {
        $q = PublicProject::query()->with('skills:id,name');
        $this->applySkillRelation($q, 'skills', $terms);
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $q->where(fn($w) => $w->where('title', 'ilike', $like)->orWhere('work_location', 'ilike', $like));
        }
        $this->applyPriceFilter($q, $min, $max);
        $this->applyOrder($q, $sort);
        $out = [];
        foreach ($q->limit(self::SOURCE_CAP)->get() as $p) {
            $price = $p->unit_price_max !== null ? (float) $p->unit_price_max : null;
            if (!$this->priceOk($price, $min, $max)) continue;
            $skills = $p->skills->pluck('name')->all();
            $out[] = [
                'source' => 'public_project', 'source_label' => '登録案件', 'is_registered' => true,
                'id' => $p->id, 'title' => $p->title ?: '(無題)', 'sub' => null,
                'skills' => $skills, 'matched_skills' => $this->countMatched($terms, $skills),
                'unit_price_min' => $p->unit_price_min !== null ? (float) $p->unit_price_min : null,
                'unit_price_max' => $price, 'location' => $p->work_location,
                'date' => $p->published_at?->toIso8601String() ?? $p->created_at?->toIso8601String(),
                'detail_url' => "/public-projects/{$p->id}",
            ];
        }
        return $out;
    }

    // ── 技術者メール（EMS）──────────────────────────────
    private function searchEngineerMails(array $terms, string $keyword, ?float $min, ?float $max, string $sort): array
    {
        $q = EngineerMailSource::query()->with('email:id,received_at,arrived_at');
        $this->applySkillJson($q, $terms, ['skills']);
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $q->where(fn($w) => $w->where('name', 'ilike', $like)->orWhere('affiliation', 'ilike', $like)->orWhere('nearest_station', 'ilike', $like));
        }
        $this->applyPriceFilter($q, $min, $max);
        $this->applyOrder($q, $sort);
        $out = [];
        foreach ($q->limit(self::SOURCE_CAP)->get() as $e) {
            $price = $e->unit_price_max !== null ? (float) $e->unit_price_max : null;
            if (!$this->priceOk($price, $min, $max)) continue;
            $skills = (array) ($e->skills ?? []);
            $out[] = [
                'source' => 'engineer_mail', 'source_label' => '技術者メール', 'is_registered' => false,
                'id' => $e->id, 'title' => $e->name ?: '(氏名なし)', 'sub' => $e->affiliation,
                'skills' => array_values($skills), 'matched_skills' => $this->countMatched($terms, $skills),
                'unit_price_min' => $e->unit_price_min !== null ? (float) $e->unit_price_min : null,
                'unit_price_max' => $price, 'location' => $e->nearest_station,
                'date' => optional($e->email)->received_at?->toIso8601String() ?? $e->created_at?->toIso8601String(),
                'detail_url' => "/engineer-mails?select={$e->id}",
            ];
        }
        return $out;
    }

    // ── 登録技術者（Engineer 自社・BP）──────────────────
    private function searchEngineers(array $terms, string $keyword, ?float $min, ?float $max, string $sort, string $affiliation = 'all'): array
    {
        // 登録技術者は件数が少なく、希望単価は profile(別テーブル)のため、cap段階は新着順で取得し
        // 最終的な単価並び替えは PHP 側(sortRows)で行う。
        $q = Engineer::query()->with(['engineerSkills.skill:id,name', 'profile:id,engineer_id,desired_unit_price_min,desired_unit_price_max'])
            ->orderByDesc('created_at');
        // 自社/BP 判定は EngineerController と同一規約（self=affiliation_type='self' / bp=それ以外）
        if ($affiliation === 'self') {
            $q->where('affiliation_type', 'self');
        } elseif ($affiliation === 'bp') {
            $q->where(fn($w) => $w->where('affiliation_type', '!=', 'self')->orWhereNull('affiliation_type'));
        }
        $this->applySkillRelation($q, 'engineerSkills.skill', $terms);
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $q->where(fn($w) => $w->where('name', 'ilike', $like)->orWhere('affiliation', 'ilike', $like)->orWhere('nearest_station', 'ilike', $like));
        }
        $out = [];
        foreach ($q->limit(self::SOURCE_CAP)->get() as $e) {
            $price = $e->profile?->desired_unit_price_max !== null ? (float) $e->profile->desired_unit_price_max : null;
            if (!$this->priceOk($price, $min, $max)) continue;
            $skills = $e->engineerSkills->map(fn($es) => $es->skill?->name)->filter()->values()->all();
            $isSelf = ($e->affiliation_type ?? '') === 'self';
            $out[] = [
                'source' => 'engineer', 'source_label' => $isSelf ? '登録技術者(自社)' : '登録技術者(BP)', 'is_registered' => true,
                'id' => $e->id, 'title' => $e->name ?: '(氏名なし)', 'sub' => $e->affiliation,
                'skills' => $skills, 'matched_skills' => $this->countMatched($terms, $skills),
                'unit_price_min' => $e->profile?->desired_unit_price_min !== null ? (float) $e->profile->desired_unit_price_min : null,
                'unit_price_max' => $price, 'location' => $e->nearest_station,
                'date' => $e->created_at?->toIso8601String(),
                'detail_url' => "/engineers/{$e->id}",
            ];
        }
        return $out;
    }

    // スキル名の「語」を構成する文字（英数字＋記号 +#.-）。これ以外（空白・引用符・角括弧・
    // カンマ・日本語等）は語の区切りとみなす。+#.- を語構成に含めることで "C" が "C++"/"C#" を、
    // "java" が "javascript" を、"Node" が "Node.js" を拾わない（別スキルは別語）。
    private const SKILL_WORD = 'A-Za-z0-9+#.-';

    /** POSIX ERE / PCRE のメタ文字をエスケープ（リテラル一致用） */
    private function escapeRegex(string $s): string
    {
        return preg_replace('/[.+*?()\[\]{}|^$\\\\]/', '\\\\$0', $s);
    }

    /**
     * スキル1表記($form)を SQL 式($expr)に OR 条件で足す。
     * トークン境界一致: $form が「語」全体として現れる時のみヒット（前後が語構成文字でない）。
     * これにより "C"→"C++"、"java"→"javascript" のような別スキルへの部分一致を防ぐ。
     * "Core Java" / "AWS Lambda" 等は空白区切りなので各語がヒットする。日本語は各文字が区切り
     * 扱いになり実質部分一致（従来どおり）。同義語の橋渡しは SkillDictionary が担う。
     */
    private function addSkillForm($w, string $expr, string $form): void
    {
        if ($form === '') return;
        $sep = '[^' . self::SKILL_WORD . ']';
        $pattern = '(^|' . $sep . ')' . $this->escapeRegex($form) . '(' . $sep . '|$)';
        $w->orWhereRaw("{$expr} ~* ?", [$pattern]);
    }

    /** json スキルカラム（配列）に対する「いずれかの語を含む」フィルタ（辞書で表記揺れ展開） */
    private function applySkillJson($q, array $terms, array $columns): void
    {
        if (empty($terms)) return;

        // 1語ぶんの条件: その語＋辞書同義語を、対象カラム横断で OR（表記揺れ吸収・語境界一致）。
        // ★ skills 系は json 型で、Laravel の json_encode が非ASCIIを \uXXXX にエスケープして
        //   保存する。素の ::text だと「ヘルプデスク」等の日本語スキルが \u 列と照合され一致しない。
        //   ::jsonb::text で \u を実文字へ正規化してから照合する（ASCII もそのまま一致）。
        $applyOneTerm = function ($w, string $term) use ($columns) {
            foreach ($columns as $col) {
                foreach ($this->expandTerms([$term]) as $f) {
                    $this->addSkillForm($w, "{$col}::jsonb::text", $f);
                }
            }
        };

        if ($this->skillMode === 'and') {
            // AND: 各語ごとに独立した where を重ねる（すべての語を含む行のみ）
            foreach ($terms as $term) {
                $q->where(fn($w) => $applyOneTerm($w, $term));
            }
        } else {
            // OR: 全語を1つの where 内で OR（いずれかを含む行）
            $q->where(function ($w) use ($terms, $applyOneTerm) {
                foreach ($terms as $term) $applyOneTerm($w, $term);
            });
        }
    }

    /** リレーション（PublicProject.skills / Engineer.engineerSkills.skill）へのスキル条件。OR/AND 対応 */
    private function applySkillRelation($q, string $relation, array $terms): void
    {
        if (empty($terms)) return;

        $matchTerm = function ($s, string $term) {
            $s->where(function ($w) use ($term) {
                foreach ($this->expandTerms([$term]) as $f) $this->addSkillForm($w, 'name', $f);
            });
        };

        if ($this->skillMode === 'and') {
            // AND: 語ごとに whereHas を重ねる（各語に一致するスキルを持つ行のみ）
            foreach ($terms as $term) {
                $q->whereHas($relation, fn($s) => $matchTerm($s, $term));
            }
        } else {
            // OR: 1つの whereHas 内で全語を OR
            $q->whereHas($relation, function ($s) use ($terms) {
                $s->where(function ($w) use ($terms) {
                    foreach ($terms as $term) {
                        foreach ($this->expandTerms([$term]) as $f) $this->addSkillForm($w, 'name', $f);
                    }
                });
            });
        }
    }

    /** 並び替え（既定: 単価 昇順）。スコアは使わない。 */
    private function sortRows(array $rows, string $sort): array
    {
        $cmpPrice = function ($a, $b, bool $asc) {
            $av = $a['unit_price_max']; $bv = $b['unit_price_max'];
            if ($av === null && $bv === null) return 0;
            if ($av === null) return 1;   // 不明は常に末尾
            if ($bv === null) return -1;
            return $asc ? ($av <=> $bv) : ($bv <=> $av);
        };
        usort($rows, function ($a, $b) use ($sort, $cmpPrice) {
            switch ($sort) {
                case 'price_desc':  return $cmpPrice($a, $b, false);
                case 'recent':      return strcmp((string) $b['date'], (string) $a['date']);
                case 'skill_match':
                    $d = count($b['matched_skills']) <=> count($a['matched_skills']);
                    return $d !== 0 ? $d : $cmpPrice($a, $b, true);
                case 'price_asc':
                default:            return $cmpPrice($a, $b, true);
            }
        });
        return $rows;
    }
}
