# Riva API Endpoints

Base URL: `http://127.0.0.1:8000/api`

All authenticated endpoints expect:

- `Authorization: Bearer {token}`
- `Accept: application/json`

## Auth

### `POST /auth/register`
- Auth required: No
- Roles: Public
- Request example:
```json
{
  "first_name": "Amina",
  "last_name": "Hassan",
  "email": "patient@riva.test",
  "phone": "01000000002",
  "password": "password",
  "password_confirmation": "password",
  "role": "patient"
}
```
- Response example:
```json
{
  "message": "Registration successful",
  "user": {
    "id": 2,
    "role": "patient"
  },
  "token": "..."
}
```
- Frontend: `SignupComponent`

### `POST /auth/login`
- Auth required: No
- Roles: Public
- Request example:
```json
{
  "email": "doctor@riva.test",
  "password": "password"
}
```
- Response example:
```json
{
  "message": "Login successful",
  "user": {
    "id": 3,
    "role": "doctor"
  },
  "token": "..."
}
```
- Frontend: `SigninComponent`

### `GET /me`
- Auth required: Yes
- Roles: All authenticated users
- Response example:
```json
{
  "user": {
    "id": 2,
    "name": "Amina Hassan",
    "role": "patient"
  }
}
```
- Frontend: `AuthService`, `ChatComponent`

### `POST /auth/logout`
- Auth required: Yes
- Roles: All authenticated users
- Request body: none
- Response example:
```json
{
  "success": true,
  "message": "Logout successful",
  "data": null
}
```
- Frontend: reserved for logout flow

## Profile

### `GET /profile`
- Auth required: Yes
- Roles: All authenticated users
- Request body: none
- Response example:
```json
{
  "user": {
    "id": 3,
    "first_name": "Omar",
    "last_name": "Mahmoud",
    "role": "doctor"
  },
  "role": "doctor",
  "role_profile": {
    "specialty": "Internal Medicine",
    "fee": "450.00"
  }
}
```
- Frontend: `ProfileComponent`, `DashboardComponent`

### `POST /profile/complete`
- Auth required: Yes
- Roles: Patient, Doctor, Caregiver
- Request example:
```json
{
  "gender": "female",
  "age": 67,
  "address": "Alexandria, Egypt",
  "chronic_conditions": "diabetes, hypertension",
  "medical_history": "diabetes, hypertension"
}
```
- Response example:
```json
{
  "success": true,
  "message": "Profile updated successfully",
  "data": {
    "user": {
      "id": 2
    },
    "role_profile": {
      "user_id": 2
    }
  }
}
```
- Frontend: `Signup2Component`

### `PUT /profile`
- Auth required: Yes
- Roles: All authenticated users
- Request example:
```json
{
  "first_name": "Amina",
  "last_name": "Hassan",
  "phone": "01000000002",
  "about": "Feeling stable this week"
}
```
- Response example:
```json
{
  "success": true,
  "message": "Profile updated successfully",
  "data": {
    "user": {
      "id": 2
    },
    "role_profile": {
      "user_id": 2
    }
  }
}
```
- Frontend: `ProfileComponent`

## Doctors

### `GET /doctors`
- Auth required: No
- Roles: Public
- Request body: none
- Response example:
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "name": "Omar Mahmoud",
      "specialty": "Internal Medicine",
      "fee": "450.00",
      "rating": 5
    }
  ]
}
```
- Frontend: `DoctorCardsComponent`

### `GET /doctors/{doctor}`
- Auth required: No
- Roles: Public
- Request body: none
- Response example:
```json
{
  "success": true,
  "message": "Success",
  "data": {
    "id": 1,
    "name": "Omar Mahmoud",
    "specialty": "Internal Medicine",
    "about": "Experienced in elderly and chronic disease care.",
    "follow_status": "active"
  }
}
```
- Frontend: `ProfileDComponent`, `BookingComponent`

### `GET /doctors/{doctor}/reviews`
- Auth required: No
- Roles: Public
- Request body: none
- Response example:
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "rating": 5,
      "comment": "Very supportive and clear follow-up plan.",
      "patient_name": "Amina Hassan"
    }
  ]
}
```
- Frontend: `ProfileDComponent`

### `POST /doctors/{doctor}/reviews`
- Auth required: Yes
- Roles: Patient with active relationship
- Request example:
```json
{
  "rating": 5,
  "comment": "Very supportive and clear follow-up plan."
}
```
- Response example:
```json
{
  "success": true,
  "message": "Review submitted successfully",
  "data": {
    "id": 1
  }
}
```
- Frontend: `ProfileDComponent`

