import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter, Router } from '@angular/router';
import { of, throwError } from 'rxjs';

import { DashboardComponent } from './dashboard.component';
import { AuthService, User } from '../../../../../service/auth.service';
import { DoctorDashboardService, DoctorDashboardResponse } from '../../../../../service/doctor-dashboard.service';
import { QueueService, QueueEntry } from '../../../../../service/queue.service';
import { ClinicService, ClinicApiEnvelope, ClinicContext } from '../../../../../service/clinic.service';
import { riskLabel } from './components/dashboard-format.util';

// ---------------------------------------------------------------------------
// Test fixtures. Every field name below matches the real backend response
// shapes exactly (DashboardController::doctor(), QueueController::index(),
// ClinicMembershipController's my-context / ClinicUser::hasPermission()) —
// nothing here invents a field, a route, or a permission the backend
// doesn't actually return, per Phase 5's anti-fabrication rule.
// ---------------------------------------------------------------------------

const DOCTOR_ID = 42;

const OTHER_DOCTOR_QUEUE_ENTRY: QueueEntry = {
  id: 4, patient_id: 13, patient_name: 'Dana Other', doctor_id: 99, doctor_name: 'Dr. Other',
  appointment_date: '2026-08-26', appointment_time: '09:00:00', type: null, status: 'confirmed',
  payment_status: 'paid', queue_status: 'waiting', queue_position: 1,
  checked_in_at: '2026-08-26T08:50:00Z', called_at: null, queue_completed_at: null,
};

const CURRENT_PATIENT_ENTRY: QueueEntry = {
  id: 1, patient_id: 10, patient_name: 'Alice Ahmed', doctor_id: DOCTOR_ID, doctor_name: 'Dr. Me',
  appointment_date: '2026-08-26', appointment_time: '09:00:00', type: 'checkup', status: 'confirmed',
  payment_status: 'paid', queue_status: 'in_service', queue_position: 1,
  checked_in_at: '2026-08-26T08:55:00Z', called_at: '2026-08-26T09:01:00Z', queue_completed_at: null,
};

const NEXT_PATIENT_ENTRY: QueueEntry = {
  id: 2, patient_id: 11, patient_name: 'Bob Said', doctor_id: DOCTOR_ID, doctor_name: 'Dr. Me',
  appointment_date: '2026-08-26', appointment_time: '09:15:00', type: 'checkup', status: 'confirmed',
  payment_status: 'paid', queue_status: 'waiting', queue_position: 2,
  checked_in_at: '2026-08-26T09:05:00Z', called_at: null, queue_completed_at: null,
};

const THIRD_WAITING_ENTRY: QueueEntry = {
  id: 3, patient_id: 12, patient_name: 'Carl Nabil', doctor_id: DOCTOR_ID, doctor_name: 'Dr. Me',
  appointment_date: '2026-08-26', appointment_time: '09:30:00', type: 'checkup', status: 'confirmed',
  payment_status: 'paid', queue_status: 'waiting', queue_position: 3,
  checked_in_at: '2026-08-26T09:10:00Z', called_at: null, queue_completed_at: null,
};

const QUEUE_ENTRIES: QueueEntry[] = [
  OTHER_DOCTOR_QUEUE_ENTRY, CURRENT_PATIENT_ENTRY, NEXT_PATIENT_ENTRY, THIRD_WAITING_ENTRY,
];

