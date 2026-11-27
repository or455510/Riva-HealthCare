<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\PatientCaregiverRelationship;
use App\Models\PatientDoctorRelationship;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __construct(private readonly NotificationService $notificationService) {}

    public function contacts(Request $request)
    {
        $user = $request->user();

        return $this->success($this->resolveContacts($user));
    }

    public function messages(Request $request, User $user)
    {
        $authUser = $request->user();
        abort_unless($this->canChatWith($authUser, $user), 403);

        $messages = Message::query()
            ->where(fn ($query) => $query
                ->where('sender_id', $authUser->id)
                ->where('receiver_id', $user->id))
            ->orWhere(fn ($query) => $query
                ->where('sender_id', $user->id)
                ->where('receiver_id', $authUser->id))
            ->orderBy('created_at')
            ->get();

        Message::query()
            ->where('sender_id', $user->id)
            ->where('receiver_id', $authUser->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return $this->success($messages);
    }

    public function store(Request $request)
    {
        $authUser = $request->user();
        $data = $request->validate([
            'receiver_id' => ['nullable', 'integer', 'exists:users,id'],
            'body' => ['required', 'string'],
        ]);

        $receiver = isset($data['receiver_id'])
            ? User::findOrFail($data['receiver_id'])
            : User::findOrFail((int) $request->route('user')->id);

        abort_unless($this->canChatWith($authUser, $receiver), 403);

        $message = Message::create([
            'sender_id' => $authUser->id,
            'receiver_id' => $receiver->id,
            'body' => $data['body'],
        ]);

        $this->notificationService->create(
            $receiver,
            'new_message',
            'New message',
            $authUser->name.' sent you a new message.',
            'info',
            Message::class,
            $message->id
        );

        return $this->success($message, 'Message sent successfully', 201);
    }

    private function resolveContacts(User $user): array
    {
        if ($user->role === 'patient' && $user->patient) {
            $doctorContacts = PatientDoctorRelationship::with('doctor.user')
                ->where('patient_id', $user->patient->id)
                ->whereIn('status', ['active', 'accepted'])
                ->get()
                ->map(fn ($relationship) => $this->contactFromUser($relationship->doctor?->user, 'online', 'doctor'))
                ->filter()
                ->values()
                ->all();

            $caregiverContacts = PatientCaregiverRelationship::with('caregiver.user')
                ->where('patient_id', $user->patient->id)
                ->whereIn('status', ['active', 'accepted'])
                ->get()
                ->map(fn ($relationship) => $this->contactFromUser($relationship->caregiver?->user, 'online', 'caregiver'))
                ->filter()
                ->values()
                ->all();

            return [...$doctorContacts, ...$caregiverContacts];
        }

        if ($user->role === 'doctor' && $user->doctor) {
            return PatientDoctorRelationship::with('patient.user')
                ->where('doctor_id', $user->doctor->id)
                ->whereIn('status', ['active', 'accepted'])
                ->get()
                ->map(fn ($relationship) => $this->contactFromUser($relationship->patient?->user, 'online', 'patient'))
                ->filter()
                ->values()
                ->all();
        }

        if ($user->role === 'caregiver' && $user->caregiver) {
            return PatientCaregiverRelationship::with('patient.user')
                ->where('caregiver_id', $user->caregiver->id)
                ->whereIn('status', ['active', 'accepted'])
                ->get()
                ->map(fn ($relationship) => $this->contactFromUser($relationship->patient?->user, 'online', 'patient'))
                ->filter()
                ->values()
                ->all();
        }

        return [];
    }

    private function contactFromUser(?User $user, string $status, string $role): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'avatar' => $user->profile_image_url
                ?: 'https://ui-avatars.com/api/?name='.urlencode($user->name).'&background=E6F0FF&color=2D5BFF',
            'status' => $status,
            'role' => $role,
        ];
    }

    private function canChatWith(User $authUser, User $otherUser): bool
    {
        if ($authUser->id === $otherUser->id) {
            return false;
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

        return $authUser->role === 'admin';
    }

    /**
     * SECURITY (Phase 0): this endpoint previously validated only 'required|file|max:20480'
     * — no mime/extension restriction at all, unlike every other upload path in the app
     * (compare DoctorVerificationController, which already restricts to jpg/jpeg/png/pdf).
     * That meant a patient or doctor could upload an executable, script, or arbitrarily
     * large-surface file type into a publicly-served storage path. The allowlist below
     * covers the media types the UI actually generates (images, voice notes, short
     * clips) plus common document types clinics realistically share, and rejects
     * everything else outright — Laravel's 'mimes' rule checks both file extension and
     * a server-side content sniff, so renaming a script to '.jpg' does not pass.
     */
    public function upload(Request $request)
    {
        $data = $request->validate([
            'file' => [
                'required',
                'file',
                'max:20480',
                'mimes:jpg,jpeg,png,gif,webp,mp4,mov,mp3,wav,webm,m4a,ogg,pdf,doc,docx,xlsx,txt',
            ],
            // SECURITY (RIVA-117, P0): 'exists' alone is not the fix here — see the
            // canChatWith() check right below, which is the actual authorization.
            // This just ensures findOrFail() below can't 500 on a bogus id.
            'receiver_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $authUser = $request->user();
        $receiver = User::findOrFail($data['receiver_id']);

        // SECURITY (RIVA-117, P0): store() (text messages) has always required
        // canChatWith() — an actual accepted patient/doctor/caregiver relationship
        // (or admin) — before letting one user message another. This upload()
        // endpoint never had that check: any authenticated user could send a file
        // "message" to an arbitrary receiver_id with zero relationship, bypassing
        // the exact authorization boundary store() enforces one method up. Fixed
        // by requiring the same check here, before the file is even stored.
        abort_unless($this->canChatWith($authUser, $receiver), 403);

        $file = $request->file('file');
        $mime = $file->getMimeType();
        $originalName = $file->getClientOriginalName();

        // ✅ تحديد النوع بالاسم كمان مش بس الـ MIME
        if (str_starts_with($mime, 'image/')) {
            $fileType = 'image';
        } elseif (
            str_starts_with($mime, 'audio/') ||
            str_contains($originalName, '.webm') ||
            str_contains($originalName, '.mp3') ||
            str_contains($originalName, 'voice_message')
        ) {
            $fileType = 'audio';
        } else {
            $fileType = 'file';
        }

        $path = $file->store('messages', 'public');

        // File-serving architecture (principal-engineering directive): store just
        // the disk-relative path here, not a raw asset('storage/...') URL — that
        // raw form is unauthenticated and bypasses the ownership check in
        // StorageFileController entirely (anyone who guesses/observes the path
        // could fetch it directly). Message::getFileUrlAttribute() turns this
        // path into a short-lived signed URL, scoped to whichever of the two
        // participants is actually viewing the conversation, on every read.
        $message = Message::create([
            'sender_id' => $authUser->id,
            'receiver_id' => $receiver->id,
            'body' => '',
            'file_url' => $path,
            'file_type' => $fileType,
            'file_name' => $originalName,
        ]);

        return response()->json(['data' => $message], 201);
    }
}
