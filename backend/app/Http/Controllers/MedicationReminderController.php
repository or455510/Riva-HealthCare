<?php

namespace App\Http\Controllers;

use App\Jobs\SendMedicationReminder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * COST / SECURITY (RIVA-102): delivery (email + WhatsApp) previously happened
 * synchronously inline in this action, and the WhatsApp recipient was a
 * hardcoded phone number rather than the actual signed-in user's own number
 * — see SendMedicationReminder's docblock and the cost/scalability audit
 * §22 P0-5. This controller now only validates input and dispatches the job;
 * all external delivery detail lives in the job.
 */
class MedicationReminderController extends Controller
{
    public function send(Request $request)
    {
        $data = $request->validate([
            'medication_name' => ['nullable', 'string', 'max:255'],
            'schedule_time' => ['nullable', 'string', 'max:100'],
        ]);

        $user = Auth::user();

        SendMedicationReminder::dispatch(
            $user->id,
            $data['medication_name'] ?? 'your medication',
            $data['schedule_time'] ?? ''
        );

        return $this->success(null, 'Reminder queued for delivery.');
    }
}
