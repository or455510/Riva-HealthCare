import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Router } from '@angular/router';

/**
 * Phase 6 (dashboard component split): Level 4 "Quick Actions" — real,
 * already-confirmed routes only (/clinic-patients, /clinic-queue,
 * /patient-cards, /clinic-services, /notifications, /ai-chat), unchanged
 * from Phase 5. "Manage services" stays gated on `canManageServices`,
 * mirroring `ClinicUser::hasPermission()` for display only — the backend
 * remains the real authorization boundary.
 *
 * Worth noting for Phase 6's responsiveness goal specifically: the app's
 * global sidebar nav (`app-sidebar`) is `hidden` below the `md` breakpoint
 * with no phone-sized replacement anywhere in the app (pre-existing,
 * outside this file's scope) — this row of buttons is one of the only real
 * navigation surfaces available on a phone today, alongside the header's
 * bell icon. It's kept as a wrapping button row (not collapsed into a menu)
 * so every destination stays one visible tap away rather than hidden behind
 * a disclosure control, and each button gets a taller tap target on small
 * screens (`py-3`, tightened back to `py-2.5` at `sm:`) for that reason.
 */
@Component({
  selector: 'app-dashboard-quick-actions',
  standalone: true,
  imports: [CommonModule, RouterModule],
  template: `
    <div class="rounded-[2.5rem] border border-sky-100 bg-white p-4 shadow-sm sm:p-6">
      <div class="mb-5 flex items-center justify-between">
        <h2 class="text-base font-bold text-slate-800">Quick Actions</h2>
      </div>
      <div class="flex flex-wrap gap-3">
        <a
          routerLink="/clinic-patients"
          class="flex items-center gap-2 rounded-xl bg-sky-50 px-4 py-3 text-xs font-black text-sky-600 transition-colors hover:bg-sky-100 sm:py-2.5"
        >
          <i class="fa-solid fa-user-plus"></i> Add patient
        </a>
        <a
          routerLink="/clinic-queue"
          class="flex items-center gap-2 rounded-xl bg-sky-50 px-4 py-3 text-xs font-black text-sky-600 transition-colors hover:bg-sky-100 sm:py-2.5"
        >
          <i class="fa-solid fa-list-ol"></i> Open queue
        </a>
        <a
          routerLink="/patient-cards"
          class="flex items-center gap-2 rounded-xl bg-sky-50 px-4 py-3 text-xs font-black text-sky-600 transition-colors hover:bg-sky-100 sm:py-2.5"
        >
          <i class="fa-solid fa-users"></i> View patients
        </a>
        <a
          *ngIf="!isLoadingPermissions && canManageServices"
          routerLink="/clinic-services"
          class="flex items-center gap-2 rounded-xl bg-sky-50 px-4 py-3 text-xs font-black text-sky-600 transition-colors hover:bg-sky-100 sm:py-2.5"
        >
          <i class="fa-solid fa-briefcase-medical"></i> Manage services
        </a>
        <a
          routerLink="/notifications"
          class="flex items-center gap-2 rounded-xl bg-sky-50 px-4 py-3 text-xs font-black text-sky-600 transition-colors hover:bg-sky-100 sm:py-2.5"
        >
          <i class="fa-regular fa-bell"></i> Notifications
        </a>
        <button
          (click)="openAiAssistant()"
          class="flex items-center gap-2 rounded-xl bg-sky-50 px-4 py-3 text-xs font-black text-sky-600 transition-colors hover:bg-sky-100 sm:py-2.5"
        >
          <i class="fa-solid fa-robot"></i> AI Assistant
        </button>
      </div>
    </div>
  `,
})
export class DashboardQuickActionsComponent {
  @Input() canManageServices = false;
  @Input() isLoadingPermissions = true;

  constructor(private router: Router) {}

  openAiAssistant(): void {
    this.router.navigate(['/ai-chat']);
  }
}
