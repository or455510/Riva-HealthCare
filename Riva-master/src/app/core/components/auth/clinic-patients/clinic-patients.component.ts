import { CommonModule } from '@angular/common';
import { Component, OnDestroy, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HttpClientModule } from '@angular/common/http';
import { RouterModule, Router } from '@angular/router';
import { Subject, Subscription } from 'rxjs';
import { debounceTime, distinctUntilChanged, finalize } from 'rxjs/operators';
import {
  ClinicPatient,
  ClinicPatientService,
  RegisterPatientPayload,
} from '../../../../service/clinic-patient.service';
import { SidebarComponent } from '../../../../components/sidebar';

/**
 * Vertical slice (Phase C, principal-engineering directive): the front-desk
 * screen for ClinicPatientController — search first (exact email or phone
 * match only, matching the backend's deliberate no-fuzzy-search design),
 * then register a genuinely new walk-in only when search confirms nothing
 * already exists. Every result is real backend data; "not found" is a real
 * empty state, never assumed.
 */
@Component({
  selector: 'app-clinic-patients',
  standalone: true,
  imports: [CommonModule, FormsModule, HttpClientModule, RouterModule, SidebarComponent],
  template: `
    <div class="flex min-h-screen bg-[#f7f8fb] font-sans">
      <app-sidebar></app-sidebar>

      <main class="flex-1 p-4 sm:p-8 md:pl-28">
        <div class="mx-auto flex max-w-6xl flex-col gap-6">
          <!-- Page header -->
          <div>
            <p class="text-xs font-bold uppercase tracking-wider text-blue-600">Clinic</p>
            <h1 class="mt-1 text-2xl font-bold text-[#1e293b] sm:text-3xl">Patients (Front Desk)</h1>
            <p class="mt-1 text-sm text-slate-500">
              Look up an existing patient by email or phone, or register a new walk-in.
            </p>
          </div>

          <!-- RIVA-141: live name search, scoped server-side to patients who
               already have a relationship with a doctor at this clinic. This
               is a "quick find" for a receptionist who recognizes a name but
               doesn't have the email/phone in front of them — it is NOT a
               platform-wide browse (see the note in the empty state below). -->
          <div class="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
            <div class="mb-4 flex items-center gap-3">
              <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-600">
                <i class="fa-solid fa-bolt"></i>
              </div>
              <div>
                <h2 class="text-base font-bold text-[#1e293b]">Quick find by name</h2>
                <p class="text-xs text-slate-400">Live results as you type — limited to patients already known to this clinic.</p>
              </div>
            </div>
            <div class="relative">
              <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-slate-300"></i>
              <input
                type="text"
                [(ngModel)]="nameQuery"
                (ngModelChange)="onNameQueryInput()"
                placeholder="Start typing a patient's name…"
                class="w-full rounded-xl border border-slate-200 bg-slate-50 py-2.5 pl-10 pr-4 text-sm font-medium text-[#1e293b] outline-none transition-colors focus:border-blue-400 focus:bg-white focus:ring-2 focus:ring-blue-100"
              />
              <span *ngIf="isNameSearching" class="absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin rounded-full border-2 border-blue-200 border-t-blue-600"></span>
            </div>

            <p *ngIf="nameSearchError" class="mt-3 text-sm text-red-500">{{ nameSearchError }}</p>

            <p *ngIf="nameQuery.trim().length > 0 && nameQuery.trim().length < 3" class="mt-3 text-xs text-slate-400">
              Keep typing — at least 3 letters needed to search.
            </p>

            <!-- Live results -->
            <div *ngIf="nameQuery.trim().length >= 3 && !isNameSearching && !nameSearchError" class="mt-3">
              <div *ngIf="nameResults.length === 0" class="rounded-xl bg-slate-50 p-4 text-center text-sm text-slate-400">
                No known patient matches "{{ nameQuery.trim() }}".
                <span class="mt-1 block text-xs text-slate-400">
                  This only searches patients already linked to a doctor at this clinic. For a brand-new walk-in, use the email/phone search below and register them.
                </span>
              </div>
              <ul *ngIf="nameResults.length > 0" class="flex flex-col divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-100">
                <li
                  *ngFor="let patient of nameResults"
                  (click)="selectNameResult(patient)"
                  class="flex cursor-pointer items-center justify-between gap-3 bg-white px-4 py-3 transition-colors hover:bg-blue-50"
                >
                  <div class="flex min-w-0 items-center gap-3">
                    <div class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-gradient-to-br from-blue-500 to-blue-700 text-xs font-bold text-white">
                      {{ patientInitials(patient) }}
                    </div>
                    <div class="min-w-0">
                      <p class="truncate text-sm font-semibold text-[#1e293b]">{{ patient.name || 'Unnamed patient' }}</p>
                      <p class="truncate text-xs text-slate-400">{{ patient.email }}</p>
                    </div>
                  </div>
                  <span
                    class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-wider"
                    [ngClass]="patient.profile_completed ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'"
                  >
                    {{ patient.profile_completed ? 'Complete' : 'Incomplete' }}
                  </span>
                </li>
              </ul>
            </div>
          </div>

          <!-- Search -->
          <div class="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
            <div class="mb-4 flex items-center gap-3">
              <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-600">
                <i class="fa-solid fa-magnifying-glass"></i>
              </div>
              <div>
                <h2 class="text-base font-bold text-[#1e293b]">Find by email or phone</h2>
                <p class="text-xs text-slate-400">Exact match on email or phone only — the patient must be in front of you. Use this to register a new walk-in.</p>
              </div>
            </div>
            <form (ngSubmit)="search()" class="flex flex-col gap-3 sm:flex-row">
              <select
                name="searchBy"
                [(ngModel)]="searchBy"
                class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-colors focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
              >
                <option value="email">By email</option>
                <option value="phone">By phone</option>
              </select>
              <input
                type="text"
                name="searchValue"
                [(ngModel)]="searchValue"
                [placeholder]="searchBy === 'email' ? 'patient@email.com' : '01xxxxxxxxx'"
                required
                class="flex-1 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-colors focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
              />
              <button
                type="submit"
                [disabled]="isSearching || !searchValue"
                class="whitespace-nowrap rounded-xl bg-[#1d4ed8] px-6 py-2.5 font-bold text-white transition-all hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
              >
                <span *ngIf="!isSearching">Search</span>
                <span *ngIf="isSearching" class="inline-flex items-center gap-2">
                  <span class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                  Searching…
                </span>
              </button>
            </form>
            <p *ngIf="searchError" class="mt-3 text-sm text-red-500">{{ searchError }}</p>
          </div>

          <!-- Found -->
          <div
            id="clinic-patient-found"
            *ngIf="searchResult?.found"
            class="rounded-[20px] border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
          >
            <p class="mb-3 text-[11px] font-black uppercase tracking-wider text-emerald-600">
              <i class="fa-solid fa-circle-check mr-1"></i>Patient found
            </p>
            <div class="flex flex-wrap items-start justify-between gap-4">
              <div class="flex min-w-0 items-center gap-3">
                <div
                  class="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-gradient-to-br from-blue-500 to-blue-700 text-sm font-bold text-white"
                >
                  {{ patientInitials(searchResult?.patient) }}
                </div>
                <div class="min-w-0">
                  <p class="truncate text-lg font-semibold text-[#1e293b]">
                    {{ searchResult?.patient?.name || 'Unnamed patient' }}
                  </p>
                  <p class="truncate text-sm text-slate-500">
                    <i class="fa-regular fa-envelope mr-1.5 text-slate-400"></i>{{ searchResult?.patient?.email }}
                  </p>
                  <p class="truncate text-sm text-slate-500" *ngIf="searchResult?.patient?.phone">
                    <i class="fa-solid fa-phone mr-1.5 text-slate-400"></i>{{ searchResult?.patient?.phone }}
                  </p>
                </div>
              </div>
              <div class="flex shrink-0 flex-col items-end gap-1.5">
                <span
                  class="rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wider"
                  [ngClass]="searchResult?.patient?.profile_completed
                    ? 'bg-emerald-100 text-emerald-700'
                    : 'bg-amber-100 text-amber-700'"
                >
                  {{ searchResult?.patient?.profile_completed ? 'Profile complete' : 'Profile incomplete' }}
                </span>
                <span *ngIf="searchResult?.patient?.gender" class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                  {{ searchResult?.patient?.gender }}
                </span>
                <span *ngIf="searchResult?.patient?.age" class="text-xs text-slate-400">{{ searchResult?.patient?.age }} yrs old</span>
              </div>
            </div>
            <!-- RIVA-141: link into the canonical patient detail page (profile-p)
                 instead of clinic-patients being a dead-end list. Only shown when
                 a real Patient row exists (patient_id) — a fresh placeholder
                 account has none yet. profile-p already handles a 403 gracefully
                 (e.g. front-desk staff with no personal doctor/caregiver
                 relationship to this specific patient) with a clear message, so
                 offering the link is safe rather than guessing access
                 client-side. -->
            <a
              *ngIf="searchResult?.patient?.patient_id"
              [routerLink]="['/profile-p', searchResult?.patient?.patient_id]"
              class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-blue-700 hover:text-blue-800"
            >
              View full profile <i class="fa-solid fa-arrow-right text-xs"></i>
            </a>
          </div>

          <!-- Not found -->
          <div
            *ngIf="searchResult && !searchResult.found && !showRegisterForm"
            class="flex flex-wrap items-center justify-between gap-4 rounded-[20px] border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
          >
            <div class="flex items-center gap-3">
              <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-amber-50 text-amber-600">
                <i class="fa-solid fa-circle-info"></i>
              </div>
              <p class="text-sm text-slate-600">No existing patient matches that {{ searchBy }}.</p>
            </div>
            <button
              (click)="openRegisterForm()"
              class="whitespace-nowrap rounded-xl bg-[#1d4ed8] px-5 py-2 font-bold text-white transition-all hover:bg-blue-700"
            >
              Register new patient
            </button>
          </div>

          <!-- Register form -->
          <div *ngIf="showRegisterForm" class="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
            <div class="mb-4 flex items-center gap-3">
              <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-600">
                <i class="fa-solid fa-user-plus"></i>
              </div>
              <div>
                <h2 class="text-base font-bold text-[#1e293b]">Register walk-in patient</h2>
                <p class="text-xs text-slate-400">Creates a real Riva patient account. They claim it later via "forgot password".</p>
              </div>
            </div>
            <form (ngSubmit)="submitRegister()" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <input name="first_name" [(ngModel)]="form.first_name" placeholder="First name" required
                class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-colors focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100" />
              <input name="last_name" [(ngModel)]="form.last_name" placeholder="Last name" required
                class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-colors focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100" />
              <input name="email" type="email" [(ngModel)]="form.email" placeholder="Email" required
                class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-colors focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100 sm:col-span-2" />
              <input name="phone" [(ngModel)]="form.phone" placeholder="Phone (optional)"
                class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-colors focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100" />
              <select name="gender" [(ngModel)]="form.gender" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-colors focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100">
                <option [ngValue]="undefined">Gender (optional)</option>
                <option value="male">Male</option>
                <option value="female">Female</option>
                <option value="other">Other</option>
              </select>
              <input name="age" type="number" min="0" max="120" [(ngModel)]="form.age" placeholder="Age (optional)"
                class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-colors focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100" />
              <select name="blood_type" [(ngModel)]="form.blood_type" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-colors focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100">
                <option [ngValue]="undefined">Blood type (optional)</option>
                <option *ngFor="let bt of bloodTypes" [value]="bt">{{ bt }}</option>
              </select>
              <input name="emergency_contact" [(ngModel)]="form.emergency_contact" placeholder="Emergency contact name & phone (optional)"
                class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-colors focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100 sm:col-span-2" />

              <div class="flex items-center gap-3 pt-2 sm:col-span-2">
                <button
                  type="submit"
                  [disabled]="isRegistering"
                  class="rounded-xl bg-[#1d4ed8] px-6 py-2.5 font-bold text-white transition-all hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {{ isRegistering ? 'Registering…' : 'Register patient' }}
                </button>
                <button type="button" (click)="showRegisterForm = false" class="text-sm font-semibold text-slate-500 hover:text-slate-700">
                  Cancel
                </button>
              </div>
            </form>
            <p *ngIf="registerError" class="mt-3 text-sm text-red-500">{{ registerError }}</p>
          </div>

          <!-- Success -->
          <div
            *ngIf="registerSuccess"
            class="flex items-start gap-3 rounded-[20px] border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
          >
            <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-600">
              <i class="fa-solid fa-check"></i>
            </div>
            <div>
              <p class="mb-1 font-semibold text-emerald-700">Patient registered</p>
              <p class="text-sm text-slate-500">{{ registerSuccess }}</p>
            </div>
          </div>
        </div>
      </main>
    </div>
  `,
})
export class ClinicPatientsComponent implements OnInit, OnDestroy {
  searchBy: 'email' | 'phone' = 'email';
  searchValue = '';
  isSearching = false;
  searchError = '';
  searchResult: { found: boolean; patient?: ClinicPatient } | null = null;

