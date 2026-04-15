<?php

namespace App\Http\Controllers;

use App\Models\DeliveryAddress;
use Illuminate\Http\Request;

class UnsubscribeController extends Controller
{
    public function handle(string $token)
    {
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
