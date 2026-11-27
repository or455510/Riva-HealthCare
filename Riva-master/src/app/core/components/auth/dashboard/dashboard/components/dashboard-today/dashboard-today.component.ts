import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { DashboardTodayStats } from '../dashboard-view-models';
import { formatCompactCurrency } from '../dashboard-format.util';

/**
 * Phase 6 (dashboard component split): Level 2 "Today". Strictly day-scoped
 * fields only — today_appointments_count, today_completed_checkups,
 * today_pending_appointments, today_cancelled_count, revenue_today — never
 * the month-scoped cancelled_this_month/revenue_this_month figures
 * relabeled as "today" (unchanged Phase 5 rule; those live in
 * dashboard-business instead).
 *
 * The 6 stat tiles stay a 2-column grid on a phone (not forced to 1 column)
 * — a dense grid of small numbers reads better as a compact 2x3 block than
 * as 6 full-width rows requiring 3x the scrolling for no readability gain.
 * The page-level layout is still single-column (see dashboard.component.html).
 */
@Component({
  selector: 'app-dashboard-today',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="rounded-[2.5rem] border border-sky-100 bg-white p-4 shadow-sm sm:p-6">
      <div class="mb-5 flex items-center justify-between">
        <h2 class="text-base font-bold text-slate-800">Today</h2>
      </div>

      <div *ngIf="isLoading" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div *ngFor="let i of [1, 2, 3, 4, 5, 6]" class="h-24 animate-pulse rounded-2xl bg-slate-100"></div>
      </div>

      <div *ngIf="!isLoading" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div class="rounded-2xl border border-sky-100 bg-sky-50/50 p-4">
          <div class="mb-3 flex h-8 w-8 items-center justify-center rounded-lg bg-sky-100 text-sky-600">
            <i class="fa-solid fa-calendar-day text-xs"></i>
          </div>
          <p class="text-2xl font-black leading-none text-slate-800">{{ stats.todayAppointments }}</p>
          <p class="mt-1.5 text-[11px] text-slate-400">Today's appointments</p>
          <p class="mt-1 text-[10px] font-bold text-sky-500">{{ stats.todayPending }} pending</p>
        </div>

        <div class="rounded-2xl border border-sky-100 bg-sky-50/50 p-4">
          <div class="mb-3 flex h-8 w-8 items-center justify-center rounded-lg bg-sky-100 text-sky-600">
            <i class="fa-solid fa-clipboard-check text-xs"></i>
          </div>
          <p class="text-2xl font-black leading-none text-slate-800">{{ stats.todayCheckups }}</p>
          <p class="mt-1.5 text-[11px] text-slate-400">Completed checkups</p>
        </div>

        <div class="rounded-2xl border border-orange-100 bg-orange-50/60 p-4">
          <div class="mb-3 flex h-8 w-8 items-center justify-center rounded-lg bg-orange-100 text-orange-500">
            <i class="fa-solid fa-user-clock text-xs"></i>
          </div>
          <p class="text-2xl font-black leading-none text-slate-800">{{ stats.noShowTodayCount }}</p>
          <p class="mt-1.5 text-[11px] text-slate-400">No-shows today</p>
        </div>

        <div class="rounded-2xl border border-rose-100 bg-rose-50/50 p-4">
          <div class="mb-3 flex h-8 w-8 items-center justify-center rounded-lg bg-rose-100 text-rose-500">
            <i class="fa-solid fa-calendar-xmark text-xs"></i>
          </div>
          <p class="text-2xl font-black leading-none text-slate-800">{{ stats.todayCancelled }}</p>
          <p class="mt-1.5 text-[11px] text-slate-400">Cancelled today</p>
        </div>

        <div class="rounded-2xl border border-emerald-100 bg-emerald-50/60 p-4">
          <div class="mb-3 flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600">
            <i class="fa-solid fa-sack-dollar text-xs"></i>
          </div>
          <p class="text-2xl font-black leading-none text-slate-800">{{ formatCurrency(stats.revenueToday) }}</p>
          <p class="mt-1.5 text-[11px] text-slate-400">Revenue today ({{ stats.currency }})</p>
        </div>

        <div class="rounded-2xl border border-sky-100 bg-sky-50/50 p-4">
          <div class="mb-3 flex h-8 w-8 items-center justify-center rounded-lg bg-sky-100 text-sky-600">
            <i class="fa-solid fa-stethoscope text-xs"></i>
          </div>
          <p class="text-2xl font-black leading-none text-slate-800">{{ stats.totalConsultations }}</p>
          <p class="mt-1.5 text-[11px] text-slate-400">Total consultations</p>
          <p class="mt-1 text-[10px] font-bold text-sky-500">{{ stats.monthlyConsultations }} this month</p>
        </div>
      </div>
    </div>
  `,
})
export class DashboardTodayComponent {
  @Input() isLoading = false;
  @Input() stats: DashboardTodayStats = {
    todayAppointments: 0,
    todayPending: 0,
    todayCheckups: 0,
    noShowTodayCount: 0,
    todayCancelled: 0,
    revenueToday: 0,
    currency: 'EGP',
    totalConsultations: 0,
    monthlyConsultations: 0,
  };

  formatCurrency(value: number): string {
    return formatCompactCurrency(value);
  }
}