  showRegisterForm = false;
  isRegistering = false;
  registerError = '';
  registerSuccess = '';
  form: RegisterPatientPayload = { first_name: '', last_name: '', email: '' };

  // RIVA-141: debounced live name search — same Subject + debounceTime(350)
  // + distinctUntilChanged() pattern established in DoctorCardsComponent /
  // CaregiverCardsComponent this window.
  //
  // RIVA notification/search audit (product-owner feedback, 2026-08-25):
  // "the boxes stay empty, it doesn't update live as I type." Root cause:
  // this Subject carried no value (Subject<void>), so every .next() emitted
  // literally `undefined` — and distinctUntilChanged() treats every
  // emission after the first as a duplicate of the previous `undefined`
  // and silently drops it. In practice this meant only the very first
  // debounced search after the page loaded ever actually ran a query;
  // every keystroke after that (including retyping after a mistake) never
  // re-searched, no matter what was typed, matching exactly what was
  // reported. Carrying the real trimmed query string fixes this properly —
  // distinctUntilChanged still skips a genuinely-unchanged value, it just
  // no longer mistakes "different search" for "same event".
  nameQuery = '';
  nameResults: ClinicPatient[] = [];
  isNameSearching = false;
  nameSearchError = '';
  private nameQueryChanged = new Subject<string>();
  private nameQuerySub?: Subscription;

