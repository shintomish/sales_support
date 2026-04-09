<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailBodyTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailBodyTemplateController extends Controller
{
    /**
     * ログインユーザーのテンプレートを取得
     * GET /v1/email-body-templates/me
     */
    public function show(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $userId   = auth()->id();

        $template = EmailBodyTemplate::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->first();

        return response()->json($template);
    }

    /**
     * ログインユーザーのテンプレートを登録/更新（upsert）
     * PUT /v1/email-body-templates/me
     */
    public function upsert(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $userId   = auth()->id();

        $v = $request->validate([
            'name'       => 'required|string|max:100',
            'name_en'    => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'position'   => 'nullable|string|max:100',
            'email'      => 'nullable|email|max:200',
            'mobile'     => 'nullable|string|max:50',
            'body_text'  => 'nullable|string',
        ]);

        $template = EmailBodyTemplate::updateOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $userId],
            array_merge($v, ['tenant_id' => $tenantId, 'user_id' => $userId]),
        );

        return response()->json($template);
    }
}
