<?php

namespace App\Http\Controllers;

use App\Models\GroupChat;
use App\Models\GroupMessage;
use Illuminate\Http\Request;

class GroupChatController extends Controller
{
    // ✅ الدكتور يعمل جروب جديد
    public function create(Request $request)
    {
        abort_unless($request->user()->role === 'doctor', 403);

        $data = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'patient_ids'=> ['required', 'array'],
            'patient_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $doctor = $request->user();

        $group = GroupChat::create([
            'name'       => $data['name'],
            'created_by' => $doctor->id,
        ]);

        // أضف الدكتور كعضو
        $group->members()->attach($doctor->id);

        // أضف المرضى
        foreach ($data['patient_ids'] as $patientId) {
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
                    ? asset('storage/' . $msg->sender->profile_image)
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
            'sender_id'     => $user->id,
            'body'          => $data['body'],
        ]);

        return $this->success([
            'message' => $message->load('sender:id,first_name,last_name,profile_image'),
        ], 'Message sent');
    }
}
