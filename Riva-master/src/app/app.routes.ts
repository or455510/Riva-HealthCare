import { NgModule } from '@angular/core';
import { RouterModule, Routes, RouteReuseStrategy, DetachedRouteHandle, ActivatedRouteSnapshot } from '@angular/router';

import { SigninComponent } from './core/components/auth/signin/signin.component';
import { ForgetPasswordComponent } from './core/components/auth/forget-password/forget-password.component';
import { NewPasswordComponent } from './core/components/auth/new-password/new-password.component';
import { ResetCodeComponent } from './core/components/auth/reset-code/reset-code.component';
import { GoogleCallbackComponent } from './core/components/auth/google-callback/google-callback.component';
import { SignupComponent } from './core/components/auth/signup/signup.component';
import { Signup2Component } from './core/components/auth/signup2/signup2.component';

import { HomeComponent } from './features/components/home/home.component';
import { ContactComponent } from './features/components/contact/contact.component';
import { Welcome1Component } from './core/components/auth/welcome1/welcome1.component';
import { DashboardPComponent } from './core/components/auth/dashboard-p/dashboard-p.component';
import { DashboardComponent } from './core/components/auth/dashboard/dashboard/dashboard.component';
import { DashboardCaregiverComponent } from './core/components/auth/dashboard-caregiver/dashboard-caregiver.component';

import { AddNewMedicationComponent } from './core/components/auth/add-new-medication/add-new-medication.component';
import { DoctorCardsComponent } from './core/components/auth/doctor-cards/doctor-cards.component';
import { PatientCardsComponent } from './core/components/auth/patient-cards/patient-cards.component';
import { CaregiverCardsComponent } from './core/components/auth/caregiver-cards/caregiver-cards.component';

import { ChatComponent } from './core/components/auth/chat/chat.component';
import { ChatCComponent } from './core/components/auth/chat-c/chat-c.component';

import { ProfileComponent } from './core/components/auth/profile/profile.component';
import { ProfileDComponent } from './core/components/auth/profile-d/profile-d.component';
import { ProfileCComponent } from './core/components/auth/profile-c/profile-c.component';
import { ProfilePComponent } from './core/components/auth/profile-p/profile-p.component';

import { BookingComponent } from './core/components/auth/booking/booking.component';
import { DoctorsFollowRequestComponent } from './core/components/auth/doctors-follow-request/doctors-follow-request.component';
import { CaregiverFollowRequestComponent } from './core/components/auth/caregiver-follow-request/caregiver-follow-request.component';

import { ReportComponent } from './core/components/auth/report/report.component';
import { NotificationsComponent } from './core/components/auth/notifications/notifications.component';

import { AuthGuard } from './guards/auth-guard';
import { AdminGuard } from './guards/admin-guard';
import { GuestGuard } from './guards/guest-guard';
import { LoadingComponent } from './loading/loading.component';
import { MyReportsComponent } from './core/components/auth/my-reports/my-reports.component';
import { UnauthorizedComponent } from './pages/unauthorized.component';
import { VideoCallComponent } from './video-call/video-call.component';

// ── Custom Reuse Strategy ──────────────────────────────────
export class CustomReuseStrategy implements RouteReuseStrategy {
  private cache: { [key: string]: DetachedRouteHandle } = {};

  private getKey(route: ActivatedRouteSnapshot): string {
    const path = route.routeConfig?.path || '';
    // جيب الـ role من الـ localStorage
    try {
      const stored = localStorage.getItem('user');
      if (stored) {
        const obj = JSON.parse(stored);
        const role = (obj?.role || obj?.type || 'unknown').toLowerCase();
        return `${role}_${path}`;
      }
    } catch {}
    return path;
  }

  private shouldCache(route: ActivatedRouteSnapshot): boolean {
    const path = route.routeConfig?.path || '';
    return path === 'chat' || path === 'chat-c';
  }

  shouldDetach(route: ActivatedRouteSnapshot): boolean {
    return this.shouldCache(route);
  }

