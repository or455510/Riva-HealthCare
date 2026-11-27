import { CommonModule } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { ActivatedRoute, RouterModule } from '@angular/router';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { finalize } from 'rxjs';
import { API_BASE_URL } from '../../../../constants';
import { AuthService } from '../../../../service/auth.service';
import { SidebarComponent } from '../../../../components/sidebar';

/**
 * RIVA-125 (visual redesign pass, appointment detail): styling/layout only.
 * The data flow is unchanged — a single authenticated GET to
 * `${API_BASE_URL}/appointments/{id}` (AppointmentController::show), which
 * eager-loads patient.user, doctor.user, hospital and service. Every field
 * rendered below (status, payment_status, amount, hospital, type, notes,
 * doctor.specialty) comes straight from that real response; nothing is
 * fabricated. This screen intentionally has no confirm/cancel/complete/pay
 * actions — those endpoints exist on the backend (AppointmentController) but
 * were never wired to this component before this pass either, so adding them
 * would be new TS logic/API surface, out of scope for a styling-only pass.
 */
@Component({
  selector: 'app-appointment-detail',
  standalone: true,
  imports: [CommonModule, RouterModule, HttpClientModule, SidebarComponent],
  template: `
    <div class="flex min-h-screen bg-[#f7f8fb] font-sans">
      <app-sidebar></app-sidebar>

      <main class="flex-1 p-4 sm:p-8 md:pl-28">
        <div class="mx-auto flex max-w-3xl flex-col gap-6">
          <a
            routerLink="/notifications"
            class="inline-flex w-fit items-center gap-2 text-sm font-bold text-slate-500 transition-colors hover:text-blue-600"
          >
            <i class="fa-solid fa-arrow-left"></i>Back
          </a>

          <!-- Loading State -->
          <div *ngIf="isLoading" class="flex h-64 items-center justify-center">
            <div class="h-10 w-10 animate-spin rounded-full border-4 border-blue-200 border-t-blue-600"></div>
          </div>

          <!-- Error State -->
          <div
            *ngIf="!isLoading && errorMessage"
            class="rounded-[24px] border border-red-100 bg-white p-8 text-center shadow-sm"
          >
            <i class="fa-solid fa-triangle-exclamation mb-3 text-3xl text-red-400"></i>
            <p class="mb-1 font-semibold text-[#1e293b]">Couldn't load this appointment</p>
            <p class="text-sm text-slate-500">{{ errorMessage }}</p>
            <button
              (click)="loadAppointment()"
              class="mt-4 rounded-xl bg-[#1d4ed8] px-6 py-2 font-bold text-white transition-all hover:bg-blue-700"
            >
              Try again
            </button>
          </div>

          <!-- Empty / not found (edge case: no error, no data) -->
          <div
            *ngIf="!isLoading && !errorMessage && !appointment"
            class="flex flex-col items-center rounded-[24px] border border-slate-200 bg-white p-16 text-slate-400 shadow-sm"
          >
            <i class="fa-solid fa-calendar-xmark mb-4 text-5xl text-slate-200"></i>
            <p class="text-lg font-semibold">Appointment not found</p>
          </div>

          <ng-container *ngIf="!isLoading && !errorMessage && appointment">
            <!-- Header -->
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <p class="text-xs font-bold uppercase tracking-wider text-blue-600">Appointment</p>
                <h1 class="mt-1 text-2xl font-bold text-[#1e293b] sm:text-3xl">{{ appointmentTitle }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ appointment.notes || 'No extra notes provided.' }}</p>
              </div>
              <div class="flex shrink-0 flex-wrap gap-2">
                <span
                  class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider"
                  [ngClass]="{
                    'bg-emerald-100 text-emerald-700': appointment.status === 'confirmed',
                    'bg-blue-100 text-blue-700': appointment.status === 'completed',
                    'bg-amber-100 text-amber-700': appointment.status === 'pending_payment' || appointment.status === 'pending',
                    'bg-red-100 text-red-600': appointment.status === 'cancelled'
                  }"
                >
                  {{ statusLabel }}
                </span>
                <span
                  class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider"
                  [ngClass]="{
                    'bg-emerald-100 text-emerald-700': appointment.payment_status === 'paid',
                    'bg-amber-100 text-amber-700': appointment.payment_status === 'unpaid',
                    'bg-red-100 text-red-600': appointment.payment_status === 'failed'
                  }"
                >
                  {{ appointment.payment_status || '—' }}
                </span>
              </div>
            </div>

            <!-- Details card -->
            <div class="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
              <div class="grid gap-4 sm:grid-cols-2">
                <div class="flex items-start gap-3 rounded-[20px] border border-slate-100 bg-slate-50 p-4">
                  <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-600">
                    <i class="fa-solid fa-calendar-day"></i>
                  </div>
                  <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Date</p>
                    <p class="mt-0.5 truncate font-bold text-[#1e293b]">{{ appointment.appointment_date || '—' }}</p>
                  </div>
                </div>

                <div class="flex items-start gap-3 rounded-[20px] border border-slate-100 bg-slate-50 p-4">
                  <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-600">
                    <i class="fa-solid fa-clock"></i>
                  </div>
                  <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Time</p>
                    <p class="mt-0.5 truncate font-bold text-[#1e293b]">{{ appointment.appointment_time || '—' }}</p>
                  </div>
                </div>

                <div class="flex items-start gap-3 rounded-[20px] border border-slate-100 bg-slate-50 p-4">
                  <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-teal-50 text-teal-600">
                    <i class="fa-solid fa-user-doctor"></i>
                  </div>
                  <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Doctor</p>
                    <p class="mt-0.5 truncate font-bold text-[#1e293b]">{{ appointment.doctor?.user?.name || 'Doctor' }}</p>
                    <p *ngIf="appointment.doctor?.specialty" class="truncate text-xs text-slate-400">{{ appointment.doctor.specialty }}</p>
                  </div>
                </div>

                <div class="flex items-start gap-3 rounded-[20px] border border-slate-100 bg-slate-50 p-4">
                  <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-purple-50 text-purple-600">
                    <i class="fa-solid fa-user"></i>
                  </div>
                  <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Patient</p>
                    <p class="mt-0.5 truncate font-bold text-[#1e293b]">{{ appointment.patient?.user?.name || 'Patient' }}</p>
                  </div>
                </div>

                <div class="flex items-start gap-3 rounded-[20px] border border-slate-100 bg-slate-50 p-4">
                  <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-indigo-50 text-indigo-600">
                    <i class="fa-solid fa-stethoscope"></i>
                  </div>
                  <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Service</p>
                    <p class="mt-0.5 truncate font-bold text-[#1e293b]">{{ appointment.service?.name || 'General consultation' }}</p>
                  </div>
                </div>

                <div *ngIf="appointment.hospital?.name" class="flex items-start gap-3 rounded-[20px] border border-slate-100 bg-slate-50 p-4">
                  <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-orange-50 text-orange-600">
                    <i class="fa-solid fa-hospital"></i>
                  </div>
                  <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Location</p>
                    <p class="mt-0.5 truncate font-bold text-[#1e293b]">{{ appointment.hospital.name }}</p>
                  </div>
                </div>

                <div *ngIf="appointment.type" class="flex items-start gap-3 rounded-[20px] border border-slate-100 bg-slate-50 p-4">
                  <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-sky-50 text-sky-600">
                    <i class="fa-solid fa-video"></i>
                  </div>
                  <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Type</p>
                    <p class="mt-0.5 truncate font-bold capitalize text-[#1e293b]">{{ appointment.type }}</p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Payment card -->
            <div class="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
              <div class="mb-4 flex items-center gap-3">
                <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-600">
                  <i class="fa-solid fa-receipt"></i>
                </div>
                <div>
                  <h2 class="text-base font-bold text-[#1e293b]">Payment</h2>
                  <p class="text-xs text-slate-400">Billing amount and payment status for this consultation.</p>
                </div>
              </div>
              <div class="flex items-center justify-between rounded-[20px] border border-slate-100 bg-slate-50 p-4">
                <div>
                  <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Amount</p>
                  <p class="mt-0.5 text-xl font-bold text-[#1e293b]">{{ appointment.amount ?? '—' }} EGP</p>
                </div>
                <span
                  class="rounded-full px-3 py-1.5 text-xs font-bold uppercase tracking-wider"
                  [ngClass]="{
                    'bg-emerald-100 text-emerald-700': appointment.payment_status === 'paid',
                    'bg-amber-100 text-amber-700': appointment.payment_status === 'unpaid',
                    'bg-red-100 text-red-600': appointment.payment_status === 'failed'
                  }"
                >
                  {{ appointment.payment_status || 'unknown' }}
                </span>
              </div>
            </div>
          </ng-container>
        </div>
      </main>
    </div>
  `,
})
export class AppointmentDetailComponent implements OnInit {
  appointment: any = null;
  isLoading = true;
  errorMessage = '';

