import { Component, OnInit } from "@angular/core";
import { CommonModule } from "@angular/common";
import { HttpClient, HttpClientModule } from "@angular/common/http";
import { RouterModule, Router } from "@angular/router";
import { AuthService } from '../../../../../service/auth.service';
import { API_BASE_URL } from '../../../../../constants';
import { SidebarComponent } from '../../../../../components/sidebar';

import {
  ApexChart,
  ApexNonAxisChartSeries,
  ApexStroke,
  ApexXAxis
} from 'ng-apexcharts';

// Doctor ERP Dashboard, Phase 5 (DOCTOR_ERP_DASHBOARD_SPEC.md) — wiring the
// already-real, already-tested backend/service layer (DoctorDashboardService,
// QueueService, ClinicService) into the live component. Nothing here invents
// a field, a route, or a permission the backend doesn't already enforce.
//
// Phase 6: the same behavior, split into presentational sub-components
// under ./components/. This file keeps EVERY service call, every piece of
// derived state, and every permission check exactly where Phase 5 put it —
// only the template markup moved out, into components that receive plain
// @Input()s and emit plain @Output()s. See dashboard.component.html for how
// the pieces are composed back together in the required UX-priority order
// (attention -> queue -> today -> quick actions -> [patients/follow-ups] ->
// business -> recent bookings).
import {
  DoctorDashboardService,
  DoctorDashboardResponse,
  EmergencyAlertItem,
  RecentBooking,
  DashboardDailyStatus,
  RiskLevel,
  deriveDoctorQueueView,
  patientsAheadOf,
  DoctorQueueView
} from '../../../../../service/doctor-dashboard.service';
import { QueueService, QueueEntry } from '../../../../../service/queue.service';
import { ClinicService } from '../../../../../service/clinic.service';

import { DashboardHeaderComponent } from './components/dashboard-header/dashboard-header.component';
import { DashboardEmergencyAlertsComponent } from './components/dashboard-emergency-alerts/dashboard-emergency-alerts.component';
import { DashboardQueueComponent } from './components/dashboard-queue/dashboard-queue.component';
import { DashboardFollowRequestsComponent } from './components/dashboard-follow-requests/dashboard-follow-requests.component';
import { DashboardTodayComponent } from './components/dashboard-today/dashboard-today.component';
import { DashboardAttentionComponent } from './components/dashboard-attention/dashboard-attention.component';
import { DashboardQuickActionsComponent } from './components/dashboard-quick-actions/dashboard-quick-actions.component';
import { DashboardMyPatientsComponent } from './components/dashboard-my-patients/dashboard-my-patients.component';
import { DashboardBusinessComponent } from './components/dashboard-business/dashboard-business.component';
import { DashboardRecentBookingsComponent } from './components/dashboard-recent-bookings/dashboard-recent-bookings.component';
import {
  MyPatientView,
  FollowRequestView,
  DashboardTodayStats,
  DashboardBusinessStats,
  ReviewItemView,
} from './components/dashboard-view-models';
import { riskLabel, riskRank } from './components/dashboard-format.util';

@Component({
  selector: 'app-dashboard',
  templateUrl: './dashboard.component.html',
  styleUrl: './dashboard.component.css',
  standalone: true,
  imports: [
    CommonModule,
    HttpClientModule,
    RouterModule,
    SidebarComponent,
    DashboardHeaderComponent,
    DashboardEmergencyAlertsComponent,
    DashboardQueueComponent,
    DashboardFollowRequestsComponent,
    DashboardTodayComponent,
    DashboardAttentionComponent,
    DashboardQuickActionsComponent,
    DashboardMyPatientsComponent,
    DashboardBusinessComponent,
    DashboardRecentBookingsComponent,
  ],
})
export class DashboardComponent implements OnInit {

  currentDoctor = { name: '', status: 'Online', avatar: '' };
  pendingRequests: FollowRequestView[] = [];
  patients: MyPatientView[] = [];
  isLoadingRequests = true;
  isLoadingPatients = true;
  dashboardError: string | null = null;
  stats = { total: 0, highRisk: 0, pdfs: 0, adherence: 0 };
  hasPatientAnalytics = false;

