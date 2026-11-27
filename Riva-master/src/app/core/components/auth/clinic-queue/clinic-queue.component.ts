import { CommonModule } from '@angular/common';
import { Component, OnDestroy, OnInit } from '@angular/core';
import { HttpClientModule } from '@angular/common/http';
import { RouterModule } from '@angular/router';
import { finalize, Subscription } from 'rxjs';
import { ClinicContextEntry, ClinicService } from '../../../../service/clinic.service';
import { QueueEntry, QueueService } from '../../../../service/queue.service';
import { SidebarComponent } from '../../../../components/sidebar';

/**
 * Vertical slice (Phase C, principal-engineering directive): the first
 * Angular screen connected to the real, tested QueueController. This is a
 * front-desk operational view — today's checked-in-or-checkable
 * appointments for the current clinic, with real state-machine actions
 * (check in, call, start, complete, no-show, skip, remove). Every action
 * calls the real backend and reflects its real response, including real
 * 403s when the signed-in user's clinic role lacks queue.manage — the
 * button-hiding below is a UX convenience derived from the same
 * ClinicUser::hasPermission() rule the backend enforces (via
 * ClinicService.myContext()), never the actual security boundary.
 *
 * RIVA-125 (visual redesign, pass 2): restyled to match the clinic-staff /
 * sidebar design system exactly (bg-[#f7f8fb] canvas, blue-600 eyebrow +
 * h1 header, rounded-2xl stat tiles, rounded-[20px] cards). The status
 * counters and the payment/type/timestamp fields added to each card are all
 * read directly from the same QueueEntry[] this component already fetches —
 * no new endpoint calls, no fabricated numbers. Every existing (click)
 * handler and action is unchanged.
 */
