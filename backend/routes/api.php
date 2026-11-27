<?php

use App\Http\Controllers\Admin\AdminAreaController;
use App\Http\Controllers\Admin\PharmacyAdminController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AiAssistantController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CallController;
use App\Http\Controllers\CaregiverController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ClinicMembershipController;
use App\Http\Controllers\ClinicPatientController;
use App\Http\Controllers\ClinicServiceController;
use App\Http\Controllers\DoctorCredentialController;
use App\Http\Controllers\ContactMessageController;
use App\Http\Controllers\DailyStatusController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiseaseController;
use App\Http\Controllers\DoctorController;
use App\Http\Controllers\DoctorVerificationController;
use App\Http\Controllers\EmergencyAlertController;
use App\Http\Controllers\GroupChatController;
use App\Http\Controllers\HospitalController;
use App\Http\Controllers\MedicationController;
use App\Http\Controllers\MedicationReminderController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\PharmacyController;
use App\Http\Controllers\PrescriptionScanController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\RelationshipController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StorageFileController;
use App\Http\Middleware\StorageCors;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    // COST / SECURITY (RIVA-101): these are public, unauthenticated,
    // security-sensitive account endpoints — throttled per-IP on top of the
    // login route's own existing hand-rolled limiter (left untouched below).
    Route::middleware('throttle:auth-sensitive')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('verify-reset-code', [AuthController::class, 'verifyResetCode']);
        Route::post('resend-reset-code', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
    });
    Route::post('login', [AuthController::class, 'login']);
    Route::get('google/redirect', [AuthController::class, 'googleRedirect']);
    Route::get('google/callback', [AuthController::class, 'googleCallback']);
    Route::post('google/complete-profile', [AuthController::class, 'completeGoogleProfile']);
});

// Public doctor routes
Route::get('doctors', [DoctorController::class, 'index']);
// RIVA-141 follow-up: MUST come before doctors/{doctor} — otherwise Laravel's
// implicit route-model binding tries to resolve "filter-options" as a
// {doctor} id/key and 404s before ever reaching the real handler.
Route::get('doctors/filter-options', [DoctorController::class, 'filterOptions']);
Route::get('doctors/{doctor}', [DoctorController::class, 'show']);
Route::get('doctors/{doctor}/reviews', [DoctorController::class, 'reviews']);
Route::get('doctors/{doctor}/availability', [DoctorController::class, 'availability']);
Route::get('doctors/{doctor}/hospitals', [DoctorController::class, 'hospitals']);
Route::get('doctors/{doctor}/services', [DoctorController::class, 'services']);
// RIVA-130 follow-up: real, doctor-chosen payment methods for the booking/
// payment page — same public visibility as /services above; see
// PaymentMethodController's docblock.
Route::get('doctors/{doctor}/payment-methods', [PaymentMethodController::class, 'forDoctor']);
// RIVA audit (Section 2 — public professional credentials, 2026-08-28): same
// public-visibility pattern as /services and /payment-methods above — only
// rows the doctor marked public are ever returned (DoctorCredentialController::forDoctor()).
Route::get('doctors/{doctor}/credentials', [DoctorCredentialController::class, 'forDoctor']);

// Public caregiver routes
Route::get('caregivers', [CaregiverController::class, 'index']);
// RIVA-141 follow-up: same ordering requirement as doctors/filter-options above.
Route::get('caregivers/filter-options', [CaregiverController::class, 'filterOptions']);
Route::get('caregivers/{caregiver}', [CaregiverController::class, 'show']);
Route::get('caregivers/{caregiver}/reviews', [CaregiverController::class, 'reviews']);

// Public misc routes
// COST / SECURITY (RIVA-101): public, unauthenticated write endpoint with no
// auth-failure signal of its own — throttled per-IP.
Route::post('contact-message', [ContactMessageController::class, 'store'])
    ->middleware('throttle:public-write');
Route::get('diseases', [DiseaseController::class, 'index']);
Route::get('diseases/{slug}', [DiseaseController::class, 'show']);
Route::get('hospitals', [HospitalController::class, 'index']);
Route::get('hospitals/{hospital}', [HospitalController::class, 'show']);

