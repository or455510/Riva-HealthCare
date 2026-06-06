<?php

// namespace App\Http\Controllers;

// use App\Models\Diagnosis;
// use App\Models\Patient;
// use App\Models\PatientDoctorRelationship;
// use App\Models\Report;
// use Illuminate\Http\Request;

// class ReportController extends Controller
// {
//     public function index(Request $request)
//     {
//         $user = $request->user();
//         $query = Report::with(['patient.user', 'doctor.user']);

//         if ($user->role === 'patient' && $user->patient) {
//             $query->where('patient_id', $user->patient->id);
//         } elseif ($user->role === 'doctor' && $user->doctor) {
//             $query->where('doctor_id', $user->doctor->id);
//         }

//         return $this->success($query->latest()->get());
//     }

//     public function storeReport(Request $request)
//     {
//         $doctor = $request->user()->doctor;
//         abort_unless($doctor, 403);

//         $data = $request->validate([
//             'patient_id' => ['required', 'exists:patients,id'],
//             'title' => ['required', 'string', 'max:255'],
//             'summary' => ['required', 'string'],
//             'final_report' => ['nullable', 'string'],
//         ]);

//         $this->ensureAssignedPatient($doctor->id, (int) $data['patient_id']);

//         $report = Report::create([
//             ...$data,
//             'doctor_id' => $doctor->id,
//         ]);

//         return $this->success($report, 'Report created successfully', 201);
//     }

//     public function patientReports(Request $request, Patient $patient)
//     {
//         $this->authorizePatientView($request, $patient);
//         return $this->success($patient->reports()->with('doctor.user')->latest()->get());
//     }

//     public function storeDiagnosis(Request $request)
//     {
//         $doctor = $request->user()->doctor;
//         abort_unless($doctor, 403);

//         $data = $request->validate([
//             'patient_id' => ['required', 'exists:patients,id'],
//             'title' => ['required', 'string', 'max:255'],
//             'details' => ['required', 'string'],
//         ]);

//         $this->ensureAssignedPatient($doctor->id, (int) $data['patient_id']);

//         $diagnosis = Diagnosis::create([
//             ...$data,
//             'doctor_id' => $doctor->id,
//         ]);

//         return $this->success($diagnosis, 'Diagnosis created successfully', 201);
//     }

//     public function patientDiagnoses(Request $request, Patient $patient)
//     {
//         $this->authorizePatientView($request, $patient);
//         return $this->success($patient->diagnoses()->with('doctor.user')->latest()->get());
//     }

//     private function ensureAssignedPatient(int $doctorId, int $patientId): void
//     {
//         abort_unless(
//             PatientDoctorRelationship::where('doctor_id', $doctorId)
//                 ->where('patient_id', $patientId)
//                 ->where('status', 'active')
//                 ->exists(),
//             403
//         );
//     }

//     private function authorizePatientView(Request $request, Patient $patient): void
//     {
//         $user = $request->user();
//         if ($user->role === 'admin') {
//             return;
//         }

//         if ($user->role === 'patient') {
//             abort_unless($user->patient?->id === $patient->id, 403);
//             return;
//         }

//         if ($user->role === 'doctor') {
//             $this->ensureAssignedPatient($user->doctor->id, $patient->id);
//         }
//     }
// }
namespace App\Http\Controllers;

