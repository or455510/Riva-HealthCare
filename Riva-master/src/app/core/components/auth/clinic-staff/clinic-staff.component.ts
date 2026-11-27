import { CommonModule } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HttpClientModule } from '@angular/common/http';
import { RouterModule } from '@angular/router';
import { finalize } from 'rxjs/operators';
import { AuthService } from '../../../../service/auth.service';
import {
  CLINIC_PERMISSIONS,
  ClinicMember,
  ClinicMemberRole,
  ClinicService,
} from '../../../../service/clinic.service';
import { SidebarComponent } from '../../../../components/sidebar';

/**
 * Vertical slice (Phase B, principal-engineering directive): the first
 * Angular screen connected to the clinic staff/RBAC backend
 * (ClinicMembershipController, tested in ClinicStaffPermissionsTest /
 * ClinicTenantIsolationTest). A doctor's own clinic ("<name>'s Practice",
 * auto-provisioned by ClinicProvisioningService) is the clinic this screen
 * manages — there is no clinic picker because EnsureClinicContext resolves
 * the single active membership automatically for the common case.
 *
 * Real-data rule: this component shows only members the API actually
 * returns. It never fabricates staff, counts, or permission state. Every
 * write action calls the real endpoint and reflects the real response
 * (including real errors, e.g. "last active owner cannot be suspended").
 */
