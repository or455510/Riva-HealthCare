<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PasswordResetController extends Controller
{
    public function sendResetLink(Request $request)
    {
        return app(AuthController::class)->forgotPassword($request);
    }

    public function verifyToken(Request $request)
    {
        return app(AuthController::class)->verifyResetCode($request);
    }

    public function reset(Request $request)
    {
        return app(AuthController::class)->resetPassword($request);
    }
}
