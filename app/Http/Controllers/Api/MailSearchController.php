<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Engineer;
use App\Models\EngineerMailSource;
use App\Models\ProjectMailSource;
use App\Models\PublicProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function search(Request $request): JsonResponse
    {
        $v = $request->validate([
            'kind'      => ['required', 'in:project,engineer'],
            'category'  => ['nullable', 'in:all,mail,self,bp'], // 全て/メール/自社/BP
            'skill'     => ['nullable', 'string', 'max:200'],
            'keyword'   => ['nullable', 'string', 'max:200'],
            'price_min' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'price_max' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'sort'      => ['nullable', 'in:price_asc,price_desc,recent,skill_match'],
            'page'      => ['nullable', 'integer', 'min:1'],
        ]);
        $category = $v['category'] ?? 'all';

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

    /** スキル入力を語に分割（空白・カンマ・読点・スラッシュ区切り） */
    private function splitTerms(string $skill): array
    {
        $parts = preg_split('/[\s,、，\/／]+/u', trim($skill)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn($t) => $t !== ''));
    }

    /** 行のスキル配列に対し、検索語のうち何件含むか（部分一致・大小無視） */
    private function countMatched(array $terms, array $skillNames): array
    {
        if (empty($terms)) return [];
        $lcNames = array_map(fn($n) => mb_strtolower((string) $n), $skillNames);
        $matched = [];
        foreach ($terms as $t) {
            $lt = mb_strtolower($t);
            foreach ($lcNames as $i => $n) {
                if ($n !== '' && (str_contains($n, $lt) || str_contains($lt, $n))) {
                    $matched[] = $skillNames[$i] ?? $t;
                    break;
                }
            }
        }
        return array_values(array_unique($matched));
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
                'detail_url' => "/matching/{$p->id}",
            ];
        }
        return $out;
    }

    // ── 登録案件（PublicProject）─────────────────────────
    private function searchPublicProjects(array $terms, string $keyword, ?float $min, ?float $max, string $sort): array
    {
        $q = PublicProject::query()->with('skills:id,name');
        if (!empty($terms)) {
            $q->whereHas('skills', function ($s) use ($terms) {
                $s->where(function ($w) use ($terms) {
                    foreach ($terms as $t) $w->orWhere('name', 'ilike', '%' . $t . '%');
                });
            });
        }
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $q->where(fn($w) => $w->where('title', 'ilike', $like)->orWhere('work_location', 'ilike', $like));
        }
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
                'detail_url' => '/engineer-mails',
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
        if (!empty($terms)) {
            $q->whereHas('engineerSkills.skill', function ($s) use ($terms) {
                $s->where(function ($w) use ($terms) {
                    foreach ($terms as $t) $w->orWhere('name', 'ilike', '%' . $t . '%');
                });
            });
        }
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

    /** json スキルカラム（配列）に対する「いずれかの語を含む」フィルタ */
    private function applySkillJson($q, array $terms, array $columns): void
    {
        if (empty($terms)) return;
        $q->where(function ($w) use ($terms, $columns) {
            foreach ($columns as $col) {
                foreach ($terms as $t) {
                    $w->orWhereRaw("{$col}::text ILIKE ?", ['%' . $t . '%']);
                }
            }
        });
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