  clinicStats = {
    todayAppointments: 0,
    todayCheckups: 0,
    todayPending: 0,
    // Real, day-scoped fields (DOCTOR_ERP_DASHBOARD_SPEC.md §10.4) — never the
    // month-scoped cancelled_this_month/revenue_this_month figures relabeled.
    todayCancelled: 0,
    revenueToday: 0,
    totalConsultations: 0,
    monthlyConsultations: 0,
    cancelledThisMonth: 0,
    revenueTotal: 0,
    revenueThisMonth: 0,
    avgRevenuePerVisit: 0,
    pendingPayments: 0,
    currency: 'EGP'
  };
  hasRevenueAnalytics = false;

  /** Level 2 "Today" card input, narrowed from clinicStats above — kept as a
   * getter (not a duplicated field) so there is exactly one place
   * (clinicStats) that ever holds this data. */
  get todayStats(): DashboardTodayStats {
    return {
      todayAppointments: this.clinicStats.todayAppointments,
      todayPending: this.clinicStats.todayPending,
      todayCheckups: this.clinicStats.todayCheckups,
      noShowTodayCount: this.doctorQueueView.noShowTodayCount,
      todayCancelled: this.clinicStats.todayCancelled,
      revenueToday: this.clinicStats.revenueToday,
      currency: this.clinicStats.currency,
      totalConsultations: this.clinicStats.totalConsultations,
      monthlyConsultations: this.clinicStats.monthlyConsultations,
    };
  }

  /** Level 5 "Business" card input, narrowed from clinicStats above. */
  get businessStats(): DashboardBusinessStats {
    return {
      revenueThisMonth: this.clinicStats.revenueThisMonth,
      revenueTotal: this.clinicStats.revenueTotal,
      avgRevenuePerVisit: this.clinicStats.avgRevenuePerVisit,
      pendingPayments: this.clinicStats.pendingPayments,
    };
  }

  // Level 3 — Attention Center
  emergencyAlerts: EmergencyAlertItem[] = [];
  emergencyAlertsCount = 0;
  reviewList: DashboardDailyStatus[] = [];
  private patientNameMap = new Map<number, string>();

  /** Attention card input: the same reviewList Phase 5 already filtered
   * (non-stable, backend risk ordering preserved), with the display name
   * resolved once here instead of being looked up per-render from the
   * template. */
  get reviewItems(): ReviewItemView[] {
    return this.reviewList.map((status) => ({
      ...status,
      patientDisplayName: this.patientName(status.patient_id),
    }));
  }

  // Level 6 — Activity
  recentBookings: RecentBooking[] = [];

  // Level 1 — Now / live queue. The queue endpoint is intentionally
  // clinic-scoped (every doctor's patients together), not doctor-scoped, so
  // "my" current/next patient and waiting count are derived client-side with
  // deriveDoctorQueueView()/patientsAheadOf() — both already-tested pure
  // functions from doctor-dashboard.service.ts, not reimplemented here.
  queueEntries: QueueEntry[] = [];
  doctorQueueView: DoctorQueueView = {
    currentPatient: null,
    nextPatient: null,
    waitingCount: 0,
    noShowTodayCount: 0,
    skippedTodayCount: 0
  };
  isLoadingQueue = true;
  queueError: string | null = null;
  private doctorId: number | null = null;

  // Permission-aware Quick Actions / Call action. This mirrors
  // ClinicUser::hasPermission() for hiding UI only — the backend re-enforces
  // the same rule server-side on every actual request regardless of what this
  // computes, so a stale/incorrect read here can never grant an action it
  // shouldn't.
  canManageQueue = false;
  canManageServices = false;
  isLoadingPermissions = true;

  callingAppointmentId: number | null = null;
  callError: string | null = null;

  public pieOptions = {
    series: [0, 0, 0] as ApexNonAxisChartSeries,
    chart: { type: "donut" as const, height: 280 } as ApexChart,
    labels: ["Stable", "Attention", "Critical"],
    colors: ["#0ea5e9", "#f59e0b", "#f43f5e"],
    legend: { position: 'bottom' as const },
    responsive: [{ breakpoint: 480, options: { chart: { width: 200 } } }]
  };