### `GET /doctors/{doctor}/availability`
- Auth required: No
- Roles: Public
- Request body: none
- Response example:
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "day_of_week": "Monday",
      "start_time": "09:00:00",
      "end_time": "13:00:00"
    }
  ]
}
```
- Frontend: `ProfileDComponent`

## Follow Relationships

### `POST /doctors/{doctor}/follow-request`
- Auth required: Yes
- Roles: Patient
- Request example:
```json
{
  "payment_method": "card",
  "payment_mode": "demo",
  "billing_email": "patient@riva.test",
  "billing_address": "Alexandria",
  "appointment_date": "2026-04-28",
  "appointment_time": "11:00",
  "type": "online",
  "amount": 450
}
```
- Response example:
```json
{
  "success": true,
  "message": "Follow request sent successfully",
  "data": {
    "id": 2,
    "status": "pending"
  }
}
```
- Frontend: `DoctorsFollowRequestComponent`

### `GET /doctor/follow-requests`
- Auth required: Yes
- Roles: Doctor
- Request body: none
- Response example:
```json
{
  "success": true,
  "data": [
    {
      "id": 2,
      "status": "pending",
      "patient": {
        "user": {
          "name": "Amina Hassan"
        }
      }
    }
  ]
}
```
- Frontend: `DashboardComponent`

### `POST /doctor/follow-requests/{id}/accept`
- Auth required: Yes
- Roles: Doctor
- Request body: none
- Response example:
```json
{
  "success": true,
  "message": "Follow request accepted",
  "data": {
    "id": 2,
    "status": "active"
  }
}
```
- Frontend: `DashboardComponent`

### `POST /doctor/follow-requests/{id}/reject`
- Auth required: Yes
- Roles: Doctor
- Request body: none
- Response example:
```json
{
  "success": true,
  "message": "Follow request rejected",
  "data": {
    "id": 2,
    "status": "rejected"
  }
}
```
- Frontend: `DashboardComponent`

### `GET /patient/my-doctors`
- Auth required: Yes
- Roles: Patient
- Request body: none
- Response example:
```json
{
  "success": true,
  "data": [
    {
      "doctor_id": 1,
      "status": "active",
      "doctor": {
        "user": {
          "name": "Omar Mahmoud"
        }
      }
    }
  ]
}
```
- Frontend: `ChatComponent`

## Medications

### `GET /medications`
- Auth required: Yes
- Roles: Patient
- Request body: none
- Response example:
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Metformin",
      "dosage": "500mg",
      "schedule_time": "09:00:00"
    }
  ]
}
```
- Frontend: `AddNewMedicationComponent`

### `POST /medications`
- Auth required: Yes
- Roles: Patient
- Request example:
```json
{
  "drug_name": "Metformin",
  "dosage": "500mg",
  "schedule_time": "09:00",
  "frequency": "daily",
  "notes": "Take after breakfast"
}
```
- Response example:
```json
{
  "success": true,
  "message": "Medication created successfully",
  "data": {
    "id": 3,
    "name": "Metformin"
  }
}
```
- Frontend: `AddNewMedicationComponent`

### `PUT /medications/{medication}`
- Auth required: Yes
- Roles: Patient
- Request example:
```json
{
  "dosage": "750mg"
}
```
- Response example:
```json
{
  "success": true,
  "message": "Medication updated successfully",
  "data": {
    "id": 1
  }
}
```
- Frontend: reserved for edit flow

### `DELETE /medications/{medication}`
- Auth required: Yes
- Roles: Patient
- Request body: none
- Response example:
```json
{
  "success": true,
  "message": "Medication deleted successfully",
  "data": null
}
```
- Frontend: `AddNewMedicationComponent`

### `POST /medications/{medication}/take`
- Auth required: Yes
- Roles: Patient
- Request body: none
- Response example:
```json
{
  "success": true,
  "message": "Medication status updated successfully",
  "data": {
    "status": "taken"
  }
}
```
- Frontend: `DashboardPComponent`

### `POST /medications/{medication}/snooze`
- Auth required: Yes
- Roles: Patient
- Request body: none
- Response example:
```json
{
  "success": true,
  "message": "Medication status updated successfully",
  "data": {
    "status": "snoozed"
  }
}
```
- Frontend: `DashboardPComponent`

### `POST /medications/{medication}/missed`
- Auth required: Yes
- Roles: Patient
- Request body: none
- Response example:
```json
{
  "success": true,
  "message": "Medication status updated successfully",
  "data": {
    "status": "missed"
  }
}
```
- Frontend: future medication flow