@Component({
  selector: 'app-clinic-staff',
  standalone: true,
  imports: [CommonModule, FormsModule, HttpClientModule, RouterModule, SidebarComponent],
  template: `
    <div class="flex min-h-screen bg-[#f7f8fb] font-sans">
      <app-sidebar></app-sidebar>

      <main class="flex-1 p-4 sm:p-8 md:pl-28">
        <div class="mx-auto flex max-w-6xl flex-col gap-6">
          <!-- Page header -->
          <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <p class="text-xs font-bold uppercase tracking-wider text-blue-600">Clinic</p>
              <h1 class="mt-1 text-2xl font-bold text-[#1e293b] sm:text-3xl">Clinic Team</h1>
              <p class="mt-1 text-sm text-slate-500">Manage who has access to your clinic and what they can do.</p>
            </div>

            <div *ngIf="!isLoading && !loadError && members.length > 0" class="flex gap-3">
              <div class="min-w-[92px] rounded-2xl border border-slate-200 bg-white px-4 py-3 text-center shadow-sm">
                <p class="text-xl font-bold text-[#1e293b]">{{ members.length }}</p>
                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Total</p>
              </div>
              <div class="min-w-[92px] rounded-2xl border border-slate-200 bg-white px-4 py-3 text-center shadow-sm">
                <p class="text-xl font-bold text-emerald-600">{{ activeCount }}</p>
                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Active</p>
              </div>
              <div class="min-w-[92px] rounded-2xl border border-slate-200 bg-white px-4 py-3 text-center shadow-sm">
                <p class="text-xl font-bold text-amber-500">{{ invitedCount }}</p>
                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Invited</p>
              </div>
            </div>
          </div>

          <!-- Loading State -->
          <div *ngIf="isLoading" class="flex h-64 items-center justify-center">
            <div class="h-10 w-10 animate-spin rounded-full border-4 border-blue-200 border-t-blue-600"></div>
          </div>

          <!-- Error State (init load) -->
          <div
            *ngIf="!isLoading && loadError"
            class="rounded-[24px] border border-red-100 bg-white p-8 text-center shadow-sm"
          >
            <i class="fa-solid fa-triangle-exclamation mb-3 text-3xl text-red-400"></i>
            <p class="mb-1 font-semibold text-[#1e293b]">Couldn't load your clinic team</p>
            <p class="text-sm text-slate-500">{{ loadError }}</p>
            <button
              (click)="loadMembers()"
              class="mt-4 rounded-xl bg-[#1d4ed8] px-6 py-2 font-bold text-white transition-all hover:bg-blue-700"
            >
              Try again
            </button>
          </div>

          <div *ngIf="!isLoading && !loadError" class="flex flex-col gap-6">
            <!-- Invite Form -->
            <div *ngIf="canManage" class="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
              <div class="mb-4 flex items-center gap-3">
                <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-600">
                  <i class="fa-solid fa-user-plus"></i>
                </div>
                <div>
                  <h2 class="text-base font-bold text-[#1e293b]">Invite a team member</h2>
                  <p class="text-xs text-slate-400">Works for anyone — if they don't have a Riva account yet, we'll generate a real invite link to send them.</p>
                </div>
              </div>
              <form (ngSubmit)="submitInvite()" class="flex flex-col gap-3 sm:flex-row">
                <input
                  type="email"
                  name="inviteEmail"
                  [(ngModel)]="inviteEmail"
                  placeholder="teammate@email.com"
                  required
                  class="flex-1 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-colors focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                />
                <select
                  name="inviteRole"
                  [(ngModel)]="inviteRole"
                  class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                >
                  <option value="staff">Staff (secretary/reception)</option>
                  <option value="doctor">Doctor</option>
                  <option value="admin">Admin</option>
                  <option value="owner">Owner</option>
                </select>
                <button
                  type="submit"
                  [disabled]="isInviting || !inviteEmail"
                  class="whitespace-nowrap rounded-xl bg-[#1d4ed8] px-6 py-2.5 font-bold text-white transition-all hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {{ isInviting ? 'Sending…' : 'Send invite' }}
                </button>
              </form>
              <p *ngIf="inviteError" class="mt-3 text-sm text-red-500">{{ inviteError }}</p>
              <p *ngIf="inviteSuccess" class="mt-3 text-sm text-green-600">{{ inviteSuccess }}</p>

              <!-- RIVA-143: a brand-new invitee has no Riva account yet, so
                   there's no notification to rely on — the inviter must copy
                   this link and send it themselves (no email delivery is
                   configured in this environment). -->
              <div *ngIf="lastInviteLink" class="mt-3 rounded-xl border border-blue-100 bg-blue-50 p-4">
                <p class="text-xs font-bold uppercase tracking-wider text-blue-700">Send this link to {{ lastInviteEmail }}</p>
                <p class="mt-1 text-xs text-blue-600">They don't have a Riva account yet — they'll set their own password when they open it. No public role picker; they join straight in as {{ lastInviteRoleLabel }}.</p>
                <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center">
                  <input
                    type="text" readonly [value]="lastInviteLink"
                    class="flex-1 rounded-lg border border-blue-200 bg-white px-3 py-2 text-xs text-slate-600"
                    (click)="$any($event.target).select()"
                  />
                  <button
                    type="button"
                    (click)="copyInviteLink()"
                    class="whitespace-nowrap rounded-lg bg-blue-600 px-4 py-2 text-xs font-bold text-white hover:bg-blue-700"
                  >
                    {{ inviteLinkCopied ? 'Copied!' : 'Copy link' }}
                  </button>
                </div>
              </div>
            </div>

            <!-- Empty State -->
            <div
              *ngIf="members.length === 0"
              class="flex flex-col items-center rounded-[24px] border border-slate-200 bg-white p-16 text-slate-400 shadow-sm"
            >
              <i class="fa-solid fa-users mb-4 text-5xl text-slate-200"></i>
              <p class="text-lg font-semibold">No team members yet</p>
            </div>

            <!-- Member Cards -->
            <div *ngIf="members.length > 0" class="grid grid-cols-1 gap-4 lg:grid-cols-2">
              <div
                *ngFor="let member of members"
                class="flex flex-col gap-4 rounded-[20px] border border-slate-200 bg-white p-5 shadow-sm transition-shadow hover:shadow-md sm:p-6"
              >
                <div class="flex items-start justify-between gap-3">
                  <div class="flex min-w-0 items-center gap-3">
                    <div
                      class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-gradient-to-br from-slate-100 to-slate-200 text-sm font-bold text-slate-500"
                    >
                      {{ memberInitials(member) }}
                    </div>
                    <div class="min-w-0">
                      <p class="truncate font-semibold text-[#1e293b]">
                        {{ member.name || 'Unnamed user' }}
                        <span *ngIf="member.user_id === currentUserId" class="text-xs font-normal text-blue-500">(you)</span>
                      </p>
                      <p class="truncate text-sm text-slate-500">{{ member.email || '—' }}</p>
                    </div>
                  </div>
                  <div class="flex shrink-0 flex-col items-end gap-1">
                    <span
                      class="rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wider"
                      [ngClass]="{
                        'bg-purple-100 text-purple-700': member.role === 'owner',
                        'bg-blue-100 text-blue-700': member.role === 'admin',
                        'bg-teal-100 text-teal-700': member.role === 'doctor',
                        'bg-amber-100 text-amber-700': member.role === 'staff'
                      }"
                    >
                      {{ member.role }}
                    </span>
                    <span
                      class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider"
                      [ngClass]="{
                        'bg-green-100 text-green-700': member.status === 'active',
                        'bg-gray-100 text-gray-500': member.status === 'invited',
                        'bg-red-100 text-red-600': member.status === 'suspended'
                      }"
                    >
                      {{ member.status }}
                    </span>
                  </div>
                </div>

                <p class="text-xs text-slate-400">
                  <ng-container *ngIf="member.joined_at">Joined {{ member.joined_at | date: 'mediumDate' }}</ng-container>
                  <ng-container *ngIf="!member.joined_at && member.invited_at">
                    Invited {{ member.invited_at | date: 'mediumDate' }}
                  </ng-container>
                </p>

              <!-- Manager actions -->
              <div *ngIf="canManage && member.user_id !== currentUserId" class="flex flex-wrap gap-2 pt-2 border-t border-gray-100">
                <select
                  [ngModel]="member.role"
                  (ngModelChange)="changeRole(member, $event)"
                  [disabled]="busyMemberId === member.id"
                  class="text-sm border border-gray-200 rounded-lg px-2 py-1.5"
                >
                  <option value="owner">owner</option>
                  <option value="admin">admin</option>
                  <option value="doctor">doctor</option>
                  <option value="staff">staff</option>
                </select>

                <button
                  *ngIf="member.status !== 'suspended'"
                  (click)="suspend(member)"
                  [disabled]="busyMemberId === member.id"
                  class="text-sm px-3 py-1.5 rounded-lg font-semibold bg-amber-50 text-amber-700 hover:bg-amber-100 disabled:opacity-50"
                >
                  Suspend
                </button>
                <button
                  *ngIf="member.status === 'suspended'"
                  (click)="reactivate(member)"
                  [disabled]="busyMemberId === member.id"
                  class="text-sm px-3 py-1.5 rounded-lg font-semibold bg-green-50 text-green-700 hover:bg-green-100 disabled:opacity-50"
                >
                  Reactivate
                </button>
                <button
                  (click)="remove(member)"
                  [disabled]="busyMemberId === member.id"
                  class="text-sm px-3 py-1.5 rounded-lg font-semibold bg-red-50 text-red-600 hover:bg-red-100 disabled:opacity-50"
                >
                  Remove
                </button>
              </div>

              <!-- Permissions editor (staff only) -->
              <div *ngIf="member.role === 'staff'" class="pt-2 border-t border-gray-100">
                <p class="text-xs font-bold uppercase tracking-wider text-gray-400 mb-2">Permissions</p>
                <div class="flex flex-col gap-2">
                  <label
                    *ngFor="let perm of allPermissions"
                    class="flex items-center gap-2 text-sm text-[#1e293b]"
                  >
                    <input
                      type="checkbox"
                      class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                      [checked]="isPermissionDraftChecked(member, perm.value)"
                      (change)="togglePermissionDraft(member, perm.value)"
                      [disabled]="!canManage || busyMemberId === member.id"
                    />
                    {{ perm.label }}
                  </label>
                </div>
                <button
                  *ngIf="canManage"
                  (click)="savePermissions(member)"
                  [disabled]="busyMemberId === member.id || !hasPermissionDraftChanged(member)"
                  class="mt-3 text-sm px-4 py-1.5 rounded-lg font-semibold bg-blue-50 text-blue-700 hover:bg-blue-100 disabled:opacity-40 disabled:cursor-not-allowed"
                >
                  Save permissions
                </button>
              </div>

              <p *ngIf="rowError[member.id]" class="text-sm text-red-500">{{ rowError[member.id] }}</p>
            </div>
          </div>
          </div>
        </div>
      </main>
    </div>
  `,
})
export class ClinicStaffComponent implements OnInit {
  members: ClinicMember[] = [];
  isLoading = true;
  loadError = '';

