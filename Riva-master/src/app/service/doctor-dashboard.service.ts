import { HttpClient } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../constants';
import { AuthService } from './auth.service';
import { ClinicApiEnvelope } from './clinic.service';
import { QueueEntry } from './queue.service';

/**
 * Doctor ERP Dashboard — vertical slice (DOCTOR_ERP_DASHBOARD_SPEC.md).
 * Typed consumer for the real, tested `GET /api/dashboard/doctor` endpoint
 * (`DashboardController::doctor()`). Field names below match that
 * controller's response exactly — this is the first strongly-typed consumer
 * of this endpoint; every previous caller (e.g. patient-cards.component.ts)
 * used `any` and read fields ad hoc. Nothing here invents a field the
 * backend doesn't actually return.
 */

export interface PatientUser {
  id: number;
  first_name?: string | null;
  last_name?: string | null;
  name?: string | null;
  email?: string | null;
  profile_image_url?: string | null;
}

export interface DashboardPatient {
  id: number;
  user_id: number;
  user?: PatientUser;
  age?: number | null;
  gender?: string | null;
  chronic_conditions?: string | null;
  medical_history?: string | null;
}

export type PatientRelationshipStatus = 'pending' | 'active' | 'accepted' | 'rejected' | 'ended';

export interface DashboardPatientRelationship {
  id: number;
  doctor_id: number;
  patient_id: number;
  status: PatientRelationshipStatus;
  requested_at: string | null;
  responded_at: string | null;
  ended_at: string | null;
  patient?: DashboardPatient;
}

export type AppointmentStatus = 'pending_payment' | 'pending' | 'confirmed' | 'cancelled' | 'completed';

export interface DashboardAppointment {
  id: number;
  patient_id: number;
  doctor_id: number;
  appointment_date: string;
  appointment_time: string;
  type: string | null;
  amount: number | string;
  payment_status: string;
  status: AppointmentStatus;
  notes: string | null;
  queue_status: QueueEntry['queue_status'];
  queue_position: number | null;
}

// Matches the actual `daily_statuses.risk_level` DB enum exactly (see
// DOCTOR_ERP_DISCOVERY.md §9 — a separate, narrower vocabulary exists in
// DailyStatus::rules()/isHighRisk() on the backend model itself; the
// dashboard endpoint has never used that mismatched vocabulary and this
// type intentionally mirrors the real column, not the model's own helpers).
export type RiskLevel = 'stable' | 'attention_needed' | 'moderate_risk' | 'high_risk';

export interface DashboardDailyStatus {
  id: number;
  patient_id: number;
  risk_level: RiskLevel;
  date: string | null;
  mood?: string | null;
  pain_level?: number | null;
  symptoms?: string | null;
  // Phase 4 reconciliation finding: `daily_statuses.medication_taken` is a
  // DB `boolean` column (tinyint), but DailyStatus::rules()/isHighRisk()
  // on the backend treat it as a 'yes'|'no'|'partial' string enum — the
  // same class of model/DB vocabulary mismatch already documented for
  // risk_level in DOCTOR_ERP_DISCOVERY.md, just not yet fixed there. Typed
  // honestly as unknown-shape rather than a clean boolean; this dashboard
  // does not read this field, so nothing here depends on it being fixed.
  medication_taken?: boolean | number | string | null;
  created_at: string;
}

export interface EmergencyAlertItem {
  id: number;
  patient_id: number;
  patient_name: string | null;
  severity: string;
  message: string | null;
  pain_level: number | null;
  symptoms: string | null;
  created_at: string;
}

export interface EmergencyAlertsSummary {
  count: number;
  items: EmergencyAlertItem[];
}

export interface RecentBooking {
  id: number;
  appointment_id: number;
  patient_name: string | null;
  action: string;
  notes: string | null;
  actor_name: string | null;
  created_at: string;
}

export interface PatientStatusDistribution {
  stable: number;
  attention: number;
  critical: number;
}

export interface RiskTrends {
  stable: number;
  attention_needed: number;
  high_risk: number;
}

