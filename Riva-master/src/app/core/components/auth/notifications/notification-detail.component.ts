import { CommonModule } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { finalize } from 'rxjs';
import { API_BASE_URL } from '../../../../constants';
import { AuthService } from '../../../../service/auth.service';
import { SidebarComponent } from '../../../../components/sidebar';

@Component({
  selector: 'app-notification-detail',
  standalone: true,
  imports: [CommonModule, RouterModule, HttpClientModule, SidebarComponent],
  template: `
    <main class="min-h-screen bg-slate-50 p-6 md:p-10 md:pl-28">
      <app-sidebar></app-sidebar>
      <section class="max-w-3xl mx-auto">
        <button routerLink="/notifications" class="text-sm font-bold text-slate-500 hover:text-blue-600 mb-6">
          <i class="fa-solid fa-arrow-left mr-2"></i>Back to notifications
        </button>
        <div *ngIf="isLoading" class="bg-white rounded-3xl border border-slate-100 p-8 text-center">
          <div class="w-10 h-10 mx-auto border-4 border-blue-100 border-t-blue-600 rounded-full animate-spin"></div>
          <p class="text-sm text-slate-400 mt-3">Loading notification...</p>
        </div>
        <div *ngIf="!isLoading && errorMessage" class="bg-red-50 border border-red-100 rounded-3xl p-6 text-red-600 text-sm font-semibold">
          {{ errorMessage }}
        </div>
        <article *ngIf="!isLoading && notification" class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
          <div class="h-24 bg-gradient-to-r from-blue-50 to-cyan-50"></div>
          <div class="p-8 -mt-10">
            <div class="w-16 h-16 rounded-2xl bg-blue-600 text-white flex items-center justify-center shadow-lg mb-5">
              <i class="fa-solid" [ngClass]="iconClass"></i>
            </div>
            <p class="text-xs font-black uppercase tracking-widest text-blue-500">{{ category }}</p>
            <h1 class="text-2xl font-black text-slate-800 mt-2">{{ title }}</h1>
            <p class="text-slate-500 leading-relaxed mt-4">{{ message }}</p>
            <div *ngIf="clinicalDetails.length" class="mt-6 grid gap-3">
              <div *ngFor="let item of clinicalDetails" class="rounded-2xl bg-slate-50 border border-slate-100 p-4">
                <p class="text-[11px] font-black uppercase tracking-widest text-slate-400">{{ item.label }}</p>
                <p class="text-sm font-bold text-slate-700 mt-1">{{ item.value }}</p>
              </div>
            </div>
            <div class="mt-6 grid gap-1 text-xs font-bold text-slate-400">
              <span>Created: {{ createdAt }}</span>
              <span>Status: {{ readAt ? 'Read ' + readAt : 'Unread' }}</span>
            </div>
            <div class="mt-8 flex flex-wrap gap-3">
              <button *ngIf="actionRoute" (click)="openAction()" class="px-5 py-3 rounded-2xl bg-blue-600 text-white text-sm font-black hover:bg-blue-700">
                {{ actionLabel }}
              </button>
              <button routerLink="/notifications" class="px-5 py-3 rounded-2xl bg-slate-100 text-slate-600 text-sm font-black hover:bg-slate-200">
                Close
              </button>
            </div>
          </div>
        </article>
      </section>
    </main>
  `
})
export class NotificationDetailComponent implements OnInit {
  notification: any = null;
  isLoading = true;
  errorMessage = '';

  constructor(private route: ActivatedRoute, private router: Router, private http: HttpClient, private authService: AuthService) {}

  ngOnInit(): void {
    const id = this.route.snapshot.paramMap.get('id');
    if (!id) {
      this.errorMessage = 'Notification not found.';
      this.isLoading = false;
      return;
    }
    this.http.get<any>(`${API_BASE_URL}/notifications/${id}`, {
      headers: { Authorization: `Bearer ${this.authService.getToken()}` }
    }).pipe(finalize(() => this.isLoading = false)).subscribe({
      next: (res) => {
        const payload = res?.data || res;
        const data = payload?.data || {};
        const message = payload?.body || payload?.message || data?.body || data?.message || data?.content || '';
        this.notification = {
          ...payload,
          title: payload?.title || data?.title || 'Notification',
          message,
          body: message,
          category: payload?.category || data?.category || payload?.type || 'general',
          action_url: payload?.action_url || data?.action_url || data?.url || null,
        };
      },
      error: (err) => this.errorMessage = err.status === 403 ? 'You are not allowed to view this notification.' : 'Unable to load this notification.',
    });
  }

  get title(): string { return this.notification?.title || this.notification?.data?.title || 'Notification'; }
  get message(): string { return this.notification?.body || this.notification?.message || this.notification?.data?.message || this.notification?.data?.body || ''; }
  get category(): string { return this.notification?.category || this.notification?.type || 'general'; }
  get createdAt(): string {
    return this.notification?.created_at
      ? new Date(this.notification.created_at).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
      : '';
  }
  get readAt(): string {
    return this.notification?.read_at
      ? new Date(this.notification.read_at).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
      : '';
  }
  get iconClass(): string {
    const key = `${this.category} ${this.notification?.type || ''}`.toLowerCase();
    if (key.includes('appointment')) return 'fa-calendar-check';
    if (key.includes('message')) return 'fa-comments';
    if (key.includes('medication')) return 'fa-pills';
    if (key.includes('emergency') || key.includes('alert')) return 'fa-triangle-exclamation';
    if (key.includes('report')) return 'fa-file-medical';
    return 'fa-bell';
  }
  get actionLabel(): string {
    const key = `${this.category} ${this.notification?.type || ''}`.toLowerCase();
    if (key.includes('appointment')) return 'View Appointment';
    if (key.includes('message')) return 'Open Chat';
    if (key.includes('medication')) return 'View Medication';
    if (key.includes('emergency') || key.includes('alert')) return 'View Alert';
    if (key.includes('report')) return 'View Report';
    return 'Open';
  }
  get actionRoute(): string | null {
    const key = `${this.category} ${this.notification?.type || ''}`.toLowerCase();
    if (this.notification?.action_url?.startsWith('/')) return this.notification.action_url;
    if (key.includes('message')) return '/chat';
    if (key.includes('medication')) return '/add-new-medication';
    if (key.includes('report')) return '/my-reports';
    if (key.includes('appointment') || key.includes('emergency') || key.includes('alert')) return this.authService.dashboardRouteForRole();
    return null;
  }
  get clinicalDetails(): { label: string; value: string }[] {
    const data = this.notification?.data || {};
    const fields: [string, string[]][] = [
      ['Patient', ['patient_name']],
      ['Condition', ['condition', 'disease']],
      ['Severity', ['severity', 'risk_level']],
      ['Source', ['trigger_source', 'source']],
      ['Recommendation', ['recommendation']],
      ['Scheduled Time', ['scheduled_time', 'appointment_time']],
      ['Date', ['appointment_date', 'timestamp']],
      ['Sender', ['sender', 'doctor_name']],
    ];

    return fields
      .map(([label, keys]) => {
        const value = keys.map(key => data?.[key]).find(Boolean);
        return value ? { label, value: String(value) } : null;
      })
      .filter((item): item is { label: string; value: string } => !!item);
  }
  openAction(): void { if (this.actionRoute) this.router.navigate([this.actionRoute]); }
}
