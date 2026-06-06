<?php

namespace App\Services;

use App\Models\DeliveryAddress;
use App\Scopes\TenantScope;
use Illuminate\Support\Facades\Log;

/**
 * ハードバウンス自動抑制。
 *
 * バウンス DSN(RFC 3464 配送状態通知) を解析し、永久エラー(5.x.x)で失敗した宛先が
 * delivery_addresses に存在すれば is_active=false に落として、以後の一斉配信から除外する。
 *
 * 方針(保守的):
 *  - 永久エラー(Status 5.x.x / Diagnostic-Code smtp 5xx)のみ自動停止。一時エラー(4.x.x)は触れない
 *    (メールボックス満杯・greylisting 等で生きた見込み客を誤って永久停止しないため)。
 *  - 解析できない/宛先がリスト外/別テナント/既に停止済み は何もしない。
 *  - config services.bounce_suppression.enforce=false(既定) のときは log-only(ログのみ・無変更)。
 *    段階導入で本番の停止対象が妥当か観察してから enforce=true に切替える。
 *
 * Kagoya 取込(無認証)から呼ばれるため TenantScope は明示的に外し、tenant_id で必ず絞る。
 */
class BounceSuppressionService
{
    /**
     * raw バウンスメッセージから失敗宛先と種別を抽出する。
     *
     * @return array<int, array{email: string, status: ?string, action: ?string, hard: bool}>
     */
    public function parse(string $raw): array
    {
        // Final-Recipient 行を offset 付きで全件取得(複数宛先 DSN 対応)。
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

            // この Final-Recipient ブロック(次の Final-Recipient まで)の中で Status/Action を探す。
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
            // Status 欠落時の補助: Diagnostic-Code: smtp; 5xx
            $diagHard = false;
            if ($status === null && preg_match('/Diagnostic-Code:\s*smtp;\s*(\d{3})/im', $seg, $dm)) {
                $diagHard = ($dm[1][0] === '5');
            }

            $hard = ($status !== null && str_starts_with($status, '5')) || $diagHard;
            // 成功/中継/一時遅延の通知はハード扱いしない(同一 DSN に成功宛先が混ざるケースの保険)。
            if (in_array($action, ['delivered', 'relayed', 'expanded', 'delayed'], true)) {
                $hard = false;
            }

            $records[] = compact('email', 'status', 'action', 'hard');
        }

        // 同一宛先が複数ブロックに出る場合は集約(どれかが hard なら hard)。
        $byEmail = [];
        foreach ($records as $r) {
            $e = $r['email'];
            if (!isset($byEmail[$e]) || ($r['hard'] && !$byEmail[$e]['hard'])) {
                $byEmail[$e] = $r;
            }
        }

        return array_values($byEmail);
    }

    /**
     * raw バウンスから 5.x.x ハードバウンスの delivery_addresses を無効化する。
     *
     * @return array<int, string> 停止した(または log-only で停止対象とした) email 一覧
     */
    public function suppressHardBounces(string $raw, int $tenantId, ?int $bounceEmailId = null): array
    {
        $enforce = (bool) config('services.bounce_suppression.enforce', false);
        $suppressed = [];

        foreach ($this->parse($raw) as $rec) {
            if (!$rec['hard']) {
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

            $context = [
                'tenant_id'  => $tenantId,
                'address_id' => $address->id,
                'email'      => $address->email,
                'status'     => $rec['status'],
                'bounce_id'  => $bounceEmailId,
            ];

            if ($enforce) {
                $address->update([
                    'is_active'          => false,
                    'unsubscribe_reason' => 'hard_bounce',
                    'unsubscribed_at'    => now(),
                ]);
                Log::info('[BounceSuppression] ハードバウンス自動停止', $context);
            } else {
                Log::info('[BounceSuppression] (log-only) 停止対象を検出（未適用）', $context);
            }

            $suppressed[] = $address->email;
        }

        return $suppressed;
    }
}