  constructor(private route: ActivatedRoute, private http: HttpClient, private authService: AuthService) {}

  ngOnInit(): void {
    this.loadAppointment();
  }

  loadAppointment(): void {
    const id = this.route.snapshot.paramMap.get('id');
    if (!id) {
      this.errorMessage = 'Appointment not found.';
      this.isLoading = false;
      return;
    }

    this.isLoading = true;
    this.errorMessage = '';

    this.http.get<any>(`${API_BASE_URL}/appointments/${id}`, {
      headers: { Authorization: `Bearer ${this.authService.getToken()}` }
    }).pipe(finalize(() => this.isLoading = false)).subscribe({
      next: (res) => this.appointment = res?.data || res,
      error: (err) => {
        this.errorMessage = err.status === 403
          ? 'You are not allowed to view this appointment.'
          : 'Unable to load this appointment.';
      }
    });
  }

  get appointmentTitle(): string {
    const type = this.appointment?.type ? `${this.appointment.type} consultation` : 'Consultation';
    return `${type} #${this.appointment?.id || ''}`;
  }

  // Purely presentational: turns the raw backend value (e.g. "pending_payment")
  // into a readable label (e.g. "Pending payment"). Does not change what data
  // is fetched or how status is stored/compared elsewhere.
  get statusLabel(): string {
    const raw = this.appointment?.status;
    if (!raw) return '—';
    const spaced = raw.replace(/_/g, ' ');
    return spaced.charAt(0).toUpperCase() + spaced.slice(1);
  }
}
