<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailDeliveryTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 配信テンプレライブラリ CRUD（テナント共有）。
 * TenantScope が tenant_id を自動付与/絞り込みするため、明示の where は不要。
 */
class EmailDeliveryTemplateController extends Controller
{
    /**
     * 一覧。purpose / is_active で絞り込み可。
     * GET /v1/email-delivery-templates?purpose=real_spot&only_active=1
     */
    public function index(Request $request): JsonResponse
    {
        $templates = EmailDeliveryTemplate::query()
            ->when($request->filled('purpose'), fn ($q) => $q->where('purpose', $request->input('purpose')))
            ->when($request->boolean('only_active'), fn ($q) => $q->where('is_active', true))
            ->orderByDesc('updated_at')
            ->get();

        return response()->json($templates);
    }

    /**
     * 作成。
     * POST /v1/email-delivery-templates
     */
    public function store(Request $request): JsonResponse
    {
        $v = $this->validatePayload($request);
        $v['user_id'] = auth()->id();

        $template = EmailDeliveryTemplate::create($v);

        return response()->json($template, 201);
    }

    /**
     * 更新。
     * PUT /v1/email-delivery-templates/{template}
     */
    public function update(Request $request, EmailDeliveryTemplate $template): JsonResponse
    {
        $template->update($this->validatePayload($request));

        return response()->json($template);
    }

    /**
     * 削除。
     * DELETE /v1/email-delivery-templates/{template}
     */
    public function destroy(EmailDeliveryTemplate $template): JsonResponse
    {
        $template->delete();

        return response()->json(['deleted' => true]);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'purpose'   => ['required', Rule::in(EmailDeliveryTemplate::PURPOSES)],
            'name'      => 'required|string|max:100',
            'subject'   => 'nullable|string|max:500',
            'body_text' => 'nullable|string',
            'is_active' => 'boolean',
        ]);
    }
}
