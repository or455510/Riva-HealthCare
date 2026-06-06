import { CommonModule } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { ActivatedRoute, RouterModule } from '@angular/router';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { finalize } from 'rxjs';
import { API_BASE_URL } from '../../../../constants';
import { AuthService } from '../../../../service/auth.service';
import { SidebarComponent } from '../../../../components/sidebar';

@Component({
  selector: 'app-appointment-detail',
  standalone: true,
  imports: [CommonModule, RouterModule, HttpClientModule, SidebarComponent],
  template: `
    <main class="min-h-screen bg-slate-50 p-6 md:p-10 md:pl-28">
      <app-sidebar></app-sidebar>
      <section class="max-w-3xl mx-auto">
        <a routerLink="/notifications" class="text-sm font-bold text-slate-500 hover:text-blue-600">
          <i class="fa-solid fa-arrow-left mr-2"></i>Back
        </a>

        <div *ngIf="isLoading" class="mt-6 bg-white rounded-3xl border border-slate-100 p-8 text-center">
          <div class="w-10 h-10 mx-auto border-4 border-blue-100 border-t-blue-600 rounded-full animate-spin"></div>
          <p class="text-sm text-slate-400 mt-3">Loading appointment...</p>
        </div>

        <div *ngIf="!isLoading && errorMessage" class="mt-6 bg-red-50 border border-red-100 rounded-3xl p-6 text-red-600 text-sm font-semibold">
          {{ errorMessage }}
        </div>

        <article *ngIf="!isLoading && appointment" class="mt-6 bg-white rounded-3xl border border-slate-100 shadow-sm p-8">
          <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
            <div>
              <p class="text-xs font-black uppercase tracking-widest text-blue-500">Appointment</p>
              <h1 class="text-2xl font-black text-slate-800 mt-2">{{ appointmentTitle }}</h1>
              <p class="text-sm text-slate-500 mt-2">{{ appointment.notes || 'No extra notes provided.' }}</p>
            </div>
            <div class="flex gap-2">
              <span class="px-3 py-1 rounded-full text-xs font-black" [ngClass]="statusClass">{{ appointment.status }}</span>
              <span class="px-3 py-1 rounded-full text-xs font-black" [ngClass]="paymentClass">{{ appointment.payment_status }}</span>
            </div>
          </div>

          <div class="mt-8 grid md:grid-cols-2 gap-4">
            <div class="rounded-2xl bg-slate-50 p-4 border border-slate-100">
              <p class="text-xs font-black uppercase text-slate-400">Date</p>
              <p class="text-lg font-black text-slate-800 mt-1">{{ appointment.appointment_date }}</p>
            </div>
            <div class="rounded-2xl bg-slate-50 p-4 border border-slate-100">
              <p class="text-xs font-black uppercase text-slate-400">Time</p>
              <p class="text-lg font-black text-slate-800 mt-1">{{ appointment.appointment_time }}</p>
            </div>
            <div class="rounded-2xl bg-slate-50 p-4 border border-slate-100">
              <p class="text-xs font-black uppercase text-slate-400">Doctor</p>
              <p class="text-lg font-black text-slate-800 mt-1">{{ appointment.doctor?.user?.name || 'Doctor' }}</p>
            </div>
            <div class="rounded-2xl bg-slate-50 p-4 border border-slate-100">
              <p class="text-xs font-black uppercase text-slate-400">Patient</p>
              <p class="text-lg font-black text-slate-800 mt-1">{{ appointment.patient?.user?.name || 'Patient' }}</p>
            </div>
          </div>
        </article>
      </section>
    </main>
  `
})
export class AppointmentDetailComponent implements OnInit {
  appointment: any = null;
  isLoading = true;
  errorMessage = '';

  constructor(private route: ActivatedRoute, private http: HttpClient, private authService: AuthService) {}

  ngOnInit(): void {
    const id = this.route.snapshot.paramMap.get('id');
    if (!id) {
      this.errorMessage = 'Appointment not found.';
      this.isLoading = false;
      return;
    }

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

  get statusClass(): string {
    return this.appointment?.status === 'confirmed' ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600';
  }

  get paymentClass(): string {
    return this.appointment?.payment_status === 'paid' ? 'bg-blue-50 text-blue-600' : 'bg-rose-50 text-rose-600';
  }
}
