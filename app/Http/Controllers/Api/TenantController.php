<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    /**
     * テナント一覧（super_admin のみ）
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->isSuperAdmin()) {
            abort(403, 'super_admin のみアクセス可能です');
        }

        return response()->json(
            Tenant::select('id', 'name', 'slug', 'plan', 'ses_enabled')
                ->orderBy('id')
                ->get()
        );
    }
}
