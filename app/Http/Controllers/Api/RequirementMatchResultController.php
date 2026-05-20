<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RequirementMatchResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 営業による ◯/△/× の手動上書き用 (docs/480 §5 PATCH /v1/requirement-match-results/{id})
 */
class RequirementMatchResultController extends Controller
{
    public function update(Request $request, int $id): JsonResponse
    {
        $record = RequirementMatchResult::findOrFail($id);

        $v = $request->validate([
            'matches'              => 'required|array|min:1',
            'matches.*.label'      => 'required|string|max:200',
            'matches.*.judgment'   => 'required|in:circle,triangle,cross,unknown',
            'matches.*.evidence'   => 'nullable|string|max:1000',
            'matches.*.confidence' => 'nullable|in:high,medium,low',
        ]);

        // ラベルで現行 matches とマージ (Claude 出力の順序を尊重しつつ営業判定を上書き)
        $current = collect($record->matches_json);
        $overrides = collect($v['matches'])->keyBy('label');

        $merged = $current->map(function ($m) use ($overrides) {
            $key = $m['label'] ?? null;
            if ($key && $overrides->has($key)) {
                $o = $overrides->get($key);
                return array_merge($m, [
                    'judgment'        => $o['judgment'],
                    'evidence'        => $o['evidence'] ?? ($m['evidence'] ?? null),
                    'confidence'      => $o['confidence'] ?? ($m['confidence'] ?? 'medium'),
                    'manual_override' => true,
                ]);
            }
            return $m;
        })->all();

        $record->update([
            'matches_json' => $merged,
            'edited_by'    => auth()->id(),
            'edited_at'    => now(),
        ]);

        return response()->json($record->fresh());
    }
}
