<?php

namespace App\Http\Controllers;

use App\Events\WebRTCSignal;
use App\Models\PatientCaregiverRelationship;
use App\Models\PatientDoctorRelationship;
use App\Models\User;
use Illuminate\Http\Request;

class CallController extends Controller
{
    /**
     * SECURITY (Phase 1 Slice 2 / relationship-boundary): this used to broadcast
     * a WebRTC call signal to any to_user_id with no relationship check at all —
     * the private-channel authorization in routes/channels.php (call.{channelId})
     * does restrict who can ever RECEIVE it to the two participants named in the
     * channel, so this was not a data-exposure IDOR, but it did let any
     * authenticated user initiate an unsolicited call signal (offer/ICE
     * candidate) at any other user with zero relationship — the same boundary
     * ChatController::canChatWith() already enforces for messaging. Mirrored
     * here for consistency.
     */
    public function signal(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|string',
            'data' => 'nullable',
            'to_user_id' => 'required|integer|exists:users,id',
        ]);

        $authUser = $request->user();
        $toUser = User::findOrFail($data['to_user_id']);

        abort_unless($this->canCall($authUser, $toUser), 403);

        broadcast(new WebRTCSignal(
            $data['type'],
            $data['data'] ?? null,
            $authUser->id,
            $toUser->id
        ))->toOthers();

        return response()->json(['status' => 'ok']);
    }

    private function canCall(User $authUser, User $otherUser): bool
    {
        if ($authUser->id === $otherUser->id) {
            return false;
        }

        if ($authUser->role === 'admin' || $otherUser->role === 'admin') {
            return $authUser->role === 'admin';
        }

        if ($authUser->role === 'patient' && $authUser->patient) {
            return PatientDoctorRelationship::where('patient_id', $authUser->patient->id)
                ->whereHas('doctor', fn ($query) => $query->where('user_id', $otherUser->id))
                ->whereIn('status', ['active', 'accepted'])
                ->exists()
                || PatientCaregiverRelationship::where('patient_id', $authUser->patient->id)
                    ->whereHas('caregiver', fn ($query) => $query->where('user_id', $otherUser->id))
                    ->whereIn('status', ['active', 'accepted'])
                    ->exists();
        }

        if ($authUser->role === 'doctor' && $authUser->doctor) {
            return PatientDoctorRelationship::where('doctor_id', $authUser->doctor->id)
                ->whereHas('patient', fn ($query) => $query->where('user_id', $otherUser->id))
                ->whereIn('status', ['active', 'accepted'])
                ->exists();
        }

        if ($authUser->role === 'caregiver' && $authUser->caregiver) {
            return PatientCaregiverRelationship::where('caregiver_id', $authUser->caregiver->id)
                ->whereHas('patient', fn ($query) => $query->where('user_id', $otherUser->id))
                ->whereIn('status', ['active', 'accepted'])
                ->exists();
        }

        return false;
    }
}
