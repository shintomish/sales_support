<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\KagoyaMailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SES Inbound 受信エンドポイント (B-light)。
 *
 * AWS Lambda (Tokyo) が SES 受信 → S3 の生 RFC822 を取得し、共有シークレットヘッダ付きで
 * ここに POST する。Kagoya の INBOX 配送遅延 (~2.5h・本番実測 p50 2.3h) を回避し、
 * arrived_at を SES 受信時刻にして「受信」表示を準リアルタイム化する。
 *
 * 認証は Supabase JWT ではなく共有シークレット (hash_equals)。ルートは認証不要グループに置く。
 * IMAP 経路は恒久バックアップ + dedup anchor として温存し、本経路と rfc_message_id で重複排除する。
 *
 * リクエスト (JSON):
 *   { "ses_message_id": "0abc...", "received_at": "2026-06-10T02:57:01Z", "raw_base64": "<base64 RFC822>" }
 * raw はバイナリ安全のため base64 で受ける。received_at は SES 受信時刻 (ISO8601)。
 */
class InboundEmailController extends Controller
{
    public function store(Request $request, KagoyaMailService $mail): JsonResponse
    {
        // ── 共有シークレット検証 (タイミング安全比較) ──
        $expected = (string) config('services.inbound.secret');
        $provided = (string) $request->header('X-Inbound-Secret', '');
        if ($expected === '' || !hash_equals($expected, $provided)) {
            Log::warning('[InboundEmail] 認証失敗', ['ip' => $request->ip()]);
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'ses_message_id' => ['required', 'string', 'max:255'],
            'received_at'    => ['nullable', 'string', 'max:64'],
            'raw_base64'     => ['required', 'string'],
        ]);

        $raw = base64_decode($data['raw_base64'], true);
        if ($raw === false || $raw === '') {
            return response()->json(['error' => 'invalid raw_base64'], 422);
        }

        try {
            $stored = $mail->storeRawFromSes(
                $raw,
                $data['ses_message_id'],
                $data['received_at'] ?? null,
            );
        } catch (\Throwable $e) {
            Log::error('[InboundEmail] 取込失敗', [
                'ses_message_id' => $data['ses_message_id'],
                'error'          => $e->getMessage(),
            ]);
            // 500 を返して Lambda の非同期リトライに委ねる (rfc_message_id dedup があるため
            // 再送されても二重登録しない)。一時障害 (DB 瞬断等) はリトライで取り込め、
            // 恒久失敗も最終的に IMAP バックアップが拾うため取りこぼさない。
            return response()->json(['error' => 'store_failed'], 500);
        }

        // ── SES 経路ヘルスチェック用ハートビート ──
        // 200 を返せた = SES→S3→Lambda→CF→API の全リンクが疎通している証跡。
        // stored=false(dedup) でも経路自体は生きているので記録する。後段で gmail_message_id が
        // ses-→imap- に上書きされても消えないため、emails テーブル走査より堅牢な死活シグナル。
        // emails:check-ses-health がこの値を読み、トラフィックが流れているのに古ければ警告する。
        try {
            Cache::put('inbound:ses:last_ok_at', now()->toIso8601String(), now()->addDays(7));
        } catch (\Throwable $e) {
            Log::warning('[InboundEmail] ハートビート記録失敗', ['error' => $e->getMessage()]);
        }

        return response()->json(['stored' => $stored], 200);
    }
}