### `POST /medications/{medication}/need-help`
- Auth required: Yes
- Roles: Patient
- Request body: none
- Response example:
```json
{
  "success": true,
  "message": "Medication status updated successfully",
  "data": {
    "status": "need_help"
  }
}
```
- Frontend: future medication flow

### `GET /medications/adherence/summary`
- Auth required: Yes
- Roles: Patient
- Request body: none
- Response example:
```json
{
  "success": true,
  "data": {
    "adherence_percentage": 50
  }
}
```
- Frontend: `DashboardPComponent`

## Daily Status

### `POST /daily-status`
- Auth required: Yes
- Roles: Patient
- Request example:
```json
{
  "mood": "low",
  "pain_level": 7,
  "sleep_quality": "poor",
  "symptoms": "dizziness",
  "notes": "Needs attention",
  "medication_taken": false
}
```
- Response example:
```json
{
  "success": true,
  "message": "Daily status saved successfully",
  "data": {
    "status": {
      "id": 2
    },
    "risk_level": "moderate_risk"
  }
}
```
- Frontend: `DashboardPComponent`

### `GET /daily-status/latest`
- Auth required: Yes
- Roles: Patient
- Request body: none
- Response example:
```json
{
  "success": true,
  "data": {
    "risk_level": "moderate_risk"
  }
}
```
- Frontend: future patient dashboard reads

## Dashboards

### `GET /dashboard/patient`
- Auth required: Yes
- Roles: Patient
- Request body: none
- Response example:
```json
{
  "success": true,
  "data": {
    "risk_level": "moderate_risk",
    "medications_today": [],
    "adherence_percentage": 50,
    "appointments": []
  }
}
```
- Frontend: `DashboardPComponent`

### `GET /dashboard/doctor`
- Auth required: Yes
- Roles: Doctor
- Request body: none
- Response example:
```json
{
  "success": true,
  "data": {
    "total_assigned_patients": 1,
    "high_risk_patients": 0,
    "pending_follow_requests": 0,
    "reports_count": 1,
    "average_adherence": 50
  }
}
```
- Frontend: `DashboardComponent`

### `GET /dashboard/caregiver`
- Auth required: Yes
- Roles: Caregiver
- Request body: none
- Response example:
```json
{
  "success": true,
  "data": {
    "assigned_patients": [],
    "missed_medications_today": 1,
    "attention_needed_cases": 1
  }
}
```
- Frontend: `DashboardCaregiverComponent`

### `GET /admin/dashboard`
- Auth required: Yes
- Roles: Admin
- Request body: none
- Response example:
```json
{
  "success": true,
  "data": {
    "total_users": 4,
    "total_patients": 1,
    "total_doctors": 1,
    "total_caregivers": 1
  }
}
```
- Frontend: `DashboardAdminComponent`

## Chat

### `GET /chat/contacts`
- Auth required: Yes
- Roles: Patient, Doctor, Caregiver, Admin
- Request body: none
- Response example:
```json
{
  "success": true,
  "data": [
    {
      "id": 3,
      "name": "Omar Mahmoud",
      "avatar": "https://ui-avatars.com/...",
      "status": "online"
    }
  ]
}
```
- Frontend: `ChatComponent`

