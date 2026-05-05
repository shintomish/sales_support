<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReportRecipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * 自動配信レポートの宛先 CRUD
 * tenant_admin / super_admin のみ操作可。
 */
class ReportRecipientController extends Controller
{
    private const REPORT_TYPES = ['daily_sales'];

    /** GET /api/v1/settings/report-recipients */
    public function index(Request $request): JsonResponse
    {
        $type = $request->query('report_type', 'daily_sales');

        $items = ReportRecipient::query()
            ->where('report_type', $type)
            ->orderByDesc('is_active')
            ->orderBy('email')
            ->get();

        return response()->json(['items' => $items]);
    }

    /** POST /api/v1/settings/report-recipients */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'email'       => ['required', 'email:rfc', 'max:255'],
            'name'        => ['nullable', 'string', 'max:100'],
            'report_type' => ['required', Rule::in(self::REPORT_TYPES)],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        $tenantId = Auth::user()->tenant_id;

        $exists = ReportRecipient::where('tenant_id', $tenantId)
            ->where('email', $validated['email'])
            ->where('report_type', $validated['report_type'])
            ->exists();
        if ($exists) {
            return response()->json([
                'message' => '同じメールアドレスが既に登録されています',
            ], 422);
        }

        $r = ReportRecipient::create($validated + ['is_active' => $validated['is_active'] ?? true]);
        return response()->json($r, 201);
    }

    /** PUT /api/v1/settings/report-recipients/{recipient} */
    public function update(Request $request, ReportRecipient $recipient): JsonResponse
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'email'       => ['required', 'email:rfc', 'max:255'],
            'name'        => ['nullable', 'string', 'max:100'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        $recipient->fill($validated);
        $recipient->save();

        return response()->json($recipient);
    }

    /** DELETE /api/v1/settings/report-recipients/{recipient} */
    public function destroy(ReportRecipient $recipient): JsonResponse
    {
        $this->authorizeAdmin();
        $recipient->delete();
        return response()->json(null, 204);
    }

    private function authorizeAdmin(): void
    {
        $role = Auth::user()->role ?? null;
        if (!in_array($role, ['super_admin', 'tenant_admin'], true)) {
            abort(403, '権限がありません');
        }
    }
}