  readonly bloodTypes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

  constructor(private clinicPatientService: ClinicPatientService, private router: Router) {}

  ngOnInit(): void {
    this.nameQuerySub = this.nameQueryChanged
      .pipe(debounceTime(350), distinctUntilChanged())
      .subscribe((q) => this.runNameSearch(q));
  }

  ngOnDestroy(): void {
    this.nameQuerySub?.unsubscribe();
  }

  onNameQueryInput(): void {
    this.nameSearchError = '';
    const q = this.nameQuery.trim();
    if (q.length < 3) {
      this.nameResults = [];
      this.isNameSearching = false;
      return;
    }
    this.nameQueryChanged.next(q);
  }

  private runNameSearch(q: string): void {
    if (q.length < 3) {
      this.nameResults = [];
      return;
    }
    this.isNameSearching = true;
    this.nameSearchError = '';
    this.clinicPatientService
      .searchByName(q)
      .pipe(finalize(() => (this.isNameSearching = false)))
      .subscribe({
        next: (res) => (this.nameResults = res.data?.patients || []),
        error: (err) => {
          this.nameResults = [];
          this.nameSearchError =
            err?.error?.message ||
            (err.status === 403
              ? 'You do not have permission to search patients for this clinic.'
              : 'Name search failed. Please try again.');
        },
      });
  }

