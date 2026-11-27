import { Component, OnDestroy, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClientModule } from '@angular/common/http';
import { Router, RouterLink, RouterLinkActive } from '@angular/router';
import { Subscription } from 'rxjs';
import { AuthService } from '../service/auth.service';
import { ClinicService } from '../service/clinic.service';
import { MobileShellComponent } from './mobile-shell/mobile-shell.component';

export type Role = 'patient' | 'doctor' | 'caregiver' | 'admin';

export interface SidebarItem {
  icon: string;
  route: string;
  title: string;
  /**
   * RIVA-125 (visual redesign, pass 1): a lightweight grouping key used only
   * to draw a subtle divider between logical sections of the nav (Overview /
   * Clinic / Communication / Records / Account) — purely presentational,
   * does not affect routing or access. Consecutive items sharing a group
   * render with no divider between them; a group change draws one.
   */
  group: string;
}

const MENU: Record<Role, SidebarItem[]> = {
  patient: [
    { icon: 'fas fa-home', route: '/dashboard-p', title: 'Dashboard', group: 'overview' },
    { icon: 'fas fa-pills', route: '/add-new-medication', title: 'Medications', group: 'health' },
    { icon: 'fa-solid fa-user-doctor', route: '/doctor-cards', title: 'Doctors', group: 'health' },
    { icon: 'fa-solid fa-user-nurse', route: '/caregiver-cards', title: 'Caregivers', group: 'health' },
    { icon: 'fa-solid fa-file-medical', route: '/my-reports', title: 'Reports', group: 'health' },
    { icon: 'fa-brands fa-rocketchat', route: '/chat', title: 'Chat', group: 'comms' },
    { icon: 'fa-solid fa-robot', route: '/ai-chat', title: 'AI Assistant', group: 'comms' },
    { icon: 'fa-regular fa-bell', route: '/notifications', title: 'Notifications', group: 'comms' },
    { icon: 'fa-solid fa-circle-user', route: '/myprofile', title: 'Profile', group: 'account' },
  ],
  doctor: [
    { icon: 'fas fa-home', route: '/dashboard', title: 'Dashboard', group: 'overview' },
    { icon: 'fa-solid fa-bed-pulse', route: '/patient-cards', title: 'Patients', group: 'clinic' },
    { icon: 'fa-solid fa-users', route: '/clinic-staff', title: 'Clinic Team', group: 'clinic' },
    { icon: 'fa-solid fa-list-ol', route: '/clinic-queue', title: 'Queue', group: 'clinic' },
    { icon: 'fa-solid fa-address-book', route: '/clinic-patients', title: 'Patients (Front Desk)', group: 'clinic' },
    { icon: 'fa-solid fa-file-invoice-dollar', route: '/clinic-services', title: 'Services & Prices', group: 'clinic' },
    // RIVA-130 follow-up (product-owner feedback, 2026-08-25): "what do I
    // gain from browsing every report I've ever written — open the
    // patient's own profile and see the reports written for THEM there."
    // The global cross-patient "My Reports" list is removed for doctors;
    // reports now live on each patient's own profile (Patients → open a
    // patient → Reports). A report another doctor wrote that a patient
    // chose to share stays in its own, separate place — see
    // /shared-reports below — never mixed back into a flat list here.
    { icon: 'fa-solid fa-share-nodes', route: '/shared-reports', title: 'Shared With Me', group: 'clinic' },
    { icon: 'fa-brands fa-rocketchat', route: '/chat', title: 'Chat', group: 'comms' },
    { icon: 'fa-solid fa-robot', route: '/ai-chat', title: 'AI Assistant', group: 'comms' },
    { icon: 'fa-regular fa-bell', route: '/notifications', title: 'Notifications', group: 'comms' },
    { icon: 'fa-solid fa-circle-user', route: '/myprofile', title: 'Profile', group: 'account' },
    // RIVA-130 follow-up (product-owner feedback, 2026-08-25): "every other
    // section has action buttons, the dashboard has no Settings button."
    // The sidebar renders on every authenticated page including the
    // dashboard, so this reaches it without needing to edit
    // dashboard.component.ts directly.
    { icon: 'fa-solid fa-gear', route: '/doctor-settings', title: 'Settings', group: 'account' },
  ],
  caregiver: [
    { icon: 'fas fa-home', route: '/dashboard-caregiver', title: 'Dashboard', group: 'overview' },
    { icon: 'fa-solid fa-bed-pulse', route: '/patient-cards', title: 'Patients', group: 'health' },
    { icon: 'fa-brands fa-rocketchat', route: '/chat', title: 'Chat', group: 'comms' },
    { icon: 'fa-solid fa-robot', route: '/ai-chat', title: 'AI Assistant', group: 'comms' },
    { icon: 'fa-regular fa-bell', route: '/notifications', title: 'Notifications', group: 'comms' },
    { icon: 'fa-solid fa-circle-user', route: '/myprofile', title: 'Profile', group: 'account' },
  ],
  admin: [
    { icon: 'fas fa-home', route: '/admin/dashboard-admin', title: 'Dashboard', group: 'overview' },
    { icon: 'fa-solid fa-users', route: '/admin/users', title: 'Users', group: 'management' },
    { icon: 'fa-solid fa-user-doctor', route: '/admin/users', title: 'Doctors', group: 'management' },
    { icon: 'fa-solid fa-user-nurse', route: '/admin/users', title: 'Caregivers', group: 'management' },
    { icon: 'fa-solid fa-bed-pulse', route: '/admin/users', title: 'Patients', group: 'management' },
    { icon: 'fa-solid fa-robot', route: '/ai-chat', title: 'AI Assistant', group: 'comms' },
    { icon: 'fa-regular fa-bell', route: '/notifications', title: 'Notifications', group: 'comms' },
  ],
};

