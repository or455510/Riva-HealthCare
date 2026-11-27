import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { EmergencyAlertItem } from '../../../../../../../service/doctor-dashboard.service';

/**
 * Phase 6 (dashboard component split): Level 3's highest-priority piece,
 * split out of the old "Attention" section on its own so it can sit first
 * in the UX hierarchy (Phase 6 spec item 1: "what requires immediate
 * attention?") on every screen size, phone included.
 *
 * Renders only when the backend reports a real active alert
 * (`active_emergency_alerts.count > 0`) — never a placeholder "no
 * emergencies" banner (unchanged Phase 5 rule). Opening an alert navigates
 * to the patient's profile, a pure static route, so this injects Router
 * directly rather than bubbling an @Output() for it.
 */
@Component({
  selector: 'app-dashboard-emergency-alerts',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div
      *ngIf="count > 0"
      data-testid="emergency-alert-banner"
      class="rounded-[2rem] border border-rose-200 bg-rose-50 p-4 sm:p-5"
    >
      <div class="mb-3 flex items-center gap-2">
        <i class="fa-solid fa-triangle-exclamation text-rose-500"></i>
        <h2 class="text-sm font-black uppercase tracking-wide text-rose-600">Emergency alerts — {{ count }}</h2>
      </div>
      <div class="space-y-2">
        <div
          *ngFor="let alert of alerts"
          (click)="openPatient(alert.patient_id)"
          class="flex cursor-pointer flex-col gap-1 rounded-xl border border-rose-100 bg-white p-3 transition-colors hover:bg-rose-50/60"
        >
          <p class="text-sm font-bold text-slate-800">{{ alert.patient_name || 'Patient' }}</p>
          <p class="text-xs font-bold text-rose-500">{{ alert.severity }} — {{ alert.message || 'No message provided' }}</p>
          <p *ngIf="alert.pain_level != null" class="text-[11px] text-slate-400">Pain level: {{ alert.pain_level }}</p>
        </div>
      </div>
    </div>
  `,
})
export class DashboardEmergencyAlertsComponent {
  @Input() count = 0;
  @Input() alerts: EmergencyAlertItem[] = [];

  constructor(private router: Router) {}

  openPatient(patientId: number | null | undefined): void {
    if (!patientId) return;
    this.router.navigate(['/profile-p', patientId]);
  }
}
