import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { LogoutButtonComponent } from '../../../../../../../components/logout-button';

/**
 * Phase 6 (dashboard component split): the dashboard's top header —
 * doctor name/avatar/status, the notifications bell, and the logout
 * button. Pure presentation; @Input()s only, no service calls, no state
 * mutation, so nothing here needs an @Output() at all.
 *
 * Responsive: the name/status text block hides below `sm` to keep the bar
 * from wrapping or squeezing the avatar/bell/logout targets on a phone —
 * those three remain visible and tappable at every width. This mirrors the
 * same "no invented mobile nav" constraint the rest of Phase 6 works under:
 * the app's global sidebar (`app-sidebar`) is itself `hidden` below `md`
 * with no mobile replacement anywhere in the app (pre-existing, outside
 * this component's scope) — the bell and Quick Actions inside the
 * dashboard are the only real navigation surface on a phone today.
 */
@Component({
  selector: 'app-dashboard-header',
  standalone: true,
  imports: [CommonModule, RouterModule, LogoutButtonComponent],
  template: `
    <header class="flex h-auto min-h-16 shrink-0 flex-wrap items-center justify-between gap-3 bg-[#f0f9ff] px-4 py-3 sm:h-20 sm:px-8 sm:py-0">
      <div class="min-w-0">
        <h1 class="text-lg font-extrabold text-slate-800 sm:text-xl">Doctor Dashboard</h1>
        <p class="truncate text-xs text-slate-400">Welcome back, {{ name }}</p>
      </div>

      <div class="flex shrink-0 items-center gap-2 rounded-2xl border border-blue-50 bg-white p-1.5 pr-2 shadow-sm sm:gap-3 sm:pr-4">
        <button
          routerLink="/notifications"
          aria-label="Notifications"
          class="grid h-10 w-10 place-items-center rounded-xl text-gray-400 transition-colors hover:text-blue-600"
        >
          <i class="fa-regular fa-bell"></i>
        </button>
        <app-logout-button></app-logout-button>
        <div class="hidden text-right sm:block">
          <p class="text-xs font-bold leading-3 text-slate-700">{{ name }}</p>
          <span class="text-[9px] font-bold uppercase text-green-500">● {{ status }}</span>
        </div>
        <img [src]="avatar" alt="" class="h-9 w-9 rounded-full object-cover" />
      </div>
    </header>
  `,
})
export class DashboardHeaderComponent {
  @Input() name = '';
  @Input() avatar = '';
  @Input() status = 'Online';
}