/**
 * Phase C (Queue slice, principal-engineering directive): a clinic
 * "secretary" has no dedicated platform role in this system — they are
 * whatever platform role they registered as (patient/doctor/caregiver),
 * plus a clinic_user membership added afterward. A doctor's own solo clinic
 * (auto-provisioned) means the doctor menu above can show these links
 * unconditionally; a patient/caregiver who is ALSO clinic staff cannot be
 * predicted from platform role alone, so these two items are appended
 * dynamically (see ngOnInit() below) only when ClinicService.myContext()
 * confirms real clinic access — never shown speculatively.
 */
const CLINIC_STAFF_ITEMS: SidebarItem[] = [
  { icon: 'fa-solid fa-users', route: '/clinic-staff', title: 'Clinic Team', group: 'clinic' },
  { icon: 'fa-solid fa-list-ol', route: '/clinic-queue', title: 'Queue', group: 'clinic' },
  { icon: 'fa-solid fa-address-book', route: '/clinic-patients', title: 'Patients (Front Desk)', group: 'clinic' },
  { icon: 'fa-solid fa-file-invoice-dollar', route: '/clinic-services', title: 'Services & Prices', group: 'clinic' },
];

@Component({
  selector: 'app-sidebar',
  standalone: true,
  imports: [CommonModule, HttpClientModule, RouterLink, RouterLinkActive, MobileShellComponent],
  template: `
    <aside
      class="fixed left-0 top-0 z-50 hidden h-screen w-20 flex-col items-center gap-1 border-r border-slate-200 bg-white py-5 shadow-[1px_0_8px_rgba(15,23,42,0.03)] md:flex"
    >
      <button
        class="mb-4 grid h-12 w-12 place-items-center rounded-2xl transition-transform hover:scale-105"
        [routerLink]="dashboardRoute"
        title="Riva dashboard"
      >
        <img src="/Picture1.png" alt="RIVA Logo" class="w-10" />
      </button>

      <nav class="flex w-full flex-1 flex-col items-center gap-1 overflow-y-auto px-2.5">
        <ng-container *ngFor="let item of menuItems; let i = index">
          <div
            *ngIf="i > 0 && item.group !== menuItems[i - 1].group"
            class="my-1.5 h-px w-8 shrink-0 bg-slate-100"
          ></div>
          <button
            class="group relative grid h-11 w-11 shrink-0 place-items-center rounded-2xl text-slate-400 transition-all hover:bg-slate-50 hover:text-blue-600"
            routerLinkActive="bg-blue-50 text-blue-600 shadow-[inset_2.5px_0_0_0_#2563eb]"
            [routerLinkActiveOptions]="{ exact: true }"
            [routerLink]="item.route"
            [title]="item.title"
            [attr.aria-label]="item.title"
          >
            <i class="text-[15px]" [class]="item.icon"></i>
          </button>
        </ng-container>
      </nav>

      <div class="mt-auto flex w-full flex-col items-center gap-2 border-t border-slate-100 pt-4">
        <button
          class="grid h-10 w-10 place-items-center overflow-hidden rounded-full bg-gradient-to-br from-blue-500 to-blue-700 text-xs font-bold text-white ring-2 ring-white transition-shadow hover:shadow-md"
          [routerLink]="profileRoute"
          [title]="currentUserName"
        >
          <img
            *ngIf="currentUserAvatar; else initialsFallback"
            [src]="currentUserAvatar"
            [alt]="currentUserName"
            class="h-full w-full object-cover"
          />
          <ng-template #initialsFallback>{{ currentUserInitials }}</ng-template>
        </button>
        <button
          class="grid h-9 w-9 place-items-center rounded-xl text-slate-300 transition-colors hover:bg-red-50 hover:text-red-500"
          title="Logout"
          aria-label="Logout"
          (click)="logout()"
        >
          <i class="fa-solid fa-right-from-bracket text-sm"></i>
        </button>
      </div>
    </aside>

    <app-mobile-shell
      [role]="role"
      [menuItems]="menuItems"
      [dashboardRoute]="dashboardRoute"
      (logout)="logout()"
    ></app-mobile-shell>
  `,
})
export class SidebarComponent implements OnInit, OnDestroy {
  private hasClinicAccess = false;
  private contextSub?: Subscription;

