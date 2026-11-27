import { CommonModule } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { finalize } from 'rxjs/operators';
import { API_BASE_URL } from '../../../../constants';
import { AuthService } from '../../../../service/auth.service';
import { SidebarComponent } from '../../../../components/sidebar';
import { DoctorCredential, DoctorCredentialsService } from '../../../../service/doctor-credentials.service';

interface CatalogEntry {
  key: string;
  label: string;
  requires_account_label: boolean;
  account_label_hint: string | null;
}

interface ConfiguredMethod {
  id: number;
  method: string;
  label: string;
  account_label: string | null;
  is_active: boolean;
}

interface Row {
  key: string;
  label: string;
  requiresAccountLabel: boolean;
  accountLabelHint: string | null;
  configuredId: number | null;
  accountLabel: string;
  enabled: boolean;
  isSaving: boolean;
  error: string;
  savedJustNow: boolean;
}

// RIVA audit (Section 1 — Doctor verification): matches
// DoctorVerificationController::status()'s real response shape exactly —
// `doctor_verifications.status` is the authoritative field (see that
// controller and its migration); `Doctor.is_verified`/`verification_status`
// stay synchronized cache fields written by the same admin approve/reject
// action, never a second, independently-editable status.
type VerificationStatus = 'not_submitted' | 'pending' | 'approved' | 'rejected';

interface VerificationRecord {
  id: number;
  status: VerificationStatus;
  rejection_reason: string | null;
  medical_license_number: string | null;
  syndicate_number: string | null;
  specialization: string | null;
  clinic_or_hospital_name: string | null;
  years_of_experience: number | null;
  created_at: string;
  reviewed_at: string | null;
}

interface VerificationStatusResponse {
  status: VerificationStatus;
  verification: VerificationRecord | null;
  doctor: unknown;
}

/**
 * RIVA-130 follow-up (product-owner request, recorded 2026-08-25): "the
 * dashboard has no Settings button... and let the DOCTOR be the one who
 * chooses which payment method(s) they personally accept, based on what's
 * actually available to them." This is the doctor's account-level Settings
 * page, reachable from the sidebar.
 *
 * RIVA audit (Section 1 / Section 8, 2026-08-28): added the Verification
 * section below, connected to the existing, already-correct
 * DoctorVerificationController (`GET doctor/verification/status`,
 * `POST doctor/verification/submit`) — no new backend endpoint needed here.
 * This was a confirmed gap: the backend workflow (upload -> pending ->
 * admin approve/reject -> resubmit) already existed and was already
 * correctly wired for the ADMIN side (AdminAreaController::
 * approveDoctorVerification()/rejectDoctorVerification(), which the current
 * admin UI actually calls), but no Doctor-facing screen ever surfaced the
 * doctor's own status, rejection reason, or a way to (re)submit documents.
 */