function buildDashboardResponse(overrides: Partial<DoctorDashboardResponse> = {}): DoctorDashboardResponse {
  return {
    total_patients: 1,
    total_assigned_patients: 1,
    stable_patients: 0,
    attention_patients: 0,
    critical_patients: 1,
    high_risk_patients: 1,
    pending_follow_requests: 0,
    reports_count: 2,
    average_adherence: 87,
    missed_medications: 1,
    weekly_reports: 3,
    weekly_patients: [1, 0, 2, 1, 0, 0, 1],
    patient_status_distribution: { stable: 0, attention: 0, critical: 1 },
    appointments: [],
    recent_daily_statuses: [
      { id: 1, patient_id: 10, risk_level: 'high_risk', date: '2026-08-25', created_at: '2026-08-25T10:00:00Z', pain_level: 7, symptoms: 'headache' },
      { id: 2, patient_id: 11, risk_level: 'stable', date: '2026-08-25', created_at: '2026-08-25T10:00:00Z' },
    ],
    risk_trends: { stable: 0, attention_needed: 0, high_risk: 1 },
    patients: [
      {
        id: 1, doctor_id: DOCTOR_ID, patient_id: 10, status: 'active',
        requested_at: null, responded_at: null, ended_at: null,
        patient: {
          id: 10, user_id: 100,
          user: { id: 100, first_name: 'Alice', last_name: 'Ahmed', email: 'alice@example.com' },
          chronic_conditions: 'Hypertension', medical_history: null,
        },
      },
    ],
    // Deliberately different from the month-scoped figures below, so a test
    // can catch any accidental relabeling of month data as "today".
    active_emergency_alerts: {
      count: 1,
      items: [
        { id: 1, patient_id: 10, patient_name: 'Alice Ahmed', severity: 'high', message: 'Severe chest pain', pain_level: 8, symptoms: 'chest pain', created_at: '2026-08-26T09:00:00Z' },
      ],
    },
    recent_bookings: [
      { id: 1, appointment_id: 55, patient_name: 'Alice Ahmed', action: 'created', notes: null, actor_name: 'Front desk', created_at: '2026-08-26T08:00:00Z' },
    ],
    today_appointments_count: 4,
    today_completed_checkups: 1,
    today_pending_appointments: 3,
    today_cancelled_count: 2,
    total_consultations: 120,
    monthly_consultations: 30,
    cancelled_this_month: 9,
    revenue_total: 500000,
    revenue_this_month: 45000,
    revenue_today: 1500,
    average_revenue_per_visit: 350,
    pending_payments_count: 2,
    currency: 'EGP',
    weekly_revenue: [1000, 0, 1500, 500, 0, 0, 1500],
    ...overrides,
  };
}

function buildClinicContext(clinics: NonNullable<ClinicContext['clinics']>): ClinicApiEnvelope<ClinicContext> {
  return { success: true, message: 'ok', data: { has_clinic: clinics.length > 0, clinics } };
}

