import { CommonModule } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { RouterModule } from '@angular/router';
import { AdminService } from './admin.service';

interface MetricCard {
  label: string;
  value: number;
  icon: string;
  tone: 'blue' | 'cyan' | 'green' | 'amber' | 'red';
  tag: string;
  route: string | null;
}

/**
 * Platform admin landing screen. Every metric, chart series, and recent-item
 * list below is read straight from AdminAreaController::overview() — no
 * fabricated numbers or invented trend history. Cards that map 1:1 onto a
 * real admin section (see admin-sidebar routes) are clickable; the two that
 * have no dedicated backend view (missed medications, active doctors list)
 * stay static rather than link to a page that doesn't exist for them.
 */
@Component({
  selector: 'app-admin-overview',
  standalone: true,
  imports: [CommonModule, RouterModule],
  template: `
    <div class="flex flex-col gap-6">
      <!-- Page header -->
      <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p class="text-xs font-bold uppercase tracking-wider text-blue-600">Platform Admin</p>
          <h1 class="mt-1 text-2xl font-bold text-[#1e293b] sm:text-3xl">Admin Overview</h1>
          <p class="mt-1 text-sm text-slate-500">
            Monitor patient safety, clinical operations, verification queues, and platform communication from one workspace.
          </p>
        </div>

        <div *ngIf="!loading && !error" class="flex gap-3">
          <div
            class="min-w-[110px] rounded-2xl border px-4 py-3 text-center shadow-sm"
            [ngClass]="metricValue('active_emergency_alerts') > 0 ? 'border-red-200 bg-red-50' : 'border-slate-200 bg-white'"
          >
            <p class="text-xl font-bold" [ngClass]="metricValue('active_emergency_alerts') > 0 ? 'text-red-600' : 'text-[#1e293b]'">
              {{ metricValue('active_emergency_alerts') }}
            </p>
            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Active Alerts</p>
          </div>
          <div class="min-w-[110px] rounded-2xl border border-slate-200 bg-white px-4 py-3 text-center shadow-sm">
            <p class="text-xl font-bold text-[#1e293b]">{{ metricValue('total_users') }}</p>
            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Total Users</p>
          </div>
          <div class="min-w-[110px] rounded-2xl border border-slate-200 bg-white px-4 py-3 text-center shadow-sm">
            <p class="text-xl font-bold text-emerald-600">{{ metricValue('active_doctors') }}</p>
            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Active Doctors</p>
          </div>
        </div>
      </div>

      <!-- Loading State -->
      <div *ngIf="loading" class="flex h-64 items-center justify-center">
        <div class="h-10 w-10 animate-spin rounded-full border-4 border-blue-200 border-t-blue-600"></div>
      </div>

      <!-- Error State -->
      <div *ngIf="!loading && error" class="rounded-[24px] border border-red-100 bg-white p-8 text-center shadow-sm">
        <i class="fa-solid fa-triangle-exclamation mb-3 text-3xl text-red-400"></i>
        <p class="mb-1 font-semibold text-[#1e293b]">Couldn't load the admin overview</p>
        <p class="text-sm text-slate-500">{{ error }}</p>
        <button
          (click)="loadOverview()"
          class="mt-4 rounded-xl bg-[#1d4ed8] px-6 py-2 font-bold text-white transition-all hover:bg-blue-700"
        >
          Try again
        </button>
      </div>

      <ng-container *ngIf="!loading && !error">
        <!-- Metric grid -->
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
          <a
            *ngFor="let card of metricCards"
            [routerLink]="card.route"
            class="group flex flex-col gap-3 rounded-[20px] border border-slate-200 bg-white p-5 shadow-sm transition-shadow"
            [ngClass]="card.route ? 'cursor-pointer hover:shadow-md' : 'cursor-default'"
          >
            <div class="flex items-start justify-between gap-2">
              <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl" [ngClass]="toneBg(card.tone)">
                <i [class]="card.icon"></i>
              </span>
              <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                {{ card.tag }}
              </span>
            </div>
            <div>
              <p class="text-2xl font-bold text-[#1e293b]">{{ card.value }}</p>
              <p class="text-xs font-semibold text-slate-500">{{ card.label }}</p>
            </div>
            <span *ngIf="card.route" class="text-[11px] font-bold text-blue-600 opacity-0 transition-opacity group-hover:opacity-100">
              View details <i class="fa-solid fa-arrow-right ml-0.5"></i>
            </span>
          </a>
        </div>

        <!-- Charts -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
          <article *ngFor="let chart of chartCards" class="rounded-[20px] border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="mb-4 flex items-start justify-between gap-2">
              <div>
                <p class="text-[11px] font-bold uppercase tracking-wider text-blue-600">{{ chart.kicker }}</p>
                <h3 class="mt-0.5 text-base font-bold text-[#1e293b]">{{ chart.title }}</h3>
              </div>
              <strong class="text-xl font-bold text-[#1e293b]">{{ chart.total }}</strong>
            </div>
            <div class="flex h-32 items-end gap-2 border-b border-slate-100">
              <div
                *ngFor="let point of chart.points"
                class="flex-1 rounded-t-full bg-gradient-to-t from-blue-700 to-sky-400 transition-all"
                [style.height.%]="barHeight(point.total, chart.max)"
                [title]="point.day + ': ' + point.total"
              ></div>
              <p *ngIf="!chart.points.length" class="pb-2 text-xs font-semibold text-slate-400">No data yet</p>
            </div>
          </article>
        </div>

        <!-- Operations panels -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
          <!-- Emergency Center -->
          <article class="flex flex-col rounded-[20px] border border-red-100 bg-white p-5 shadow-sm sm:p-6 lg:col-span-1">
            <div class="mb-4 flex items-start justify-between gap-2">
              <div>
                <p class="text-[11px] font-bold uppercase tracking-wider text-red-600">Safety command</p>
                <h3 class="mt-0.5 text-base font-bold text-[#1e293b]">Emergency Center</h3>
              </div>
              <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-red-50 text-red-500">
                <i class="fa-solid fa-triangle-exclamation"></i>
              </span>
            </div>

            <div *ngIf="data?.recent?.emergencies?.length; else noEmergencies" class="flex flex-col divide-y divide-slate-100">
              <div *ngFor="let item of data?.recent?.emergencies" class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                <span class="h-2.5 w-2.5 shrink-0 rounded-full bg-red-500"></span>
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-semibold text-[#1e293b]">{{ item.patient?.user?.name || 'Patient' }}</p>
                  <p class="truncate text-xs text-slate-500">{{ item.severity || 'critical' }} &middot; {{ item.status || 'active' }}</p>
                </div>
                <a
                  routerLink="/admin/emergencies"
                  class="shrink-0 rounded-lg bg-red-50 px-3 py-1.5 text-xs font-bold text-red-700 hover:bg-red-100"
                >
                  View
                </a>
              </div>
            </div>
            <ng-template #noEmergencies>
              <div class="flex flex-1 flex-col items-center justify-center py-8 text-center">
                <i class="fa-solid fa-shield-heart mb-3 text-4xl text-slate-200"></i>
                <p class="text-sm font-semibold text-slate-400">No active emergency alerts</p>
              </div>
            </ng-template>

            <a
              routerLink="/admin/emergencies"
              class="mt-4 pt-3 text-center text-xs font-bold text-blue-600 hover:text-blue-700 border-t border-slate-100"
            >
              View all emergencies <i class="fa-solid fa-arrow-right ml-0.5"></i>
            </a>
          </article>

          <!-- Contact Queue -->
          <article class="flex flex-col rounded-[20px] border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="mb-4 flex items-start justify-between gap-2">
              <div>
                <p class="text-[11px] font-bold uppercase tracking-wider text-blue-600">Inbox</p>
                <h3 class="mt-0.5 text-base font-bold text-[#1e293b]">Contact Queue</h3>
              </div>
              <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-blue-50 text-blue-600">
                <i class="fa-solid fa-envelope-open-text"></i>
              </span>
            </div>

            <div *ngIf="data?.recent?.contacts?.length; else noContacts" class="flex flex-col divide-y divide-slate-100">
              <div *ngFor="let item of data?.recent?.contacts" class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                <span class="h-2.5 w-2.5 shrink-0 rounded-full bg-sky-400"></span>
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-semibold text-[#1e293b]">{{ item.full_name }}</p>
                  <p class="truncate text-xs text-slate-500">{{ item.subject }}</p>
                </div>
              </div>
            </div>
            <ng-template #noContacts>
              <div class="flex flex-1 flex-col items-center justify-center py-8 text-center">
                <i class="fa-solid fa-inbox mb-3 text-4xl text-slate-200"></i>
                <p class="text-sm font-semibold text-slate-400">No contact messages waiting</p>
              </div>
            </ng-template>

            <a
              routerLink="/admin/contact-messages"
              class="mt-4 pt-3 text-center text-xs font-bold text-blue-600 hover:text-blue-700 border-t border-slate-100"
            >
              View all messages <i class="fa-solid fa-arrow-right ml-0.5"></i>
            </a>
          </article>

          <!-- Activity Stream -->
          <article class="flex flex-col rounded-[20px] border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="mb-4 flex items-start justify-between gap-2">
              <div>
                <p class="text-[11px] font-bold uppercase tracking-wider text-blue-600">Audit</p>
                <h3 class="mt-0.5 text-base font-bold text-[#1e293b]">Activity Stream</h3>
              </div>
              <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-blue-50 text-blue-600">
                <i class="fa-solid fa-list-check"></i>
              </span>
            </div>

            <div *ngIf="data?.recent?.activity?.length; else noActivity" class="flex flex-col divide-y divide-slate-100">
              <div *ngFor="let item of data?.recent?.activity" class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                <span class="h-2.5 w-2.5 shrink-0 rounded-full bg-emerald-500"></span>
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-semibold text-[#1e293b]">{{ item.action }}</p>
                  <p class="truncate text-xs text-slate-500">{{ item.user?.name || 'System' }}</p>
                </div>
              </div>
            </div>
            <ng-template #noActivity>
              <div class="flex flex-1 flex-col items-center justify-center py-8 text-center">
                <i class="fa-solid fa-clipboard-list mb-3 text-4xl text-slate-200"></i>
                <p class="text-sm font-semibold text-slate-400">No platform activity recorded</p>
              </div>
            </ng-template>

            <a
              routerLink="/admin/activity-logs"
              class="mt-4 pt-3 text-center text-xs font-bold text-blue-600 hover:text-blue-700 border-t border-slate-100"
            >
              View full log <i class="fa-solid fa-arrow-right ml-0.5"></i>
            </a>
          </article>
        </div>
      </ng-container>
    </div>
  `,
})
export class AdminOverviewComponent implements OnInit {
  data: any;
  loading = true;
  error = '';

