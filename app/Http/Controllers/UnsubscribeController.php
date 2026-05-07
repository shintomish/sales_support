<?php

namespace App\Http\Controllers;

use App\Models\DeliveryAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UnsubscribeController extends Controller
{
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

        $address->update(['is_active' => false]);

        return view('unsubscribe', ['status' => 'success']);
    }
}
