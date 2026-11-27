<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\GenericNotificationMail;
use App\Models\Appointment;
use App\Models\ContactMessage;
use App\Models\DailyStatus;
use App\Models\Doctor;
use App\Models\DoctorVerification;
use App\Models\EmergencyAlert;
use App\Models\MedicationLog;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\SystemActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AdminAreaController extends Controller
{
    public function overview()
    {
        $today = now()->toDateString();

        return $this->success([
            'metrics' => [
                'total_users' => User::count(),
                'patients' => User::where('role', 'patient')->count(),
                'doctors' => User::where('role', 'doctor')->count(),
                'caregivers' => User::where('role', 'caregiver')->count(),
                'pending_doctor_verifications' => DoctorVerification::where('status', 'pending')->count(),
                'active_emergency_alerts' => EmergencyAlert::where(fn ($query) => $query
                    ->whereNull('resolved_at')
                    ->orWhere('status', 'active')
                )->count(),
                'high_risk_patients' => DailyStatus::where('risk_level', 'high_risk')->distinct('patient_id')->count('patient_id'),
                'missed_medications_today' => MedicationLog::where('status', 'missed')->whereDate('created_at', $today)->count(),
                'today_appointments' => Appointment::whereDate('appointment_date', $today)->count(),
                'unread_contact_messages' => ContactMessage::whereIn('status', ['new', 'open'])->count(),
                'active_doctors' => Doctor::where('is_active', true)->where('is_verified', true)->count(),
            ],
            'charts' => [
                'registrations' => $this->dailyCounts(User::query(), 'created_at'),
                'alerts' => $this->dailyCounts(EmergencyAlert::query(), 'created_at'),
                'appointments' => $this->dailyCounts(Appointment::query(), 'appointment_date'),
            ],
            'recent' => [
                'emergencies' => EmergencyAlert::with('patient.user')->latest()->limit(5)->get(),
                'contacts' => ContactMessage::latest()->limit(5)->get(),
                'activity' => SystemActivityLog::with('user')->latest('created_at')->limit(8)->get(),
            ],
        ]);
    }

    public function users(Request $request)
    {
        $query = User::query()->latest();

        $this->searchUsers($query, $request->query('search'));
        $this->whereIf($query, 'role', $request->query('role'));

        if ($request->filled('status')) {
            $status = $request->query('status');
            $query->where(fn ($q) => $q->where('status', $status)->orWhere('is_active', $status === 'active'));
        }

        return $this->success($query->paginate($this->perPage($request)));
    }

    public function updateUser(Request $request, User $user)
    {
        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        if (isset($data['first_name']) || isset($data['last_name'])) {
            $data['name'] = trim(($data['first_name'] ?? $user->first_name).' '.($data['last_name'] ?? $user->last_name));
        }

        $user->update($data);

        return $this->success($user->fresh(), 'User updated successfully');
    }

    public function updateUserStatus(Request $request, User $user)
    {
        $data = $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'in:active,inactive,suspended'],
        ]);

        if ($request->user()->id === $user->id && (($data['is_active'] ?? true) === false || ($data['status'] ?? 'active') !== 'active')) {
            return $this->error('Admin users cannot deactivate or suspend themselves.', 422);
        }

        $isActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (($data['status'] ?? 'active') === 'active');
        $status = $data['status'] ?? ($isActive ? 'active' : 'inactive');

        $user->update(['is_active' => $isActive, 'status' => $status]);

        return $this->success($user->fresh(), 'User status updated successfully');
    }

    public function doctorVerification(Request $request)
    {
        $query = Doctor::with('user')->latest();
        $this->whereIf($query, 'verification_status', $request->query('status', 'pending'));

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->whereHas('user', fn ($q) => $this->searchUsers($q, $search))
                ->orWhere('license_number', 'like', "%{$search}%")
                ->orWhere('specialty', 'like', "%{$search}%");
        }

        return $this->success($query->paginate($this->perPage($request)));
    }

    public function approveDoctor(Doctor $doctor)
    {
        $doctor->update([
            'verification_status' => 'verified',
            'verified_at' => now(),
            'rejection_reason' => null,
            'is_verified' => true,
        ]);

        return $this->success($doctor->fresh('user'), 'Doctor approved successfully');
    }

    public function rejectDoctor(Request $request, Doctor $doctor)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $doctor->update([
            'verification_status' => 'rejected',
            'verified_at' => null,
            'rejection_reason' => $data['reason'],
            'is_verified' => false,
        ]);

        return $this->success($doctor->fresh('user'), 'Doctor rejected successfully');
    }

    public function doctorVerifications(Request $request)
    {
        $query = DoctorVerification::with(['doctor.user', 'reviewer'])->latest();
        $this->whereIf($query, 'status', $request->query('status', 'pending'));

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(fn ($q) => $q
                ->where('medical_license_number', 'like', "%{$search}%")
                ->orWhere('syndicate_number', 'like', "%{$search}%")
                ->orWhere('specialization', 'like', "%{$search}%")
                ->orWhereHas('doctor.user', fn ($userQuery) => $this->searchUsers($userQuery, $search))
            );
        }

        return $this->success($query->paginate($this->perPage($request)));
    }

    public function showDoctorVerification(DoctorVerification $verification)
    {
        return $this->success($verification->load(['doctor.user', 'reviewer']));
    }

    public function approveDoctorVerification(Request $request, DoctorVerification $verification)
    {
        $verification->load('doctor.user');

        DB::transaction(function () use ($request, $verification) {
            $verification->update([
                'status' => 'approved',
                'rejection_reason' => null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            $verification->doctor->update([
                'verification_status' => 'verified',
                'verified_at' => now(),
                'rejection_reason' => null,
                'is_verified' => true,
                'specialty' => $verification->specialization ?: $verification->doctor->specialty,
                'license_number' => $verification->medical_license_number ?: $verification->doctor->license_number,
                'years_of_experience' => $verification->years_of_experience ?? $verification->doctor->years_of_experience,
            ]);

            $verification->doctor->user?->update([
                'doctor_verified_at' => now(),
                'verification_status' => 'approved',
            ]);
        });

        Log::info('Doctor verification approved', [
            'verification_id' => $verification->id,
            'admin_id' => $request->user()->id,
        ]);

        return $this->success($verification->fresh(['doctor.user', 'reviewer']), 'Doctor verification approved successfully');
    }

    public function rejectDoctorVerification(Request $request, DoctorVerification $verification)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $verification->load('doctor.user');

        DB::transaction(function () use ($request, $verification, $data) {
            $verification->update([
                'status' => 'rejected',
                'rejection_reason' => $data['reason'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            $verification->doctor->update([
                'verification_status' => 'rejected',
                'verified_at' => null,
                'rejection_reason' => $data['reason'],
                'is_verified' => false,
            ]);

            $verification->doctor->user?->update([
                'doctor_verified_at' => null,
                'verification_status' => 'rejected',
            ]);
        });

        Log::info('Doctor verification rejected', [
            'verification_id' => $verification->id,
            'admin_id' => $request->user()->id,
        ]);

        return $this->success($verification->fresh(['doctor.user', 'reviewer']), 'Doctor verification rejected');
    }

    public function patientMonitoring(Request $request)
    {
        $query = Patient::with([
            'user',
            'doctorRelationships.doctor.user',
            'caregiverRelationships.caregiver.user',
        ])->latest();

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->whereHas('user', fn ($q) => $this->searchUsers($q, $search));
        }

        $page = $query->paginate($this->perPage($request));
        $page->getCollection()->transform(function (Patient $patient) {
            $lastReport = DailyStatus::where('patient_id', $patient->id)->latest()->first();

            return [
                'id' => $patient->id,
                'user' => $patient->user,
                'caregivers' => $patient->caregiverRelationships->map(fn ($rel) => [
                    'status' => $rel->status,
                    'caregiver' => $rel->caregiver?->user,
                ])->values(),
                'doctors' => $patient->doctorRelationships->map(fn ($rel) => [
                    'status' => $rel->status,
                    'doctor' => $rel->doctor?->user,
                ])->values(),
                'last_login_at' => $patient->user?->last_login_at,
                'missed_medication_count' => MedicationLog::where('patient_id', $patient->id)->where('status', 'missed')->count(),
                'risk_status' => $lastReport?->risk_level ?? 'unknown',
                'last_report_at' => $lastReport?->created_at,
            ];
        });

        return $this->success($page);
    }

    public function emergencies(Request $request)
    {
        $query = EmergencyAlert::with('patient.user')->latest();
        $this->whereIf($query, 'severity', $request->query('severity'));
        $this->whereIf($query, 'status', $request->query('status'));

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->whereHas('patient.user', fn ($q) => $this->searchUsers($q, $search));
        }

        return $this->success($query->paginate($this->perPage($request)));
    }

    public function resolveEmergency(EmergencyAlert $alert)
    {
        $alert->update(['status' => 'resolved', 'resolved_at' => now()]);

        return $this->success($alert->fresh('patient.user'), 'Emergency alert marked as resolved');
    }

    public function dailyReports(Request $request)
    {
        $query = DailyStatus::with('patient.user')->latest();
        $this->whereIf($query, 'risk_level', $request->query('risk'));

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->whereHas('patient.user', fn ($q) => $this->searchUsers($q, $search));
        }

        return $this->success($query->paginate($this->perPage($request)));
    }

    public function appointments(Request $request)
    {
        $query = Appointment::with(['patient.user', 'doctor.user', 'hospital'])->latest();
        $this->whereIf($query, 'status', $request->query('status'));
        $this->whereIf($query, 'payment_status', $request->query('payment_status'));

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->whereHas('patient.user', fn ($q) => $this->searchUsers($q, $search))
                ->orWhereHas('doctor.user', fn ($q) => $this->searchUsers($q, $search));
        }

        return $this->success($query->paginate($this->perPage($request)));
    }

    public function contactMessages(Request $request)
    {
        $query = ContactMessage::latest();
        $this->whereIf($query, 'status', $request->query('status'));

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(fn ($q) => $q
                ->where('full_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('subject', 'like', "%{$search}%")
            );
        }

        return $this->success($query->paginate($this->perPage($request)));
    }

    public function markContactHandled(ContactMessage $message)
    {
        $message->update(['status' => 'handled', 'handled_at' => now()]);

        return $this->success($message->fresh(), 'Contact message marked as handled');
    }

    public function notifications(Request $request)
    {
        $query = Notification::with('notifiable')->latest();
        $this->whereIf($query, 'type', $request->query('type'));
        $this->whereIf($query, 'category', $request->query('category'));

        if ($request->query('status') === 'unread') {
            $query->whereNull('read_at');
        } elseif ($request->query('status') === 'read') {
            $query->whereNotNull('read_at');
        }

        return $this->success($query->paginate($this->perPage($request)));
    }

    public function resendNotification(Notification $notification)
    {
        if (! ($notification->notifiable instanceof User) || ! $notification->notifiable->email) {
            return $this->error('Notification recipient does not have an email address.', 422);
        }

        try {
            Mail::to($notification->notifiable->email)->send(
                new GenericNotificationMail($notification->title, $notification->message, $notification->action_url)
            );

            $notification->update(['email_status' => 'sent']);

            return $this->success($notification->fresh(), 'Notification email resent successfully');
        } catch (Throwable $exception) {
            $notification->update(['email_status' => 'failed']);
            Log::warning('Admin notification resend failed', [
                'notification_id' => $notification->id,
                'message' => $exception->getMessage(),
            ]);

            return $this->error('Notification email failed to send.', 503);
        }
    }

    public function activityLogs(Request $request)
    {
        $query = SystemActivityLog::with('user')->latest('created_at');
        $this->whereIf($query, 'action', $request->query('action'));

        if ($request->filled('role')) {
            $query->whereHas('user', fn ($q) => $q->where('role', $request->query('role')));
        }

        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->query('date'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(fn ($q) => $q
                ->where('action', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
            );
        }

        return $this->success($query->paginate($this->perPage($request)));
    }

    public function settings()
    {
        // Phase 0: these were hardcoded to the project owner's personal email/phone
        // directly in this controller (same underlying issue as the emergency-alert
        // fallback destination, see config/services.php "emergency" and app/Jobs/
        // SendEmergencyAlertNotifications). Now sourced from the same config so
        // there is exactly one place this is configured, not two.
        $adminEmail = (string) config('services.emergency.admin_email');
        $adminWhatsapp = (string) config('services.emergency.admin_whatsapp');

        return $this->success([
            'admin_email' => $adminEmail,
            'admin_whatsapp_number' => $adminWhatsapp,
            'mail_configured' => (bool) config('mail.mailers.smtp.host') && (bool) config('mail.from.address'),
            'whatsapp_configured' => (bool) config('services.twilio.sid') && (bool) config('services.twilio.token') && (bool) config('services.twilio.from'),
            'gemini_configured' => (bool) config('services.gemini.key'),
            'missed_dose_grace_minutes' => (int) config('riva.missed_dose_grace_minutes', 30),
            'platform_contact' => [
                'location' => 'Cairo, Egypt',
                'phone' => $adminWhatsapp,
                'email' => $adminEmail,
            ],
        ]);
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', 10), 5), 50);
    }

    private function whereIf(Builder $query, string $column, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $query->where($column, $value);
        }
    }

    private function searchUsers(Builder $query, ?string $search): Builder
    {
        if (! $search) {
            return $query;
        }

        return $query->where(fn ($q) => $q
            ->where('name', 'like', "%{$search}%")
            ->orWhere('first_name', 'like', "%{$search}%")
            ->orWhere('last_name', 'like', "%{$search}%")
            ->orWhere('email', 'like', "%{$search}%")
            ->orWhere('phone', 'like', "%{$search}%")
        );
    }

    private function dailyCounts(Builder $query, string $column): array
    {
        $driver = DB::connection()->getDriverName();
        $dateExpression = $driver === 'sqlite' ? "date({$column})" : "DATE({$column})";

        $rows = $query
            ->where($column, '>=', Carbon::now()->subDays(13)->startOfDay())
            ->selectRaw("{$dateExpression} as day, count(*) as total")
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        return collect(range(13, 0))
            ->map(function (int $offset) use ($rows) {
                $day = Carbon::now()->subDays($offset)->toDateString();

                return [
                    'day' => $day,
                    'total' => (int) ($rows[$day]->total ?? 0),
                ];
            })
            ->values()
            ->all();
    }
}
