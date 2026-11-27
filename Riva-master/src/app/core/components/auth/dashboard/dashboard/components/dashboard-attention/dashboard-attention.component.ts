import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { ReviewItemView } from '../dashboard-view-models';
import { riskLabel } from '../dashboard-format.util';

/**
 * Phase 6 (dashboard component split): Level 3's "Attention — Review
 * Required" — medication adherence plus the risk-ordered daily-monitoring
 * review list. The backend already sorts high_risk -> stable and dedupes
 * per patient (`DashboardController::attentionOrderedStatuses()`); this
 * component (like the Phase 5 parent before it) only narrows to non-stable
 * and never re-orders or relabels with a vocabulary of its own — `riskLabel`
 * is the one shared helper both this component and the parent's "My
 * Patients" sort use, so the two can never drift into different vocabularies.
 */
@Component({
  selector: 'app-dashboard-attention',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="rounded-[2.5rem] border border-sky-100 bg-white p-4 shadow-sm sm:p-6">
      <div class="mb-5 flex items-center justify-between">
        <h2 class="text-base font-bold text-slate-800">Attention — Review Required</h2>
      </div>

      <div *ngIf="!isLoading" class="mb-4 inline-block rounded-2xl border border-sky-100 bg-sky-50/50 p-4">
        <p class="text-2xl font-black leading-none text-slate-800">{{ adherence }}%</p>
        <p class="mt-1 text-[11px] text-slate-400">Average medication adherence</p>
      </div>

      <div *ngIf="isLoading" class="space-y-3">
        <div *ngFor="let i of [1, 2]" class="h-16 animate-pulse rounded-2xl bg-slate-100"></div>
      </div>

      <div *ngIf="!isLoading && reviewItems.length === 0" class="py-6 text-center text-sm text-slate-400">
        No patients need review right now
      </div>

      <div *ngIf="!isLoading && reviewItems.length > 0" class="space-y-2">
        <div
          *ngFor="let item of reviewItems"
          (click)="openPatient(item.patient_id)"
          class="flex cursor-pointer items-center justify-between gap-3 rounded-2xl border border-sky-100 bg-sky-50/50 p-3 transition-colors hover:bg-sky-50"
        >
          <div class="min-w-0">
            <p class="truncate text-sm font-bold text-slate-800">{{ item.patientDisplayName }}</p>
            <p class="truncate text-xs text-slate-400">
              {{ item.date || (item.created_at | date: 'd MMM') }}
              <span *ngIf="item.pain_level != null"> · Pain: {{ item.pain_level }}</span>
              <span *ngIf="item.symptoms"> · {{ item.symptoms }}</span>
            </p>
          </div>
          <span
            class="shrink-0 rounded-full px-2 py-1 text-[9px] font-black uppercase"
            [class.bg-rose-100]="item.risk_level === 'high_risk'"
            [class.text-rose-600]="item.risk_level === 'high_risk'"
            [class.bg-orange-100]="item.risk_level === 'moderate_risk' || item.risk_level === 'attention_needed'"
            [class.text-orange-600]="item.risk_level === 'moderate_risk' || item.risk_level === 'attention_needed'"
          >
            {{ riskLabel(item.risk_level) }}
          </span>
        </div>
      </div>
    </div>
  `,
})
export class DashboardAttentionComponent {
  @Input() isLoading = false;
  @Input() adherence = 0;
  @Input() reviewItems: ReviewItemView[] = [];

  constructor(private router: Router) {}

  riskLabel = riskLabel;

  openPatient(patientId: number | null | undefined): void {
    if (!patientId) return;
    this.router.navigate(['/profile-p', patientId]);
  }
}
