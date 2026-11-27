<?php

namespace App\Http\Controllers;

use App\Models\Diagnosis;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientCaregiverRelationship;
use App\Models\PatientDoctorRelationship;
use App\Models\Report;
use App\Models\ReportComment;
use App\Models\ReportShare;
use App\Services\NotificationService;
use App\Services\RecordAccessAuditService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly RecordAccessAuditService $accessAudit
    ) {}

    // ─── List reports ───────────────────────────────────────────────
    /**
     * SECURITY (RIVA-106 / audit §22 P1-3, "unmatched role"): this used to
     * be a plain if/elseif chain with no else branch — a role that matched
     * none of patient/doctor/caregiver (this route's middleware also
     * allows 'admin' through), or a patient/doctor/caregiver user whose
     * profile relation is unexpectedly missing (a data-integrity edge
     * case, not just a hypothetical), fell through with zero WHERE clause
     * applied. Report::with(...)->get() with no filter returns every
     * patient's medical report in the system to that caller. Mirrors the
     * already-correct pattern in AppointmentController::index(): admin's
     * unfiltered access is explicit and intentional (platform oversight,
     * same trust model as everywhere else in this app), and every other
     * unmatched case now falls through to an explicit empty result instead
     * of an implicit unfiltered one.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Report::with(['patient.user', 'doctor.user', 'comments.user']);

        if ($user->role === 'admin') {
            // intentionally unfiltered: platform admin oversight.
        } elseif ($user->role === 'patient' && $user->patient) {
            $query->where('patient_id', $user->patient->id);
        } elseif ($user->role === 'doctor' && $user->doctor) {
            // RIVA-142: a doctor sees reports they wrote themselves, PLUS any
            // report a patient explicitly shared with them via ReportShare
            // (never every report of a patient they treat — that stays
            // scoped to the authoring doctor unless the patient shares it).
            $doctorId = $user->doctor->id;
            $sharedReportIds = ReportShare::where('doctor_id', $doctorId)->pluck('report_id');
            $query->where(function ($q) use ($doctorId, $sharedReportIds) {
                $q->where('doctor_id', $doctorId)->orWhereIn('id', $sharedReportIds);
            });
        } elseif ($user->role === 'caregiver' && $user->caregiver) {
            // caregiver sees reports of their assigned patients
            $patientIds = PatientCaregiverRelationship::where('caregiver_id', $user->caregiver->id)
                ->whereIn('status', ['active', 'accepted'])
                ->pluck('patient_id');
            $query->whereIn('patient_id', $patientIds);
        } else {
            // Unrecognized role, or a role whose profile row is missing —
            // never fall through to an unfiltered query.
            $query->whereRaw('1 = 0');
        }

        $reports = $query->latest()->get()->map(fn ($r) => $this->transformReport($r, $user));

        return $this->success($reports);
    }

    // ─── Single report ───────────────────────────────────────────────
    public function show(Request $request, Report $report)
    {
        $user = $request->user();
        $this->authorizeReportAccess($user, $report);
        $this->accessAudit->logView($user, 'report', $report->id, $report->patient_id, $request->ip());

        $report->load(['patient.user', 'doctor.user', 'comments.user']);

        // Mark unread comments as read if patient is viewing
        if ($user->role === 'patient') {
            $report->comments()
                ->where('is_read_by_patient', false)
                ->update(['is_read_by_patient' => true]);
        }

        $data = $this->transformReport($report, $user);

        // RIVA-142: only the owning patient sees who this report has been
        // shared with — a receiving doctor sees the report itself but not
        // the patient's full sharing list.
        if ($user->role === 'patient' && $user->patient?->id === $report->patient_id) {
            $data['shared_with'] = ReportShare::where('report_id', $report->id)
                ->with('doctor.user')
                ->get()
                ->map(fn (ReportShare $share) => [
                    'doctor_id' => $share->doctor_id,
                    'doctor_name' => $share->doctor?->user?->name ?? 'Doctor',
                ])
                ->values();
        }

        return $this->success($data);
    }

    /**
     * RIVA-142: the patient who owns this report grants a specific doctor —
     * other than the one who wrote it — access to it. Only that patient may
     * do this; a doctor or caregiver can never share on a patient's behalf.
     */
    public function shareReport(Request $request, Report $report)
    {
        $user = $request->user();
        abort_unless($user->role === 'patient' && $user->patient?->id === $report->patient_id, 403);

        $data = $request->validate([
            'doctor_id' => ['required', 'integer', 'exists:doctors,id'],
        ]);

        abort_if(
            (int) $data['doctor_id'] === (int) $report->doctor_id,
            422,
            'This doctor already wrote this report and already has access to it.'
        );

        $share = ReportShare::firstOrCreate([
            'report_id' => $report->id,
            'doctor_id' => $data['doctor_id'],
        ]);

        if ($share->wasRecentlyCreated) {
            $doctor = Doctor::with('user')->find($data['doctor_id']);
            if ($doctor?->user) {
                $this->notificationService->create(
                    $doctor->user,
                    'report.shared',
                    'A Patient Shared a Report With You',
                    "{$user->name} shared their report \"{$report->title}\" with you.",
                    [
                        'patient_id' => $report->patient_id,
                        'report_id' => $report->id,
                        'report_title' => $report->title,
                        'source' => 'report_share',
                        'recommendation' => 'Open your reports to review it.',
                    ],
                    '/my-reports',
                    'reports'
                );
            }
        }

        return $this->success(null, 'Report shared.', $share->wasRecentlyCreated ? 201 : 200);
    }

    public function unshareReport(Request $request, Report $report, int $doctorId)
    {
        $user = $request->user();
        abort_unless($user->role === 'patient' && $user->patient?->id === $report->patient_id, 403);

        ReportShare::where('report_id', $report->id)->where('doctor_id', $doctorId)->delete();

        return $this->success(null, 'Sharing revoked.');
    }

    // ─── Doctor/Caregiver creates a report ──────────────────────────
    public function storeReport(Request $request)
    {
        $user = $request->user();
        $doctor = $user->doctor;
        abort_unless($doctor, 403);

        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['required', 'string'],
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
            'report_id' => $report->id,
            'user_id' => $user->id,
            'commenter_role' => $commenterRole,
            'comment' => $data['comment'],
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
            'id' => $comment->id,
            'comment' => $comment->comment,
            'commenter_role' => $comment->commenter_role,
            'commenter_name' => $user->name,
            'commenter_avatar' => $user->avatar ?? null,
            'created_at' => $comment->created_at,
        ], 'Comment added successfully', 201);
    }

    // ─── Patient reports list ───────────────────────────────────────
    /**
     * RIVA-130 follow-up (product-owner request, recorded 2026-08-25): "what
     * do I gain from browsing every report I've ever written — open the
     * patient's own profile and see the reports written for THEM there"
     * — this endpoint IS that per-patient view (report.component.ts, reached
     * from ProfilePComponent::goToReport()). Its own scoping had a real
     * cross-doctor leak the same request also called out: it returned
     * `$patient->reports()` completely unfiltered by author, so ANY doctor
     * with an active relationship to this patient could read every OTHER
     * doctor's reports about them too — the exact opposite of the explicit
     * "isolation between my reports and any other doctor's reports" this
     * request asked for. A doctor caller now sees only the reports THEY
     * personally wrote for this patient; a report another doctor wrote (and
     * the patient hasn't shared) never appears here at all — see
     * sharedWithMe() below for where an explicitly-shared report belongs
     * instead. Patient/caregiver/admin callers are unchanged (a patient owns
     * every report about them; caregiver/admin oversight was never the
     * cross-doctor-privacy question this request raised).
     */
    public function patientReports(Request $request, Patient $patient)
    {
        $user = $request->user();
        $accessMode = $this->patientAccessMode($request, $patient);
        abort_if($accessMode === 'none', 403);
        $this->accessAudit->logView($user, 'patient_reports_list', $patient->id, $patient->id, $request->ip());

        if ($accessMode === 'preview') {
            return $this->success([
                'access_mode' => 'preview',
                'reports' => [],
                'preview' => $this->patientPreview($patient),
            ]);
        }

        $query = $patient->reports()->with(['doctor.user', 'comments.user']);
        if ($user->role === 'doctor' && $user->doctor) {
            $query->where('doctor_id', $user->doctor->id);
        }

        $reports = $query->latest()->get()->map(fn ($r) => $this->transformReport($r));

        return $this->success([
            'access_mode' => 'full',
            'reports' => $reports,
            'preview' => $this->patientPreview($patient),
        ]);
    }

    /**
     * RIVA-130 follow-up: the isolated home for a report ANOTHER doctor
     * wrote that the owning patient chose to share with this doctor (see
     * shareReport() above / ReportShare) — deliberately never mixed into
     * patientReports() above, matching the explicit request that shared
     * reports stay "physically separate... isolated between my reports and
     * any other doctor's reports." Spans every patient (a patient can share
     * a report regardless of which patients this doctor otherwise treats),
     * which is exactly why this can't just be a filter on the per-patient
     * view — it is categorically a different list.
     */
    public function sharedWithMe(Request $request)
    {
        $doctor = $request->user()->doctor;
        abort_unless($doctor, 403);

        $reportIds = ReportShare::where('doctor_id', $doctor->id)->pluck('report_id');
        $reports = Report::with(['patient.user', 'doctor.user', 'comments.user'])
            ->whereIn('id', $reportIds)
            ->latest()
            ->get()
            ->map(fn (Report $r) => $this->transformReport($r));

        return $this->success($reports);
    }

    // ─── Diagnoses ───────────────────────────────────────────────────
    public function storeDiagnosis(Request $request)
    {
        $doctor = $request->user()->doctor;
        abort_unless($doctor, 403);

        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'title' => ['required', 'string', 'max:255'],
            'details' => ['required', 'string'],
        ]);

        $this->ensureAssignedPatient($doctor->id, (int) $data['patient_id']);

        $diagnosis = Diagnosis::create([...$data, 'doctor_id' => $doctor->id]);

        return $this->success($diagnosis, 'Diagnosis created successfully', 201);
    }

    public function patientDiagnoses(Request $request, Patient $patient)
    {
        $this->authorizePatientView($request, $patient);
        $this->accessAudit->logView($request->user(), 'patient_diagnoses_list', $patient->id, $patient->id, $request->ip());

        return $this->success($patient->diagnoses()->with('doctor.user')->latest()->get());
    }

    // ─── Helpers ─────────────────────────────────────────────────────
    private function transformReport(Report $report, $viewer = null): array
    {
        $comments = $report->relationLoaded('comments')
            ? $report->comments->map(fn ($c) => [
                'id' => $c->id,
                'comment' => $c->comment,
                'commenter_role' => $c->commenter_role,
                'commenter_name' => $c->user?->name ?? 'Unknown',
                'commenter_avatar' => $c->user?->avatar ?? null,
                'is_read' => $c->is_read_by_patient,
                'created_at' => $c->created_at,
            ])
            : [];

        $unreadCount = is_array($comments)
            ? collect($comments)->where('is_read', false)->count()
            : 0;

        return [
            'id' => $report->id,
            'title' => $report->title,
            'summary' => $report->summary,
            'final_report' => $report->final_report,
            'doctor' => [
                'id' => $report->doctor?->id,
                'name' => $report->doctor?->user?->name ?? 'Doctor',
            ],
            'patient' => [
                'id' => $report->patient?->id,
                'name' => $report->patient?->user?->name ?? 'Patient',
            ],
            'comments' => $comments,
            'unread_comments' => $unreadCount,
            // RIVA-142: true only when this specific viewer is a doctor
            // seeing this report via a patient's explicit share, not because
            // they wrote it or are the currently-assigned doctor.
            'is_shared_with_me' => $viewer && $viewer->role === 'doctor' && $viewer->doctor
                && (int) $report->doctor_id !== (int) $viewer->doctor->id,
            'created_at' => $report->created_at,
            'updated_at' => $report->updated_at,
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
            'admin' => null,
            'patient' => abort_unless($user->patient?->id === $report->patient_id, 403),
            'doctor' => $this->ensureAssignedPatientOrSharedReport($user->doctor?->id, $report),
            'caregiver' => $this->ensureAssignedPatientCaregiver($user->caregiver->id, $report->patient_id),
            default => abort(403),
        };
    }

    /**
     * RIVA-142: a doctor may view a report either because they are the
     * patient's currently active doctor (the pre-existing rule), OR because
     * the owning patient explicitly shared THIS report with them via
     * ReportShare — sharing never grants blanket access to the patient's
     * other reports or records, only this one.
     */
    private function ensureAssignedPatientOrSharedReport(?int $doctorId, Report $report): void
    {
        abort_unless($doctorId, 403);

        $hasActiveRelationship = PatientDoctorRelationship::where('doctor_id', $doctorId)
            ->where('patient_id', $report->patient_id)
            ->whereIn('status', ['active', 'accepted'])
            ->exists();

        if ($hasActiveRelationship) {
            return;
        }

        abort_unless(
            ReportShare::where('report_id', $report->id)->where('doctor_id', $doctorId)->exists(),
            403
        );
    }

    private function authorizePatientView(Request $request, Patient $patient): void
    {
        abort_if($this->patientAccessMode($request, $patient) === 'none', 403);
    }

    private function patientAccessMode(Request $request, Patient $patient): string
    {
        $user = $request->user();
        if ($user->role === 'admin') {
            return 'full';
        }

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