  constructor(
    private authService: AuthService,
    private router: Router,
    private clinicService: ClinicService
  ) {}

  ngOnInit(): void {
    // The doctor menu already links to /clinic-staff and /clinic-queue
    // unconditionally (a doctor always has at least their own
    // auto-provisioned solo clinic), so this probe is only needed for
    // platform roles that might ALSO be clinic staff without being a
    // 'doctor' account.
    if (this.role === 'doctor' || this.role === 'admin') {
      return;
    }

    this.contextSub = this.clinicService.myContext().subscribe({
      next: (res) => (this.hasClinicAccess = !!res.data?.has_clinic),
      error: () => (this.hasClinicAccess = false),
    });
  }

  ngOnDestroy(): void {
    this.contextSub?.unsubscribe();
  }

  get role(): Role {
    const role = this.authService.getUserRole();
    return (['patient', 'doctor', 'caregiver', 'admin'].includes(role) ? role : 'patient') as Role;
  }

  get menuItems(): SidebarItem[] {
    const base = MENU[this.role];

    if (this.role !== 'doctor' && this.role !== 'admin' && this.hasClinicAccess) {
      return [...base, ...CLINIC_STAFF_ITEMS];
    }

    return base;
  }

  get dashboardRoute(): string {
    return this.authService.dashboardRouteForRole(this.role);
  }

  get profileRoute(): string {
    return this.role === 'admin' ? '/admin/profile' : '/myprofile';
  }

  get currentUserName(): string {
    const user = this.authService.getUser();
    return user?.name || [user?.first_name, user?.last_name].filter(Boolean).join(' ') || 'Your profile';
  }

  get currentUserAvatar(): string | null {
    return this.authService.getUser()?.profile_image_url || null;
  }

  get currentUserInitials(): string {
    const name = this.currentUserName.trim();
    if (!name || name === 'Your profile') return '?';
    return name
      .split(/\s+/)
      .slice(0, 2)
      .map((part) => part.charAt(0).toUpperCase())
      .join('');
  }

  logout(): void {
    this.authService.logout();
    this.router.navigate(['/signin']);
  }
}
