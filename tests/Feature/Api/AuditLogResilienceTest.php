<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

/**
 * 監査ログ(USER_ACTIVITY)の書き込み失敗がリクエストを致命化しないことを保証する。
 *
 * 事故: audit-YYYY-MM-DD.log が root 所有になると www-data が追記できず例外→本来200が500化し、
 *       全API（ログイン後の最初のリクエスト含む）が落ちてログイン不能になる事象が日付替わり毎に再発していた。
 *       LogUserActivity / SupabaseAuth の監査書き込みを try/catch で握り潰す恒久対策を検証する。
 */
class AuditLogResilienceTest extends TestCase
{
    public function test_監査ログが書き込めなくてもリクエストは成功する(): void
    {
        $this->actingAsUser();

        // audit チャネルを書き込み不能なパスに差し替え（mkdir 不能→StreamHandler が例外）
        config(['logging.channels.audit' => [
            'driver' => 'single',
            'path'   => '/nonexistent-dir-for-test/audit.log',
        ]]);

        // LogUserActivity を通る通常のGET。監査書き込みが失敗しても 200 を返すこと。
        $this->getJson('/api/v1/skill-aliases')->assertOk();
    }
}