export interface DoctorDashboardResponse {
  total_patients: number;
  total_assigned_patients: number;
  stable_patients: number;
  attention_patients: number;
  critical_patients: number;
  high_risk_patients: number;
  pending_follow_requests: number;
  reports_count: number;
  average_adherence: number;
  missed_medications: number;
  weekly_reports: number;
  weekly_patients: number[];
  patient_status_distribution: PatientStatusDistribution;
  appointments: DashboardAppointment[];
  recent_daily_statuses: DashboardDailyStatus[];
  risk_trends: RiskTrends;
  patients: DashboardPatientRelationship[];
  // Dashboard-spec gap #3 (DOCTOR_ERP_DASHBOARD_SPEC.md §10.6) — closed this
  // phase. Relationship-scoped (never clinic-membership-scoped) on the
  // backend; see DoctorDashboardAttentionCenterTest.
  active_emergency_alerts: EmergencyAlertsSummary;
  // Dashboard-spec gap #4 (§10.9) — closed this phase. Deliberately named
  // "recent_bookings," not "recent_activity": the only backing table
  // (AppointmentBookingLog) records exactly one action type ('created')
  // today. Never relabel this in the UI as a full activity feed.
  recent_bookings: RecentBooking[];
  today_appointments_count: number;
  today_completed_checkups: number;
  today_pending_appointments: number;
  // Dashboard-spec gap #1 (§10.4) — closed this phase.
  today_cancelled_count: number;
  total_consultations: number;
  monthly_consultations: number;
  cancelled_this_month: number;
  revenue_total: number;
  revenue_this_month: number;
  // Dashboard-spec gap #2 (§10.4) — closed this phase.
  revenue_today: number;
  average_revenue_per_visit: number;
  pending_payments_count: number;
  currency: string;
  weekly_revenue: number[];
}

/**
 * Client-side derivation of "my current patient / my next patient / how
 * many of my patients are waiting" from the shared, clinic-wide queue feed
 * (`GET /api/clinic/queue`) — a deliberate design decision from
 * DOCTOR_ERP_DASHBOARD_SPEC.md §4/§9.3: the queue is intentionally
 * clinic-scoped, not doctor-scoped, on the backend (a receptionist needs to
 * see every doctor's patients), so a doctor-specific view is derived here
 * rather than by asking the backend for a new, narrower endpoint it was
 * never designed to need. `QueueController::index()` already orders entries
 * by (in_service, called, waiting, null) then `queue_position` then
 * `appointment_time` and already excludes cancelled appointments and scopes
 * to today — this function does not re-implement any of that, it only
 * filters the already-correctly-ordered list down to one doctor's rows.
 */
export interface DoctorQueueView {
  currentPatient: QueueEntry | null;
  nextPatient: QueueEntry | null;
  waitingCount: number;
  noShowTodayCount: number;
  skippedTodayCount: number;
}

export function deriveDoctorQueueView(entries: QueueEntry[], doctorId: number): DoctorQueueView {
  const mine = entries.filter((entry) => entry.doctor_id === doctorId);

  const currentPatient = mine.find((entry) => entry.queue_status === 'in_service') ?? null;

  const waiting = mine
    .filter((entry) => entry.queue_status === 'waiting')
    .sort((a, b) => (a.queue_position ?? 0) - (b.queue_position ?? 0));

  return {
    currentPatient,
    nextPatient: waiting[0] ?? null,
    waitingCount: mine.filter((entry) => entry.queue_status === 'waiting' || entry.queue_status === 'called').length,
    noShowTodayCount: mine.filter((entry) => entry.queue_status === 'no_show').length,
    skippedTodayCount: mine.filter((entry) => entry.queue_status === 'skipped').length,
  };
}

/**
 * "N patients before you" for a given waiting entry, counting only this
 * doctor's own waiting/called patients ordered ahead of it by queue
 * position — never a fabricated minutes estimate (DOCTOR_ERP_DASHBOARD_SPEC.md
 * §25/§31: no per-patient average-consultation-duration data exists
 * anywhere in this codebase to make a minutes estimate defensible).
 */
export function patientsAheadOf(entries: QueueEntry[], doctorId: number, target: QueueEntry): number {
  const position = target.queue_position ?? 0;

  return entries.filter(
    (entry) =>
      entry.doctor_id === doctorId &&
      entry.id !== target.id &&
      (entry.queue_status === 'waiting' || entry.queue_status === 'called') &&
      (entry.queue_position ?? 0) < position
  ).length;
}

@Injectable({ providedIn: 'root' })
export class DoctorDashboardService {
  private readonly baseUrl = `${API_BASE_URL}/dashboard/doctor`;

  constructor(private http: HttpClient, private authService: AuthService) {}

  private get authHeaders() {
    return { Authorization: `Bearer ${this.authService.getToken()}` };
  }

  get(): Observable<ClinicApiEnvelope<DoctorDashboardResponse>> {
    return this.http.get<ClinicApiEnvelope<DoctorDashboardResponse>>(this.baseUrl, { headers: this.authHeaders });
  }
}