  store(route: ActivatedRouteSnapshot, handle: DetachedRouteHandle): void {
    this.cache[this.getKey(route)] = handle;
  }

  shouldAttach(route: ActivatedRouteSnapshot): boolean {
    return !!this.cache[this.getKey(route)];
  }

  retrieve(route: ActivatedRouteSnapshot): DetachedRouteHandle | null {
    return this.cache[this.getKey(route)] || null;
  }

  shouldReuseRoute(future: ActivatedRouteSnapshot, curr: ActivatedRouteSnapshot): boolean {
    return future.routeConfig === curr.routeConfig;
  }
}

// ── Routes ────────────────────────────────────────────────
export const routes: Routes = [

  { path: '', redirectTo: '/home', pathMatch: 'full' },
  { path: 'home', component: HomeComponent },
  { path: 'loading', component: LoadingComponent },
{
  path: 'diseases/diabetes',
  data: { diseaseSlug: 'diabetes' },
  loadComponent: () =>
    import('./features/components/diabetes/disease-detail.component')
      .then(m => m.DiseaseDetailComponent)
},

{
  path: 'diseases/heart',
  data: { diseaseSlug: 'heart' },
  loadComponent: () =>
    import('./features/components/heart/disease-detail.component')
      .then(m => m.DiseaseDetailComponent)
},

{
  path: 'diseases/hypertension',
  data: { diseaseSlug: 'hypertension' },
  loadComponent: () =>
    import('./features/components/hypertension/disease-detail.component')
      .then(m => m.DiseaseDetailComponent)
},

{
  path: 'diseases/cancer',
  data: { diseaseSlug: 'cancer' },
  loadComponent: () =>
    import('./features/components/cancer/disease-detail.component')
      .then(m => m.DiseaseDetailComponent)
},
  // Auth
  { path: 'signin', component: SigninComponent, canActivate: [GuestGuard] },
  { path: 'signup', component: SignupComponent, canActivate: [GuestGuard] },
  { path: 'signup2', component: Signup2Component, canActivate: [AuthGuard], data: { roles: ['patient', 'doctor', 'caregiver'], allowIncompleteProfile: true } },
  { path: 'complete-profile/patient', component: Signup2Component, canActivate: [AuthGuard], data: { roles: ['patient'], allowIncompleteProfile: true } },
  { path: 'complete-profile/doctor', component: Signup2Component, canActivate: [AuthGuard], data: { roles: ['doctor'], allowIncompleteProfile: true } },
  { path: 'complete-profile/caregiver', component: Signup2Component, canActivate: [AuthGuard], data: { roles: ['caregiver'], allowIncompleteProfile: true } },
  { path: 'forget-password', component: ForgetPasswordComponent, canActivate: [GuestGuard] },
  { path: 'verify-reset-code', component: ResetCodeComponent, canActivate: [GuestGuard] },
  { path: 'new-password', component: NewPasswordComponent, canActivate: [GuestGuard] },
  { path: 'auth/google/callback', component: GoogleCallbackComponent },
  { path: 'unauthorized', component: UnauthorizedComponent },

  // General
  { path: 'contact', component: ContactComponent },

  // Protected
  { path: 'welcome1', component: Welcome1Component, canActivate: [AuthGuard] },
  { path: 'dashboard', component: DashboardComponent, canActivate: [AuthGuard], data: { roles: ['doctor'] } },
  { path: 'dashboard-p', component: DashboardPComponent, canActivate: [AuthGuard], data: { roles: ['patient'] } },
  { path: 'dashboard-caregiver', component: DashboardCaregiverComponent, canActivate: [AuthGuard], data: { roles: ['caregiver'] } },

  // Vertical slice (Phase B, principal-engineering directive): connects the
  // already-tested ClinicMembershipController RBAC backend to a real screen.
  //
  // Widened from ['doctor'] (Phase C, Queue slice): a clinic "secretary" has
  // no dedicated platform role in this system at all — they are whatever
  // platform role they registered as (patient/doctor/caregiver), plus a
  // clinic_user membership added later. Gating this route to 'doctor' only
  // would make it unreachable for exactly the persona it needs to serve.
  // The real authorization boundary remains entirely backend-side
  // (EnsureClinicContext + ClinicUser::hasPermission()) — this route guard
  // is deliberately permissive, per "never rely only on frontend guards."
  {
    path: 'clinic-staff',
    loadComponent: () =>
      import('./core/components/auth/clinic-staff/clinic-staff.component')
        .then(m => m.ClinicStaffComponent),
    canActivate: [AuthGuard],
    data: { roles: ['doctor', 'patient', 'caregiver'] }
  },

  // RIVA-143: deliberately PUBLIC (no AuthGuard, no `data.roles`) — the
  // invitee has no account/token yet when they open this link. See
  // ClinicMembershipController::inviteDetails()/acceptInvite() and
  // AcceptClinicInviteComponent's docblock.
  {
    path: 'accept-clinic-invite/:token',
    loadComponent: () =>
      import('./core/components/auth/accept-clinic-invite/accept-clinic-invite.component')
        .then(m => m.AcceptClinicInviteComponent),
  },

  // Vertical slice (Phase C, Queue slice, principal-engineering directive):
  // connects the real, tested QueueController to a front-desk operational
  // screen. Same widened role gating as clinic-staff above, for the same
  // reason — a receptionist checking patients in is not necessarily a
  // 'doctor' platform account.
  {
    path: 'clinic-queue',
    loadComponent: () =>
      import('./core/components/auth/clinic-queue/clinic-queue.component')
        .then(m => m.ClinicQueueComponent),
    canActivate: [AuthGuard],
    data: { roles: ['doctor', 'patient', 'caregiver'] }
  },

  // Vertical slice (Phase C, Patient registration/search, principal-
  // engineering directive): connects the real, tested
  // ClinicPatientController to a front-desk screen. Same widened role
  // gating as clinic-staff/clinic-queue above.
  {
    path: 'clinic-patients',
    loadComponent: () =>
      import('./core/components/auth/clinic-patients/clinic-patients.component')
        .then(m => m.ClinicPatientsComponent),
    canActivate: [AuthGuard],
    data: { roles: ['doctor', 'patient', 'caregiver'] }
  },

  // Vertical slice (Phase C, Services & Prices, principal-engineering
  // directive): connects the real, tested ClinicServiceController to a
  // clinic-scoped price-list screen. Same widened role gating as
  // clinic-staff/clinic-queue/clinic-patients above.
  {
    path: 'clinic-services',
    loadComponent: () =>
      import('./core/components/auth/clinic-services/clinic-services.component')
        .then(m => m.ClinicServicesComponent),
    canActivate: [AuthGuard],
    data: { roles: ['doctor', 'patient', 'caregiver'] }
  },

  { path: 'add-new-medication', component: AddNewMedicationComponent, canActivate: [AuthGuard], data: { roles: ['patient'] } },

  { path: 'doctor-cards', component: DoctorCardsComponent, canActivate: [AuthGuard], data: { roles: ['patient'] } },
  { path: 'patient-cards', component: PatientCardsComponent, canActivate: [AuthGuard], data: { roles: ['doctor', 'caregiver', 'admin'] } },
  { path: 'caregiver-cards', component: CaregiverCardsComponent, canActivate: [AuthGuard], data: { roles: ['patient'] } },

  { path: 'chat/:id', component: ChatComponent, canActivate: [AuthGuard], data: { roles: ['patient', 'doctor', 'caregiver'] } },
  { path: 'chat', component: ChatComponent, canActivate: [AuthGuard], data: { roles: ['patient', 'doctor', 'caregiver'] } },
  { path: 'chat-c', component: ChatCComponent, canActivate: [AuthGuard], data: { roles: ['caregiver'] } },
  {
    path: 'ai-chat',
    loadComponent: () =>
      import('./core/components/auth/ai-chat/ai-chat.component')
        .then(m => m.AiChatComponent),
    canActivate: [AuthGuard],
    data: { roles: ['patient', 'doctor', 'caregiver', 'admin'] }
  },
  {path:'video-call',component:VideoCallComponent,canActivate: [AuthGuard], data: { roles: ['patient', 'doctor', 'caregiver'] } },

  { path: 'myprofile', component: ProfileComponent, canActivate: [AuthGuard], data: { roles: ['patient', 'doctor', 'caregiver', 'admin'] } },
  { path: 'profile-d/:id', component: ProfileDComponent, canActivate: [AuthGuard], data: { roles: ['patient', 'doctor', 'caregiver', 'admin'] } },
  { path: 'profile-c/:id', component: ProfileCComponent, canActivate: [AuthGuard], data: { roles: ['patient', 'doctor', 'caregiver', 'admin'] } },
  { path: 'profile-p/:id', component: ProfilePComponent, canActivate: [AuthGuard], data: { roles: ['doctor', 'caregiver', 'admin'] } },

  { path: 'booking/:id', component: BookingComponent, canActivate: [AuthGuard], data: { roles: ['patient'] } },
  { path: 'appointments/book/:doctorId', component: BookingComponent, canActivate: [AuthGuard], data: { roles: ['patient'] } },
  { path: 'appointments/:id', loadComponent: () => import('./core/components/auth/appointments/appointment-detail.component').then(m => m.AppointmentDetailComponent), canActivate: [AuthGuard], data: { roles: ['patient', 'doctor', 'admin'] } },

  { path: 'doctors-follow-request', component: DoctorsFollowRequestComponent, canActivate: [AuthGuard], data: { roles: ['doctor'] } },
  { path: 'caregiver-follow-request', component: CaregiverFollowRequestComponent, canActivate: [AuthGuard], data: { roles: ['caregiver'] } },

  { path: 'report/:id', component: ReportComponent, canActivate: [AuthGuard], data: { roles: ['doctor', 'caregiver', 'admin'] } },

  // RIVA-130 (product-owner request, recorded 2026-08-25): doctor-only, since
  // PrescriptionScanController::scan()/register() are both doctor-only
  // server-side. Linked from report.component.html's "Scan Prescription"
  // button (itself gated *ngIf="canWriteReport").
  {
    path: 'prescription-scan/:patientId',
    loadComponent: () =>
      import('./core/components/auth/prescription-scan/prescription-scan.component')
        .then(m => m.PrescriptionScanComponent),
    canActivate: [AuthGuard],
    data: { roles: ['doctor'] }
  },
  // RIVA-130 follow-up (product-owner request, recorded 2026-08-25): "the
  // dashboard has no Settings button, like other sites have." Linked from
  // the shared sidebar (see components/sidebar.ts) rather than an in-page
  // button on dashboard.component.ts, which this pass could not reach to
  // edit directly (a pre-existing environment limitation, unrelated to this
  // route). First section: doctor-chosen payment methods — see
  // DoctorSettingsComponent's docblock.
  {
    path: 'doctor-settings',
    loadComponent: () =>
      import('./core/components/auth/doctor-settings/doctor-settings.component')
        .then(m => m.DoctorSettingsComponent),
    canActivate: [AuthGuard],
    data: { roles: ['doctor'] }
  },
  { path: 'notifications', component: NotificationsComponent, canActivate: [AuthGuard], data: { roles: ['patient', 'doctor', 'caregiver', 'admin'] } },
  { path: 'notifications/:id', loadComponent: () => import('./core/components/auth/notifications/notification-detail.component').then(m => m.NotificationDetailComponent), canActivate: [AuthGuard], data: { roles: ['patient', 'doctor', 'caregiver', 'admin'] } },
  // RIVA-130 follow-up (product-owner feedback, 2026-08-25): 'doctor'
  // removed — the global cross-patient report list is gone for doctors
  // (reports now live on each patient's own profile; see sidebar.ts and
  // the new /shared-reports route below). Patients and caregivers keep
  // this page — a patient legitimately wants every report about THEM
  // regardless of which doctor wrote it, which was never the complaint.
  { path: 'my-reports', component: MyReportsComponent, canActivate: [AuthGuard], data: { roles: ['patient', 'caregiver'] } },
  {
    path: 'shared-reports',
    loadComponent: () =>
      import('./core/components/auth/shared-reports/shared-reports.component')
        .then(m => m.SharedReportsComponent),
    canActivate: [AuthGuard],
    data: { roles: ['doctor'] }
  },

  // ======================
  // ⭐ ADMIN SECTION
  // ======================
  {
    path: 'admin',
    canActivate: [AdminGuard],
    loadComponent: () =>
      import('./pages/admin/admin-layout.component')
        .then(m => m.AdminLayoutComponent),
    children: [
      { path: '', redirectTo: 'overview', pathMatch: 'full' },
      { path: 'dashboard-admin', redirectTo: 'overview', pathMatch: 'full' },
      {
        path: 'overview',
        loadComponent: () =>
          import('./pages/admin/admin-overview.component')
            .then(m => m.AdminOverviewComponent)
      },
      {
        path: 'users',
        loadComponent: () =>
          import('./pages/admin/admin-data-page.component')
            .then(m => m.AdminDataPageComponent),
        data: { page: 'users' }
      },
      {
        path: 'doctor-verification',
        loadComponent: () =>
          import('./pages/admin/admin-data-page.component')
            .then(m => m.AdminDataPageComponent),
        data: { page: 'doctor-verification' }
      },
      {
        path: 'patient-monitoring',
        loadComponent: () =>
          import('./pages/admin/admin-data-page.component')
            .then(m => m.AdminDataPageComponent),
        data: { page: 'patient-monitoring' }
      },
      {
        path: 'emergencies',
        loadComponent: () =>
          import('./pages/admin/admin-data-page.component')
            .then(m => m.AdminDataPageComponent),
        data: { page: 'emergencies' }
      },
      {
        path: 'daily-reports',
        loadComponent: () =>
          import('./pages/admin/admin-data-page.component')
            .then(m => m.AdminDataPageComponent),
        data: { page: 'daily-reports' }
      },
      {
        path: 'appointments',
        loadComponent: () =>
          import('./pages/admin/admin-data-page.component')
            .then(m => m.AdminDataPageComponent),
        data: { page: 'appointments' }
      },
      {
        path: 'contact-messages',
        loadComponent: () =>
          import('./pages/admin/admin-data-page.component')
            .then(m => m.AdminDataPageComponent),
        data: { page: 'contact-messages' }
      },
      {
        path: 'notifications',
        loadComponent: () =>
          import('./pages/admin/admin-data-page.component')
            .then(m => m.AdminDataPageComponent),
        data: { page: 'notifications' }
      },
      {
        path: 'activity-logs',
        loadComponent: () =>
          import('./pages/admin/admin-data-page.component')
            .then(m => m.AdminDataPageComponent),
        data: { page: 'activity-logs' }
      },
      {
        path: 'settings',
        loadComponent: () =>
          import('./pages/admin/admin-data-page.component')
            .then(m => m.AdminDataPageComponent),
        data: { page: 'settings' }
      },
      {
        path: 'profile',
        loadComponent: () =>
          import('./pages/admin/admin-data-page.component')
            .then(m => m.AdminDataPageComponent),
        data: { page: 'profile' }
      }
    ]
  },

  // 404
  { path: '**', redirectTo: '/home' }
];

@NgModule({
  imports: [
    RouterModule.forRoot(routes, {
      scrollPositionRestoration: 'top',
      anchorScrolling: 'enabled'
    })
  ],
  exports: [RouterModule]
})
export class AppRoutingModule {}
