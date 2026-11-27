import { CommonModule } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { RouterModule } from '@angular/router';
import { finalize } from 'rxjs/operators';
import { API_BASE_URL } from '../../../../constants';
import { AuthService } from '../../../../service/auth.service';
import { SidebarComponent } from '../../../../components/sidebar';

interface ReportComment {
  id: number;
  comment: string;
  commenter_role: 'doctor' | 'caregiver';
  commenter_name: string;
  is_read: boolean;
  created_at: string;
}

interface SharedReport {
  id: number;
  title: string;
  summary: string;
  final_report: string;
  doctor: { id: number; name: string };
  patient: { id: number; name: string };
  comments: ReportComment[];
  created_at: string;
}

/**
 * RIVA-130 follow-up (product-owner request, recorded 2026-08-25): "keep any
 * report a patient shared with another doctor physically separate — isolated
 * between my reports and any other doctor's reports." This page is exactly
 * that separate place: reports OTHER doctors wrote that a patient explicitly
 * chose to share with this doctor (GET /reports/shared-with-me), never mixed
 * into a patient's own profile (that view — report.component.ts — now only
 * ever shows reports THIS doctor personally authored for that patient; see
 * ReportController::patientReports()'s docblock for the isolation fix this
 * pairs with). Read-only: commenting requires a real doctor-patient
 * relationship (ReportController::addComment()'s ensureAssignedPatient()),
 * which a doctor who only has a shared report may not have — so this view
 * doesn't offer a comment box that would likely just 403.
 */
