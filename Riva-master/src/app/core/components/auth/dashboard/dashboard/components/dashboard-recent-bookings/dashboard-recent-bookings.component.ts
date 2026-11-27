import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { RecentBooking } from '../../../../../../../service/doctor-dashboard.service';

/**
 * Phase 6 (dashboard component split): Level 6. Exact terminology preserved
 * — "Recent bookings", never "Recent activity" — because the only backing
 * table (AppointmentBookingLog) records exactly one action type ('created')
 * today; calling this an activity feed would overstate what it actually is.
 * Display-only, matching Phase 5: no navigation per row, just the existing
 * "Notifications →" link (a real route, not an invented in-dashboard
 * notification feed).
 */
@Component({
  selector: 'app-dashboard-recent-bookings',
  standalone: true,
  imports: [CommonModule, RouterModule],
  template: `
    <div class="rounded-[2.5rem] border border-sky-100 bg-white p-4 shadow-sm sm:p-6">
      <div class="mb-5 flex items-center justify-between">
        <h2 class="text-base font-bold text-slate-800">Recent bookings</h2>
        <a routerLink="/notifications" class="text-xs font-bold text-sky-500 hover:text-sky-600">Notifications →</a>
      </div>

      <div *ngIf="isLoading" class="space-y-3">
        <div *ngFor="let i of [1, 2]" class="h-12 animate-pulse rounded-2xl bg-slate-100"></div>
      </div>

      <div *ngIf="!isLoading && bookings.length === 0" data-testid="recent-bookings-empty" class="py-6 text-center text-sm text-slate-400">
        No recent bookings
      </div>

      <div *ngIf="!isLoading && bookings.length > 0" class="space-y-2">
        <div
          *ngFor="let booking of bookings"
          class="flex flex-col gap-1 rounded-2xl border border-sky-100 bg-sky-50/50 p-3 sm:flex-row sm:items-center sm:justify-between sm:gap-3"
        >
          <div class="min-w-0">
            <p class="truncate text-sm font-bold text-slate-800">{{ booking.patient_name || 'Patient' }}</p>
            <p class="truncate text-xs text-slate-400">
              {{ booking.action }}<span *ngIf="booking.actor_name"> · by {{ booking.actor_name }}</span>
            </p>
          </div>
          <p class="shrink-0 text-[10px] text-slate-400">{{ booking.created_at | date: 'd MMM, HH:mm' }}</p>
        </div>
      </div>
    </div>
  `,
})
export class DashboardRecentBookingsComponent {
  @Input() isLoading = false;
  @Input() bookings: RecentBooking[] = [];
}