  selectNameResult(patient: ClinicPatient): void {
    this.searchResult = { found: true, patient };
    this.showRegisterForm = false;
    this.registerSuccess = '';
    // Scroll the found-patient card into view for continuity, since it
    // renders above the name-search card.
    setTimeout(() => document.getElementById('clinic-patient-found')?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 0);
  }

  patientInitials(patient?: ClinicPatient): string {
    const name = (patient?.name || patient?.email || '?').trim();
    if (!name) return '?';
    const parts = name.split(/\s+/).filter(Boolean);
    if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
  }

  search(): void {
    if (!this.searchValue) return;
    this.isSearching = true;
    this.searchError = '';
    this.searchResult = null;
    this.showRegisterForm = false;
    this.registerSuccess = '';

    const request$ =
      this.searchBy === 'email'
        ? this.clinicPatientService.searchByEmail(this.searchValue.trim())
        : this.clinicPatientService.searchByPhone(this.searchValue.trim());

    request$.pipe(finalize(() => (this.isSearching = false))).subscribe({
      next: (res) => (this.searchResult = res.data),
      error: (err) => {
        this.searchError =
          err?.error?.message ||
          (err.status === 403
            ? 'You do not have permission to search patients for this clinic.'
            : 'Search failed. Please try again.');
      },
    });
  }

  openRegisterForm(): void {
    this.form = {
      first_name: '',
      last_name: '',
      email: this.searchBy === 'email' ? this.searchValue.trim() : '',
      phone: this.searchBy === 'phone' ? this.searchValue.trim() : undefined,
    };
    this.showRegisterForm = true;
  }

  submitRegister(): void {
    this.isRegistering = true;
    this.registerError = '';
    this.registerSuccess = '';

    this.clinicPatientService
      .register(this.form)
      .pipe(finalize(() => (this.isRegistering = false)))
      .subscribe({
        next: (res) => {
          this.registerSuccess = res.message || `${res.data.name} was registered successfully.`;
          this.showRegisterForm = false;
          this.searchResult = { found: true, patient: res.data };
        },
        error: (err) => {
          this.registerError = err?.error?.message || 'Failed to register patient.';
        },
      });
  }
}
