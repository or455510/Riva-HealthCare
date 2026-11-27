import { Component, CUSTOM_ELEMENTS_SCHEMA, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { NgApexchartsModule } from 'ng-apexcharts';
import { MyPatientView } from '../dashboard-view-models';

/**
 * Phase 6 (dashboard component split): "My Patients" plus its two adjacent
 * analytics charts and the AI Assistant tile — kept as one component
 * because on the Phase 5 desktop layout these four pieces already shared
 * one 12-column grid row/row-wrap as a single visual region, not four
 * independent ones. Per the Phase 5 "My Patients decision" (kept inline,
 * risk-prioritized, not converted into a link-only hub to /patient-cards),
 * this is unchanged here — same data, same `/chat/:userId` navigation on
 * row click (not `/profile-p`, a deliberate pre-existing difference from
 * the queue/attention/emergency sections' patient click, preserved as-is).
 *
 * Layout change from Phase 5 (responsiveness pass): the old
 * `lg:grid-cols-12` row placed the list at col-span-8 and the AI
 * tile/2 charts each at col-span-4, which is 8+4+4+4=20 columns of content
 * in a 12-column grid — it already wrapped into two rows on desktop, and
 * the second row left an empty 4-column gap (8 used of 12). Phase 6 makes
 * this one full-width card instead: the patient list on top, then the AI
 * tile and both charts as an even 1/2/3-column row below it (`grid-cols-1
 * sm:grid-cols-2 lg:grid-cols-3`) — same four pieces, same data, no
 * leftover gap, and no column-math that only worked by accident at one
 * breakpoint.
 *
 * `pieOptions`/`lineOptions` stay loosely typed (matching the `any`-leaning
 * shape ng-apexcharts itself expects for chart-config objects) rather than
 * re-declaring the whole ApexCharts option surface here — Phase 5 built
 * these once in the parent and only ever spreads/replaces `series`; nothing
 * in this split changes how they're constructed.
 */
@Component({
  selector: 'app-dashboard-my-patients',
  standalone: true,
  imports: [CommonModule, NgApexchartsModule],
  // spline-viewer is a real, pre-existing custom element (unchanged from
  // Phase 5's AI Assistant tile) — this schema was on the parent component
  // before the split; it now needs to live on whichever component actually
  // renders the tag.
  schemas: [CUSTOM_ELEMENTS_SCHEMA],
  template: `
    <div class="rounded-[2.5rem] border border-sky-100 bg-white p-4 shadow-sm sm:p-6">
      <div class="mb-6 flex items-center justify-between">
        <h2 class="text-lg font-bold text-slate-800">My Patients</h2>
        <span class="text-xs text-slate-400">{{ patients.length }} total</span>
      </div>

      <div
        *ngFor="let patient of patients"
        (click)="openChat(patient.userId)"
        class="flex cursor-pointer items-center justify-between gap-3 rounded-2xl p-3 transition-all hover:bg-sky-50"
      >
        <div class="flex min-w-0 items-center gap-4">
          <span
            class="h-2 w-2 shrink-0 rounded-full"
            [class.bg-rose-500]="patient.status === 'Critical'"
            [class.bg-orange-400]="patient.status === 'Attention'"
            [class.bg-green-500]="patient.status === 'Stable'"
            [class.bg-slate-300]="patient.status === 'No data'"
          ></span>
          <img [src]="patient.img" alt="" class="h-10 w-10 shrink-0 rounded-full object-cover" />
          <div class="min-w-0">
            <h3 class="truncate text-sm font-bold text-slate-700">{{ patient.name }}</h3>
            <p class="truncate text-xs text-slate-400">{{ patient.condition }}</p>
          </div>
        </div>
        <div class="shrink-0 text-right">
          <p class="text-[9px] font-black uppercase" [class.text-sky-600]="patient.status === 'Stable'">{{ patient.status }}</p>
        </div>
      </div>

      <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div
          class="flex flex-col items-center gap-4 rounded-[2rem] border border-sky-100 bg-gradient-to-b from-sky-50/50 to-white p-4 shadow-sm sm:p-6"
        >
          <div
            class="relative h-[200px] w-full cursor-pointer overflow-hidden rounded-[30px] border border-sky-100 bg-sky-50 shadow-sm transition-all hover:-translate-y-1 hover:shadow-xl focus:outline-none focus:ring-4 focus:ring-sky-100"
            role="button"
            tabindex="0"
            aria-label="Open Riva AI Assistant"
            (click)="openAiAssistant()"
            (keydown.enter)="openAiAssistant()"
            (keydown.space)="$event.preventDefault(); openAiAssistant()"
          >
            <div class="absolute left-4 top-4 z-10 rounded-xl bg-white/80 p-3 shadow-sm backdrop-blur-sm">
              <p class="text-sm font-bold text-sky-600">Hey! I'm Riva 👋</p>
            </div>
            <spline-viewer url="https://prod.spline.design/RfqAjOnSviCEEX3U/scene.splinecode" class="h-full w-full"></spline-viewer>
          </div>
          <h3 class="text-sm font-black text-slate-800">Medical AI Assistant</h3>
        </div>

        <div class="rounded-[2rem] border border-sky-100 bg-white p-4 shadow-sm sm:p-6">
          <h2 class="mb-4 text-center text-sm font-black uppercase tracking-widest text-slate-800">Patient Status</h2>
          <div *ngIf="!hasPatientAnalytics" class="flex h-[280px] flex-col items-center justify-center text-sm text-slate-400">
            <i class="fa-solid fa-chart-pie mb-3 text-3xl text-slate-200"></i>
            No patient data available yet.
          </div>
          <apx-chart
            *ngIf="hasPatientAnalytics"
            [series]="pieOptions.series"
            [chart]="pieOptions.chart"
            [labels]="pieOptions.labels"
            [colors]="pieOptions.colors"
            [responsive]="pieOptions.responsive"
          ></apx-chart>
        </div>

        <div class="rounded-[2rem] border border-sky-100 bg-white p-4 shadow-sm sm:p-6">
          <h2 class="mb-4 text-center text-sm font-black uppercase tracking-widest text-slate-800">Weekly Patients</h2>
          <div *ngIf="!hasPatientAnalytics" class="flex h-[280px] flex-col items-center justify-center text-sm text-slate-400">
            <i class="fa-solid fa-chart-line mb-3 text-3xl text-slate-200"></i>
            No patient data available yet.
          </div>
          <apx-chart
            *ngIf="hasPatientAnalytics"
            [series]="lineOptions.series"
            [chart]="lineOptions.chart"
            [xaxis]="lineOptions.xaxis"
            [stroke]="lineOptions.stroke"
            [colors]="lineOptions.colors"
          ></apx-chart>
        </div>
      </div>
    </div>
  `,
})
export class DashboardMyPatientsComponent {
  @Input() patients: MyPatientView[] = [];
  @Input() hasPatientAnalytics = false;
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  @Input() pieOptions: any;
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  @Input() lineOptions: any;

  constructor(private router: Router) {}

  openChat(userId: number | null | undefined): void {
    this.router.navigate(['/chat', userId]);
  }

  openAiAssistant(): void {
    this.router.navigate(['/ai-chat']);
  }
}
