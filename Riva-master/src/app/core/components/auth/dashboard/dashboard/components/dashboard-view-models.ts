import { DashboardDailyStatus } from '../../../../../../service/doctor-dashboard.service';

/**
 * Phase 6 (dashboard component split): typed view-models the parent
 * dashboard.component.ts builds from the real backend response and passes
 * down as @Input()s. Splitting these out of `any` (the Phase 5 shape) makes
 * every split-out component's contract explicit and checkable — no field
 * here is invented; each one is a real backend field, or a real backend
 * field composed with client-derived display data (e.g. `name` resolved
 * from the doctor's own `patients` relationship list) exactly as Phase 5
 * already computed it.
 */

/** One "Attention — Review Required" row: a real `recent_daily_statuses`
 * entry (unchanged, backend risk ordering preserved) plus the display name
 * resolved client-side from the doctor's own patients list — the same
 * `patientName()` lookup Phase 5 already did, now precomputed once by the
 * parent instead of being called per-render from inside the template. */
export interface ReviewItemView extends DashboardDailyStatus {
  patientDisplayName: string;
}

/** One row of the "My Patients" list — unchanged shape from Phase 5. */
export interface MyPatientView {
  id: number | undefined;
  userId: number | undefined;
  name: string;
  img: string;
  condition: string;
  /** Display label from riskLabel() — 'Critical' | 'Attention' | 'Stable' | 'No data'. */
  status: string;
  riskRank: number;
}

/** One pending "Patient Follow Requests" row — pre-existing (pre-Phase-5)
 * feature, unchanged shape, now typed instead of `any`. */
export interface FollowRequestView {
  id: number;
  name: string;
  avatar: string;
  condition: string;
  requestedAt: string;
  isProcessing: boolean;
}

/** Level 2 — Today card data, grouped as one object instead of nine
 * separate @Input()s. Field names are intentionally NOT the raw backend
 * names (today_appointments_count, etc.) — this is the same
 * `clinicStats`/day-scoped subset dashboard.component.ts already built in
 * Phase 5, just given a narrower, explicit type for this one card. */
export interface DashboardTodayStats {
  todayAppointments: number;
  todayPending: number;
  todayCheckups: number;
  noShowTodayCount: number;
  todayCancelled: number;
  revenueToday: number;
  currency: string;
  totalConsultations: number;
  monthlyConsultations: number;
}

/** Level 5 — Business card data. */
export interface DashboardBusinessStats {
  revenueThisMonth: number;
  revenueTotal: number;
  avgRevenuePerVisit: number;
  pendingPayments: number;
}
