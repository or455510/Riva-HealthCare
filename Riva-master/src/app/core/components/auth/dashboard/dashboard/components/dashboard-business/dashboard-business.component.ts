import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { NgApexchartsModule } from 'ng-apexcharts';
import { DashboardBusinessStats } from '../dashboard-view-models';
import { formatCompactCurrency } from '../dashboard-format.util';

/**
 * Phase 6 (dashboard component split): Level 5 "Business". Month/all-time
 * revenue figures live here, never relabeled as "today" (that's
 * dashboard-today's job) — unchanged Phase 5 rule. The Weekly Revenue chart
 * (previously its own card, orphaned outside any grid after the Phase 5
 * layout fix) now lives inside this same Business card instead of floating
 * separately — it's the same Level 5 data, so grouping it here removes a
 * card boundary that was drawing an arbitrary line through one concept.
 * "Manage services"/"Clinic team" stay real, existing routes
 * (/clinic-services, /clinic-staff) — no invented navigation.
 */
@Component({
  selector: 'app-dashboard-business',
  standalone: true,
  imports: [CommonModule, RouterModule, NgApexchartsModule],
  template: `
    <div class="rounded-[2.5rem] border border-sky-100 bg-white p-4 shadow-sm sm:p-6">
      <div class="mb-5 flex items-center justify-between">
        <h2 class="text-base font-bold text-slate-800">Business</h2>
      </div>

      <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-2xl border border-emerald-100 bg-emerald-50/60 p-4">
          <p class="text-2xl font-black leading-none text-slate-800">{{ formatCurrency(stats.revenueThisMonth) }}</p>
          <p class="mt-1.5 text-[11px] text-slate-400">Revenue this month</p>
        </div>
        <div class="rounded-2xl border border-emerald-100 bg-emerald-50/60 p-4">
          <p class="text-2xl font-black leading-none text-slate-800">{{ formatCurrency(stats.revenueTotal) }}</p>
          <p class="mt-1.5 text-[11px] text-slate-400">Revenue total</p>
        </div>
        <div class="rounded-2xl border border-sky-100 bg-sky-50/50 p-4">
          <p class="text-2xl font-black leading-none text-slate-800">{{ formatCurrency(stats.avgRevenuePerVisit) }}</p>
          <p class="mt-1.5 text-[11px] text-slate-400">Avg. revenue / visit</p>
        </div>
        <div class="rounded-2xl border border-orange-100 bg-orange-50/60 p-4">
          <p class="text-2xl font-black leading-none text-slate-800">{{ stats.pendingPayments }}</p>
          <p class="mt-1.5 text-[11px] text-slate-400">Pending payments</p>
        </div>
      </div>

      <div class="mb-5 flex flex-wrap gap-3">
        <a
          *ngIf="!isLoadingPermissions && canManageServices"
          routerLink="/clinic-services"
          class="rounded-xl border border-sky-100 bg-white px-4 py-2.5 text-xs font-black text-sky-600 transition-colors hover:bg-sky-50"
        >
          Manage services →
        </a>
        <a
          routerLink="/clinic-staff"
          class="rounded-xl border border-sky-100 bg-white px-4 py-2.5 text-xs font-black text-sky-600 transition-colors hover:bg-sky-50"
        >
          Clinic team →
        </a>
      </div>

      <div class="rounded-[2rem] border border-sky-100 bg-white p-4 sm:p-6">
        <h2 class="mb-4 text-center text-sm font-black uppercase tracking-widest text-slate-800">Weekly Revenue</h2>
        <div *ngIf="!hasRevenueAnalytics" class="flex h-[280px] flex-col items-center justify-center text-sm text-slate-400">
          <i class="fa-solid fa-chart-line mb-3 text-3xl text-slate-200"></i>
          No revenue data available yet.
        </div>
        <apx-chart
          *ngIf="hasRevenueAnalytics"
          [series]="revenueOptions.series"
          [chart]="revenueOptions.chart"
          [xaxis]="revenueOptions.xaxis"
          [stroke]="revenueOptions.stroke"
          [colors]="revenueOptions.colors"
          [dataLabels]="revenueOptions.dataLabels"
        ></apx-chart>
      </div>
    </div>
  `,
})
export class DashboardBusinessComponent {
  @Input() stats: DashboardBusinessStats = {
    revenueThisMonth: 0,
    revenueTotal: 0,
    avgRevenuePerVisit: 0,
    pendingPayments: 0,
  };
  @Input() canManageServices = false;
  @Input() isLoadingPermissions = true;
  @Input() hasRevenueAnalytics = false;
  // Loosely typed to match ng-apexcharts' own chart-config shape — see the
  // same justification in dashboard-my-patients.component.ts.
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  @Input() revenueOptions: any;

  formatCurrency(value: number): string {
    return formatCompactCurrency(value);
  }
}