@Component({
  selector: 'app-clinic-queue',
  standalone: true,
  imports: [CommonModule, HttpClientModule, RouterModule, SidebarComponent],
  template: `
    <div class="flex min-h-screen bg-[#f7f8fb] font-sans">
      <app-sidebar></app-sidebar>

      <main class="flex-1 p-4 sm:p-8 md:pl-28">
        <div class="mx-auto flex max-w-6xl flex-col gap-6">
          <!-- Page header -->
          <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <p class="text-xs font-bold uppercase tracking-wider text-blue-600">Clinic</p>
              <h1 class="mt-1 text-2xl font-bold text-[#1e293b] sm:text-3xl">Today's Queue</h1>
              <p class="mt-1 text-sm text-slate-500">
                {{ today | date: 'fullDate' }} &middot;
                {{ entries.length === 0 ? 'No appointments yet' : entries.length + ' appointment' + (entries.length === 1 ? '' : 's') + ' today' }}
              </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
              <ng-container *ngIf="!isLoading && !loadError && entries.length > 0">
                <div class="min-w-[76px] rounded-2xl border border-slate-200 bg-white px-3.5 py-2.5 text-center shadow-sm">
                  <p class="text-lg font-bold text-blue-600">{{ waitingCount }}</p>
                  <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Waiting</p>
                </div>
                <div class="min-w-[76px] rounded-2xl border border-slate-200 bg-white px-3.5 py-2.5 text-center shadow-sm">
                  <p class="text-lg font-bold text-amber-500">{{ calledCount }}</p>
                  <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Called</p>
                </div>
                <div class="min-w-[76px] rounded-2xl border border-slate-200 bg-white px-3.5 py-2.5 text-center shadow-sm">
                  <p class="text-lg font-bold text-purple-600">{{ inServiceCount }}</p>
                  <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">In Room</p>
                </div>
                <div class="min-w-[76px] rounded-2xl border border-slate-200 bg-white px-3.5 py-2.5 text-center shadow-sm">
                  <p class="text-lg font-bold text-emerald-600">{{ completedCount }}</p>
                  <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Done</p>
                </div>
              </ng-container>

              <button
                (click)="loadQueue()"
                [disabled]="isLoading"
                class="self-start rounded-xl border border-slate-200 bg-white px-4 py-2 font-semibold text-[#1e293b] transition-all hover:bg-slate-50 disabled:opacity-50 sm:self-auto"
              >
                <i class="fa-solid fa-rotate-right mr-2" [class.fa-spin]="isLoading"></i>Refresh
              </button>
            </div>
          </div>

          <!-- Loading State -->
          <div *ngIf="isLoading" class="flex h-64 items-center justify-center">
            <div class="h-10 w-10 animate-spin rounded-full border-4 border-blue-200 border-t-blue-600"></div>
          </div>

          <!-- Error State -->
          <div
            *ngIf="!isLoading && loadError"
            class="rounded-[24px] border border-red-100 bg-white p-8 text-center shadow-sm"
          >
            <i class="fa-solid fa-triangle-exclamation mb-3 text-3xl text-red-400"></i>
            <p class="mb-1 font-semibold text-[#1e293b]">Couldn't load today's queue</p>
            <p class="text-sm text-slate-500">{{ loadError }}</p>
            <button
              (click)="loadQueue()"
              class="mt-4 rounded-xl bg-[#1d4ed8] px-6 py-2 font-bold text-white transition-all hover:bg-blue-700"
            >
              Try again
            </button>
          </div>

          <div *ngIf="!isLoading && !loadError" class="flex flex-col gap-6">
            <!-- Empty State -->
            <div
              *ngIf="entries.length === 0"
              class="flex flex-col items-center rounded-[24px] border border-slate-200 bg-white p-16 text-slate-400 shadow-sm"
            >
              <i class="fa-solid fa-calendar-day mb-4 text-5xl text-slate-200"></i>
              <p class="text-lg font-semibold">No appointments today</p>
              <p class="mt-1 text-sm text-slate-400">Checked-in patients for today will appear here.</p>
            </div>

            <!-- Queue Cards -->
            <div *ngIf="entries.length > 0" class="grid grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-3">
              <div
                *ngFor="let entry of entries"
                class="flex flex-col gap-4 rounded-[20px] border border-slate-200 bg-white p-5 shadow-sm transition-shadow hover:shadow-md"
              >
                <div class="flex items-start justify-between gap-3">
                  <!--
                    RIVA-130 follow-up (product-owner feedback, 2026-08-25):
                    "why all this detour" — to see reports, scan a
                    prescription, or call the patient, the doctor had to
                    leave this page, open the patient list, search for the
                    patient, then open their profile. The whole card (not
                    just a small icon) now links straight to
                    /profile-p/:patient_id, the same patient-profile route
                    the patient list itself already links to.
                  -->
                  <a
                    [routerLink]="['/profile-p', entry.patient_id]"
                    class="group flex min-w-0 items-center gap-3 rounded-xl -m-1 p-1 transition-colors hover:bg-slate-50"
                    title="Open {{ entry.patient_name || 'this patient' }}'s profile"
                  >
                    <div
                      class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-gradient-to-br from-slate-100 to-slate-200 text-sm font-bold text-slate-500"
                    >
                      {{ patientInitials(entry) }}
                    </div>
                    <div class="min-w-0">
                      <p class="truncate font-semibold text-[#1e293b] group-hover:text-blue-600 group-hover:underline">
                        {{ entry.patient_name || 'Unnamed patient' }}
                        <i class="fa-solid fa-arrow-up-right-from-square ml-1 text-[10px] text-slate-300 group-hover:text-blue-400"></i>
                      </p>
                      <p class="truncate text-sm text-slate-500">Dr. {{ entry.doctor_name || '—' }}</p>
                    </div>
                  </a>
                  <div class="flex shrink-0 flex-col items-end gap-1">
                    <span
                      class="rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wider"
                      [ngClass]="queueBadgeClass(entry.queue_status)"
                    >
                      {{ entry.queue_status || 'not checked in' }}
                    </span>
                    <span
                      class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider"
                      [ngClass]="paymentBadgeClass(entry.payment_status)"
                    >
                      {{ entry.payment_status }}
                    </span>
                  </div>
                </div>

                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-500">
                  <span><i class="fa-regular fa-clock mr-1"></i>{{ entry.appointment_time }}</span>
                  <span *ngIf="entry.queue_position">
                    <i class="fa-solid fa-hashtag mr-1"></i>{{ entry.queue_position }} in line
                  </span>
                  <span *ngIf="entry.type" class="capitalize"><i class="fa-regular fa-note-sticky mr-1"></i>{{ entry.type }}</span>
                </div>

                <div
                  *ngIf="entry.checked_in_at || entry.called_at || entry.queue_completed_at"
                  class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-400"
                >
                  <span *ngIf="entry.checked_in_at">Checked in {{ entry.checked_in_at | date: 'shortTime' }}</span>
                  <span *ngIf="entry.called_at">&middot; Called {{ entry.called_at | date: 'shortTime' }}</span>
                  <span *ngIf="entry.queue_completed_at">&middot; Closed {{ entry.queue_completed_at | date: 'shortTime' }}</span>
                </div>

                <p *ngIf="rowError[entry.id]" class="text-sm text-red-500">{{ rowError[entry.id] }}</p>

                <div *ngIf="canManageQueue" class="flex flex-wrap gap-2 border-t border-slate-100 pt-3">
                  <button
                    *ngIf="!entry.queue_status || entry.queue_status === 'no_show' || entry.queue_status === 'skipped'"
                    (click)="run(entry, 'checkIn')"
                    [disabled]="busyId === entry.id"
                    class="rounded-lg bg-blue-50 px-3 py-1.5 text-sm font-semibold text-blue-700 transition-colors hover:bg-blue-100 disabled:opacity-50"
                  >
                    Check in
                  </button>

                  <button
                    *ngIf="entry.queue_status === 'waiting'"
                    (click)="run(entry, 'call')"
                    [disabled]="busyId === entry.id"
                    class="rounded-lg bg-amber-50 px-3 py-1.5 text-sm font-semibold text-amber-700 transition-colors hover:bg-amber-100 disabled:opacity-50"
                  >
                    Call
                  </button>

                  <button
                    *ngIf="entry.queue_status === 'waiting' || entry.queue_status === 'called'"
                    (click)="run(entry, 'start')"
                    [disabled]="busyId === entry.id"
                    class="rounded-lg bg-purple-50 px-3 py-1.5 text-sm font-semibold text-purple-700 transition-colors hover:bg-purple-100 disabled:opacity-50"
                  >
                    Start
                  </button>

                  <button
                    *ngIf="entry.queue_status === 'called' || entry.queue_status === 'in_service'"
                    (click)="run(entry, 'complete')"
                    [disabled]="busyId === entry.id"
                    class="rounded-lg bg-green-50 px-3 py-1.5 text-sm font-semibold text-green-700 transition-colors hover:bg-green-100 disabled:opacity-50"
                  >
                    Complete
                  </button>

                  <button
                    *ngIf="entry.queue_status === 'waiting' || entry.queue_status === 'called'"
                    (click)="run(entry, 'skip')"
                    [disabled]="busyId === entry.id"
                    class="rounded-lg bg-gray-100 px-3 py-1.5 text-sm font-semibold text-gray-600 transition-colors hover:bg-gray-200 disabled:opacity-50"
                  >
                    Skip
                  </button>

                  <button
                    *ngIf="entry.queue_status === 'waiting' || entry.queue_status === 'called'"
                    (click)="run(entry, 'noShow')"
                    [disabled]="busyId === entry.id"
                    class="rounded-lg bg-red-50 px-3 py-1.5 text-sm font-semibold text-red-600 transition-colors hover:bg-red-100 disabled:opacity-50"
                  >
                    No-show
                  </button>

                  <button
                    *ngIf="entry.queue_status"
                    (click)="run(entry, 'remove')"
                    [disabled]="busyId === entry.id"
                    class="ml-auto rounded-lg bg-gray-50 px-3 py-1.5 text-sm font-semibold text-gray-500 transition-colors hover:bg-gray-100 disabled:opacity-50"
                  >
                    Remove
                  </button>
                </div>

                <p *ngIf="!canManageQueue" class="border-t border-slate-100 pt-3 text-xs text-slate-400">
                  View only — you don't have queue management permission for this clinic.
                </p>
              </div>
            </div>
          </div>
        </div>
      </main>
    </div>
  `,
})
export class ClinicQueueComponent implements OnInit, OnDestroy {
  entries: QueueEntry[] = [];
  isLoading = true;
  loadError = '';
  busyId: number | null = null;
  rowError: Record<number, string> = {};
  canManageQueue = false;
  readonly today = new Date();

