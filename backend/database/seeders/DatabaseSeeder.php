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
use App\Models\Message;
use App\Models\Patient;
use App\Models\PatientCaregiverRelationship;
use App\Models\PatientDoctorRelationship;
use App\Models\Report;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * SECURITY (Phase 0): this seeder previously hardcoded real personal Gmail
     * addresses belonging to actual people (including the project owner) and a
     * single shared, trivial password ("12345678") for every seeded account,
     * including the admin account. Fixed by: (1) refusing to run in production
     * at all, so this can never create known-password accounts on a live
     * database; (2) using synthetic @riva.demo addresses instead of real
     * people's emails; (3) generating a random password per run and printing
     * it once to the console instead of a fixed, guessable value.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'DatabaseSeeder is demo/local seed data only and must never run against a production environment.'
            );
        }

        $plainPassword = Str::password(16);
        $password = Hash::make($plainPassword);
        $this->command?->warn("Demo accounts seeded. Password for every seeded account: {$plainPassword}");
        $this->command?->warn('This password is generated fresh each run and is not stored anywhere else — save it now if you need it.');

        $usersData = [
            ['email' => 'admin@riva.demo', 'first_name' => 'Ibrahim', 'last_name' => 'Sayed', 'role' => 'admin', 'phone' => '01010000001', 'address' => 'Cairo, Egypt'],
            ['email' => 'doctor.internal-medicine@riva.demo', 'first_name' => 'Emad', 'last_name' => 'Sama', 'role' => 'doctor', 'phone' => '01010000002', 'address' => 'Giza, Egypt'],
            ['email' => 'doctor.cardiology@riva.demo', 'first_name' => 'Mohammed', 'last_name' => 'Saeed', 'role' => 'doctor', 'phone' => '01010000003', 'address' => 'Mansoura, Egypt'],
            ['email' => 'doctor.dermatology@riva.demo', 'first_name' => 'Nour', 'last_name' => 'Zeid', 'role' => 'doctor', 'phone' => '01010000004', 'address' => 'Alexandria, Egypt'],
            ['email' => 'caregiver.mostafa@riva.demo', 'first_name' => 'mostafa', 'last_name' => 'kmal', 'role' => 'caregiver', 'phone' => '01010000004', 'address' => 'Alexandria, Egypt'],
            ['email' => 'caregiver.mariam@riva.demo', 'first_name' => 'Mariam', 'last_name' => 'Osama', 'role' => 'caregiver', 'phone' => '01010000005', 'address' => 'Cairo, Egypt'],
            ['email' => 'caregiver.mahmoud@riva.demo', 'first_name' => 'Mahmoud', 'last_name' => 'Feraawn', 'role' => 'caregiver', 'phone' => '01010000006', 'address' => 'Tanta, Egypt'],
            ['email' => 'patient.habiba@riva.demo', 'first_name' => 'Habiba', 'last_name' => 'Sameh', 'role' => 'patient', 'phone' => '01010000007', 'address' => 'Banha, Egypt'],
            ['email' => 'patient.roro@riva.demo', 'first_name' => 'Roro', 'last_name' => 'Farouk', 'role' => 'patient', 'phone' => '01010000008', 'address' => 'Cairo, Egypt'],
            ['email' => 'patient.omar@riva.demo', 'first_name' => 'Omar', 'last_name' => 'Ramadan', 'role' => 'patient', 'phone' => '01010000009', 'address' => 'Sheblanga, Egypt'],
            ['email' => 'patient.mohamed@riva.demo', 'first_name' => 'Mohamed', 'last_name' => 'Abdelaleem', 'role' => 'patient', 'phone' => '01010000010', 'address' => 'Giza, Egypt'],
        ];

        $users = [];

        foreach ($usersData as $data) {
            $users[$data['email']] = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'name' => $data['first_name'].' '.$data['last_name'],
                    'phone' => $data['phone'],
                    'address' => $data['address'],
                    'role' => $data['role'],
                    'password' => $password,
                    'is_active' => true,
                ]
            );
        }

        $doctor1 = Doctor::updateOrCreate(
            ['user_id' => $users['doctor.internal-medicine@riva.demo']->id],
            [
                'specialty' => 'Internal Medicine',
                'years_of_experience' => 10,
                'fee' => 400,
                'bio' => 'Specialist in chronic disease follow-up and elderly care.',
                'about' => 'Focused on diabetes, hypertension, and long-term monitoring.',
                'license_number' => 'DOC-RIVA-001',
                'verification_status' => 'verified',
                'is_active' => true,
                'contact_info' => 'doctor.internal-medicine@riva.demo',
                'available_days' => 'Monday,Wednesday,Saturday',
            ]
        );

        $doctor2 = Doctor::updateOrCreate(
            ['user_id' => $users['doctor.cardiology@riva.demo']->id],
            [
                'specialty' => 'Cardiology',
                'years_of_experience' => 8,
                'fee' => 500,
                'bio' => 'Cardiology doctor supporting heart patients and blood pressure monitoring.',
                'about' => 'Experienced in cardiac follow-up and risk assessment.',
                'license_number' => 'DOC-RIVA-002',
                'verification_status' => 'verified',
                'is_active' => true,
                'contact_info' => 'doctor.cardiology@riva.demo',
                'available_days' => 'Tuesday,Thursday,Sunday',
            ]
        );

        $doctor3 = Doctor::updateOrCreate(
            ['user_id' => $users['doctor.dermatology@riva.demo']->id],
            [
                'specialty' => 'Dermatology',
                'years_of_experience' => 6,
                'fee' => 350,
                'bio' => 'Dermatology specialist for skin follow-up and patient reports.',
                'about' => 'Supports patients with skin symptoms and treatment follow-up.',
                'license_number' => 'DOC-RIVA-003',
                'verification_status' => 'pending',
                'is_active' => true,
                'contact_info' => 'doctor.dermatology@riva.demo',
                'available_days' => 'Saturday,Sunday',
            ]
        );

        $caregiver1 = Caregiver::updateOrCreate(
            ['user_id' => $users['caregiver.mariam@riva.demo']->id],
            [
                'specialty' => 'Elderly Support',
                'experience_years' => 5,
                'salary' => 6000,
                'verification_status' => 'verified',
                'is_active' => true,
                'about' => 'Caregiver specialized in medication reminders and daily reports.',
            ]
        );

        $caregiver2 = Caregiver::updateOrCreate(
            ['user_id' => $users['caregiver.mahmoud@riva.demo']->id],
            [
                'specialty' => 'Chronic Care Support',
                'experience_years' => 4,
                'salary' => 5500,
                'verification_status' => 'verified',
                'is_active' => true,
                'about' => 'Supports patients with chronic diseases and emergency alerts.',
            ]
        );

        $patients = [];

        $patients['Habiba'] = Patient::updateOrCreate(
            ['user_id' => $users['patient.habiba@riva.demo']->id],
            [
                'age' => 58,
                'gender' => 'female',
                'blood_type' => 'O+',
                'emergency_contact' => '01234567890',
                'chronic_conditions' => 'diabetes, hypertension',
                'medical_history' => 'Type 2 diabetes and high blood pressure.',
            ]
        );

        $patients['Roro'] = Patient::updateOrCreate(
            ['user_id' => $users['patient.roro@riva.demo']->id],
            [
                'age' => 45,
                'gender' => 'female',
                'blood_type' => 'A+',
                'emergency_contact' => '01234567891',
                'chronic_conditions' => 'heart disease',
                'medical_history' => 'Heart disease follow-up and medication adherence.',
            ]
        );

        $patients['Omar'] = Patient::updateOrCreate(
            ['user_id' => $users['patient.omar@riva.demo']->id],
            [
                'age' => 21,
                'gender' => 'male',
                'blood_type' => 'B+',
                'emergency_contact' => '01012077945',
                'chronic_conditions' => 'diabetes, hypertension, cancer',
                'medical_history' => 'Diabetes, hypertension, and cancer monitoring.',
            ]
        );

        $patients['Mohamed'] = Patient::updateOrCreate(
            ['user_id' => $users['patient.mohamed@riva.demo']->id],
            [
                'age' => 63,
                'gender' => 'male',
                'blood_type' => 'AB+',
                'emergency_contact' => '01234567892',
                'chronic_conditions' => 'post-surgery recovery',
                'medical_history' => 'Post-surgery recovery and daily pain monitoring.',
            ]
        );

        $relations = [
            [$patients['Habiba'], $doctor1, $caregiver1],
            [$patients['Roro'], $doctor2, $caregiver2],
            [$patients['Omar'], $doctor1, $caregiver1],
            [$patients['Mohamed'], $doctor2, $caregiver2],
        ];

        foreach ($relations as [$patient, $doctor, $caregiver]) {
            PatientDoctorRelationship::updateOrCreate(
                ['patient_id' => $patient->id, 'doctor_id' => $doctor->id],
                [
                    'status' => 'active',
                    'requested_at' => now()->subDays(5),
                    'responded_at' => now()->subDays(4),
                ]
            );

            PatientCaregiverRelationship::updateOrCreate(
                ['patient_id' => $patient->id, 'caregiver_id' => $caregiver->id],
                [
                    'status' => 'active',
                    'requested_at' => now()->subDays(4),
                    'responded_at' => now()->subDays(3),
                ]
            );
        }

        $medicationsData = [
            [$patients['Habiba'], 'Metformin', '500mg', '09:00', 'daily', 'Take after breakfast'],
            [$patients['Habiba'], 'Amlodipine', '5mg', '20:00', 'daily', 'Take after dinner'],
            [$patients['Roro'], 'Aspirin', '75mg', '10:00', 'daily', 'Take after meal'],
            [$patients['Roro'], 'Atorvastatin', '20mg', '22:00', 'daily', 'Take before sleep'],
            [$patients['Omar'], 'Insulin', '10 units', '08:00', 'daily', 'Before breakfast'],
            [$patients['Omar'], 'Cancer Support Medication', '1 tablet', '18:00', 'daily', 'As prescribed'],
            [$patients['Mohamed'], 'Pain Relief', '500mg', '12:00', 'daily', 'Only if pain is present'],
            [$patients['Mohamed'], 'Antibiotic', '250mg', '21:00', 'daily', 'Complete the course'],
        ];

        $medications = [];

        foreach ($medicationsData as [$patient, $name, $dosage, $time, $frequency, $instructions]) {
            $medications[] = Medication::updateOrCreate(
                ['patient_id' => $patient->id, 'name' => $name],
                [
                    'dosage' => $dosage,
                    'schedule_time' => $time,
                    'frequency' => $frequency,
                    'instructions' => $instructions,
                    'is_active' => true,
                ]
            );
        }

        foreach ($medications as $index => $medication) {
            MedicationLog::updateOrCreate(
                [
                    'medication_id' => $medication->id,
                    'patient_id' => $medication->patient_id,
                    'scheduled_at' => now()->subHours($index + 1)->format('Y-m-d H:i:s'),
                ],
                [
                    'taken_at' => $index % 2 === 0 ? now()->subHours($index)->format('Y-m-d H:i:s') : null,
                    'status' => $index % 2 === 0 ? 'taken' : 'missed',
                    'confirmed_by' => Patient::find($medication->patient_id)?->user_id,
                ]
            );
        }

        foreach ($patients as $name => $patient) {
            DailyStatus::updateOrCreate(
                ['patient_id' => $patient->id, 'created_at' => now()->subDay()->format('Y-m-d H:i:s')],
                [
                    'mood' => 'good',
                    'pain_level' => 3,
                    'sleep_quality' => 'good',
                    'symptoms' => 'stable',
                    'notes' => 'Patient is stable today.',
                    'medication_taken' => true,
                    'risk_level' => 'stable',
                    'updated_at' => now()->subDay()->format('Y-m-d H:i:s'),
                ]
            );

            DailyStatus::updateOrCreate(
                ['patient_id' => $patient->id, 'created_at' => now()->format('Y-m-d H:i:s')],
                [
                    'mood' => $name === 'Omar' ? 'tired' : 'normal',
                    'pain_level' => $name === 'Omar' ? 7 : 4,
                    'sleep_quality' => $name === 'Omar' ? 'poor' : 'fair',
                    'symptoms' => $name === 'Omar' ? 'dizziness and fatigue' : 'mild symptoms',
                    'notes' => $name === 'Omar' ? 'Needs doctor attention.' : 'Follow-up recommended.',
                    'medication_taken' => $name !== 'Omar',
                    'risk_level' => $name === 'Omar' ? 'moderate_risk' : 'attention_needed',
                    'updated_at' => now()->format('Y-m-d H:i:s'),
                ]
            );
        }

        foreach ([$doctor1, $doctor2, $doctor3] as $doctor) {
            foreach (['Monday', 'Wednesday', 'Saturday'] as $day) {
                DoctorAvailability::updateOrCreate(
                    ['doctor_id' => $doctor->id, 'day_of_week' => $day],
                    ['start_time' => '09:00', 'end_time' => '15:00', 'is_available' => true]
                );
            }
        }

        foreach ($relations as [$patient, $doctor]) {
            DoctorReview::updateOrCreate(
                ['doctor_id' => $doctor->id, 'patient_id' => $patient->id],
                ['rating' => 5, 'comment' => 'Excellent follow-up and clear medical guidance.']
            );

            Appointment::updateOrCreate(
                [
                    'patient_id' => $patient->id,
                    'doctor_id' => $doctor->id,
                    'appointment_date' => now()->addDays($patient->id % 5 + 1)->format('Y-m-d'),
                ],
                [
                    'appointment_time' => '11:00',
                    'type' => 'online',
                    'amount' => $doctor->fee ?? 400,
                    'payment_status' => 'paid',
                    'status' => 'confirmed',
                    'notes' => 'Routine follow-up appointment.',
                ]
            );

            Report::updateOrCreate(
                [
                    'patient_id' => $patient->id,
                    'doctor_id' => $doctor->id,
                    'title' => 'Medical Follow-up Report',
                ],
                [
                    'summary' => 'Patient requires continuous monitoring and medication adherence.',
                    'final_report' => 'Continue current plan and follow up weekly.',
                ]
            );

            Diagnosis::updateOrCreate(
                [
                    'patient_id' => $patient->id,
                    'doctor_id' => $doctor->id,
                    'title' => 'Chronic condition follow-up',
                ],
                ['details' => 'Monitor symptoms, medication adherence, and daily status reports.']
            );
        }

        $chatPairs = [
            [$users['patient.habiba@riva.demo'], $users['doctor.internal-medicine@riva.demo']],
            [$users['patient.omar@riva.demo'], $users['doctor.internal-medicine@riva.demo']],
            [$users['patient.roro@riva.demo'], $users['doctor.cardiology@riva.demo']],
            [$users['patient.mohamed@riva.demo'], $users['doctor.cardiology@riva.demo']],
            [$users['patient.omar@riva.demo'], $users['caregiver.mariam@riva.demo']],
        ];

        foreach ($chatPairs as [$sender, $receiver]) {
            Message::updateOrCreate(
                [
                    'sender_id' => $sender->id,
                    'receiver_id' => $receiver->id,
                    'body' => 'Hello, I need follow-up about my health status.',
                ],
                ['is_read' => false]
            );

            Message::updateOrCreate(
                [
                    'sender_id' => $receiver->id,
                    'receiver_id' => $sender->id,
                    'body' => 'Hello, I reviewed your data. Please keep tracking your medication and daily reports.',
                ],
                ['is_read' => false]
            );
        }

        foreach ([
            [
                'title' => 'Cancer',
                'slug' => 'cancer',
                'overview' => 'General awareness content about cancer and when to seek specialist care.',
                'symptoms' => 'Unexplained weight loss, fatigue, pain.',
                'causes' => 'Varies by type and risk factors.',
                'treatment_info' => 'Depends on diagnosis and physician guidance.',
                'tips' => 'Keep screening appointments and report new symptoms early.',
            ],
            [
                'title' => 'Diabetes',
                'slug' => 'diabetes',
                'overview' => 'Managing blood sugar through medication, diet, and monitoring.',
                'symptoms' => 'Excess thirst, fatigue, blurred vision.',
                'causes' => 'Insulin resistance or insulin deficiency.',
                'treatment_info' => 'Medication adherence, nutrition, exercise, check-ups.',
                'tips' => 'Track glucose and keep medication schedules consistent.',
            ],
            [
                'title' => 'Hypertension',
                'slug' => 'hypertension',
                'overview' => 'High blood pressure requires steady monitoring and adherence.',
                'symptoms' => 'Often silent, but may include headaches or dizziness.',
                'causes' => 'Lifestyle, genetics, chronic conditions.',
                'treatment_info' => 'Medication, sodium control, activity, monitoring.',
                'tips' => 'Check blood pressure regularly and avoid missed doses.',
            ],
            [
                'title' => 'Heart Disease',
                'slug' => 'heart-disease',
                'overview' => 'Heart disease needs regular physician follow-up and symptom awareness.',
                'symptoms' => 'Chest discomfort, shortness of breath, fatigue.',
                'causes' => 'Plaque buildup, hypertension, other cardiovascular risks.',
                'treatment_info' => 'Lifestyle support, prescribed medications, timely review.',
                'tips' => 'Report warning signs early and keep scheduled reviews.',
            ],
            [
                'title' => 'Post-Surgery Recovery',
                'slug' => 'post-surgery-recovery',
                'overview' => 'Recovery planning includes adherence, wound awareness, and follow-up.',
                'symptoms' => 'Pain, swelling, fatigue, healing concerns.',
                'causes' => 'Normal recovery process or complications if symptoms escalate.',
                'treatment_info' => 'Follow physician instructions and monitor symptoms.',
                'tips' => 'Use reminders and notify support if pain or fever increases.',
            ],
        ] as $disease) {
            Disease::updateOrCreate(['slug' => $disease['slug']], $disease);
        }

        UserNotification::updateOrCreate(
            ['user_id' => $users['doctor.internal-medicine@riva.demo']->id, 'title' => 'High-risk daily status'],
            [
                'type' => 'daily_status_risk',
                'message' => 'One of your patients submitted a moderate-risk daily status.',
                'severity' => 'warning',
                'is_read' => false,
            ]
        );

        UserNotification::updateOrCreate(
            ['user_id' => $users['caregiver.mariam@riva.demo']->id, 'title' => 'Medication missed'],
            [
                'type' => 'medication_missed',
                'message' => 'A linked patient missed a medication dose.',
                'severity' => 'warning',
                'is_read' => false,
            ]
        );

        UserNotification::updateOrCreate(
            ['user_id' => $users['patient.omar@riva.demo']->id, 'title' => 'Appointment confirmed'],
            [
                'type' => 'appointment_update',
                'message' => 'Your upcoming appointment is confirmed.',
                'severity' => 'success',
                'is_read' => false,
            ]
        );
    }
}