@Component({
  selector: 'app-doctor-settings',
  standalone: true,
  imports: [CommonModule, FormsModule, HttpClientModule, SidebarComponent],
  template: `
    <div class="flex min-h-screen bg-[#f7f8fb] font-sans">
      <app-sidebar></app-sidebar>

      <main class="flex-1 p-4 sm:p-8 md:pl-28">
        <div class="mx-auto flex max-w-3xl flex-col gap-6">
          <div>
            <p class="text-xs font-bold uppercase tracking-wider text-blue-600">Account</p>
            <h1 class="mt-1 text-2xl font-bold text-[#1e293b] sm:text-3xl">Settings</h1>
            <p class="mt-1 text-sm text-slate-500">Manage your verification, and how patients pay you for consultations.</p>
          </div>

          <!-- ══════ Verification (Section 1) ══════ -->
          <div class="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
            <div class="mb-5 flex items-start justify-between gap-3">
              <div>
                <h2 class="text-base font-bold text-[#1e293b]">Verification</h2>
                <p class="mt-1 text-sm text-slate-500">
                  Verified doctors get a visible badge on their profile and cards so patients know their documents
                  were reviewed and approved.
                </p>
              </div>
              <span
                *ngIf="!isLoadingVerification"
                class="shrink-0 rounded-full px-3 py-1 text-[11px] font-black uppercase tracking-wider"
                [ngClass]="{
                  'bg-slate-100 text-slate-500': verificationStatus === 'not_submitted',
                  'bg-amber-100 text-amber-700': verificationStatus === 'pending',
                  'bg-emerald-100 text-emerald-700': verificationStatus === 'approved',
                  'bg-red-100 text-red-600': verificationStatus === 'rejected'
                }"
              >
                {{ verificationStatusLabel() }}
              </span>
            </div>

            <div *ngIf="isLoadingVerification" class="flex h-24 items-center justify-center">
              <div class="h-8 w-8 animate-spin rounded-full border-4 border-blue-200 border-t-blue-600"></div>
            </div>

            <div *ngIf="!isLoadingVerification">
              <!-- Pending: no form, just the real state -->
              <p *ngIf="verificationStatus === 'pending'" class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-700">
                <i class="fa-regular fa-clock mr-1.5"></i>Your documents were submitted and are waiting for admin review.
              </p>

              <!-- Approved: no form, just the real state -->
              <p *ngIf="verificationStatus === 'approved'" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                <i class="fa-solid fa-circle-check mr-1.5"></i>You're verified. The badge is now visible on your profile and doctor cards.
              </p>

              <!-- Rejected: show the real reason, then the resubmission form -->
              <p *ngIf="verificationStatus === 'rejected' && verification?.rejection_reason" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-600">
                <i class="fa-solid fa-circle-exclamation mr-1.5"></i><strong>Not approved:</strong> {{ verification?.rejection_reason }}
              </p>

              <!-- Submit / resubmit form: shown for not_submitted and rejected -->
              <form
                *ngIf="verificationStatus === 'not_submitted' || verificationStatus === 'rejected'"
                (ngSubmit)="submitVerification()"
                class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2"
              >
                <div class="sm:col-span-2">
                  <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-400">National ID / identity document *</label>
                  <input type="file" accept=".jpg,.jpeg,.png,.pdf" (change)="onFileSelected($event, 'national_id_file')" required
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm" />
                </div>
                <div class="sm:col-span-2">
                  <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-400">Medical license *</label>
                  <input type="file" accept=".jpg,.jpeg,.png,.pdf" (change)="onFileSelected($event, 'medical_license_file')" required
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm" />
                </div>
                <div class="sm:col-span-2">
                  <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-400">Syndicate card *</label>
                  <input type="file" accept=".jpg,.jpeg,.png,.pdf" (change)="onFileSelected($event, 'syndicate_card_file')" required
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm" />
                </div>
                <div class="sm:col-span-2">
                  <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-400">Certificate (optional)</label>
                  <input type="file" accept=".jpg,.jpeg,.png,.pdf" (change)="onFileSelected($event, 'certificate_file')"
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm" />
                </div>

                <input [(ngModel)]="verificationForm.medical_license_number" name="medical_license_number" placeholder="Medical license number"
                  class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm" />
                <input [(ngModel)]="verificationForm.syndicate_number" name="syndicate_number" placeholder="Syndicate number"
                  class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm" />
                <input [(ngModel)]="verificationForm.specialization" name="specialization" placeholder="Specialization"
                  class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm" />
                <input [(ngModel)]="verificationForm.years_of_experience" name="years_of_experience" type="number" min="0" max="80"
                  placeholder="Years of experience" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm" />
                <input [(ngModel)]="verificationForm.clinic_or_hospital_name" name="clinic_or_hospital_name" placeholder="Clinic / hospital name"
                  class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm sm:col-span-2" />

                <div class="sm:col-span-2">
                  <button
                    type="submit"
                    [disabled]="isSubmittingVerification"
                    class="rounded-xl bg-[#1d4ed8] px-6 py-2.5 font-bold text-white transition-all hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    {{ isSubmittingVerification ? 'Submitting…' : (verificationStatus === 'rejected' ? 'Resubmit documents' : 'Submit for verification') }}
                  </button>
                  <p *ngIf="verificationError" class="mt-3 text-sm text-red-500">{{ verificationError }}</p>
                  <p *ngIf="verificationSuccess" class="mt-3 text-sm text-green-600">{{ verificationSuccess }}</p>
                </div>
              </form>

              <p class="mt-4 text-xs text-slate-400">
                <i class="fa-solid fa-lock mr-1"></i>These documents are private and reviewed by Riva admins only — they are never shown publicly.
              </p>
            </div>
          </div>

          <!-- ══════ Professional Credentials (Section 2) ══════ -->
          <div class="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
            <div class="mb-5">
              <h2 class="text-base font-bold text-[#1e293b]">Professional credentials</h2>
              <p class="mt-1 text-sm text-slate-500">
                Degrees, certificates and training you'd like patients to see on your public profile. This is
                separate from your private verification documents above — nothing here is shown publicly until
                you turn "Show on profile" on for it.
              </p>
            </div>

            <div *ngIf="isLoadingCredentials" class="flex h-20 items-center justify-center">
              <div class="h-7 w-7 animate-spin rounded-full border-4 border-blue-200 border-t-blue-600"></div>
            </div>

            <div *ngIf="!isLoadingCredentials" class="flex flex-col divide-y divide-slate-100">
              <div *ngFor="let c of credentials" class="flex flex-col gap-2 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                  <p class="font-semibold text-[#1e293b]">{{ c.title }}</p>
                  <p class="text-xs text-slate-500">
                    {{ credentialTypeLabel(c.credential_type) }}
                    <span *ngIf="c.issuing_organization"> &middot; {{ c.issuing_organization }}</span>
                    <span *ngIf="c.year"> &middot; {{ c.year }}</span>
                  </p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                  <button
                    type="button"
                    (click)="toggleCredentialPublic(c)"
                    [disabled]="credentialBusyId === c.id"
                    class="whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-bold disabled:opacity-50"
                    [ngClass]="c.is_public ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'"
                  >
                    {{ c.is_public ? 'Shown on profile' : 'Hidden' }}
                  </button>
                  <button
                    type="button"
                    (click)="deleteCredential(c)"
                    [disabled]="credentialBusyId === c.id"
                    class="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-bold text-red-600 disabled:opacity-50"
                  >
                    Delete
                  </button>
                </div>
              </div>
              <p *ngIf="credentials.length === 0" class="py-2 text-xs text-slate-400">No credentials added yet.</p>
            </div>

            <form (ngSubmit)="submitCredential()" class="mt-5 grid grid-cols-1 gap-3 border-t border-slate-100 pt-5 sm:grid-cols-2">
              <input [(ngModel)]="credentialForm.title" name="cred_title" placeholder="Title (e.g. MBBCh - Cairo University)" required
                class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm sm:col-span-2" />
              <select [(ngModel)]="credentialForm.credential_type" name="cred_type"
                class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm">
                <option value="bachelor">Bachelor</option>
                <option value="master">Master's</option>
                <option value="phd">PhD</option>
                <option value="certificate">Certificate</option>
                <option value="training">Training</option>
                <option value="professional_license">Professional License</option>
                <option value="other">Other</option>
              </select>
              <input [(ngModel)]="credentialForm.year" name="cred_year" type="number" placeholder="Year"
                class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm" />
              <input [(ngModel)]="credentialForm.issuing_organization" name="cred_org" placeholder="Issuing organization (optional)"
                class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm sm:col-span-2" />
              <textarea [(ngModel)]="credentialForm.description" name="cred_desc" rows="2" placeholder="Description (optional)"
                class="resize-none rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm sm:col-span-2"></textarea>
              <div class="sm:col-span-2">
                <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-400">Document (optional)</label>
                <input type="file" accept=".jpg,.jpeg,.png,.pdf" (change)="onCredentialFileSelected($event)"
                  class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm" />
              </div>
              <label class="flex items-center gap-2 text-sm text-slate-600 sm:col-span-2">
                <input type="checkbox" [(ngModel)]="credentialForm.is_public" name="cred_public" />
                Show this credential on my public profile
              </label>
              <div class="sm:col-span-2">
                <button type="submit" [disabled]="isSubmittingCredential"
                  class="rounded-xl bg-[#1d4ed8] px-6 py-2.5 font-bold text-white transition-all hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">
                  {{ isSubmittingCredential ? 'Saving…' : 'Add credential' }}
                </button>
                <p *ngIf="credentialError" class="mt-3 text-sm text-red-500">{{ credentialError }}</p>
              </div>
            </form>
          </div>

          <!-- ══════ Payment methods ══════ -->
          <div class="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
            <div class="mb-5">
              <h2 class="text-base font-bold text-[#1e293b]">Payment methods</h2>
              <p class="mt-1 text-sm text-slate-500">
                Turn on whichever methods you personally accept and add the number or handle patients should send
                to. Patients booking with you will only ever see the methods you've turned on here — nothing is
                shown until you configure it.
              </p>
            </div>

            <div *ngIf="isLoading" class="flex h-32 items-center justify-center">
              <div class="h-8 w-8 animate-spin rounded-full border-4 border-blue-200 border-t-blue-600"></div>
            </div>

            <p *ngIf="!isLoading && loadError" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-600">
              {{ loadError }}
            </p>

            <div *ngIf="!isLoading && !loadError" class="flex flex-col divide-y divide-slate-100">
              <div *ngFor="let row of rows" class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex items-start gap-3">
                  <button
                    type="button"
                    role="switch"
                    [attr.aria-checked]="row.enabled"
                    (click)="row.enabled = !row.enabled"
                    class="mt-0.5 h-6 w-11 shrink-0 rounded-full transition-colors"
                    [ngClass]="row.enabled ? 'bg-blue-600' : 'bg-slate-200'"
                  >
                    <span
                      class="block h-5 w-5 translate-x-0.5 rounded-full bg-white shadow transition-transform"
                      [ngClass]="row.enabled ? 'translate-x-[22px]' : 'translate-x-0.5'"
                    ></span>
                  </button>
                  <div>
                    <p class="font-semibold text-[#1e293b]">{{ row.label }}</p>
                    <p *ngIf="row.configuredId && row.enabled" class="text-xs text-emerald-600">Visible to patients</p>
                    <p *ngIf="!row.enabled" class="text-xs text-slate-400">Hidden from patients</p>
                  </div>
                </div>

                <div class="flex flex-1 flex-col items-stretch gap-2 sm:max-w-xs sm:flex-row sm:items-center">
                  <input
                    *ngIf="row.requiresAccountLabel"
                    type="text"
                    [(ngModel)]="row.accountLabel"
                    [placeholder]="row.accountLabelHint || 'Account detail'"
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:border-blue-400 focus:bg-white focus:outline-none"
                  />
                  <button
                    type="button"
                    (click)="saveRow(row)"
                    [disabled]="row.isSaving"
                    class="whitespace-nowrap rounded-xl bg-[#1d4ed8] px-4 py-2 text-sm font-bold text-white transition-all hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    {{ row.isSaving ? 'Saving…' : row.savedJustNow ? 'Saved ✓' : 'Save' }}
                  </button>
                </div>
                <p *ngIf="row.error" class="w-full text-xs text-red-500 sm:basis-full">{{ row.error }}</p>
              </div>
            </div>
            <p *ngIf="!isLoading && !loadError && rows.length === 0" class="mt-2 text-xs text-slate-400">
              No payment methods available to configure.
            </p>
          </div>
        </div>
      </main>
    </div>
  `,
})
export class DoctorSettingsComponent implements OnInit {
  rows: Row[] = [];
  isLoading = true;
  loadError = '';

