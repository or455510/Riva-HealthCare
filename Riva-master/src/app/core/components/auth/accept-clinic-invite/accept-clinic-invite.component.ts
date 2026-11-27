import { CommonModule } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HttpClientModule } from '@angular/common/http';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { finalize } from 'rxjs/operators';
import { ClinicInviteDetails, ClinicService } from '../../../../service/clinic.service';
import { AuthService } from '../../../../service/auth.service';

/**
 * RIVA-143 (product decision, recorded 2026-08-25, confirmed by the product
 * owner): the real invite-link flow for clinic staff. This page is
 * deliberately PUBLIC (no AuthGuard, see app.routes.ts) — the invitee has no
 * account/token yet when they click the link a clinic owner/admin hands
 * them. It never shows the public role picker (see signup.component) —
 * their clinic role was already set by the inviting admin
 * (ClinicMembershipController::store()); this page only collects their name
 * and a password of their own choosing.
 */
@Component({
  selector: 'app-accept-clinic-invite',
  standalone: true,
  imports: [CommonModule, FormsModule, HttpClientModule, RouterModule],
  template: `
    <div class="flex min-h-screen items-center justify-center bg-[#f7f8fb] p-4 font-sans">
      <div class="w-full max-w-md rounded-[24px] border border-slate-200 bg-white p-7 shadow-sm sm:p-8">

        <!-- Loading -->
        <div *ngIf="isLoadingDetails" class="flex h-48 items-center justify-center">
          <div class="h-10 w-10 animate-spin rounded-full border-4 border-blue-200 border-t-blue-600"></div>
        </div>

        <!-- Invalid / expired link -->
        <div *ngIf="!isLoadingDetails && detailsError" class="text-center">
          <i class="fa-solid fa-triangle-exclamation mb-3 text-3xl text-red-400"></i>
          <p class="mb-1 font-semibold text-[#1e293b]">This invite link doesn't work</p>
          <p class="text-sm text-slate-500">{{ detailsError }}</p>
          <a routerLink="/signin" class="mt-5 inline-block text-sm font-bold text-blue-600 hover:underline">Go to sign in</a>
        </div>

        <!-- Invite form -->
        <div *ngIf="!isLoadingDetails && !detailsError && details">
          <p class="text-xs font-bold uppercase tracking-wider text-blue-600">Clinic Invite</p>
          <h1 class="mt-1 text-2xl font-bold text-[#1e293b]">Join {{ details.clinic_name || 'your clinic' }}</h1>
          <p class="mt-1 text-sm text-slate-500">
            You've been invited as <span class="font-semibold text-slate-700">{{ details.role }}</span>
            ({{ details.email }}). Set your name and a password to activate your account.
          </p>

          <form (ngSubmit)="submit()" class="mt-6 flex flex-col gap-3">
            <div class="grid grid-cols-2 gap-3">
              <input
                type="text" name="first_name" [(ngModel)]="firstName" placeholder="First name" required
                class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm transition-colors focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
              />
              <input
                type="text" name="last_name" [(ngModel)]="lastName" placeholder="Last name" required
                class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm transition-colors focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
              />
            </div>
            <input
              type="password" name="password" [(ngModel)]="password" placeholder="Password (min 8 characters)" required minlength="8"
              class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm transition-colors focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
            />
            <input
              type="password" name="password_confirmation" [(ngModel)]="passwordConfirmation" placeholder="Confirm password" required
              class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm transition-colors focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
            />

            <p *ngIf="submitError" class="text-sm text-red-500">{{ submitError }}</p>

            <button
              type="submit"
              [disabled]="isSubmitting || !firstName || !lastName || !password || !passwordConfirmation"
              class="mt-2 rounded-xl bg-[#1d4ed8] py-2.5 text-sm font-bold text-white transition-all hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {{ isSubmitting ? 'Setting up your account…' : 'Join clinic' }}
            </button>
          </form>
        </div>
      </div>
    </div>
  `,
})
export class AcceptClinicInviteComponent implements OnInit {
  token = '';
  details: ClinicInviteDetails | null = null;
  isLoadingDetails = true;
  detailsError = '';

  firstName = '';
  lastName = '';
  password = '';
  passwordConfirmation = '';
  isSubmitting = false;
  submitError = '';

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private clinicService: ClinicService,
    private authService: AuthService,
  ) {}

  ngOnInit(): void {
    this.token = this.route.snapshot.paramMap.get('token') || '';
    if (!this.token) {
      this.detailsError = 'No invite token was provided.';
      this.isLoadingDetails = false;
      return;
    }

    this.clinicService
      .getInviteDetails(this.token)
      .pipe(finalize(() => (this.isLoadingDetails = false)))
      .subscribe({
        next: (res) => { this.details = res.data; },
        error: (err) => {
          this.detailsError = err?.status === 410
            ? 'This invite link has expired. Ask the clinic to send you a new one.'
            : (err?.error?.message || 'This invite link is invalid or has already been used.');
        },
      });
  }

  submit(): void {
    if (this.password !== this.passwordConfirmation) {
      this.submitError = 'Passwords do not match.';
      return;
    }

    this.isSubmitting = true;
    this.submitError = '';

    this.clinicService
      .acceptInvite(this.token, {
        first_name: this.firstName.trim(),
        last_name: this.lastName.trim(),
        password: this.password,
        password_confirmation: this.passwordConfirmation,
      })
      .pipe(finalize(() => (this.isSubmitting = false)))
      .subscribe({
        next: (res) => {
          this.authService.saveToken({
            message: res.message,
            user: res.data.user as any,
            token: res.data.token,
          });
          // RIVA-143: staff lands directly at the Front Desk they'll
          // actually use; any other invited role lands on the team page.
          const landing = res.data.clinic_role === 'staff' ? '/clinic-patients' : '/clinic-staff';
          this.router.navigate([landing]);
        },
        error: (err) => {
          this.submitError = err?.error?.message || 'Failed to set up your account. Please try again.';
        },
      });
  }
}