  constructor(private admin: AdminService) {}

  ngOnInit(): void {
    this.loadOverview();
  }

  loadOverview(): void {
    this.loading = true;
    this.error = '';
    this.admin.get('overview').subscribe({
      next: (res) => {
        this.data = res.data;
        this.loading = false;
      },
      error: (err) => {
        this.error = err?.error?.message || 'Unable to load admin overview.';
        this.loading = false;
      },
    });
  }

  // Cards that map onto a real admin-sidebar route are clickable (route set);
  // the two with no dedicated backend view stay as plain, non-linking tiles.
  get metricCards(): MetricCard[] {
    const m = this.data?.metrics || {};
    return [
      { label: 'Active Emergencies', value: m.active_emergency_alerts ?? 0, icon: 'fa-solid fa-triangle-exclamation', tone: 'red', tag: 'urgent', route: '/admin/emergencies' },
      { label: 'Pending Doctor Verifications', value: m.pending_doctor_verifications ?? 0, icon: 'fa-solid fa-user-shield', tone: 'amber', tag: 'review', route: '/admin/doctor-verification' },
      { label: 'High Risk Patients', value: m.high_risk_patients ?? 0, icon: 'fa-solid fa-heart-pulse', tone: 'red', tag: 'clinical', route: '/admin/patient-monitoring' },
      { label: 'Missed Medications Today', value: m.missed_medications_today ?? 0, icon: 'fa-solid fa-pills', tone: 'amber', tag: 'today', route: null },
      { label: 'Today Appointments', value: m.today_appointments ?? 0, icon: 'fa-solid fa-calendar-day', tone: 'blue', tag: 'scheduled', route: '/admin/appointments' },
      { label: 'Unread Contacts', value: m.unread_contact_messages ?? 0, icon: 'fa-solid fa-envelope', tone: 'cyan', tag: 'inbox', route: '/admin/contact-messages' },
      { label: 'Total Users', value: m.total_users ?? 0, icon: 'fa-solid fa-users', tone: 'blue', tag: 'platform', route: '/admin/users' },
      { label: 'Active Doctors', value: m.active_doctors ?? 0, icon: 'fa-solid fa-user-doctor', tone: 'green', tag: 'verified', route: null },
    ];
  }

  get chartCards() {
    const charts = this.data?.charts || {};
    return [
      this.chart('User Growth', 'Registrations', charts.registrations || []),
      this.chart('Emergency Activity', 'Safety alerts', charts.alerts || []),
      this.chart('Appointment Demand', 'Care operations', charts.appointments || []),
    ];
  }

  metricValue(key: string): number {
    return Number(this.data?.metrics?.[key] || 0);
  }

  toneBg(tone: MetricCard['tone']): string {
    switch (tone) {
      case 'red': return 'bg-red-50 text-red-600';
      case 'amber': return 'bg-amber-50 text-amber-600';
      case 'green': return 'bg-emerald-50 text-emerald-600';
      case 'cyan': return 'bg-cyan-50 text-cyan-600';
      default: return 'bg-blue-50 text-blue-600';
    }
  }

  chart(title: string, kicker: string, points: any[]) {
    const total = points.reduce((sum, point) => sum + Number(point.total || 0), 0);
    const max = Math.max(1, ...points.map(point => Number(point.total || 0)));
    return { title, kicker, points, total, max };
  }

  barHeight(value: number, max: number): number {
    return Math.max(6, (Number(value || 0) / Math.max(1, max)) * 100);
  }
}