  // ── Verification (Section 1) ─────────────────────────────────────────
  isLoadingVerification = true;
  verificationStatus: VerificationStatus = 'not_submitted';
  verification: VerificationRecord | null = null;
  verificationForm: {
    medical_license_number: string;
    syndicate_number: string;
    specialization: string;
    clinic_or_hospital_name: string;
    years_of_experience: number | null;
  } = { medical_license_number: '', syndicate_number: '', specialization: '', clinic_or_hospital_name: '', years_of_experience: null };
  private verificationFiles: Record<string, File> = {};
  isSubmittingVerification = false;
  verificationError = '';
  verificationSuccess = '';

  // ── Professional Credentials (Section 2) ──────────────────────────────
  credentials: DoctorCredential[] = [];
  isLoadingCredentials = true;
  credentialForm: {
    title: string;
    credential_type: string;
    issuing_organization: string;
    year: number | null;
    description: string;
    is_public: boolean;
  } = { title: '', credential_type: 'certificate', issuing_organization: '', year: null, description: '', is_public: false };
  private credentialFile: File | null = null;
  credentialBusyId: number | null = null;
  isSubmittingCredential = false;
  credentialError = '';

  private get authHeaders() {
    return { Authorization: `Bearer ${this.authService.getToken()}` };
  }