  inviteEmail = '';
  inviteRole: ClinicMemberRole = 'staff';
  isInviting = false;
  inviteError = '';
  inviteSuccess = '';

  // RIVA-143: set only right after inviting a brand-new (no prior account)
  // email — the one-time link the inviter must hand to that person.
  lastInviteLink = '';
  lastInviteEmail = '';
  lastInviteRoleLabel = '';
  inviteLinkCopied = false;

  busyMemberId: number | null = null;
  rowError: Record<number, string> = {};
  permissionDrafts: Record<number, string[]> = {};

  readonly allPermissions = CLINIC_PERMISSIONS;

  constructor(private clinicService: ClinicService, private authService: AuthService) {}

  get currentUserId(): number | undefined {
    return this.authService.getUser()?.id;
  }

  get myMembership(): ClinicMember | undefined {
    return this.members.find((m) => m.user_id === this.currentUserId);
  }

  get canManage(): boolean {
    const mine = this.myMembership;
    return !!mine && (mine.role === 'owner' || mine.role === 'admin');
  }

  // RIVA-125 (visual redesign, pass 1): purely presentational summary tiles
  // derived from the same real `members` array the cards below already
  // render — no separate fetch, no fabricated numbers.
  get activeCount(): number {
    return this.members.filter((m) => m.status === 'active').length;
  }