@Component({
  selector: 'app-shared-reports',
  standalone: true,
  imports: [CommonModule, HttpClientModule, RouterModule, SidebarComponent],
  template: `
    <div class="flex min-h-screen bg-[#f7f8fb] font-sans">
      <app-sidebar></app-sidebar>

      <main class="flex-1 p-4 sm:p-8 md:pl-28">
        <div class="mx-auto flex max-w-4xl flex-col gap-6">
          <div>
            <p class="text-xs font-bold uppercase tracking-wider text-blue-600">Reports</p>
            <h1 class="mt-1 text-2xl font-bold text-[#1e293b] sm:text-3xl">Shared With Me</h1>
            <p class="mt-1 text-sm text-slate-500">
              Reports another doctor wrote that a patient chose to share with you specifically — kept separate from
              your own patients' reports, which you'll find on each patient's own profile.
            </p>
          </div>

          <div *ngIf="isLoading" class="flex h-64 items-center justify-center">
            <div class="h-10 w-10 animate-spin rounded-full border-4 border-blue-200 border-t-blue-600"></div>
          </div>

          <div *ngIf="!isLoading && loadError" class="rounded-[24px] border border-red-100 bg-white p-8 text-center shadow-sm">
            <i class="fa-solid fa-triangle-exclamation mb-3 text-3xl text-red-400"></i>
            <p class="mb-1 font-semibold text-[#1e293b]">Couldn't load shared reports</p>
            <p class="text-sm text-slate-500">{{ loadError }}</p>
          </div>

          <div *ngIf="!isLoading && !loadError && reports.length === 0" class="flex flex-col items-center rounded-[24px] border border-slate-200 bg-white p-16 text-slate-400 shadow-sm">
            <i class="fa-solid fa-share-nodes mb-4 text-5xl text-slate-200"></i>
            <p class="text-lg font-semibold">No reports shared with you yet</p>
            <p class="mt-1 text-sm text-slate-400">When a patient shares another doctor's report with you, it'll appear here.</p>
          </div>

          <div *ngIf="!isLoading && !loadError && reports.length > 0" class="flex flex-col gap-4">
            <div
              *ngFor="let report of reports"
              (click)="openReport(report)"
              class="cursor-pointer rounded-[20px] border border-slate-200 bg-white p-5 shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md"
            >
              <div class="flex items-start justify-between gap-3">
                <div>
                  <h3 class="font-bold text-[#1e293b]">{{ report.title }}</h3>
                  <p class="mt-0.5 text-sm text-slate-500">
                    Patient: {{ report.patient?.name || 'Unknown' }} &middot; Written by Dr. {{ report.doctor?.name }}
                  </p>
                </div>
                <span class="shrink-0 rounded-full bg-indigo-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-indigo-600">
                  Shared
                </span>
              </div>
              <p class="mt-2 line-clamp-2 text-sm text-slate-400">{{ report.summary }}</p>
              <p class="mt-2 text-xs text-slate-300">{{ report.created_at | date: 'MMMM d, y' }}</p>
            </div>
          </div>
        </div>
      </main>
    </div>

    <!-- Detail modal -->
    <div *ngIf="selectedReport" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-3 backdrop-blur-sm md:items-center md:p-6">
      <div class="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-[28px] bg-white shadow-2xl">
        <div class="flex-shrink-0 border-b border-slate-100 px-6 pb-4 pt-6">
          <div class="flex items-start justify-between">
            <div>
              <h2 class="text-lg font-bold text-[#1e293b]">{{ selectedReport.title }}</h2>
              <p class="mt-0.5 text-sm text-slate-400">
                Patient: {{ selectedReport.patient?.name }} &middot; Dr. {{ selectedReport.doctor?.name }} &middot;
                {{ selectedReport.created_at | date: 'MMMM d, y' }}
              </p>
            </div>
            <button (click)="selectedReport = null" class="ml-4 text-xl leading-none text-slate-400 transition-colors hover:text-slate-600">✕</button>
          </div>
        </div>

        <div class="flex-1 overflow-y-auto">
          <div class="border-b border-slate-50 px-6 py-5">
            <p class="mb-2 text-xs font-bold uppercase text-slate-400">Summary / Vitals</p>
            <p class="text-sm leading-relaxed text-slate-700">{{ selectedReport.summary }}</p>
          </div>

          <div *ngIf="selectedReport.final_report" class="border-b border-slate-50 px-6 py-5">
            <p class="mb-2 text-xs font-bold uppercase text-slate-400">Medical Report</p>
            <p class="whitespace-pre-line text-sm leading-relaxed text-slate-700">{{ selectedReport.final_report }}</p>
          </div>

          <div class="px-6 py-5">
            <p class="mb-3 text-xs font-bold uppercase text-slate-400">
              Comments
              <span *ngIf="selectedReport.comments?.length" class="ml-1 text-blue-500">({{ selectedReport.comments.length }})</span>
            </p>
            <div *ngIf="!selectedReport.comments?.length" class="py-6 text-center text-sm text-slate-300">No comments on this report.</div>
            <div *ngIf="selectedReport.comments?.length" class="space-y-3">
              <div *ngFor="let c of selectedReport.comments" class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                <div class="flex items-center gap-2">
                  <span class="text-sm font-bold text-[#1e293b]">{{ c.commenter_name }}</span>
                  <span class="text-xs text-slate-400">{{ c.created_at | date: 'MMM d, h:mm a' }}</span>
                </div>
                <p class="mt-1.5 text-sm text-slate-600">{{ c.comment }}</p>
              </div>
            </div>
          </div>
        </div>

        <div class="flex-shrink-0 border-t border-slate-50 px-6 pb-6 pt-3">
          <button (click)="selectedReport = null" class="w-full rounded-xl bg-[#1e293b] py-3 text-sm font-semibold text-white transition-all hover:bg-[#0f172a]">
            Close
          </button>
        </div>
      </div>
    </div>
  `,
})
export class SharedReportsComponent implements OnInit {
  reports: SharedReport[] = [];
  isLoading = true;
  loadError = '';
  selectedReport: SharedReport | null = null;

  private get authHeaders() {
    return { Authorization: `Bearer ${this.authService.getToken()}` };
  }

  constructor(private http: HttpClient, private authService: AuthService) {}

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.isLoading = true;
    this.loadError = '';
    this.http.get<any>(`${API_BASE_URL}/reports/shared-with-me`, { headers: this.authHeaders })
      .pipe(finalize(() => (this.isLoading = false)))
      .subscribe({
        next: (res) => { this.reports = res?.data ?? res ?? []; },
        error: (err) => { this.loadError = err?.error?.message || 'Failed to load shared reports.'; },
      });
  }

  openReport(report: SharedReport): void {
    this.selectedReport = report;
  }
}
