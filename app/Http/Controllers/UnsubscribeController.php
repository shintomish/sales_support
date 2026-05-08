<?php

namespace App\Http\Controllers;

use App\Models\DeliveryAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UnsubscribeController extends Controller
{
    /** GET: 確認画面を表示 */
    public function showConfirm(string $token)
    {
        if (!Str::isUuid($token)) {
            return response()->view('unsubscribe', ['status' => 'invalid'], 404);
        }

        $address = DeliveryAddress::where('unsubscribe_token', $token)->first();

        if (!$address) {
            return response()->view('unsubscribe', ['status' => 'invalid'], 404);
        }

        if (!$address->is_active) {
            return view('unsubscribe', ['status' => 'already']);
        }

        return view('unsubscribe', ['status' => 'confirm', 'token' => $token]);
    }

    /** POST: 「はい」確認後の実行 */
    public function handle(string $token)
    {
        if (!Str::isUuid($token)) {
            return response()->view('unsubscribe', ['status' => 'invalid'], 404);
        }

        $address = DeliveryAddress::where('unsubscribe_token', $token)->first();

        if (!$address) {
            return response()->view('unsubscribe', ['status' => 'invalid'], 404);
        }

        if (!$address->is_active) {
            return view('unsubscribe', ['status' => 'already']);
        }

        $address->update([
            'is_active'          => false,
            'unsubscribe_reason' => 'self_unsubscribed',
            'unsubscribed_at'    => now(),
        ]);

        return view('unsubscribe', ['status' => 'success']);
    }
}
