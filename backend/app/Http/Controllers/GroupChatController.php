<?php

namespace App\Http\Controllers;

use App\Models\GroupChat;
use App\Models\GroupMessage;
use App\Models\PatientDoctorRelationship;
use Illuminate\Http\Request;

class GroupChatController extends Controller
{
    /**
     * SECURITY (Phase 1 Slice 2 / relationship-boundary IDOR): 'patient_ids' used
     * to be validated only against exists:users,id — any authenticated doctor
     * could add ANY user in the system (a patient they have never treated,
     * belonging to a different doctor/clinic entirely) into a group chat and
     * start messaging them, completely bypassing the active-relationship check
     * that ChatController::canChatWith() enforces for ordinary 1:1 messaging.
     * Each patient_id is now required to have an active/accepted
     * PatientDoctorRelationship with the creating doctor, same boundary as
     * everywhere else in the app.
     */
    // ✅ الدكتور يعمل جروب جديد
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user->role === 'doctor' && $user->doctor, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // COST/DATA-INTEGRITY (RIVA-104): 'distinct' closes the primary way a
            // client could cause a duplicate group_chat_members row (repeating a
            // patient_id in the same request) — see the accompanying
            // group_chat_members unique constraint migration, which is now the
            // authoritative guard at the data layer regardless of how a
            // duplicate is attempted.
            'patient_ids' => ['required', 'array', 'min:1'],
            'patient_ids.*' => ['integer', 'exists:users,id', 'distinct'],
        ]);

        $doctor = $user;

        // A doctor cannot have an active PatientDoctorRelationship with
        // themselves, so this also implicitly excludes the doctor's own id
        // from $relatedPatientUserIds — but filter explicitly too, since the
        // dedicated attach() below already adds the doctor as a member.
        $patientIds = array_values(array_diff(array_unique($data['patient_ids']), [$doctor->id]));

        $relatedPatientUserIds = PatientDoctorRelationship::query()
            ->where('doctor_id', $doctor->doctor->id)
            ->whereIn('status', ['active', 'accepted'])
            ->whereHas('patient', fn ($q) => $q->whereIn('user_id', $patientIds))
            ->with('patient:id,user_id')
            ->get()
            ->pluck('patient.user_id')
            ->all();

        $unauthorized = array_diff($patientIds, $relatedPatientUserIds);
        abort_unless(empty($unauthorized), 403, 'You can only add patients you have an active relationship with.');

        $group = GroupChat::create([
            'name' => $data['name'],
            'created_by' => $doctor->id,
        ]);

        // أضف الدكتور كعضو
        $group->members()->attach($doctor->id);

        // أضف المرضى
        foreach ($patientIds as $patientId) {
            $group->members()->attach($patientId);
        }

        return $this->success([
            'group' => $group->load('members'),
        ], 'Group created successfully');
    }

    // ✅ جيب كل الجروبات بتاعت اليوزر
    public function index(Request $request)
    {
        $user = $request->user();

        $groups = GroupChat::whereHas('members', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->with(['members', 'lastMessage'])->get();

        return $this->success(['groups' => $groups]);
    }

    // ✅ جيب رسائل جروب معين
    public function messages(Request $request, $groupId)
    {
        $user = $request->user();

        $group = GroupChat::whereHas('members', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->findOrFail($groupId);

        $messages = GroupMessage::where('group_chat_id', $groupId)
            ->with('sender:id,first_name,last_name,profile_image')
            ->orderBy('created_at')
            ->get()
            ->map(function ($msg) {
                if ($msg->sender) {
                    $msg->sender->profile_image_url = $msg->sender->profile_image
                        ? asset('storage/'.$msg->sender->profile_image)
                        : null;
                }

                return $msg;
            });

        return $this->success(['messages' => $messages]);
    }

    // ✅ ابعت رسالة في الجروب
    public function sendMessage(Request $request, $groupId)
    {
        $user = $request->user();

        $group = GroupChat::whereHas('members', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->findOrFail($groupId);

        $data = $request->validate([
            'body' => ['required', 'string'],
        ]);

        $message = GroupMessage::create([
            'group_chat_id' => $group->id,
            'sender_id' => $user->id,
            'body' => $data['body'],
        ]);

        return $this->success([
            'message' => $message->load('sender:id,first_name,last_name,profile_image'),
        ], 'Message sent');
    }
}
