<?php

namespace App\Http\Controllers;

use App\Events\WebRTCSignal;
use Illuminate\Http\Request;

class CallController extends Controller
{
    public function signal(Request $request)
    {
        $request->validate([
            'type'       => 'required|string',
            'data'       => 'nullable',
            'to_user_id' => 'required|integer|exists:users,id',
        ]);

        broadcast(new WebRTCSignal(
            $request->type,
            $request->data,
            auth()->id(),
            $request->to_user_id
        ))->toOthers();

        return response()->json(['status' => 'ok']);
    }
}