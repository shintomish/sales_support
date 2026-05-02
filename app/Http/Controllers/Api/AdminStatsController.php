<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 管理画面: 機能別データ統計ダッシュボード (#15)
 *
 * - 各機能の総件数 + 直近30日増分
 * - business-cards バケット全体 / cards/ プレフィックスのストレージ容量
 * - tenant 絞り込み (tenant_admin: 自テナントのみ / super_admin: 任意 or all)
 * - 15分キャッシュ
 */
class AdminStatsController extends Controller
{
    /** 集計対象のテーブル一覧（key: 表示名 = ハードコード, value: 物理テーブル名） */
    private const TABLES = [
        'customers',
        'contacts',
        'deals',
        'ses_contracts',
        'engineers',
        'engineer_skills',
        'engineer_profiles',
        'public_projects',
        'project_mail_sources',
        'engineer_mail_sources',
        'delivery_campaigns',
        'delivery_send_histories',
        'activities',
        'tasks',
        'emails',
        'business_cards',
    ];

    /** 集計期間として許可される日数（直近N日の新規追加件数を返す） */
    private const ALLOWED_PERIODS = [7, 30, 90, 365];

    public function index(Request $request)
    {
        $actor = $request->user();
        if (!($actor->isSuperAdmin() || $actor->isTenantAdmin())) {
            abort(403, '権限がありません');
        }

        // tenant_id クエリのパース
        $rawTenant = $request->query('tenant_id');
        $scope     = 'self';        // tenant_admin か super_admin の自テナント
        $tenantId  = (int) $actor->tenant_id;

        if ($actor->isSuperAdmin()) {
            if ($rawTenant === 'all') {
                $scope    = 'all';
                $tenantId = null;
            } elseif ($rawTenant !== null && $rawTenant !== '') {
                $scope    = 'tenant';
                $tenantId = (int) $rawTenant;
            }
        } else {
            // tenant_admin は他テナント指定不可
            if ($rawTenant !== null && $rawTenant !== '' && (int) $rawTenant !== (int) $actor->tenant_id) {
                abort(403, '他テナントの統計は閲覧できません');
            }
        }

        // 期間パラメータ（許可リストに無ければ 30）
        $period = (int) $request->query('period', 30);
        if (!in_array($period, self::ALLOWED_PERIODS, true)) $period = 30;

        $cacheBase  = $scope === 'all' ? 'admin:stats:all' : "admin:stats:tenant:{$tenantId}";
        $cacheKey   = "{$cacheBase}:p{$period}";
        $forceFresh = (bool) $request->boolean('refresh');
        if ($forceFresh) Cache::forget($cacheKey);

        $payload = Cache::remember($cacheKey, 900, function () use ($scope, $tenantId, $period) {
            return $this->build($scope, $tenantId, $period);
        });

        return response()->json($payload);
    }

    private function build(string $scope, ?int $tenantId, int $period): array
    {
        $stats   = [];
        $cutoff  = Carbon::now()->subDays($period);

        foreach (self::TABLES as $table) {
            $hasTenant = $this->tableHasTenantId($table);

            $totalQ = DB::table($table);
            $addedQ = DB::table($table)->where('created_at', '>=', $cutoff);

            if ($hasTenant && $scope !== 'all' && $tenantId !== null) {
                $totalQ->where('tenant_id', $tenantId);
                $addedQ->where('tenant_id', $tenantId);
            }

            $stats[$table] = [
                'total' => (int) $totalQ->count(),
                'added' => (int) $addedQ->count(),
            ];
        }

        // business_cards のストレージ容量（cards_bytes はテナント別）
        $stats['business_cards']['storage'] = $this->businessCardsStorage($scope, $tenantId);

        return [
            'scope'        => $scope,         // 'self' | 'tenant' | 'all'
            'tenant_id'    => $tenantId,
            'period_days'  => $period,
            'generated_at' => now()->toIso8601String(),
            'stats'        => $stats,
        ];
    }

    /** テーブルに tenant_id カラムがあるか（DB 情報スキーマで判定、結果は 1リクエスト内キャッシュ） */
    private function tableHasTenantId(string $table): bool
    {
        static $cache = [];
        if (!isset($cache[$table])) {
            $cache[$table] = DB::getSchemaBuilder()->hasColumn($table, 'tenant_id');
        }
        return $cache[$table];
    }

    /**
     * Supabase Storage の business-cards バケットを集計（storage.objects テーブル直接）
     * - bucket_total_bytes: 全プレフィックス合計（バケット全体・テナント区別なし）
     * - cards_bytes:        business_cards.image_path に対応する Storage オブジェクトの合計（テナント別）
     */
    private function businessCardsStorage(string $scope, ?int $tenantId): array
    {
        try {
            $bucket = config('services.supabase.bucket', 'business-cards');

            // バケット全体は全テナント共通
            $totalRow = DB::selectOne(
                "SELECT COALESCE(SUM((metadata->>'size')::bigint), 0)::bigint AS bytes
                 FROM storage.objects WHERE bucket_id = ?",
                [$bucket]
            );

            // cards_bytes は business_cards との JOIN でテナント別
            $cardsSql = "SELECT COALESCE(SUM((so.metadata->>'size')::bigint), 0)::bigint AS bytes
                         FROM storage.objects so
                         INNER JOIN business_cards bc ON bc.image_path LIKE '%/' || so.name
                         WHERE so.bucket_id = ?
                           AND so.name LIKE 'cards/%'
                           AND bc.deleted_at IS NULL";
            $params = [$bucket];

            if ($scope !== 'all' && $tenantId !== null) {
                $cardsSql .= " AND bc.tenant_id = ?";
                $params[] = $tenantId;
            }

            $cardsRow = DB::selectOne($cardsSql, $params);

            return [
                'bucket_total_bytes' => (int) ($totalRow->bytes ?? 0),
                'cards_bytes'        => (int) ($cardsRow->bytes ?? 0),
            ];
        } catch (\Throwable $e) {
            return ['bucket_total_bytes' => 0, 'cards_bytes' => 0, 'error' => $e->getMessage()];
        }
    }
}