### `GET /messages/{user}`
- Auth required: Yes
- Roles: Patient, Doctor, Caregiver, Admin
- Request body: none
- Response example:
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "sender_id": 2,
      "receiver_id": 3,
      "body": "Hello doctor"
    }
  ]
}
```
- Frontend: `ChatComponent`

### `POST /messages`
- Auth required: Yes
- Roles: Patient, Doctor, Caregiver, Admin
- Request example:
```json
{
  "receiver_id": 3,
  "body": "Hello doctor"
}
```
- Response example:
```json
{
  "success": true,
  "message": "Message sent successfully",
  "data": {
    "id": 2,
    "body": "Hello doctor"
  }
}
```
- Frontend: `ChatComponent`

## Appointments and Payments

### `POST /appointments`
- Auth required: Yes
- Roles: Patient
- Request example:
```json
{
  "doctor_id": 1,
  "appointment_date": "2026-04-28",
  "appointment_time": "11:00",
  "type": "online",
  "amount": 450,
  "notes": "Routine follow-up"
}
```
- Response example:
```json
{
  "success": true,
  "message": "Appointment created successfully",
  "data": {
    "id": 1,
    "status": "pending"
  }
}
```
- Frontend: booking/follow flow

### `POST /payments/{appointment}/pay`
- Auth required: Yes
- Roles: Patient, Doctor, Admin
- Request body: none
- Response example:
```json
{
  "success": true,
  "message": "Payment processed successfully",
  "data": {
    "payment_status": "paid",
    "status": "confirmed"
  }
}
```
- Frontend: future payment flow

## Notifications

### `GET /notifications`
- Auth required: Yes
- Roles: All authenticated users
- Request body: none
- Response example:
```json
{
  "success": true,
  "data": {
    "items": [],
    "unread_count": 0
  }
}
```
- Frontend: notification surfaces, future unread badge

### `POST /notifications/{id}/read`
- Auth required: Yes
- Roles: Notification owner
- Request body: none
- Response example:
```json
{
  "success": true,
  "message": "Notification marked as read",
  "data": {
    "id": 1,
    "is_read": true
  }
}
```
- Frontend: notification surfaces

### `POST /notifications/read-all`
- Auth required: Yes
- Roles: All authenticated users
- Request body: none
- Response example:
```json
{
  "success": true,
  "message": "All notifications marked as read",
  "data": null
}
```
- Frontend: notification surfaces

## Disease Awareness

### `GET /diseases`
- Auth required: No
- Roles: Public
- Request body: none
- Response example:
```json
{
  "success": true,
  "data": [
    {
      "title": "Diabetes",
      "slug": "diabetes"
    }
  ]
}
```
- Frontend: disease awareness pages

### `GET /diseases/{slug}`
- Auth required: No
- Roles: Public
- Request body: none
- Response example:
```json
{
  "success": true,
  "data": {
    "title": "Diabetes",
    "overview": "Managing blood sugar through medication, diet, and monitoring."
  }
}
```
- Frontend: disease details page

### `POST /admin/diseases`
- Auth required: Yes
- Roles: Admin
- Request example:
```json
{
  "title": "Diabetes",
  "slug": "diabetes",
  "overview": "Awareness content"
}
```
- Response example:
```json
{
  "success": true,
  "message": "Disease content created successfully",
  "data": {
    "id": 1
  }
}
```
- Frontend: admin content management

## Reports and Diagnoses

### `GET /reports`
- Auth required: Yes
- Roles: Patient, Doctor, Admin
- Request body: none
- Response example:
```json
{
  "success": true,
  "data": [
    {
      "title": "Monthly Follow-up Report"
    }
  ]
}
```
- Frontend: doctor/admin reports pages

### `POST /reports`
- Auth required: Yes
- Roles: Doctor
- Request example:
```json
{
  "patient_id": 1,
  "title": "Monthly Follow-up Report",
  "summary": "Patient blood pressure improved."
}
```
- Response example:
```json
{
  "success": true,
  "message": "Report created successfully",
  "data": {
    "id": 1
  }
}
```
- Frontend: doctor reporting flow

### `POST /diagnoses`
- Auth required: Yes
- Roles: Doctor
- Request example:
```json
{
  "patient_id": 1,
  "title": "Chronic hypertension management",
  "details": "Continue medication adherence and weekly monitoring."
}
```
- Response example:
```json
{
  "success": true,
  "message": "Diagnosis created successfully",
  "data": {
    "id": 1
  }
}
```
- Frontend: doctor diagnosis flow

## Hospitals

### `GET /hospitals`
- Auth required: No
- Roles: Public
- Request body: none
- Response example:
```json
{
  "success": true,
  "data": []
}
```
- Frontend: reserved if hospital selection is enabled

### `POST /admin/doctor-hospitals`
- Auth required: Yes
- Roles: Admin
- Request example:
```json
{
  "doctor_id": 1,
  "hospital_id": 1
}
```
- Response example:
```json
{
  "success": true,
  "message": "Doctor hospital assigned successfully",
  "data": []
}
```
- Frontend: admin hospital assignment

## Admin Management

### `GET /admin/users`
- Auth required: Yes
- Roles: Admin
- Request body: none
- Response example:
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "email": "admin@riva.test"
    }
  ]
}
```
- Frontend: admin user management

### `PATCH /admin/users/{user}/status`
- Auth required: Yes
- Roles: Admin
- Request example:
```json
{
  "is_active": false
}
```
- Response example:
```json
{
  "success": true,
  "message": "User status updated successfully",
  "data": {
    "id": 2,
    "is_active": false
  }
}
```
- Frontend: admin user management

### `PATCH /admin/doctors/{doctor}/verify`
- Auth required: Yes
- Roles: Admin
- Request example:
```json
{
  "verification_status": "verified"
}
```
- Response example:
```json
{
  "success": true,
  "message": "Doctor verification updated successfully",
  "data": {
    "id": 1,
    "verification_status": "verified"
  }
}
```
- Frontend: admin doctor verification