  constructor(private http: HttpClient, private authService: AuthService, private doctorCredentialsService: DoctorCredentialsService) {}

  ngOnInit(): void {
    this.load();
    this.loadVerificationStatus();
    this.loadCredentials();
  }

  // ── Verification ──────────────────────────────────────────────────
  loadVerificationStatus(): void {
    this.isLoadingVerification = true;
    this.http
      .get<any>(`${API_BASE_URL}/doctor/verification/status`, { headers: this.authHeaders })
      .pipe(finalize(() => (this.isLoadingVerification = false)))
      .subscribe({
        next: (res) => {
          const data: VerificationStatusResponse = res?.data ?? res;
          this.verificationStatus = data?.status || 'not_submitted';
          this.verification = data?.verification || null;
          if (this.verification) {
            this.verificationForm = {
              medical_license_number: this.verification.medical_license_number || '',
              syndicate_number: this.verification.syndicate_number || '',
              specialization: this.verification.specialization || '',
              clinic_or_hospital_name: this.verification.clinic_or_hospital_name || '',
              years_of_experience: this.verification.years_of_experience,
            };
          }
        },
        error: () => {
          // Honest fallback: an unreadable status is treated as not_submitted
          // (the same default DoctorVerificationController::status() itself
          // falls back to) rather than guessing "approved"/"rejected".
          this.verificationStatus = 'not_submitted';
        },
      });
  }

