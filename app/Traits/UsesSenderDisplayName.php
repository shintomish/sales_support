<?php

namespace App\Traits;

use App\Models\EmailBodyTemplate;

/**
 * メール送信時の From ヘッダ表示名をユーザのメール署名テンプレートから取得する。
 * 設定 (/settings/email-template) の sender_display_name で選択された値を使う。
 * 未設定なら null を返し、Mail 側で config('mail.from.name') にフォールバックする。
 */
trait UsesSenderDisplayName
{
    protected function senderDisplayName(?int $userId = null): ?string
    {
        $userId ??= auth()->id();
        if (!$userId) return null;
        // 1 リクエスト中の重複 SELECT を避ける (controller インスタンス内 cache)
        if (!isset($this->_senderDisplayNameCache)) {
            $this->_senderDisplayNameCache = [];
        }
        if (!array_key_exists($userId, $this->_senderDisplayNameCache)) {
            $this->_senderDisplayNameCache[$userId] = EmailBodyTemplate::where('user_id', $userId)
                ->value('sender_display_name');
        }
        return $this->_senderDisplayNameCache[$userId] ?: null;
    }

    /** @var array<int, string|null> */
    private array $_senderDisplayNameCache = [];
}
