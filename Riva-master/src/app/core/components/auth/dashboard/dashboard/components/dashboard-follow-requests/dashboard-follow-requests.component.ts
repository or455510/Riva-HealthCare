import { Component, EventEmitter, Input, Output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FollowRequestView } from '../dashboard-view-models';

/**
 * Phase 6 (dashboard component split): "Patient Follow Requests" — a
 * pre-existing feature that predates Phase 5 and isn't part of the
 * Level 1-6 spec, but Phase 6 requires preserving every existing dashboard
 * feature, not just the Phase 5 additions, so it gets its own component
 * like everything else rather than being dropped.
 *
 * Accept/reject both mutate server state and then re-trigger loads the
 * parent owns (loadPendingRequests()/loadDoctorPatients()), so both stay
 * @Output()s rather than being handled locally.
 */
@Component({
  selector: 'app-dashboard-follow-requests',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="rounded-[2.5rem] border border-sky-100 bg-white p-4 shadow-sm sm:p-6">
      <div class="mb-5 flex items-center justify-between">
        <h2 class="text-base font-bold text-slate-800">
          Patient Follow Requests
          <span *ngIf="requests.length > 0" class="ml-2 rounded-full bg-red-500 px-2 py-0.5 text-[10px] font-black text-white">
            {{ requests.length }}
          </span>
        </h2>
      </div>

      <div *ngIf="isLoading" class="space-y-3">
        <div *ngFor="let i of [1, 2]" class="flex animate-pulse items-center gap-4">
          <div class="h-10 w-10 rounded-full bg-slate-200"></div>
          <div class="h-4 flex-1 rounded bg-slate-100"></div>
        </div>
      </div>

      <div *ngIf="!isLoading && requests.length === 0" class="py-8 text-center text-sm text-slate-400">
        <i class="fa-solid fa-user-check mb-2 block text-3xl text-slate-200"></i> No pending requests
      </div>

      <div *ngIf="requests.length > 0" class="space-y-3">
        <div
          *ngFor="let req of requests"
          class="flex flex-col gap-3 rounded-2xl border border-sky-100 bg-sky-50/50 p-3 sm:flex-row sm:items-center"
        >
          <div class="flex min-w-0 flex-1 items-center gap-4">
            <img [src]="req.avatar" alt="" class="h-10 w-10 shrink-0 rounded-full object-cover" />
            <div class="min-w-0">
              <p class="truncate text-sm font-bold text-slate-800">{{ req.name }}</p>
              <p class="truncate text-xs text-slate-400">{{ req.condition }} · {{ req.requestedAt }}</p>
            </div>
          </div>
          <div class="flex shrink-0 gap-2">
            <button
              (click)="accept.emit(req)"
              [disabled]="req.isProcessing"
              class="flex-1 rounded-xl bg-green-500 px-4 py-2 text-[11px] font-black text-white transition-colors hover:bg-green-600 sm:flex-none"
            >
              {{ req.isProcessing ? 'Accepting...' : 'Accept' }}
            </button>
            <button
              (click)="reject.emit(req)"
              [disabled]="req.isProcessing"
              class="flex-1 rounded-xl bg-red-100 px-4 py-2 text-[11px] font-black text-red-500 transition-colors hover:bg-red-200 sm:flex-none"
            >
              {{ req.isProcessing ? 'Rejecting...' : 'Reject' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  `,
})
export class DashboardFollowRequestsComponent {
  @Input() isLoading = false;
  @Input() requests: FollowRequestView[] = [];

  @Output() accept = new EventEmitter<FollowRequestView>();
  @Output() reject = new EventEmitter<FollowRequestView>();
}
