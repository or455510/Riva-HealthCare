<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Caregiver;
use App\Models\DailyStatus;
use App\Models\Diagnosis;
use App\Models\Disease;
use App\Models\Doctor;
use App\Models\DoctorAvailability;
use App\Models\DoctorReview;
use App\Models\Medication;
use App\Models\MedicationLog;
use App\Models\Patient;
use App\Models\PatientCaregiverRelationship;
use App\Models\PatientDoctorRelationship;
use App\Models\Report;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@riva.test'],
            [
                'first_name' => 'Riva',
                'last_name'  => 'Admin',
                'name'       => 'Riva Admin',
                'phone'      => '01000000001',
                'address'    => 'Cairo, Egypt',
                'role'       => 'admin',
                'password'   => 'password',
                'is_active'  => true,
            ]
        );

        $patientUser = User::updateOrCreate(
            ['email' => 'patient@riva.test'],
            [
                'first_name' => 'Amina',
                'last_name'  => 'Hassan',
                'name'       => 'Amina Hassan',
                'phone'      => '01000000002',
                'address'    => 'Alexandria, Egypt',
                'role'       => 'patient',
                'password'   => 'password',
                'is_active'  => true,
            ]
        );

        $doctorUser = User::updateOrCreate(
            ['email' => 'doctor@riva.test'],
            [
                'first_name' => 'Omar',
                'last_name'  => 'Mahmoud',
                'name'       => 'Omar Mahmoud',
                'phone'      => '01000000003',
                'address'    => 'Giza, Egypt',
                'role'       => 'doctor',
                'password'   => 'password',
                'is_active'  => true,
            ]
        );

        $caregiverUser = User::updateOrCreate(
            ['email' => 'caregiver@riva.test'],
            [
                'first_name' => 'Sara',
                'last_name'  => 'Nabil',
                'name'       => 'Sara Nabil',
                'phone'      => '01000000004',
                'address'    => 'Mansoura, Egypt',
                'role'       => 'caregiver',
                'password'   => 'password',
                'is_active'  => true,
            ]
        );

        $patient = Patient::updateOrCreate(
            ['user_id' => $patientUser->id],
            [
                'age'               => 67,
                'gender'            => 'female',
                'blood_type'        => 'O+',
                'emergency_contact' => '01234567890',
                'chronic_conditions'=> 'diabetes, hypertension',
                'medical_history'   => 'diabetes, hypertension',
            ]
        );

        $doctor = Doctor::updateOrCreate(
            ['user_id' => $doctorUser->id],
            [
                'specialty'           => 'Internal Medicine',
                'years_of_experience' => 12,
                'fee'                 => 450,
                'bio'                 => 'Internal medicine specialist focused on chronic care monitoring.',
                'about'               => 'Experienced in elderly and chronic disease care.',
                'license_number'      => 'DOC-RIVA-001',
                'verification_status' => 'verified',
                'is_active'           => true,
                'contact_info'        => 'doctor@riva.test',
                'available_days'      => 'Monday,Wednesday,Saturday',
            ]
        );

        $caregiver = Caregiver::updateOrCreate(
            ['user_id' => $caregiverUser->id],
            [
                'specialty'           => 'Elderly Support',
                'experience_years'    => 6,
                'salary'              => 6000,
                'verification_status' => 'verified',
                'is_active'           => true,
                'about'               => 'Medication and daily follow-up caregiver.',
            ]
        );

        PatientDoctorRelationship::updateOrCreate(
            ['patient_id' => $patient->id, 'doctor_id' => $doctor->id],
            [
                'status'       => 'active',
                'requested_at' => now()->subDays(5)->format('Y-m-d H:i:s'),
                'responded_at' => now()->subDays(4)->format('Y-m-d H:i:s'),
            ]
        );

        PatientCaregiverRelationship::updateOrCreate(
            ['patient_id' => $patient->id, 'caregiver_id' => $caregiver->id],
            [
                'status'       => 'active',
                'requested_at' => now()->subDays(4)->format('Y-m-d H:i:s'),
                'responded_at' => now()->subDays(3)->format('Y-m-d H:i:s'),
            ]
        );

        $medication1 = Medication::updateOrCreate(
            ['patient_id' => $patient->id, 'name' => 'Metformin'],
            [
                'dosage'         => '500mg',
                'schedule_time'  => '09:00',
                'frequency'      => 'daily',
                'instructions'   => 'Take after breakfast',
                'is_active'      => true,
            ]
        );

        $medication2 = Medication::updateOrCreate(
            ['patient_id' => $patient->id, 'name' => 'Amlodipine'],
            [
                'dosage'        => '5mg',
                'schedule_time' => '20:00',
                'frequency'     => 'daily',
                'instructions'  => 'Take after dinner',
                'is_active'     => true,
            ]
        );

        MedicationLog::updateOrCreate(
            [
                'medication_id' => $medication1->id,
                'patient_id'    => $patient->id,
                'scheduled_at'  => now()->subDay()->format('Y-m-d H:i:s'),
            ],
            [
                'taken_at'     => now()->subDay()->addMinutes(20)->format('Y-m-d H:i:s'),
                'status'       => 'taken',
                'confirmed_by' => $patientUser->id,
            ]
        );

        MedicationLog::updateOrCreate(
            [
                'medication_id' => $medication2->id,
                'patient_id'    => $patient->id,
                'scheduled_at'  => now()->subHours(6)->format('Y-m-d H:i:s'),
            ],
            [
                'status'       => 'missed',
                'confirmed_by' => $patientUser->id,
            ]
        );

        DailyStatus::updateOrCreate(
            ['patient_id' => $patient->id, 'created_at' => now()->subDay()->format('Y-m-d H:i:s')],
            [
                'mood'             => 'good',
                'pain_level'       => 3,
                'sleep_quality'    => 'good',
                'symptoms'         => '',
                'notes'            => 'Feeling stable',
                'medication_taken' => true,
                'risk_level'       => 'stable',
                'updated_at'       => now()->subDay()->format('Y-m-d H:i:s'),
            ]
        );

        DailyStatus::updateOrCreate(
            ['patient_id' => $patient->id, 'created_at' => now()->format('Y-m-d H:i:s')],
            [
                'mood'             => 'tired',
                'pain_level'       => 7,
                'sleep_quality'    => 'poor',
                'symptoms'         => 'dizziness',
                'notes'            => 'Needs attention',
                'medication_taken' => false,
                'risk_level'       => 'moderate_risk',
                'updated_at'       => now()->format('Y-m-d H:i:s'),
            ]
        );

        DoctorAvailability::updateOrCreate(
            ['doctor_id' => $doctor->id, 'day_of_week' => 'Monday'],
            ['start_time' => '09:00', 'end_time' => '13:00', 'is_available' => true]
        );

        DoctorAvailability::updateOrCreate(
            ['doctor_id' => $doctor->id, 'day_of_week' => 'Wednesday'],
            ['start_time' => '10:00', 'end_time' => '15:00', 'is_available' => true]
        );

        DoctorReview::updateOrCreate(
            ['doctor_id' => $doctor->id, 'patient_id' => $patient->id],
            ['rating' => 5, 'comment' => 'Very supportive and clear follow-up plan.']
        );

        Appointment::updateOrCreate(
            [
                'patient_id'       => $patient->id,
                'doctor_id'        => $doctor->id,
                'appointment_date' => now()->addDays(2)->format('Y-m-d'),
            ],
            [
                'appointment_time' => '11:00',
                'type'             => 'online',
                'amount'           => 450,
                'payment_status'   => 'paid',
                'status'           => 'confirmed',
                'notes'            => 'Routine follow-up',
            ]
        );

        Report::updateOrCreate(
            [
                'patient_id' => $patient->id,
                'doctor_id'  => $doctor->id,
                'title'      => 'Monthly Follow-up Report',
            ],
            [
                'summary'      => 'Patient blood pressure improved with consistent adherence.',
                'final_report' => 'Continue current plan and monitoring.',
            ]
        );

        Diagnosis::updateOrCreate(
            [
                'patient_id' => $patient->id,
                'doctor_id'  => $doctor->id,
                'title'      => 'Chronic hypertension management',
            ],
            ['details' => 'Continue medication adherence and weekly monitoring.']
        );

        foreach ([
            [
                'title'          => 'Cancer',
                'slug'           => 'cancer',
                'overview'       => 'General awareness content about cancer and when to seek specialist care.',
                'symptoms'       => 'Unexplained weight loss, fatigue, pain.',
                'causes'         => 'Varies by type and risk factors.',
                'treatment_info' => 'Depends on diagnosis and physician guidance.',
                'tips'           => 'Keep screening appointments and report new symptoms early.',
            ],
            [
                'title'          => 'Diabetes',
                'slug'           => 'diabetes',
                'overview'       => 'Managing blood sugar through medication, diet, and monitoring.',
                'symptoms'       => 'Excess thirst, fatigue, blurred vision.',
                'causes'         => 'Insulin resistance or insulin deficiency.',
                'treatment_info' => 'Medication adherence, nutrition, exercise, check-ups.',
                'tips'           => 'Track glucose and keep medication schedules consistent.',
            ],
            [
                'title'          => 'Hypertension',
                'slug'           => 'hypertension',
                'overview'       => 'High blood pressure requires steady monitoring and adherence.',
                'symptoms'       => 'Often silent, but may include headaches or dizziness.',
                'causes'         => 'Lifestyle, genetics, chronic conditions.',
                'treatment_info' => 'Medication, sodium control, activity, monitoring.',
                'tips'           => 'Check blood pressure regularly and avoid missed doses.',
            ],
            [
                'title'          => 'Heart Disease',
                'slug'           => 'heart-disease',
                'overview'       => 'Heart disease needs regular physician follow-up and symptom awareness.',
                'symptoms'       => 'Chest discomfort, shortness of breath, fatigue.',
                'causes'         => 'Plaque buildup, hypertension, other cardiovascular risks.',
                'treatment_info' => 'Lifestyle support, prescribed medications, timely review.',
                'tips'           => 'Report warning signs early and keep scheduled reviews.',
            ],
            [
                'title'          => 'Post-Surgery Recovery',
                'slug'           => 'post-surgery-recovery',
                'overview'       => 'Recovery planning includes adherence, wound awareness, and follow-up.',
                'symptoms'       => 'Pain, swelling, fatigue, healing concerns.',
                'causes'         => 'Normal recovery process or complications if symptoms escalate.',
                'treatment_info' => 'Follow physician instructions and monitor symptoms.',
                'tips'           => 'Use reminders and notify support if pain or fever increases.',
            ],
        ] as $disease) {
            Disease::updateOrCreate(['slug' => $disease['slug']], $disease);
        }

        UserNotification::updateOrCreate(
            ['user_id' => $doctorUser->id, 'title' => 'High-risk daily status'],
            [
                'type'     => 'daily_status_risk',
                'message'  => 'A patient submitted a moderate-risk daily status.',
                'severity' => 'warning',
                'is_read'  => false,
            ]
        );

        UserNotification::updateOrCreate(
            ['user_id' => $caregiverUser->id, 'title' => 'Medication missed'],
            [
                'type'     => 'medication_missed',
                'message'  => 'A patient missed a medication dose.',
                'severity' => 'warning',
                'is_read'  => false,
            ]
        );

        UserNotification::updateOrCreate(
            ['user_id' => $patientUser->id, 'title' => 'Appointment confirmed'],
            [
                'type'     => 'appointment_update',
                'message'  => 'Your upcoming appointment is confirmed.',
                'severity' => 'success',
                'is_read'  => false,
            ]
        );
    }
}