  get invitedCount(): number {
    return this.members.filter((m) => m.status === 'invited').length;
  }

  memberInitials(member: ClinicMember): string {
    const name = (member.name || member.email || '?').trim();
    if (!name) return '?';
    const parts = name.split(/\s+/).filter(Boolean);
    if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
  }

  ngOnInit(): void {
    this.loadMembers();
  }

  loadMembers(): void {
    this.isLoading = true;
    this.loadError = '';
    this.clinicService
      .listMembers()
      .pipe(finalize(() => (this.isLoading = false)))
      .subscribe({
        next: (res) => {
          this.members = res.data || [];
          this.members.forEach((m) => (this.permissionDrafts[m.id] = [...(m.permissions || [])]));
        },
        error: (err) => {
          this.loadError =
            err?.error?.message ||
            (err.status === 403
              ? 'You are not an active member of any clinic yet.'
              : 'Something went wrong loading your clinic team.');
        },
      });
  }

  submitInvite(): void {
    if (!this.inviteEmail) return;
    this.isInviting = true;
    this.inviteError = '';
    this.inviteSuccess = '';
    this.lastInviteLink = '';
    this.inviteLinkCopied = false;
    this.clinicService
      .inviteMember(this.inviteEmail.trim(), this.inviteRole)
      .pipe(finalize(() => (this.isInviting = false)))
      .subscribe({
        next: (res) => {
          this.members = [...this.members, res.data];
          this.permissionDrafts[res.data.id] = [...(res.data.permissions || [])];

          if (res.data.is_new_account && res.data.invite_link) {
            this.lastInviteLink = res.data.invite_link;
            this.lastInviteEmail = res.data.email || this.inviteEmail.trim();
            this.lastInviteRoleLabel = res.data.role;
            this.inviteSuccess = '';
          } else {
            this.inviteSuccess = `Invite sent to ${res.data.email}.`;
          }

          this.inviteEmail = '';
        },
        error: (err) => {
          this.inviteError = err?.error?.message || 'Failed to send invite.';
        },
      });
  }

