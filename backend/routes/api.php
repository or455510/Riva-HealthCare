<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AiAssistantController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CaregiverController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ContactMessageController;
use App\Http\Controllers\DailyStatusController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiseaseController;
use App\Http\Controllers\DoctorController;
use App\Http\Controllers\EmergencyAlertController;
use App\Http\Controllers\GroupChatController;
use App\Http\Controllers\HospitalController;
use App\Http\Controllers\MedicationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RelationshipController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\StorageCors;
use App\Http\Controllers\MedicationReminderController;


Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('verify-reset-code', [AuthController::class, 'verifyResetCode']);
    Route::post('resend-reset-code', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    Route::get('google/redirect', [AuthController::class, 'googleRedirect']);
    Route::get('google/callback', [AuthController::class, 'googleCallback']);
    Route::post('google/complete-profile', [AuthController::class, 'completeGoogleProfile']);
});

// Public doctor routes
Route::get('doctors', [DoctorController::class, 'index']);
Route::get('doctors/{doctor}', [DoctorController::class, 'show']);
Route::get('doctors/{doctor}/reviews', [DoctorController::class, 'reviews']);
Route::get('doctors/{doctor}/availability', [DoctorController::class, 'availability']);
Route::get('doctors/{doctor}/hospitals', [DoctorController::class, 'hospitals']);

// Public caregiver routes
Route::get('caregivers', [CaregiverController::class, 'index']);
Route::get('caregivers/{caregiver}', [CaregiverController::class, 'show']);
Route::get('caregivers/{caregiver}/reviews', [CaregiverController::class, 'reviews']);

// Public misc routes
Route::post('contact-message', [ContactMessageController::class, 'store']);
Route::get('diseases', [DiseaseController::class, 'index']);
Route::get('diseases/{slug}', [DiseaseController::class, 'show']);
Route::get('hospitals', [HospitalController::class, 'index']);
Route::get('hospitals/{hospital}', [HospitalController::class, 'show']);

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
    Route::post('ai-assistant/message', [AiAssistantController::class, 'message']);

    Route::post('doctors/{doctor}/reviews', [DoctorController::class, 'storeReview']);
    // ✅ Caregiver review (auth required — patient only enforced in controller)
    Route::post('caregivers/{caregiver}/reviews', [CaregiverController::class, 'storeReview']);

Route::post('messages', [ChatController::class, 'store']);
Route::post('messages/upload', [ChatController::class, 'upload']); // ✅ الأول
Route::get('messages/{user}', [ChatController::class, 'messages']);
Route::post('messages/{user}', [ChatController::class, 'store']);
Route::get('/audio/{path}', function ($path) {
    $fullPath = storage_path('app/public/' . $path);
    if (!file_exists($fullPath)) abort(404);
    return response()->file($fullPath, [
        'Access-Control-Allow-Origin' => '*',
        'Content-Type' => mime_content_type($fullPath),
    ]);
})->where('path', '.*');
Route::get('/files/{path}', function ($path) {
    $fullPath = storage_path('app/public/' . $path);
    if (!file_exists($fullPath)) abort(404);
    $mime = mime_content_type($fullPath);
    return response()->file($fullPath, [
        'Content-Type' => $mime,
        'Access-Control-Allow-Origin' => '*',
    ]);
})->where('path', '.*');
    Route::get('chat/contacts', [ChatController::class, 'contacts']);
    Route::post('call/signal', [App\Http\Controllers\CallController::class, 'signal']);
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

Route::get('storage/{path}', function ($path) {
    $fullPath = storage_path('app/public/' . $path);
    if (!file_exists($fullPath)) abort(404);
    return response()->file($fullPath);
})->where('path', '.*')->middleware(StorageCors::class);
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
        Route::get('reports/{report}', [ReportController::class, 'show']);
        Route::post('reports/{report}/comments', [ReportController::class, 'addComment']);
        Route::get('patients/{patient}/reports', [ReportController::class, 'patientReports']);
        Route::post('diagnoses', [ReportController::class, 'storeDiagnosis']);
        Route::get('patients/{patient}/diagnoses', [ReportController::class, 'patientDiagnoses']);
        Route::get('patients/{patient}/daily-status', [DailyStatusController::class, 'patientStatuses']);
    });

    Route::middleware('role:admin')->prefix('admin')->group(function () {
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
    });
});