describe('DashboardComponent', () => {
  let httpMock: HttpTestingController;
  let authServiceSpy: jasmine.SpyObj<AuthService>;
  let doctorDashboardServiceSpy: jasmine.SpyObj<DoctorDashboardService>;
  let queueServiceSpy: jasmine.SpyObj<QueueService>;
  let clinicServiceSpy: jasmine.SpyObj<ClinicService>;
  let router: Router;

  /**
   * Builds a fresh TestBed + component instance for one test, with
   * overridable service responses. A fresh module per test (rather than a
   * single shared beforeEach) is needed because several tests require
   * distinct permission/queue/dashboard responses before ngOnInit fires.
   */
  function setup(config: {
    doctor?: { id?: number };
    dashboard?: DoctorDashboardResponse;
    dashboardError?: boolean;
    queue?: QueueEntry[];
    queueError?: boolean;
    clinicContext?: ClinicApiEnvelope<ClinicContext>;
    clinicContextError?: boolean;
  } = {}): { component: DashboardComponent; fixture: ComponentFixture<DashboardComponent> } {
    const user: User = { id: 1, email: 'doctor@example.com', role: 'doctor', doctor: config.doctor ?? { id: DOCTOR_ID } };

    authServiceSpy = jasmine.createSpyObj<AuthService>('AuthService', [
      'getToken', 'getUser', 'getUserRole', 'dashboardRouteForRole',
    ]);
    authServiceSpy.getToken.and.returnValue('fake-token');
    authServiceSpy.getUser.and.returnValue(user);
    // DashboardComponent's template renders <app-sidebar>, whose ngOnInit and
    // template both call these two real AuthService methods directly — they
    // must be stubbed here too, consistent with the 'doctor' role used above.
    authServiceSpy.getUserRole.and.returnValue('doctor');
    authServiceSpy.dashboardRouteForRole.and.returnValue('/dashboard');

    doctorDashboardServiceSpy = jasmine.createSpyObj<DoctorDashboardService>('DoctorDashboardService', ['get']);
    doctorDashboardServiceSpy.get.and.returnValue(
      config.dashboardError
        ? throwError(() => new Error('network error'))
        : of({ success: true, message: 'ok', data: config.dashboard ?? buildDashboardResponse() })
    );

    queueServiceSpy = jasmine.createSpyObj<QueueService>('QueueService', ['list', 'call']);
    queueServiceSpy.list.and.returnValue(
      config.queueError
        ? throwError(() => new Error('network error'))
        : of({ success: true, message: 'ok', data: config.queue ?? QUEUE_ENTRIES })
    );
    queueServiceSpy.call.and.returnValue(of({ success: true, message: 'ok', data: { ...NEXT_PATIENT_ENTRY, queue_status: 'called' } }));

    clinicServiceSpy = jasmine.createSpyObj<ClinicService>('ClinicService', ['myContext']);
    clinicServiceSpy.myContext.and.returnValue(
      config.clinicContextError
        ? throwError(() => new Error('network error'))
        : of(config.clinicContext ?? buildClinicContext([{ clinic_id: 1, clinic_name: 'Clinic A', role: 'staff', permissions: ['queue.manage'] }]))
    );

    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [DashboardComponent],
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: AuthService, useValue: authServiceSpy },
        { provide: DoctorDashboardService, useValue: doctorDashboardServiceSpy },
        { provide: QueueService, useValue: queueServiceSpy },
        { provide: ClinicService, useValue: clinicServiceSpy },
      ],
    });

    httpMock = TestBed.inject(HttpTestingController);
    router = TestBed.inject(Router);

    const fixture = TestBed.createComponent(DashboardComponent);
    const component = fixture.componentInstance;
    fixture.detectChanges(); // triggers ngOnInit()

    // The component's own two direct HttpClient calls (profile + follow
    // requests) predate Phase 5 and are out of its scope; they're stubbed
    // here only so they resolve deterministically instead of touching the
    // network during this test.
    httpMock.match(req => req.url.endsWith('/profile')).forEach(req =>
      req.flush({ user: { first_name: 'Doc', last_name: 'Tor' } })
    );
    httpMock.match(req => req.url.endsWith('/doctor/follow-requests')).forEach(req =>
      req.flush({ data: [] })
    );

    fixture.detectChanges();
    return { component, fixture };
  }

  afterEach(() => {
    httpMock?.verify();
  });

  it('should create', () => {
    const { component } = setup();
    expect(component).toBeTruthy();
  });

  it('loads dashboard data and maps day-scoped fields — never the month-scoped ones — into clinicStats', () => {
    const { component } = setup();
    expect(doctorDashboardServiceSpy.get).toHaveBeenCalled();
    expect(component.clinicStats.todayCancelled).toBe(2); // today_cancelled_count
    expect(component.clinicStats.revenueToday).toBe(1500); // revenue_today
    // Never the month-scoped figures relabeled as "today":
    expect(component.clinicStats.todayCancelled).not.toBe(9); // cancelled_this_month
    expect(component.clinicStats.revenueToday).not.toBe(45000); // revenue_this_month
    expect(component.clinicStats.cancelledThisMonth).toBe(9);
    expect(component.clinicStats.revenueThisMonth).toBe(45000);
    expect(component.isLoadingPatients).toBeFalse();
    expect(component.dashboardError).toBeNull();
  });

  it('sets a plain error message (not the raw backend error) when the dashboard call fails', () => {
    const { component } = setup({ dashboardError: true });
    expect(component.dashboardError).toBe("Couldn't load dashboard data.");
    expect(component.isLoadingPatients).toBeFalse();
  });

  it("loads the shared clinic queue and derives this doctor's current/next patient and waiting count", () => {
    const { component } = setup();
    expect(queueServiceSpy.list).toHaveBeenCalled();
    expect(component.doctorQueueView.currentPatient?.id).toBe(CURRENT_PATIENT_ENTRY.id);
    expect(component.doctorQueueView.nextPatient?.id).toBe(NEXT_PATIENT_ENTRY.id);
    // Only this doctor's own entries are counted — the other doctor's
    // waiting patient (doctor_id 99) must never leak into this doctor's view.
    expect(component.doctorQueueView.waitingCount).toBe(2);
    expect(component.isLoadingQueue).toBeFalse();
    expect(component.queueError).toBeNull();
  });

  it("computes patients-before-next using only this doctor's own queue entries", () => {
    const { component } = setup();
    // Bob (next) is first in this doctor's own waiting line, so nobody of
    // his is ahead of him even though another doctor's patient sits at
    // queue_position 1 in the shared, clinic-wide feed.
    expect(component.patientsBeforeNext()).toBe(0);
  });

  it('shows a plain queue error message (not the raw backend error) when the queue call fails', () => {
    const { component } = setup({ queueError: true });
    expect(component.queueError).toBe("Couldn't load the queue.");
    expect(component.isLoadingQueue).toBeFalse();
  });

  it('grants canManageQueue when the clinic membership has the real queue.manage permission', () => {
    const { component } = setup();
    expect(component.canManageQueue).toBeTrue();
  });

  it('withholds canManageQueue when the clinic membership lacks queue.manage (fails closed)', () => {
    const { component } = setup({
      clinicContext: buildClinicContext([{ clinic_id: 1, clinic_name: 'Clinic A', role: 'staff', permissions: [] }]),
    });
    expect(component.canManageQueue).toBeFalse();
  });

  it('withholds all permission-gated actions when no clinic context resolves at all', () => {
    const { component } = setup({ clinicContextError: true });
    expect(component.canManageQueue).toBeFalse();
    expect(component.canManageServices).toBeFalse();
  });

  it('calls the real QueueService.call() and refreshes the queue when calling the next patient with permission', () => {
    const { component } = setup(); // queue.manage granted by the default fixture
    component.callPatient(component.doctorQueueView.nextPatient);
    expect(queueServiceSpy.call).toHaveBeenCalledWith(NEXT_PATIENT_ENTRY.id);
    // A real, targeted refresh — not a full page reload:
    expect(queueServiceSpy.list).toHaveBeenCalledTimes(2);
  });

  it('never calls QueueService.call() when the doctor lacks queue.manage permission', () => {
    const { component } = setup({
      clinicContext: buildClinicContext([{ clinic_id: 1, clinic_name: 'Clinic A', role: 'staff', permissions: [] }]),
    });
    component.callPatient(component.doctorQueueView.nextPatient);
    expect(queueServiceSpy.call).not.toHaveBeenCalled();
  });

  it('exposes real active emergency alerts from the backend response', () => {
    const { component } = setup();
    expect(component.emergencyAlertsCount).toBe(1);
    expect(component.emergencyAlerts.length).toBe(1);
    expect(component.emergencyAlerts[0].patient_name).toBe('Alice Ahmed');
  });

  it('reports zero emergency alerts honestly (a real empty state, not an invented placeholder)', () => {
    const { component } = setup({ dashboard: buildDashboardResponse({ active_emergency_alerts: { count: 0, items: [] } }) });
    expect(component.emergencyAlertsCount).toBe(0);
    expect(component.emergencyAlerts.length).toBe(0);
  });

  it('renders recent bookings using the real backend fields', () => {
    const { component } = setup();
    expect(component.recentBookings.length).toBe(1);
    expect(component.recentBookings[0].action).toBe('created');
    expect(component.recentBookings[0].patient_name).toBe('Alice Ahmed');
  });

  it('shows a real empty state for recent bookings when the backend returns none', () => {
    const { component } = setup({ dashboard: buildDashboardResponse({ recent_bookings: [] }) });
    expect(component.recentBookings.length).toBe(0);
  });

  it('filters the review-required list to non-stable statuses without inventing a new risk vocabulary', () => {
    const { component } = setup();
    expect(component.reviewList.length).toBe(1);
    expect(component.reviewList[0].patient_id).toBe(10);
    expect(component.reviewList[0].risk_level).toBe('high_risk');
    expect(riskLabel(component.reviewList[0].risk_level)).toBe('Critical');
  });

  // Phase 6: openPatient()/openAiAssistant() moved out of DashboardComponent
  // into the split-out leaf components that actually render those clicks
  // (dashboard-queue, dashboard-attention, dashboard-emergency-alerts,
  // dashboard-my-patients, dashboard-quick-actions) — each pure-navigation
  // action is now injected+tested at the component that owns it, not
  // proxied through the parent. See their own *.component.spec.ts files.

  it('Phase 6: resolves the review-required patient name from the doctor\'s own patients list', () => {
    const { component } = setup();
    expect(component.reviewItems.length).toBe(1);
    expect(component.reviewItems[0].patientDisplayName).toBe('Alice Ahmed');
    expect(component.reviewItems[0].risk_level).toBe('high_risk');
  });

  it('Phase 6: todayStats getter carries only day-scoped fields, sourced from clinicStats', () => {
    const { component } = setup();
    expect(component.todayStats.todayCancelled).toBe(2);
    expect(component.todayStats.revenueToday).toBe(1500);
    expect(component.todayStats.noShowTodayCount).toBe(component.doctorQueueView.noShowTodayCount);
  });

  it('Phase 6: businessStats getter carries only month/all-time fields, sourced from clinicStats', () => {
    const { component } = setup();
    expect(component.businessStats.revenueThisMonth).toBe(45000);
    expect(component.businessStats.revenueTotal).toBe(500000);
    expect(component.businessStats.avgRevenuePerVisit).toBe(350);
    expect(component.businessStats.pendingPayments).toBe(2);
  });
});
