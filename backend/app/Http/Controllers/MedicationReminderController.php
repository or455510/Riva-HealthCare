<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class MedicationReminderController extends Controller
{
    public function send(Request $request)
    {
        $user = Auth::user();
        $medicationName = $request->input('medication_name', 'your medication');
        $scheduleTime   = $request->input('schedule_time', '');
        $message        = "💊 Reminder: Please take {$medicationName} at {$scheduleTime}.";

        // ✅ Email
        Mail::raw($message, function ($m) use ($user) {
            $m->to($user->email)->subject('💊 Medication Reminder - RIVA');
        });

        // ✅ WhatsApp via Twilio REST API
        Http::withBasicAuth(
            config('services.twilio.sid'),
            config('services.twilio.token')
        )->asForm()->post(
            "https://api.twilio.com/2010-04-01/Accounts/" . config('services.twilio.sid') . "/Messages.json",
            [
                'From' => 'whatsapp:' . config('services.twilio.from'),
                 'To' => 'whatsapp:+201060465845',
                'Body' => $message,
            ]
        );

        return response()->json(['message' => 'Reminder sent successfully.']);
    }
}