// Public pharmacy routes (RIVA-110, directive §16) — read-only directory +
// medicine availability search. No write access exists outside the admin
// group below.
Route::get('pharmacies', [PharmacyController::class, 'index']);
Route::get('pharmacies/{pharmacy}', [PharmacyController::class, 'show']);
Route::get('pharmacies/{pharmacy}/branches', [PharmacyController::class, 'branches']);
Route::get('medicines', [PharmacyController::class, 'medicines']);
Route::get('medicines/{medicine}/availability', [PharmacyController::class, 'availability']);

// SECURITY / file-serving architecture (principal-engineering directive): deliberately
// OUTSIDE the auth:sanctum group below — a plain <img src>/<a href> cannot attach a
// bearer token, so this is the only reachable way to render a private, ownership-checked
// file (doctor-verification documents, chat attachments) directly in the browser. The
// 'signed' middleware (Laravel's ValidateSignature, checking APP_KEY-keyed HMAC + expiry
// over the full query string) is the actual credential here, not authentication — see
// StorageFileController::signedUrl()/showSigned() for how links are minted and verified.
Route::get('/files/signed/{path}', [StorageFileController::class, 'showSigned'])
    ->where('path', '.*')
    ->middleware('signed')
    ->name('files.signed');

// RIVA-143: public (unauthenticated) clinic staff invite-accept endpoints —
// the invitee has no account/token yet when they open the link, so these
// must live outside the auth:sanctum group below. See
// ClinicMembershipController::inviteDetails()/acceptInvite() docblocks.
Route::get('clinic-invites/{token}', [ClinicMembershipController::class, 'inviteDetails']);
Route::post('clinic-invites/{token}/accept', [ClinicMembershipController::class, 'acceptInvite'])
    ->middleware('throttle:auth-sensitive');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::post('auth/set-role', [AuthController::class, 'setRole']); // ✅

    Route::get('profile', [ProfileController::class, 'show']);
    Route::post('profile', [ProfileController::class, 'update']);
    Route::patch('profile', [ProfileController::class, 'update']);
    Route::put('profile', [ProfileController::class, 'update']);
    Route::put('profile/password', [ProfileController::class, 'changePassword']);
    Route::post('profile/complete', [ProfileController::class, 'complete']);
    Route::put('profile/complete', [ProfileController::class, 'complete']);
    // COST / SECURITY (RIVA-101): the one endpoint that spends real external
    // money per call (Gemini) — throttled per-user (falls back to per-IP for
    // an unauthenticated caller, though this route also requires auth:sanctum).
    Route::post('ai-assistant/message', [AiAssistantController::class, 'message'])
        ->middleware('throttle:ai-assistant');

    Route::post('doctors/{doctor}/reviews', [DoctorController::class, 'storeReview']);
    // ✅ Caregiver review (auth required — patient only enforced in controller)
    Route::post('caregivers/{caregiver}/reviews', [CaregiverController::class, 'storeReview']);

    Route::post('messages', [ChatController::class, 'store']);
    Route::post('messages/upload', [ChatController::class, 'upload']); // ✅ الأول
    Route::get('messages/{user}', [ChatController::class, 'messages']);
    Route::post('messages/{user}', [ChatController::class, 'store']);
    // SECURITY (Phase 0): /audio/{path} and /files/{path} used to be identical closures
    // reading storage_path('app/public/'.$path) with no ownership check — any
    // authenticated user could read any other user's uploaded file. Both now route
    // through StorageFileController::show(), which checks ownership per path prefix
    // and blocks path traversal. See app/Http/Controllers/StorageFileController.php.
    Route::get('/audio/{path}', [StorageFileController::class, 'show'])->where('path', '.*');
    Route::get('/files/{path}', [StorageFileController::class, 'show'])->where('path', '.*');
    Route::get('chat/contacts', [ChatController::class, 'contacts']);
    Route::post('call/signal', [CallController::class, 'signal']);
    Route::get('groups', [GroupChatController::class, 'index']);
    Route::post('groups', [GroupChatController::class, 'create']);
    Route::get('groups/{groupId}', [GroupChatController::class, 'messages']);
    Route::get('groups/{groupId}/messages', [GroupChatController::class, 'messages']);
    Route::post('groups/{groupId}/messages', [GroupChatController::class, 'sendMessage']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::get('notifications/{notification}', [NotificationController::class, 'show']);
    Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read']);
    Route::patch('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::patch('notifications/{notification}/read', [NotificationController::class, 'read']);
    Route::delete('notifications', [NotificationController::class, 'destroyAll']);
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy']);
    Route::middleware('auth:sanctum')->post('/medication-reminder/send',
        [MedicationReminderController::class, 'send']);

    // SECURITY (Phase 0): same IDOR fix as /audio and /files above — see StorageFileController.
    Route::get('storage/{path}', [StorageFileController::class, 'show'])
        ->where('path', '.*')
        ->middleware(StorageCors::class);
    Route::middleware('role:patient')->group(function () {
        Route::post('doctors/{doctor}/follow-request', [RelationshipController::class, 'sendDoctorFollowRequest']);
        Route::post('caregivers/{caregiver}/follow-request', [RelationshipController::class, 'sendCaregiverFollowRequest']);
        Route::get('patient/my-doctors', [RelationshipController::class, 'patientDoctors']);
        Route::get('patient/my-caregivers', [RelationshipController::class, 'patientCaregivers']);
        Route::get('daily-status', [DailyStatusController::class, 'index']);
        Route::post('daily-status', [DailyStatusController::class, 'store']);
        Route::get('daily-status/latest', [DailyStatusController::class, 'latest']);
        Route::get('medications', [MedicationController::class, 'index']);
        Route::get('medications/today-schedule', [MedicationController::class, 'todaySchedule']);
        Route::get('patient/medications/today', [MedicationController::class, 'patientToday']);
        Route::post('patient/medications/doses/{dose}/take', [MedicationController::class, 'takeDose']);
        Route::post('patient/medications/doses/{dose}/snooze', [MedicationController::class, 'snoozeDose']);
        Route::post('medications', [MedicationController::class, 'store']);
        Route::get('medications/adherence/summary', [MedicationController::class, 'adherenceSummary']);
        Route::get('dashboard/patient', [DashboardController::class, 'patient']);
        Route::post('emergency-alert', [EmergencyAlertController::class, 'store']);
        Route::post('emergency-alerts', [EmergencyAlertController::class, 'store']);
    });

    Route::middleware('role:doctor')->group(function () {
        Route::get('doctor/follow-requests', [RelationshipController::class, 'doctorFollowRequests']);
        Route::post('doctor/follow-requests/{relationship}/accept', [RelationshipController::class, 'acceptDoctorFollowRequest']);
        Route::post('doctor/follow-requests/{relationship}/reject', [RelationshipController::class, 'rejectDoctorFollowRequest']);
        Route::get('doctor/patients', [RelationshipController::class, 'doctorPatients']);
        Route::get('doctor-patients', [RelationshipController::class, 'doctorPatients']);
        Route::post('doctor-relationships/{relationship}/accept', [RelationshipController::class, 'acceptDoctorFollowRequest']);
        Route::post('doctor-relationships/{relationship}/reject', [RelationshipController::class, 'rejectDoctorFollowRequest']);
        Route::get('dashboard/doctor', [DashboardController::class, 'doctor']);
        Route::get('doctor/verification/status', [DoctorVerificationController::class, 'status']);
        Route::post('doctor/verification/submit', [DoctorVerificationController::class, 'submit']);
    });

    Route::middleware('role:caregiver')->group(function () {
        Route::get('caregiver/follow-requests', [RelationshipController::class, 'caregiverFollowRequests']);
        Route::post('caregiver/follow-requests/{relationship}/accept', [RelationshipController::class, 'acceptCaregiverFollowRequest']);
        Route::post('caregiver/follow-requests/{relationship}/reject', [RelationshipController::class, 'rejectCaregiverFollowRequest']);
        Route::get('dashboard/caregiver', [DashboardController::class, 'caregiver']);
    });

    Route::middleware('role:patient,doctor,caregiver,admin')->group(function () {
        Route::get('medications/{medication}', [MedicationController::class, 'show']);
        Route::put('medications/{medication}', [MedicationController::class, 'update']);
        Route::delete('medications/{medication}', [MedicationController::class, 'destroy']);
        Route::post('medications/{medication}/take', [MedicationController::class, 'take']);
        Route::post('medications/{medication}/snooze', [MedicationController::class, 'snooze']);
        Route::post('medications/{medication}/missed', [MedicationController::class, 'missed']);
        Route::post('medications/{medication}/need-help', [MedicationController::class, 'needHelp']);
        Route::post('medication-logs/detect-missed', [MedicationController::class, 'detectMissed']);

        Route::get('appointments', [AppointmentController::class, 'index']);
        Route::post('appointments', [AppointmentController::class, 'store']);
        Route::get('appointments/{appointment}', [AppointmentController::class, 'show']);
        Route::put('appointments/{appointment}', [AppointmentController::class, 'update']);
        Route::post('appointments/{appointment}/confirm', [AppointmentController::class, 'confirm']);
        Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);
        Route::post('appointments/{appointment}/complete', [AppointmentController::class, 'complete']);
        Route::post('payments/{appointment}/pay', [AppointmentController::class, 'pay']);
        Route::post('payments/{appointment}/fail', [AppointmentController::class, 'failPayment']);
        Route::get('payments/{appointment}', [AppointmentController::class, 'paymentStatus']);

        Route::get('reports', [ReportController::class, 'index']);
        Route::post('reports', [ReportController::class, 'storeReport']);
        // RIVA-130 follow-up: MUST come before reports/{report} — otherwise
        // Laravel's implicit route-model binding tries to resolve
        // "shared-with-me" as a {report} id and 404s before ever reaching
        // the real handler (same ordering requirement as filter-options
        // elsewhere in this file).
        Route::get('reports/shared-with-me', [ReportController::class, 'sharedWithMe']);
        Route::get('reports/{report}', [ReportController::class, 'show']);
        Route::post('reports/{report}/comments', [ReportController::class, 'addComment']);
        Route::post('reports/{report}/share', [ReportController::class, 'shareReport']);
        Route::delete('reports/{report}/share/{doctorId}', [ReportController::class, 'unshareReport']);
        Route::get('patients/{patient}/reports', [ReportController::class, 'patientReports']);
        Route::post('diagnoses', [ReportController::class, 'storeDiagnosis']);
        Route::get('patients/{patient}/diagnoses', [ReportController::class, 'patientDiagnoses']);
        Route::get('patients/{patient}/daily-status', [DailyStatusController::class, 'patientStatuses']);
        Route::get('patients/{patient}/profile', [DashboardController::class, 'patientProfile']);

        // RIVA-130: real photo-based prescription capture — see
        // PrescriptionScanController's docblock.
        Route::post('prescriptions/scan', [PrescriptionScanController::class, 'scan']);
        Route::post('prescriptions/register', [PrescriptionScanController::class, 'register']);

        // RIVA-130 follow-up: a doctor's own choice of which real payment
        // rails they accept and the account detail patients pay to — see
        // PaymentMethodController's docblock. Restricted to the owning
        // doctor inside the controller (abort_unless($doctor, 403) /
        // assertOwnedByDoctor()), same pattern as prescriptions above.
        Route::get('payment-methods/catalog', [PaymentMethodController::class, 'catalog']);
        Route::get('doctor/payment-methods', [PaymentMethodController::class, 'index']);
        Route::post('doctor/payment-methods', [PaymentMethodController::class, 'store']);
        Route::patch('doctor/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'update']);
        Route::delete('doctor/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy']);

        // RIVA audit (Section 2 — public professional credentials, 2026-08-28):
        // owner-scoped CRUD for the doctor's own credentials list, same pattern
        // as doctor/payment-methods above (assertOwnedByDoctor() inside the
        // controller). The public, read-only counterpart is the
        // doctors/{doctor}/credentials route above.
        Route::get('doctor/credentials', [DoctorCredentialController::class, 'index']);
        Route::post('doctor/credentials', [DoctorCredentialController::class, 'store']);
        Route::patch('doctor/credentials/{credential}', [DoctorCredentialController::class, 'update']);
        Route::delete('doctor/credentials/{credential}', [DoctorCredentialController::class, 'destroy']);
    });

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('overview', [AdminAreaController::class, 'overview']);
        Route::get('users-management', [AdminAreaController::class, 'users']);
        Route::put('users/{user}', [AdminAreaController::class, 'updateUser']);
        Route::patch('users/{user}/admin-status', [AdminAreaController::class, 'updateUserStatus']);
        Route::get('doctor-verification', [AdminAreaController::class, 'doctorVerification']);
        Route::post('doctor-verification/{doctor}/approve', [AdminAreaController::class, 'approveDoctor']);
        Route::post('doctor-verification/{doctor}/reject', [AdminAreaController::class, 'rejectDoctor']);
        Route::get('doctor-verifications', [AdminAreaController::class, 'doctorVerifications']);
        Route::get('doctor-verifications/{verification}', [AdminAreaController::class, 'showDoctorVerification']);
        Route::post('doctor-verifications/{verification}/approve', [AdminAreaController::class, 'approveDoctorVerification']);
        Route::post('doctor-verifications/{verification}/reject', [AdminAreaController::class, 'rejectDoctorVerification']);
        Route::get('patient-monitoring', [AdminAreaController::class, 'patientMonitoring']);
        Route::get('emergencies', [AdminAreaController::class, 'emergencies']);
        Route::post('emergencies/{alert}/resolve', [AdminAreaController::class, 'resolveEmergency']);
        Route::get('daily-reports', [AdminAreaController::class, 'dailyReports']);
        Route::get('appointments-payments', [AdminAreaController::class, 'appointments']);
        Route::get('contact-messages', [AdminAreaController::class, 'contactMessages']);
        Route::post('contact-messages/{message}/handled', [AdminAreaController::class, 'markContactHandled']);
        Route::get('notifications-center', [AdminAreaController::class, 'notifications']);
        Route::post('notifications-center/{notification}/resend', [AdminAreaController::class, 'resendNotification']);
        Route::get('activity', [AdminAreaController::class, 'activityLogs']);
        Route::get('settings', [AdminAreaController::class, 'settings']);

        Route::get('dashboard', [DashboardController::class, 'admin']);
        Route::get('users', [AdminController::class, 'users']);
        Route::patch('users/{user}/status', [AdminController::class, 'updateUserStatus']);
        Route::get('patients', [AdminController::class, 'patients']);
        Route::get('doctors', [AdminController::class, 'doctors']);
        Route::patch('doctors/{doctor}/verify', [AdminController::class, 'verifyDoctor']);
        Route::get('caregivers', [AdminController::class, 'caregivers']);
        Route::get('reports', [AdminController::class, 'reports']);
        Route::get('alerts', [AdminController::class, 'alerts']);
        Route::get('activity-logs', [AdminController::class, 'activityLogs']);
        Route::get('login-attempts', [AdminController::class, 'loginAttempts']);
        Route::post('diseases', [DiseaseController::class, 'store']);
        Route::put('diseases/{disease}', [DiseaseController::class, 'update']);
        Route::delete('diseases/{disease}', [DiseaseController::class, 'destroy']);
        Route::post('hospitals', [HospitalController::class, 'store']);
        Route::put('hospitals/{hospital}', [HospitalController::class, 'update']);
        Route::delete('hospitals/{hospital}', [HospitalController::class, 'destroy']);
        Route::post('doctor-hospitals', [HospitalController::class, 'attachDoctorHospital']);

        // Pharmacy foundation admin CRUD (RIVA-110) — the only write surface
        // for pharmacies/branches/medicines/availability.
        Route::get('pharmacies', [PharmacyAdminController::class, 'indexPharmacies']);
        Route::post('pharmacies', [PharmacyAdminController::class, 'storePharmacy']);
        Route::put('pharmacies/{pharmacy}', [PharmacyAdminController::class, 'updatePharmacy']);
        Route::delete('pharmacies/{pharmacy}', [PharmacyAdminController::class, 'destroyPharmacy']);
        Route::post('pharmacies/{pharmacy}/branches', [PharmacyAdminController::class, 'storeBranch']);
        Route::put('pharmacy-branches/{branch}', [PharmacyAdminController::class, 'updateBranch']);
        Route::delete('pharmacy-branches/{branch}', [PharmacyAdminController::class, 'destroyBranch']);
        Route::get('medicines', [PharmacyAdminController::class, 'indexMedicines']);
        Route::post('medicines', [PharmacyAdminController::class, 'storeMedicine']);
        Route::put('medicines/{medicine}', [PharmacyAdminController::class, 'updateMedicine']);
        Route::delete('medicines/{medicine}', [PharmacyAdminController::class, 'destroyMedicine']);
        Route::post('pharmacy-availability', [PharmacyAdminController::class, 'storeAvailability']);
        Route::put('pharmacy-availability/{availability}', [PharmacyAdminController::class, 'updateAvailability']);
        Route::delete('pharmacy-availability/{availability}', [PharmacyAdminController::class, 'destroyAvailability']);
    });

    // Phase C (Queue slice, principal-engineering directive): a lightweight,
    // graceful "do I have clinic access, and as what role" probe for the
    // frontend to decide whether to render clinic-staff navigation at all.
    // Deliberately OUTSIDE 'clinic.context' — that middleware hard-aborts
    // (403/409) for zero or multiple memberships, which is correct for an
    // actual data-access endpoint but wrong for a nav-rendering hint that
    // must degrade gracefully for the majority of users who have no clinic
    // membership at all.
    Route::get('clinic/my-context', [ClinicMembershipController::class, 'myContext']);

    // PHASE 1 (Identity + Tenancy + RBAC): clinic-scoped routes. 'clinic.context'
    // resolves and verifies the caller's active membership in the target clinic
    // before any of these run (see EnsureClinicContext / ClinicContextService) —
    // this is the actual tenant-isolation boundary, not just a route grouping.
    Route::middleware('clinic.context')->prefix('clinic')->group(function () {
        Route::get('members', [ClinicMembershipController::class, 'index']);
        Route::post('members', [ClinicMembershipController::class, 'store']);
        Route::patch('members/{member}/role', [ClinicMembershipController::class, 'updateRole']);
        Route::patch('members/{member}/status', [ClinicMembershipController::class, 'updateStatus']);
        Route::patch('members/{member}/permissions', [ClinicMembershipController::class, 'updatePermissions']);
        Route::delete('members/{member}', [ClinicMembershipController::class, 'destroy']);

        // Phase C (Queue + Appointment operational workflow): see
        // QueueController's docblock for why this is clinic-scoped rather
        // than folded into the relationship-scoped AppointmentController.
        Route::get('queue', [QueueController::class, 'index']);
        Route::post('queue/{appointment}/check-in', [QueueController::class, 'checkIn']);
        Route::post('queue/{appointment}/call', [QueueController::class, 'call']);
        Route::post('queue/{appointment}/start', [QueueController::class, 'start']);
        Route::post('queue/{appointment}/complete', [QueueController::class, 'complete']);
        Route::post('queue/{appointment}/no-show', [QueueController::class, 'noShow']);
        Route::post('queue/{appointment}/skip', [QueueController::class, 'skip']);
        Route::post('queue/{appointment}/remove', [QueueController::class, 'remove']);

        // Phase C (Patient registration/search): the first real consumer of
        // ClinicUser::PERMISSION_REGISTER_PATIENTS. See
        // ClinicPatientController's docblock for scope boundaries.
        Route::get('patients/search', [ClinicPatientController::class, 'search']);
        Route::get('patients/search-by-name', [ClinicPatientController::class, 'searchByName']);
        Route::post('patients', [ClinicPatientController::class, 'register']);

        // Phase C (Services & Prices): the first real consumer of
        // ClinicUser::PERMISSION_MANAGE_SERVICES. See
        // ClinicServiceController's docblock for scope boundaries.
        Route::get('services', [ClinicServiceController::class, 'index']);
        Route::post('services', [ClinicServiceController::class, 'store']);
        Route::patch('services/{service}', [ClinicServiceController::class, 'update']);
        Route::delete('services/{service}', [ClinicServiceController::class, 'destroy']);
    });
});
