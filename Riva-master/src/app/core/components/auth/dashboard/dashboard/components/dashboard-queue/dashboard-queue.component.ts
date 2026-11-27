import { Component, EventEmitter, Input, Output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Router } from '@angular/router';
import { DoctorQueueView } from '../../../../../../../service/doctor-dashboard.service';
import { QueueEntry } from '../../../../../../../service/queue.service';

/**
 * Phase 6 (dashboard component split): Level 1 "Now — Live Queue". Unchanged
 * Phase 5 behavior — current/next patient and waiting count are still
 * derived entirely by the parent (deriveDoctorQueueView()/patientsAheadOf(),
 * both from doctor-dashboard.service.ts) and handed down as plain data; this
 * component owns no queue logic of its own, so there is nothing here that
 * could compute a different answer than the parent's existing tests already
 * cover. No fabricated ETA/minutes anywhere in this template — only real
 * counts.
 *
 * Calling a patient (`callPatient`) mutates server state and must trigger a
 * real queue refresh, both owned by the parent (which also holds the
 * permission-mirroring logic) — that's why it's an @Output() rather than
 * handled locally, unlike the pure-navigation `openPatient()` below.
 */
@Component({
  selector: 'app-dashboard-queue',
  standalone: true,
  imports: [CommonModule, RouterModule],
  template: `
    <div class="rounded-[2.5rem] border border-sky-100 bg-white p-4 shadow-sm sm:p-6">
      <div class="mb-5 flex items-center justify-between">
        <h2 class="text-base font-bold text-slate-800">Now — Live Queue</h2>
        <a routerLink="/clinic-queue" class="text-xs font-bold text-sky-500 hover:text-sky-600">Open queue →</a>
      </div>

      <div *ngIf="isLoading" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div *ngFor="let i of [1, 2, 3]" class="h-24 animate-pulse rounded-2xl bg-slate-100"></div>
      </div>

      <div *ngIf="!isLoading && error" class="py-8 text-center">
        <p class="mb-3 text-sm text-rose-500">{{ error }}</p>
        <button (click)="retry.emit()" class="rounded-xl bg-sky-100 px-4 py-2 text-xs font-black text-sky-600 transition-colors hover:bg-sky-200">
          Retry
        </button>
      </div>

      <div *ngIf="!isLoading && !error" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-2xl border border-sky-100 bg-sky-50/50 p-4">
          <p class="mb-2 text-[11px] text-slate-400">Current patient</p>
          <ng-container *ngIf="queueView.currentPatient; else noCurrent">
            <p
              class="cursor-pointer text-sm font-bold text-slate-800 hover:text-sky-600"
              (click)="openPatient(queueView.currentPatient?.patient_id)"
            >
              {{ queueView.currentPatient?.patient_name || 'Patient' }}
            </p>
            <p class="mt-1 text-[10px] font-bold text-sky-500">In consultation</p>
          </ng-container>
          <ng-template #noCurrent>
            <p class="text-sm text-slate-400">No patient in consultation</p>
          </ng-template>
        </div>

        <div class="rounded-2xl border border-sky-100 bg-sky-50/50 p-4">
          <p class="mb-2 text-[11px] text-slate-400">Next patient</p>
          <ng-container *ngIf="queueView.nextPatient; else noNext">
            <p
              class="cursor-pointer text-sm font-bold text-slate-800 hover:text-sky-600"
              (click)="openPatient(queueView.nextPatient?.patient_id)"
            >
              {{ queueView.nextPatient?.patient_name || 'Patient' }}
            </p>
            <p class="mt-1 text-[10px] text-slate-400">{{ patientsBeforeNext }} patient(s) before them</p>
            <button
              *ngIf="canManageQueue"
              (click)="callPatient.emit(queueView.nextPatient!)"
              [disabled]="callingAppointmentId === queueView.nextPatient?.id"
              class="mt-2 w-full rounded-xl bg-sky-500 px-3 py-2.5 text-[11px] font-black text-white transition-colors hover:bg-sky-600 disabled:opacity-50"
            >
              {{ callingAppointmentId === queueView.nextPatient?.id ? 'Calling…' : 'Call this patient' }}
            </button>
            <p *ngIf="callError" class="mt-1 text-[10px] text-rose-500">{{ callError }}</p>
          </ng-container>
          <ng-template #noNext>
            <p class="text-sm text-slate-400">Queue is empty right now</p>
          </ng-template>
        </div>

        <div class="rounded-2xl border border-sky-100 bg-sky-50/50 p-4">
          <p class="mb-2 text-[11px] text-slate-400">Waiting</p>
          <p class="text-2xl font-black leading-none text-slate-800">{{ queueView.waitingCount }}</p>
          <p class="mt-1 text-[10px] text-slate-400">{{ todayAppointmentsCount }} appointments today</p>
        </div>
      </div>
    </div>
  `,
})
export class DashboardQueueComponent {
  @Input() isLoading = false;
  @Input() error: string | null = null;
  @Input() queueView: DoctorQueueView = {
    currentPatient: null,
    nextPatient: null,
    waitingCount: 0,
    noShowTodayCount: 0,
    skippedTodayCount: 0,
  };
  @Input() canManageQueue = false;
  @Input() callingAppointmentId: number | null = null;
  @Input() callError: string | null = null;
  @Input() patientsBeforeNext = 0;
  @Input() todayAppointmentsCount = 0;

  @Output() retry = new EventEmitter<void>();
  @Output() callPatient = new EventEmitter<QueueEntry>();

  constructor(private router: Router) {}

  openPatient(patientId: number | null | undefined): void {
    if (!patientId) return;
    this.router.navigate(['/profile-p', patientId]);
  }
}