  verificationStatusLabel(): string {
    switch (this.verificationStatus) {
      case 'pending': return 'Pending review';
      case 'approved': return 'Verified';
      case 'rejected': return 'Rejected';
      default: return 'Not submitted';
    }
  }

  onFileSelected(event: Event, field: string): void {
    const input = event.target as HTMLInputElement;
    if (input.files && input.files[0]) {
      this.verificationFiles[field] = input.files[0];
    }
  }

  submitVerification(): void {
    if (!this.verificationFiles['national_id_file'] || !this.verificationFiles['medical_license_file'] || !this.verificationFiles['syndicate_card_file']) {
      this.verificationError = 'National ID, medical license, and syndicate card are required.';
      return;
    }

    this.isSubmittingVerification = true;
    this.verificationError = '';
    this.verificationSuccess = '';

    const formData = new FormData();
    Object.entries(this.verificationFiles).forEach(([key, file]) => formData.append(key, file));
    if (this.verificationForm.medical_license_number) formData.append('medical_license_number', this.verificationForm.medical_license_number);
    if (this.verificationForm.syndicate_number) formData.append('syndicate_number', this.verificationForm.syndicate_number);
    if (this.verificationForm.specialization) formData.append('specialization', this.verificationForm.specialization);
    if (this.verificationForm.clinic_or_hospital_name) formData.append('clinic_or_hospital_name', this.verificationForm.clinic_or_hospital_name);
    if (this.verificationForm.years_of_experience !== null) formData.append('years_of_experience', String(this.verificationForm.years_of_experience));

    this.http
      .post<any>(`${API_BASE_URL}/doctor/verification/submit`, formData, { headers: this.authHeaders })
      .pipe(finalize(() => (this.isSubmittingVerification = false)))
      .subscribe({
        next: (res) => {
          this.verificationSuccess = res?.message || 'Your verification is pending admin review.';
          this.verificationFiles = {};
          this.loadVerificationStatus();
        },
        error: (err) => {
          this.verificationError = err?.error?.message || 'Failed to submit verification documents.';
        },
      });
  }

  // ── Professional Credentials (Section 2) ──────────────────────────────
  credentialTypeLabel(type: string): string {
    switch (type) {
      case 'bachelor': return 'Bachelor';
      case 'master': return "Master's";
      case 'phd': return 'PhD';
      case 'certificate': return 'Certificate';
      case 'training': return 'Training';
      case 'professional_license': return 'Professional License';
      default: return 'Other';
    }
  }

  loadCredentials(): void {
    this.isLoadingCredentials = true;
    this.doctorCredentialsService
      .list()
      .pipe(finalize(() => (this.isLoadingCredentials = false)))
      .subscribe({
        next: (res) => {
          this.credentials = res?.data ?? [];
        },
        error: () => {
          // Honest fallback: an unreadable list renders as empty rather than
          // guessing — matches the pattern used for payment methods above.
          this.credentials = [];
        },
      });
  }

  onCredentialFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.credentialFile = input.files && input.files[0] ? input.files[0] : null;
  }

  submitCredential(): void {
    if (!this.credentialForm.title.trim()) {
      this.credentialError = 'Title is required.';
      return;
    }

    this.isSubmittingCredential = true;
    this.credentialError = '';

    const formData = new FormData();
    formData.append('title', this.credentialForm.title.trim());
    formData.append('credential_type', this.credentialForm.credential_type);
    if (this.credentialForm.issuing_organization) formData.append('issuing_organization', this.credentialForm.issuing_organization);
    if (this.credentialForm.year !== null) formData.append('year', String(this.credentialForm.year));
    if (this.credentialForm.description) formData.append('description', this.credentialForm.description);
    formData.append('is_public', this.credentialForm.is_public ? '1' : '0');
    if (this.credentialFile) formData.append('file', this.credentialFile);

    this.doctorCredentialsService
      .create(formData)
      .pipe(finalize(() => (this.isSubmittingCredential = false)))
      .subscribe({
        next: () => {
          this.credentialForm = { title: '', credential_type: 'certificate', issuing_organization: '', year: null, description: '', is_public: false };
          this.credentialFile = null;
          this.loadCredentials();
        },
        error: (err) => {
          this.credentialError = err?.error?.message || 'Failed to add this credential.';
        },
      });
  }

  toggleCredentialPublic(c: DoctorCredential): void {
    this.credentialBusyId = c.id;
    const nextValue = !c.is_public;
    this.doctorCredentialsService
      .update(c.id, { is_public: nextValue })
      .pipe(finalize(() => (this.credentialBusyId = null)))
      .subscribe({
        next: () => {
          c.is_public = nextValue;
        },
        error: () => {
          // Leave the row's displayed state untouched on failure so the UI
          // never claims a change was saved when it wasn't.
        },
      });
  }

  deleteCredential(c: DoctorCredential): void {
    this.credentialBusyId = c.id;
    this.doctorCredentialsService
      .remove(c.id)
      .pipe(finalize(() => (this.credentialBusyId = null)))
      .subscribe({
        next: () => {
          this.credentials = this.credentials.filter((x) => x.id !== c.id);
        },
        error: () => {
          // Leave the row in place on failure rather than silently dropping it.
        },
      });
  }

  // ── Payment methods ──────────────────────────────────────────────────
  private load(): void {
    this.isLoading = true;
    this.loadError = '';

    this.http.get<any>(`${API_BASE_URL}/payment-methods/catalog`, { headers: this.authHeaders }).subscribe({
      next: (catalogRes) => {
        const catalog: CatalogEntry[] = catalogRes?.data ?? catalogRes ?? [];

        this.http.get<any>(`${API_BASE_URL}/doctor/payment-methods`, { headers: this.authHeaders })
          .pipe(finalize(() => (this.isLoading = false)))
          .subscribe({
            next: (configuredRes) => {
              const configured: ConfiguredMethod[] = configuredRes?.data ?? configuredRes ?? [];
              this.rows = catalog.map((entry) => {
                const existing = configured.find((c) => c.method === entry.key);
                return {
                  key: entry.key,
                  label: entry.label,
                  requiresAccountLabel: entry.requires_account_label,
                  accountLabelHint: entry.account_label_hint,
                  configuredId: existing?.id ?? null,
                  accountLabel: existing?.account_label || '',
                  enabled: existing?.is_active ?? false,
                  isSaving: false,
                  error: '',
                  savedJustNow: false,
                };
              });
            },
            error: (err) => {
              this.loadError = err?.error?.message || 'Failed to load your payment method settings.';
            },
          });
      },
      error: (err) => {
        this.isLoading = false;
        this.loadError = err?.error?.message || 'Failed to load the payment methods catalog.';
      },
    });
  }

  saveRow(row: Row): void {
    if (row.requiresAccountLabel && row.enabled && !row.accountLabel.trim()) {
      row.error = 'Add an account number/handle before turning this on.';
      return;
    }

    row.isSaving = true;
    row.error = '';
    row.savedJustNow = false;

    this.http.post<any>(`${API_BASE_URL}/doctor/payment-methods`, {
      method: row.key,
      account_label: row.accountLabel.trim() || null,
      is_active: row.enabled,
    }, { headers: this.authHeaders })
      .pipe(finalize(() => (row.isSaving = false)))
      .subscribe({
        next: (res) => {
          const data = res?.data ?? res;
          row.configuredId = data?.id ?? row.configuredId;
          row.savedJustNow = true;
          setTimeout(() => (row.savedJustNow = false), 2500);
        },
        error: (err) => {
          row.error = err?.error?.message || 'Failed to save this payment method.';
        },
      });
  }
}