  copyInviteLink(): void {
    if (!this.lastInviteLink) return;
    navigator.clipboard?.writeText(this.lastInviteLink).then(() => {
      this.inviteLinkCopied = true;
      setTimeout(() => (this.inviteLinkCopied = false), 2500);
    });
  }

  changeRole(member: ClinicMember, role: ClinicMemberRole): void {
    if (role === member.role) return;
    this.busyMemberId = member.id;
    delete this.rowError[member.id];
    this.clinicService
      .updateRole(member.id, role)
      .pipe(finalize(() => (this.busyMemberId = null)))
      .subscribe({
        next: (res) => this.replaceMember(res.data),
        error: (err) => (this.rowError[member.id] = err?.error?.message || 'Failed to update role.'),
      });
  }

  suspend(member: ClinicMember): void {
    this.setStatus(member, 'suspended');
  }

  reactivate(member: ClinicMember): void {
    this.setStatus(member, 'active');
  }

  private setStatus(member: ClinicMember, status: 'active' | 'suspended'): void {
    this.busyMemberId = member.id;
    delete this.rowError[member.id];
    this.clinicService
      .updateStatus(member.id, status)
      .pipe(finalize(() => (this.busyMemberId = null)))
      .subscribe({
        next: (res) => this.replaceMember(res.data),
        error: (err) => (this.rowError[member.id] = err?.error?.message || 'Failed to update status.'),
      });
  }

  remove(member: ClinicMember): void {
    this.busyMemberId = member.id;
    delete this.rowError[member.id];
    this.clinicService
      .removeMember(member.id)
      .pipe(finalize(() => (this.busyMemberId = null)))
      .subscribe({
        next: () => {
          this.members = this.members.filter((m) => m.id !== member.id);
          delete this.permissionDrafts[member.id];
        },
        error: (err) => (this.rowError[member.id] = err?.error?.message || 'Failed to remove member.'),
      });
  }

  isPermissionDraftChecked(member: ClinicMember, permission: string): boolean {
    return (this.permissionDrafts[member.id] || []).includes(permission);
  }

  togglePermissionDraft(member: ClinicMember, permission: string): void {
    const current = this.permissionDrafts[member.id] || [];
    this.permissionDrafts[member.id] = current.includes(permission)
      ? current.filter((p) => p !== permission)
      : [...current, permission];
  }

  hasPermissionDraftChanged(member: ClinicMember): boolean {
    const draft = [...(this.permissionDrafts[member.id] || [])].sort();
    const saved = [...(member.permissions || [])].sort();
    return JSON.stringify(draft) !== JSON.stringify(saved);
  }

  savePermissions(member: ClinicMember): void {
    this.busyMemberId = member.id;
    delete this.rowError[member.id];
    this.clinicService
      .updatePermissions(member.id, this.permissionDrafts[member.id] || [])
      .pipe(finalize(() => (this.busyMemberId = null)))
      .subscribe({
        next: (res) => this.replaceMember(res.data),
        error: (err) => (this.rowError[member.id] = err?.error?.message || 'Failed to update permissions.'),
      });
  }

  private replaceMember(updated: ClinicMember): void {
    this.members = this.members.map((m) => (m.id === updated.id ? updated : m));
    this.permissionDrafts[updated.id] = [...(updated.permissions || [])];
  }
}