use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\PatientCaregiverRelationship;
use App\Models\PatientDoctorRelationship;
use App\Models\Report;
use App\Models\ReportComment;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {}

    // ─── List reports ───────────────────────────────────────────────
    public function index(Request $request)
    {
        $user  = $request->user();
        $query = Report::with(['patient.user', 'doctor.user', 'comments.user']);

        if ($user->role === 'patient' && $user->patient) {
            $query->where('patient_id', $user->patient->id);
        } elseif ($user->role === 'doctor' && $user->doctor) {
            $query->where('doctor_id', $user->doctor->id);
        } elseif ($user->role === 'caregiver' && $user->caregiver) {
            // caregiver sees reports of their assigned patients
            $patientIds = PatientCaregiverRelationship::where('caregiver_id', $user->caregiver->id)
                ->whereIn('status', ['active', 'accepted'])
                ->pluck('patient_id');
            $query->whereIn('patient_id', $patientIds);
        }

        $reports = $query->latest()->get()->map(fn ($r) => $this->transformReport($r));

        return $this->success($reports);
    }

    // ─── Single report ───────────────────────────────────────────────
    public function show(Request $request, Report $report)
    {
        $user = $request->user();
        $this->authorizeReportAccess($user, $report);

        $report->load(['patient.user', 'doctor.user', 'comments.user']);

        // Mark unread comments as read if patient is viewing
        if ($user->role === 'patient') {
            $report->comments()
                ->where('is_read_by_patient', false)
                ->update(['is_read_by_patient' => true]);
        }

        return $this->success($this->transformReport($report));
    }

    // ─── Doctor/Caregiver creates a report ──────────────────────────
    public function storeReport(Request $request)
    {
        $user   = $request->user();
        $doctor = $user->doctor;
        abort_unless($doctor, 403);

        $data = $request->validate([
            'patient_id'   => ['required', 'exists:patients,id'],
            'title'        => ['required', 'string', 'max:255'],
            'summary'      => ['required', 'string'],
            'final_report' => ['nullable', 'string'],
        ]);

        $this->ensureAssignedPatient($doctor->id, (int) $data['patient_id']);

        $report = Report::create([
            ...$data,
            'doctor_id' => $doctor->id,
        ]);

        // Notify the patient
        $patient = Patient::find($data['patient_id']);
        if ($patient?->user) {
            $this->notificationService->create(
                $patient->user,
                'report.created',
                'New Medical Report',
                "Dr. {$user->name} has created a new report for you: \"{$data['title']}\".",
                [
                    'patient_id' => $patient->id,
                    'patient_name' => $patient->user?->name,
                    'doctor_id' => $doctor->id,
                    'doctor_name' => $user->name,
                    'report_id' => $report->id,
                    'report_title' => $data['title'],
                    'source' => 'report',
                    'recommendation' => 'Open your reports and review the doctor summary.',
                ],
                '/my-reports',
                'reports'
            );
        }

        return $this->success($report->load('doctor.user'), 'Report created successfully', 201);
    }

    // ─── Doctor or Caregiver adds a comment on a report ─────────────
    public function addComment(Request $request, Report $report)
    {
        $user = $request->user();

        // Determine who is commenting
        $commenterRole = null;
        if ($user->role === 'doctor' && $user->doctor) {
            $this->ensureAssignedPatient($user->doctor->id, $report->patient_id);
            $commenterRole = 'doctor';
        } elseif ($user->role === 'caregiver' && $user->caregiver) {
            $this->ensureAssignedPatientCaregiver($user->caregiver->id, $report->patient_id);
            $commenterRole = 'caregiver';
        } else {
            abort(403, 'Only doctors and caregivers can comment on reports.');
        }

        $data = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        $comment = ReportComment::create([
            'report_id'      => $report->id,
            'user_id'        => $user->id,
            'commenter_role' => $commenterRole,
            'comment'        => $data['comment'],
        ]);

        // Notify the patient
        $patient = $report->patient()->with('user')->first();
        if ($patient?->user) {
            $roleLabel = $commenterRole === 'doctor' ? "Dr. {$user->name}" : "Caregiver {$user->name}";
            $this->notificationService->create(
                $patient->user,
                'report.comment',
                'New Comment on Your Report',
                "{$roleLabel} commented on your report \"{$report->title}\".",
                [
                    'patient_id' => $patient->id,
                    'patient_name' => $patient->user?->name,
                    'report_id' => $report->id,
                    'report_title' => $report->title,
                    'sender' => $roleLabel,
                    'source' => 'report_comment',
                    'recommendation' => 'Open your reports to read and follow up.',
                ],
                '/my-reports',
                'reports'
            );
        }

        return $this->success([
            'id'             => $comment->id,
            'comment'        => $comment->comment,
            'commenter_role' => $comment->commenter_role,
            'commenter_name' => $user->name,
            'commenter_avatar' => $user->avatar ?? null,
            'created_at'     => $comment->created_at,
        ], 'Comment added successfully', 201);
    }

    // ─── Patient reports list ───────────────────────────────────────
    public function patientReports(Request $request, Patient $patient)
    {
        $accessMode = $this->patientAccessMode($request, $patient);
        abort_if($accessMode === 'none', 403);

        if ($accessMode === 'preview') {
            return $this->success([
                'access_mode' => 'preview',
                'reports' => [],
                'preview' => $this->patientPreview($patient),
            ]);
        }

        $reports = $patient->reports()->with(['doctor.user', 'comments.user'])->latest()->get()
            ->map(fn ($r) => $this->transformReport($r));
        return $this->success([
            'access_mode' => 'full',
            'reports' => $reports,
            'preview' => $this->patientPreview($patient),
        ]);
    }

    // ─── Diagnoses ───────────────────────────────────────────────────
    public function storeDiagnosis(Request $request)
    {
        $doctor = $request->user()->doctor;
        abort_unless($doctor, 403);

        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'title'      => ['required', 'string', 'max:255'],
            'details'    => ['required', 'string'],
        ]);

        $this->ensureAssignedPatient($doctor->id, (int) $data['patient_id']);

        $diagnosis = Diagnosis::create([...$data, 'doctor_id' => $doctor->id]);

        return $this->success($diagnosis, 'Diagnosis created successfully', 201);
    }

    public function patientDiagnoses(Request $request, Patient $patient)
    {
        $this->authorizePatientView($request, $patient);
        return $this->success($patient->diagnoses()->with('doctor.user')->latest()->get());
    }

    // ─── Helpers ─────────────────────────────────────────────────────
    private function transformReport(Report $report): array
    {
        $comments = $report->relationLoaded('comments')
            ? $report->comments->map(fn ($c) => [
                'id'             => $c->id,
                'comment'        => $c->comment,
                'commenter_role' => $c->commenter_role,
                'commenter_name' => $c->user?->name ?? 'Unknown',
                'commenter_avatar' => $c->user?->avatar ?? null,
                'is_read'        => $c->is_read_by_patient,
                'created_at'     => $c->created_at,
            ])
            : [];

        $unreadCount = is_array($comments)
            ? collect($comments)->where('is_read', false)->count()
            : 0;

        return [
            'id'           => $report->id,
            'title'        => $report->title,
            'summary'      => $report->summary,
            'final_report' => $report->final_report,
            'doctor'       => [
                'id'   => $report->doctor?->id,
                'name' => $report->doctor?->user?->name ?? 'Doctor',
            ],
            'patient'       => [
                'id'   => $report->patient?->id,
                'name' => $report->patient?->user?->name ?? 'Patient',
            ],
            'comments'      => $comments,
            'unread_comments' => $unreadCount,
            'created_at'    => $report->created_at,
            'updated_at'    => $report->updated_at,
        ];
    }

    private function ensureAssignedPatient(int $doctorId, int $patientId): void
    {
        abort_unless(
            PatientDoctorRelationship::where('doctor_id', $doctorId)
                ->where('patient_id', $patientId)
                ->whereIn('status', ['active', 'accepted'])
                ->exists(),
            403
        );
    }

    private function ensureAssignedPatientCaregiver(int $caregiverId, int $patientId): void
    {
        abort_unless(
            PatientCaregiverRelationship::where('caregiver_id', $caregiverId)
                ->where('patient_id', $patientId)
                ->whereIn('status', ['active', 'accepted'])
                ->exists(),
            403
        );
    }

    private function authorizeReportAccess($user, Report $report): void
    {
        match ($user->role) {
            'admin'     => null,
            'patient'   => abort_unless($user->patient?->id === $report->patient_id, 403),
            'doctor'    => $this->ensureAssignedPatient($user->doctor->id, $report->patient_id),
            'caregiver' => $this->ensureAssignedPatientCaregiver($user->caregiver->id, $report->patient_id),
            default     => abort(403),
        };
    }

    private function authorizePatientView(Request $request, Patient $patient): void
    {
        abort_if($this->patientAccessMode($request, $patient) === 'none', 403);
    }

    private function patientAccessMode(Request $request, Patient $patient): string
    {
        $user = $request->user();
        if ($user->role === 'admin') return 'full';

        if ($user->role === 'patient') {
            return $user->patient?->id === $patient->id ? 'full' : 'none';
        }

        if ($user->role === 'doctor' && $user->doctor) {
            $status = PatientDoctorRelationship::where('doctor_id', $user->doctor->id)
                ->where('patient_id', $patient->id)
                ->latest()
                ->value('status');
            return in_array($status, ['active', 'accepted'], true) ? 'full' : ($status === 'pending' ? 'preview' : 'none');
        }

        if ($user->role === 'caregiver' && $user->caregiver) {
            $status = PatientCaregiverRelationship::where('caregiver_id', $user->caregiver->id)
                ->where('patient_id', $patient->id)
                ->latest()
                ->value('status');
            return in_array($status, ['active', 'accepted'], true) ? 'full' : ($status === 'pending' ? 'preview' : 'none');
        }

        return 'none';
    }

    private function patientPreview(Patient $patient): array
    {
        $patient->loadMissing('user');
        $latestStatus = $patient->dailyStatuses()->latest()->first();

        return [
            'patient' => [
                'id' => $patient->id,
                'name' => $patient->user?->name ?? 'Patient',
                'age' => $patient->age,
                'gender' => $patient->gender,
                'blood_type' => $patient->blood_type,
                'chronic_conditions' => $patient->chronic_conditions,
            ],
            'latest_check_in' => $latestStatus ? [
                'date' => $latestStatus->date,
                'mood' => $latestStatus->mood,
                'pain_level' => $latestStatus->pain_level,
                'sleep_quality' => $latestStatus->sleep_quality,
                'risk_level' => $latestStatus->risk_level,
                'symptoms' => $latestStatus->symptoms,
                'notes' => $latestStatus->notes,
                'created_at' => $latestStatus->created_at,
            ] : null,
            'recent_check_ins' => $patient->dailyStatuses()
                ->latest()
                ->take(3)
                ->get(['id', 'date', 'mood', 'pain_level', 'sleep_quality', 'risk_level', 'symptoms', 'notes', 'created_at'])
                ->values(),
        ];
    }
}
