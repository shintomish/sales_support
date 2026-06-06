<?php

namespace App\Services;

use App\Models\DeliveryAddress;
use App\Scopes\TenantScope;
use Illuminate\Support\Facades\Log;

/**
 * バウンス自動抑制。
 *
 * バウンス DSN(RFC 3464) を解析し、配信先 delivery_addresses を自動停止する。
 *
 * 2種類のバウンスを区別する:
 *  - hard  (5.x.x 永久エラー = 宛先不明など): 1回で即時停止。
 *  - expired(4.x.x で最終 give-up = "Message expired" / Unable to lookup DNS など。SES が ~14時間
 *           再試行して諦めた通知): 1回は相手サーバーの一時ダウンの可能性があるため、同一宛先が
 *           閾値(config services.bounce_suppression.expired_threshold・既定2)回に達してから停止。
 *
 * 方針(保守的):
 *  - 一時遅延(Action: delayed)・成功/中継(delivered/relayed/expanded)は対象外。
 *  - 宛先がリスト外/別テナント/既に停止済み は何もしない。
 *  - config services.bounce_suppression.enforce=false(既定)は log-only: 停止対象をログ出力するのみで
 *    is_active は変更しない(段階導入の観察用)。ただし soft_bounce_count / last_bounce_at の
 *    メタデータは log-only でも更新し、enforce 切替時に閾値判定が正しく効くようにする。
 *
 * Kagoya 取込(無認証)から呼ばれるため TenantScope は明示的に外し、tenant_id で必ず絞る。
 */
class BounceSuppressionService
{
    /**
     * raw バウンスメッセージから失敗宛先と種別を抽出する。
     *
     * @return array<int, array{email: string, status: ?string, action: ?string, hard: bool, expired: bool}>
     */
    public function parse(string $raw): array
    {
        if (!preg_match_all(
            '/^Final-Recipient:\s*(?:rfc822|x-[\w-]+)?\s*;?\s*<?([^\s<>;,]+@[^\s<>;,]+?)>?\s*$/im',
            $raw,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            return [];
        }

        $records = [];
        $n = count($m[0]);
        for ($i = 0; $i < $n; $i++) {
            $email = strtolower(trim($m[1][$i][0]));
            if (!str_contains($email, '@')) {
                continue;
            }

            // この Final-Recipient ブロック(次の Final-Recipient まで)で Status/Action/Diagnostic を探す。
            $segStart = $m[0][$i][1];
            $segEnd = ($i + 1 < $n) ? $m[0][$i + 1][1] : strlen($raw);
            $seg = substr($raw, $segStart, $segEnd - $segStart);

            $status = null;
            if (preg_match('/^Status:\s*([0-9]\.[0-9]+\.[0-9]+)/im', $seg, $sm)) {
                $status = $sm[1];
            }
            $action = null;
            if (preg_match('/^Action:\s*([a-zA-Z]+)/im', $seg, $am)) {
                $action = strtolower($am[1]);
            }
            $diagHard = false;
            if ($status === null && preg_match('/Diagnostic-Code:\s*smtp;\s*(\d{3})/im', $seg, $dm)) {
                $diagHard = ($dm[1][0] === '5');
            }

            $isSuccess = in_array($action, ['delivered', 'relayed', 'expanded'], true);
            $isDelayed = $action === 'delayed'; // まだ再試行中(最終通知でない)

            $hard = !$isSuccess && (($status !== null && str_starts_with($status, '5')) || $diagHard);

            // expired = 4.x.x の最終 give-up。Action: failed もしくは本文に expired/unable to deliver。
            $expired = !$hard && !$isSuccess && !$isDelayed
                && ($status !== null && str_starts_with($status, '4'))
                && ($action === 'failed' || preg_match('/message expired|unable to deliver|unable to lookup dns/i', $seg) === 1);

            $records[] = compact('email', 'status', 'action', 'hard', 'expired');
        }

        // 同一宛先が複数ブロックに出る場合は集約(より重い severity を採用)。
        $byEmail = [];
        foreach ($records as $r) {
            $e = $r['email'];
            if (!isset($byEmail[$e])) {
                $byEmail[$e] = $r;
            } else {
                $byEmail[$e]['hard'] = $byEmail[$e]['hard'] || $r['hard'];
                $byEmail[$e]['expired'] = $byEmail[$e]['expired'] || $r['expired'];
            }
        }

        return array_values($byEmail);
    }

    /**
     * raw バウンスから delivery_addresses を抑制する。
     *  - hard(5.x.x): 即時停止(reason=hard_bounce)
     *  - expired(4.x.x give-up): soft_bounce_count を加算し、閾値到達で停止(reason=expired_bounce)
     *
     * @return array<int, string> 停止した(または log-only で停止対象に達した) email 一覧
     */
    public function suppressBounces(string $raw, int $tenantId, ?int $bounceEmailId = null): array
    {
        $enforce   = (bool) config('services.bounce_suppression.enforce', false);
        $threshold = max(1, (int) config('services.bounce_suppression.expired_threshold', 2));
        $suppressed = [];

        foreach ($this->parse($raw) as $rec) {
            if (!$rec['hard'] && !$rec['expired']) {
                continue;
            }

            $address = DeliveryAddress::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->whereRaw('lower(email) = ?', [$rec['email']])
                ->where('is_active', true)
                ->first();

            if (!$address) {
                continue; // リスト外 / 既に停止済み / 別テナント
            }

            $now = now();
            $kind = $rec['hard'] ? 'hard' : 'expired';
            $update = ['last_bounce_at' => $now];
            $reason = null;
            $shouldDisable = false;

            if ($rec['hard']) {
                $shouldDisable = true;
                $reason = 'hard_bounce';
            } else {
                $count = (int) ($address->soft_bounce_count ?? 0) + 1;
                $update['soft_bounce_count'] = $count;
                $shouldDisable = $count >= $threshold;
                $reason = 'expired_bounce';
            }

            // enforce 時のみ実際に停止。log-only でもカウント/last_bounce_at は更新する。
            if ($shouldDisable && $enforce) {
                $update['is_active'] = false;
                $update['unsubscribe_reason'] = $reason;
                $update['unsubscribed_at'] = $now;
            }
            $address->update($update);

            $context = [
                'tenant_id'  => $tenantId,
                'address_id' => $address->id,
                'email'      => $address->email,
                'kind'       => $kind,
                'status'     => $rec['status'],
                'count'      => $update['soft_bounce_count'] ?? null,
                'threshold'  => $threshold,
                'bounce_id'  => $bounceEmailId,
            ];

            if ($shouldDisable) {
                Log::info($enforce
                    ? "[BounceSuppression] 自動停止({$kind})"
                    : "[BounceSuppression] (log-only) 停止対象を検出({$kind}・未適用)", $context);
                $suppressed[] = $address->email;
            } else {
                Log::info('[BounceSuppression] expired カウント加算(閾値未満)', $context);
            }
        }

        return array_values(array_unique($suppressed));
    }
}