  public lineOptions = {
    series: [{ name: "Patients", data: [0, 0, 0, 0, 0, 0, 0] }],
    chart: { type: "area" as const, height: 280, toolbar: { show: false } } as ApexChart,
    xaxis: { categories: ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"] } as ApexXAxis,
    stroke: { curve: "smooth" as const, width: 3 } as ApexStroke,
    colors: ["#0ea5e9"]
  };

  public revenueOptions = {
    series: [{ name: "Revenue", data: [0, 0, 0, 0, 0, 0, 0] }],
    chart: { type: "area" as const, height: 280, toolbar: { show: false } } as ApexChart,
    xaxis: { categories: ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"] } as ApexXAxis,
    stroke: { curve: "smooth" as const, width: 3 } as ApexStroke,
    colors: ["#10b981"],
    dataLabels: { enabled: false }
  };

  constructor(
    private http: HttpClient,
    private authService: AuthService,
    private doctorDashboardService: DoctorDashboardService,
    private queueService: QueueService,
    private clinicService: ClinicService,
    public router: Router
  ) {}

  private get authHeaders() {
    return { Authorization: `Bearer ${this.authService.getToken()}` };
  }

  ngOnInit(): void {
    // The login/profile response already eager-loads and returns the full
    // Doctor model as user.doctor (AuthController::formatUser()) — the same
    // Doctor.id every Appointment/queue entry's doctor_id references. This is
    // never sent to the backend as an ownership claim (every endpoint here
    // resolves the doctor server-side from the authenticated user); it is
    // only used client-side to filter the shared clinic queue down to "my"
    // patients, exactly as DOCTOR_ERP_DASHBOARD_SPEC.md §4/§9.3 specifies.
    const doctorProfile = this.authService.getUser()?.doctor as { id?: number } | undefined;
    this.doctorId = doctorProfile?.id ?? null;

    this.loadDoctorProfile();
    this.loadPendingRequests();
    this.loadDoctorPatients();
    this.loadQueue();
    this.loadPermissions();
  }

  loadDoctorProfile(): void {
    this.http.get<any>(`${API_BASE_URL}/profile`, {
      headers: this.authHeaders
    }).subscribe(res => {
      const user = res.user || res;
      this.currentDoctor.name = `${user.first_name || ''} ${user.last_name || ''}`.trim() || 'Doctor';
      this.currentDoctor.avatar = user.profile_image_url
        || (user.profile_image ? `http://127.0.0.1:8000/storage/${user.profile_image}` : null)
        || `https://ui-avatars.com/api/?name=${encodeURIComponent(this.currentDoctor.name)}&background=E0F2FE&color=0EA5E9`;
    });
  }

  loadPendingRequests(): void {
    this.isLoadingRequests = true;
    this.http.get<any>(`${API_BASE_URL}/doctor/follow-requests`, { headers: this.authHeaders }).subscribe({
      next: (res) => {
        const list = res.data || res.requests || [];
        // ✅ خد بس الـ pending — الـ API بيرجع كل الـ requests حتى الـ active
        this.pendingRequests = list
          .filter((r: any) => r.status === 'pending')
          .map((r: any) => ({
            id:          r.id,
            name:        `${r.patient?.user?.first_name || ''} ${r.patient?.user?.last_name || ''}`.trim() || 'Patient',
            avatar:      r.patient?.user?.profile_image
              || `https://ui-avatars.com/api/?name=P&background=E0F2FE&color=0EA5E9`,
            condition:   r.patient?.chronic_conditions || r.patient?.medical_history || '—',
            requestedAt: r.created_at
              ? new Date(r.created_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })
              : '',
            isProcessing: false
          }));
        this.isLoadingRequests = false;
      },
      error: () => { this.isLoadingRequests = false; }
    });
  }

  /**
   * Level 4 permission gating — a client-side mirror of
   * ClinicUser::hasPermission(): owner/admin are always full-access by role,
   * a plain 'staff' membership is gated by its explicit permissions list, and
   * a doctor with no clinic context at all gets both flags false (fails
   * closed — the buttons stay hidden rather than guessing). The backend is
   * still the real authorization boundary regardless of what this computes.
   */
  loadPermissions(): void {
    this.isLoadingPermissions = true;
    this.clinicService.myContext().subscribe({
      next: (res) => {
        const clinics = res.data?.clinics ?? [];
        this.canManageQueue = clinics.some(c => c.role === 'owner' || c.role === 'admin' || (c.permissions ?? []).includes('queue.manage'));
        this.canManageServices = clinics.some(c => c.role === 'owner' || c.role === 'admin' || (c.permissions ?? []).includes('services.manage'));
        this.isLoadingPermissions = false;
      },
      error: () => {
        // No clinic context resolved -> hide permission-gated actions rather
        // than guess. canManageQueue/canManageServices already default false.
        this.isLoadingPermissions = false;
      }
    });
  }

  /**
   * Level 1 — the shared, clinic-wide queue (GET /api/clinic/queue). Loaded
   * independently of GET /dashboard/doctor so one endpoint's failure never
   * blocks the other's section from rendering (DOCTOR_ERP_DASHBOARD_SPEC.md §21).
   */
  loadQueue(): void {
    this.isLoadingQueue = true;
    this.queueError = null;
    this.queueService.list().subscribe({
      next: (res) => {
        this.queueEntries = res.data ?? [];
        this.recomputeQueueView();
        this.isLoadingQueue = false;
      },
      error: () => {
        this.queueError = "Couldn't load the queue.";
        this.isLoadingQueue = false;
      }
    });
  }

  private recomputeQueueView(): void {
    if (this.doctorId == null) {
      this.doctorQueueView = { currentPatient: null, nextPatient: null, waitingCount: 0, noShowTodayCount: 0, skippedTodayCount: 0 };
      return;
    }
    this.doctorQueueView = deriveDoctorQueueView(this.queueEntries, this.doctorId);
  }

  /**
   * "N patients before you" — never a fabricated minutes/ETA estimate
   * (DOCTOR_ERP_DASHBOARD_SPEC.md §25/§31: no per-patient consultation-duration
   * data exists anywhere in this codebase to make a minutes estimate honest).
   */
  patientsBeforeNext(): number {
    if (this.doctorId == null || !this.doctorQueueView.nextPatient) return 0;
    return patientsAheadOf(this.queueEntries, this.doctorId, this.doctorQueueView.nextPatient);
  }

  callPatient(entry: QueueEntry | null): void {
    if (!entry || !this.canManageQueue || this.callingAppointmentId) return;
    this.callingAppointmentId = entry.id;
    this.callError = null;
    this.queueService.call(entry.id).subscribe({
      next: () => {
        this.callingAppointmentId = null;
        // Real refresh of just the queue section, not a full page reload.
        this.loadQueue();
      },
      error: (err) => {
        this.callingAppointmentId = null;
        // A 403 here means a stale frontend permission read raced a real
        // backend rejection — the backend remains the actual authorization
        // boundary regardless of what canManageQueue computed client-side.
        this.callError = err?.status === 403
          ? 'You do not have permission to manage the queue.'
          : "Couldn't call this patient. Try again.";
      }
    });
  }

  private patientName(patientId: number | null | undefined): string {
    if (!patientId) return 'Patient';
    return this.patientNameMap.get(patientId) || `Patient #${patientId}`;
  }

  loadDoctorPatients(): void {
    this.isLoadingPatients = true;
    this.dashboardError = null;
    this.doctorDashboardService.get().subscribe({
      next: (res) => {
        const data: DoctorDashboardResponse = res.data;

        const riskMap = new Map<number, RiskLevel>(
          (data.recent_daily_statuses ?? []).map(status => [status.patient_id, status.risk_level])
        );

        this.patientNameMap = new Map(
          (data.patients ?? [])
            .filter(r => !!r.patient)
            .map(r => {
              const user = r.patient!.user;
              const name = `${user?.first_name || ''} ${user?.last_name || ''}`.trim() || user?.name || 'Patient';
              return [r.patient!.id, name] as [number, string];
            })
        );

        const list = data.patients || [];
        this.patients = list
          .filter((r) => r.status === 'active')
          .map((r): MyPatientView => {
            const patient = r.patient;
            const user = patient?.user;
            const name = `${user?.first_name || ''} ${user?.last_name || ''}`.trim() || user?.name || 'Patient';
            const riskLevel = patient ? riskMap.get(patient.id) : undefined;
            return {
              id: patient?.id,
              userId: user?.id,
              name,
              img: user?.profile_image_url
                || `https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=E0F2FE&color=0EA5E9`,
              condition: patient?.chronic_conditions || patient?.medical_history || 'N/A',
              status: riskLabel(riskLevel),
              riskRank: riskRank(riskLevel),
            };
          })
          .sort((a, b) => a.riskRank - b.riskRank);

        const distribution = data.patient_status_distribution || { stable: 0, attention: 0, critical: 0 };
        const pieSeries: number[] = [
          Number(distribution.stable ?? 0),
          Number(distribution.attention ?? 0),
          Number(distribution.critical ?? 0),
        ];
        const weekly: number[] = Array.isArray(data.weekly_patients) ? data.weekly_patients.map((value) => Number(value) || 0) : [0, 0, 0, 0, 0, 0, 0];

        this.stats.total = Number(data.total_patients ?? data.total_assigned_patients ?? this.patients.length) || 0;
        this.stats.highRisk = Number(data.critical_patients ?? data.high_risk_patients ?? 0) || 0;
        this.stats.pdfs = Number(data.reports_count ?? 0) || 0;
        this.stats.adherence = Number(data.average_adherence ?? 0) || 0;
        this.pieOptions = { ...this.pieOptions, series: pieSeries as ApexNonAxisChartSeries };
        this.lineOptions = { ...this.lineOptions, series: [{ name: 'Patients', data: weekly }] };
        this.hasPatientAnalytics = this.stats.total > 0 && (pieSeries.some(value => value > 0) || weekly.some(value => value > 0));

        const weeklyRevenue: number[] = Array.isArray(data.weekly_revenue) ? data.weekly_revenue.map((value) => Number(value) || 0) : [0, 0, 0, 0, 0, 0, 0];
        this.clinicStats = {
          todayAppointments: Number(data.today_appointments_count ?? 0) || 0,
          todayCheckups: Number(data.today_completed_checkups ?? 0) || 0,
          todayPending: Number(data.today_pending_appointments ?? 0) || 0,
          todayCancelled: Number(data.today_cancelled_count ?? 0) || 0,
          revenueToday: Number(data.revenue_today ?? 0) || 0,
          totalConsultations: Number(data.total_consultations ?? 0) || 0,
          monthlyConsultations: Number(data.monthly_consultations ?? 0) || 0,
          cancelledThisMonth: Number(data.cancelled_this_month ?? 0) || 0,
          revenueTotal: Number(data.revenue_total ?? 0) || 0,
          revenueThisMonth: Number(data.revenue_this_month ?? 0) || 0,
          avgRevenuePerVisit: Number(data.average_revenue_per_visit ?? 0) || 0,
          pendingPayments: Number(data.pending_payments_count ?? 0) || 0,
          currency: data.currency || 'EGP'
        };
        this.revenueOptions = { ...this.revenueOptions, series: [{ name: 'Revenue', data: weeklyRevenue }] };
        this.hasRevenueAnalytics = this.clinicStats.revenueTotal > 0 || weeklyRevenue.some(value => value > 0);

        this.emergencyAlerts = data.active_emergency_alerts?.items ?? [];
        this.emergencyAlertsCount = data.active_emergency_alerts?.count ?? 0;

        // Backend already sorts high_risk -> stable and dedupes per patient
        // (DashboardController::attentionOrderedStatuses()) — this only
        // narrows to non-stable, it does not re-order using any vocabulary
        // of its own.
        this.reviewList = (data.recent_daily_statuses ?? []).filter(s => s.risk_level !== 'stable');

        this.recentBookings = data.recent_bookings ?? [];

        this.isLoadingPatients = false;
      },
      error: () => {
        this.dashboardError = "Couldn't load dashboard data.";
        this.isLoadingPatients = false;
      }
    });
  }

  acceptRequest(req: FollowRequestView): void {
    req.isProcessing = true;
    this.http.post(`${API_BASE_URL}/doctor/follow-requests/${req.id}/accept`, {}, { headers: this.authHeaders }).subscribe({
      next: () => {
        // ✅ reload من الـ API بدل الحذف اليدوي
        this.loadPendingRequests();
        this.loadDoctorPatients();
      },
      error: (err) => { console.error(err); req.isProcessing = false; }
    });
  }

  rejectRequest(req: FollowRequestView): void {
    req.isProcessing = true;
    this.http.post(`${API_BASE_URL}/doctor/follow-requests/${req.id}/reject`, {}, { headers: this.authHeaders }).subscribe({
      next: () => {
        // ✅ reload من الـ API بدل الحذف اليدوي
        this.loadPendingRequests();
      },
      error: (err) => { console.error(err); req.isProcessing = false; }
    });
  }
}