  private contextSub?: Subscription;

  constructor(private queueService: QueueService, private clinicService: ClinicService) {}

  ngOnInit(): void {
    this.loadQueue();
    this.loadContext();
  }

  ngOnDestroy(): void {
    this.contextSub?.unsubscribe();
  }

  // RIVA-125 (visual redesign, pass 2): purely presentational counters
  // derived from the same real `entries` array already fetched from
  // QueueController::index() — no separate fetch, no fabricated numbers.
  get waitingCount(): number {
    return this.entries.filter((e) => e.queue_status === 'waiting').length;
  }

  get calledCount(): number {
    return this.entries.filter((e) => e.queue_status === 'called').length;
  }

  get inServiceCount(): number {
    return this.entries.filter((e) => e.queue_status === 'in_service').length;
  }

  get completedCount(): number {
    return this.entries.filter((e) => e.queue_status === 'completed').length;
  }

  patientInitials(entry: QueueEntry): string {
    const name = (entry.patient_name || '?').trim();
    if (!name) return '?';
    const parts = name.split(/\s+/).filter(Boolean);
    if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
  }

  loadQueue(): void {
    this.isLoading = true;
    this.loadError = '';
    this.queueService
      .list()
      .pipe(finalize(() => (this.isLoading = false)))
      .subscribe({
        next: (res) => (this.entries = res.data || []),
        error: (err) => {
          this.loadError =
            err?.error?.message ||
            (err.status === 403
              ? 'You are not an active member of any clinic yet.'
              : 'Something went wrong loading the queue.');
        },
      });
  }

  private loadContext(): void {
    this.contextSub = this.clinicService.myContext().subscribe({
      next: (res) => {
        const entries: ClinicContextEntry[] = res.data?.clinics || [];
        this.canManageQueue = entries.some(
          (e) => e.role === 'owner' || e.role === 'admin' || (e.role === 'staff' && e.permissions.includes('queue.manage'))
        );
      },
      error: () => {
        // A failed context probe just hides manage buttons — the backend
        // still enforces the real permission on every action either way.
        this.canManageQueue = false;
      },
    });
  }

  run(entry: QueueEntry, action: 'checkIn' | 'call' | 'start' | 'complete' | 'skip' | 'noShow' | 'remove'): void {
    this.busyId = entry.id;
    delete this.rowError[entry.id];

    this.queueService[action](entry.id)
      .pipe(finalize(() => (this.busyId = null)))
      .subscribe({
        next: (res) => {
          this.entries = this.entries.map((e) => (e.id === res.data.id ? res.data : e));
        },
        error: (err) => {
          this.rowError[entry.id] = err?.error?.message || 'Action failed.';
        },
      });
  }

  queueBadgeClass(status: QueueEntry['queue_status']): Record<string, boolean> {
    return {
      'bg-purple-100 text-purple-700': status === 'in_service',
      'bg-amber-100 text-amber-700': status === 'called',
      'bg-blue-100 text-blue-700': status === 'waiting',
      'bg-green-100 text-green-700': status === 'completed',
      'bg-red-100 text-red-600': status === 'no_show',
      'bg-gray-100 text-gray-500': status === 'skipped' || !status,
    };
  }

  paymentBadgeClass(status: QueueEntry['payment_status']): Record<string, boolean> {
    return {
      'bg-emerald-50 text-emerald-600': status === 'paid',
      'bg-slate-100 text-slate-500': status === 'unpaid',
      'bg-red-50 text-red-500': status === 'failed',
    };
  }
}
