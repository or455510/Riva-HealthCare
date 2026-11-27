import { CommonModule } from '@angular/common';
import { Component, OnDestroy, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HttpClientModule } from '@angular/common/http';
import { RouterModule } from '@angular/router';
import { finalize, Subscription } from 'rxjs';
import { ClinicContextEntry, ClinicService as ClinicContextService } from '../../../../service/clinic.service';
import {
  ClinicPricedService,
  ClinicServicePayload,
  ClinicServicesService,
} from '../../../../service/clinic-services.service';
import { SidebarComponent } from '../../../../components/sidebar';

/**
 * Vertical slice (Phase C, Services & Prices, principal-engineering
 * directive): the first Angular screen connected to the real, tested
 * ClinicServiceController. Every clinic member can see the price list
 * (staff quoting a walk-in need it even without services.manage); creating,
 * editing, and deleting are only offered when ClinicService.myContext()
 * reports services.manage — a UX convenience, not the real boundary, which
 * remains ClinicUser::hasPermission() on the backend. A service with
 * appointment history cannot be deleted (backend returns 409); the delete
 * handler surfaces that real error and points to deactivating instead.
 *
 * RIVA-125 (visual redesign, pass 2): restyled to match the Clinic Team
 * screen's approved design system (bg-[#f7f8fb] canvas, rounded-2xl stat
 * tiles, rounded-[20/24px] cards, always-visible manage form instead of a
 * toggle). The price list itself moved from a card grid to a table, which
 * reads better for a scannable price sheet — every stat tile and badge is
 * still computed only from the real `services` array already returned by
 * ClinicServiceController::index(); nothing here is fabricated.
 */
@Component({
  selector: 'app-clinic-services',
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
              <h1 class="mt-1 text-2xl font-bold text-[#1e293b] sm:text-3xl">Services &amp; Prices</h1>
              <p class="mt-1 text-sm text-slate-500">Bookable offerings for your clinic, with real prices.</p>
              <p *ngIf="contextLoaded && !canManage" class="mt-2 text-xs font-medium text-slate-400">
                <i class="fa-solid fa-eye mr-1"></i>View only — ask a clinic owner or admin for services management access.
              </p>
            </div>

            <div *ngIf="!isLoading && !loadError && services.length > 0" class="flex gap-3">
              <div class="min-w-[92px] rounded-2xl border border-slate-200 bg-white px-4 py-3 text-center shadow-sm">
                <p class="text-xl font-bold text-[#1e293b]">{{ services.length }}</p>
                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Total</p>
              </div>
              <div class="min-w-[92px] rounded-2xl border border-slate-200 bg-white px-4 py-3 text-center shadow-sm">
                <p class="text-xl font-bold text-emerald-600">{{ activeCount }}</p>
                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Active</p>
              </div>
              <div class="min-w-[92px] rounded-2xl border border-slate-200 bg-white px-4 py-3 text-center shadow-sm">
                <p class="text-xl font-bold text-slate-400">{{ inactiveCount }}</p>
                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Inactive</p>
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
            <p class="mb-1 font-semibold text-[#1e293b]">Couldn't load services</p>
            <p class="text-sm text-slate-500">{{ loadError }}</p>
            <button
              (click)="loadServices()"
              class="mt-4 rounded-xl bg-[#1d4ed8] px-6 py-2 font-bold text-white transition-all hover:bg-blue-700"
            >
              Try again
            </button>
          </div>

          <div *ngIf="!isLoading && !loadError" class="flex flex-col gap-6">
            <!-- Create form -->
            <div *ngIf="canManage" class="rounded-[24px] border border-slate-200 bg-white p-4 shadow-sm sm:p-7">
              <div class="mb-4 flex items-center gap-3">
                <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-600">
                  <i class="fa-solid fa-plus"></i>
                </div>
                <div>
                  <h2 class="text-base font-bold text-[#1e293b]">Add a new service</h2>
                  <p class="text-xs text-slate-400">Define what you offer and set its price so staff can quote it accurately.</p>
                </div>
              </div>
              <form (ngSubmit)="submitCreate()" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <input
                  name="name"
                  [(ngModel)]="createForm.name"
                  placeholder="Name (e.g. General Consultation)"
                  required
                  class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-colors focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100 sm:col-span-2"
                />
                <textarea
                  name="description"
                  [(ngModel)]="createForm.description"
                  placeholder="Description (optional)"
                  rows="2"
                  class="resize-none rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-colors focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100 sm:col-span-2"
                ></textarea>
                <input
                  name="price"
                  type="number"
                  min="0"
                  step="0.01"
                  [(ngModel)]="createForm.price"
                  placeholder="Price"
                  required
                  class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-colors focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                />
                <input
                  name="duration_minutes"
                  type="number"
                  min="1"
                  [(ngModel)]="createForm.duration_minutes"
                  placeholder="Duration (minutes, optional)"
                  class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-colors focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                />

                <div class="flex items-center gap-3 sm:col-span-2">
                  <button
                    type="submit"
                    [disabled]="isSaving"
                    class="whitespace-nowrap rounded-xl bg-[#1d4ed8] px-6 py-2.5 font-bold text-white transition-all hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    {{ isSaving ? 'Saving…' : 'Create service' }}
                  </button>
                </div>
              </form>
              <p *ngIf="createError" class="mt-3 text-sm text-red-500">{{ createError }}</p>
              <p *ngIf="createSuccess" class="mt-3 text-sm text-green-600">{{ createSuccess }}</p>
            </div>

            <!-- Empty State -->
            <div
              *ngIf="services.length === 0"
              class="flex flex-col items-center rounded-[24px] border border-slate-200 bg-white p-8 text-slate-400 shadow-sm sm:p-16"
            >
              <i class="fa-solid fa-file-invoice-dollar mb-4 text-5xl text-slate-200"></i>
              <p class="text-lg font-semibold">No services yet</p>
              <p class="mt-1 text-sm" *ngIf="!canManage">Ask a clinic owner or admin to add priced services.</p>
            </div>

            <!-- Price list -->
            <div *ngIf="services.length > 0" class="flex flex-col gap-4">
              <div class="relative">
                <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-sm text-slate-300"></i>
                <input
                  type="text"
                  [(ngModel)]="searchTerm"
                  placeholder="Search services by name…"
                  class="w-full rounded-xl border border-slate-200 bg-white py-2.5 pl-10 pr-4 text-sm shadow-sm transition-colors focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 sm:max-w-sm"
                />
              </div>

              <div class="overflow-hidden rounded-[20px] border border-slate-200 bg-white shadow-sm">
                <!-- Desktop/tablet: scannable price table (unchanged) -->
                <div class="hidden overflow-x-auto md:block">
                  <table class="w-full min-w-[640px] text-sm">
                    <thead class="border-b border-slate-100 bg-slate-50/60">
                      <tr>
                        <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wide text-slate-400">Service</th>
                        <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wide text-slate-400">Price</th>
                        <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wide text-slate-400">Duration</th>
                        <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wide text-slate-400">Status</th>
                        <th *ngIf="canManage" class="px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wide text-slate-400">
                          Actions
                        </th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                      <tr *ngIf="filteredServices.length === 0">
                        <td [attr.colspan]="canManage ? 5 : 4" class="px-5 py-8 text-center text-sm text-slate-400">
                          No services match &quot;{{ searchTerm }}&quot;.
                        </td>
                      </tr>

                      <ng-container *ngFor="let service of filteredServices">
                        <!-- View row -->
                        <tr *ngIf="editingId !== service.id" class="transition-colors hover:bg-slate-50/60">
                          <td class="px-5 py-4">
                            <p class="font-semibold text-[#1e293b]">{{ service.name }}</p>
                            <p class="mt-0.5 max-w-xs truncate text-xs text-slate-500" *ngIf="service.description">
                              {{ service.description }}
                            </p>
                          </td>
                          <td class="px-5 py-4 font-bold text-[#1e293b]">{{ service.price }} EGP</td>
                          <td class="px-5 py-4 text-slate-500">
                            <span *ngIf="service.duration_minutes"><i class="fa-regular fa-clock mr-1"></i>{{ service.duration_minutes }} min</span>
                            <span *ngIf="!service.duration_minutes">—</span>
                          </td>
                          <td class="px-5 py-4">
                            <span
                              class="rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wider"
                              [ngClass]="service.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'"
                            >
                              {{ service.is_active ? 'active' : 'inactive' }}
                            </span>
                          </td>
                          <td *ngIf="canManage" class="px-5 py-4">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                              <button
                                (click)="openEditForm(service)"
                                class="rounded-lg bg-blue-50 px-3 py-1.5 text-sm font-semibold text-blue-700 hover:bg-blue-100"
                              >
                                Edit
                              </button>
                              <button
                                *ngIf="service.is_active"
                                (click)="toggleActive(service)"
                                [disabled]="busyId === service.id"
                                class="rounded-lg bg-amber-50 px-3 py-1.5 text-sm font-semibold text-amber-700 hover:bg-amber-100 disabled:opacity-50"
                              >
                                Deactivate
                              </button>
                              <button
                                *ngIf="!service.is_active"
                                (click)="toggleActive(service)"
                                [disabled]="busyId === service.id"
                                class="rounded-lg bg-green-50 px-3 py-1.5 text-sm font-semibold text-green-700 hover:bg-green-100 disabled:opacity-50"
                              >
                                Reactivate
                              </button>
                              <button
                                (click)="deleteService(service)"
                                [disabled]="busyId === service.id"
                                class="rounded-lg bg-red-50 px-3 py-1.5 text-sm font-semibold text-red-600 hover:bg-red-100 disabled:opacity-50"
                              >
                                Delete
                              </button>
                            </div>
                          </td>
                        </tr>
                        <tr *ngIf="editingId !== service.id && rowError[service.id]">
                          <td [attr.colspan]="canManage ? 5 : 4" class="bg-red-50/50 px-5 pb-3 text-xs text-red-500">
                            {{ rowError[service.id] }}
                          </td>
                        </tr>

                        <!-- Inline edit row -->
                        <tr *ngIf="editingId === service.id">
                          <td colspan="5" class="bg-slate-50/70 px-5 py-5">
                            <form (ngSubmit)="submitEdit(service)" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                              <input
                                name="edit_name"
                                [(ngModel)]="editForm.name"
                                placeholder="Name"
                                required
                                class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm sm:col-span-2"
                              />
                              <textarea
                                name="edit_description"
                                [(ngModel)]="editForm.description"
                                placeholder="Description (optional)"
                                rows="2"
                                class="resize-none rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm sm:col-span-2"
                              ></textarea>
                              <input
                                name="edit_price"
                                type="number"
                                min="0"
                                step="0.01"
                                [(ngModel)]="editForm.price"
                                placeholder="Price"
                                required
                                class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"
                              />
                              <input
                                name="edit_duration"
                                type="number"
                                min="1"
                                [(ngModel)]="editForm.duration_minutes"
                                placeholder="Minutes"
                                class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"
                              />
                              <div class="flex items-center gap-3 sm:col-span-2">
                                <button
                                  type="submit"
                                  [disabled]="isSaving"
                                  class="rounded-lg bg-[#1d4ed8] px-4 py-1.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                                >
                                  {{ isSaving ? 'Saving…' : 'Save' }}
                                </button>
                                <button type="button" (click)="editingId = null" class="text-sm text-slate-500 hover:text-slate-700">
                                  Cancel
                                </button>
                              </div>
                              <p *ngIf="editError" class="text-sm text-red-500 sm:col-span-2">{{ editError }}</p>
                            </form>
                          </td>
                        </tr>
                      </ng-container>
                    </tbody>
                  </table>
                </div>

                <!-- Mobile: card list (same data/handlers as the table above, no desktop change) -->
                <div class="divide-y divide-slate-100 md:hidden">
                  <p *ngIf="filteredServices.length === 0" class="px-5 py-8 text-center text-sm text-slate-400">
                    No services match &quot;{{ searchTerm }}&quot;.
                  </p>

                  <ng-container *ngFor="let service of filteredServices">
                    <!-- View card -->
                    <div *ngIf="editingId !== service.id" class="p-4">
                      <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                          <p class="truncate font-semibold text-[#1e293b]">{{ service.name }}</p>
                          <p class="mt-0.5 truncate text-xs text-slate-500" *ngIf="service.description">
                            {{ service.description }}
                          </p>
                        </div>
                        <span
                          class="shrink-0 rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wider"
                          [ngClass]="service.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'"
                        >
                          {{ service.is_active ? 'active' : 'inactive' }}
                        </span>
                      </div>

                      <div class="mt-3 flex items-center gap-4 text-sm">
                        <span class="font-bold text-[#1e293b]">{{ service.price }} EGP</span>
                        <span class="text-slate-500">
                          <span *ngIf="service.duration_minutes"><i class="fa-regular fa-clock mr-1"></i>{{ service.duration_minutes }} min</span>
                          <span *ngIf="!service.duration_minutes">—</span>
                        </span>
                      </div>

                      <p *ngIf="rowError[service.id]" class="mt-2 text-xs text-red-500">{{ rowError[service.id] }}</p>

                      <div *ngIf="canManage" class="mt-3 flex flex-wrap items-center gap-2">
                        <button
                          (click)="openEditForm(service)"
                          class="rounded-lg bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-100"
                        >
                          Edit
                        </button>
                        <button
                          *ngIf="service.is_active"
                          (click)="toggleActive(service)"
                          [disabled]="busyId === service.id"
                          class="rounded-lg bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-700 hover:bg-amber-100 disabled:opacity-50"
                        >
                          Deactivate
                        </button>
                        <button
                          *ngIf="!service.is_active"
                          (click)="toggleActive(service)"
                          [disabled]="busyId === service.id"
                          class="rounded-lg bg-green-50 px-3 py-2 text-sm font-semibold text-green-700 hover:bg-green-100 disabled:opacity-50"
                        >
                          Reactivate
                        </button>
                        <button
                          (click)="deleteService(service)"
                          [disabled]="busyId === service.id"
                          class="rounded-lg bg-red-50 px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-100 disabled:opacity-50"
                        >
                          Delete
                        </button>
                      </div>
                    </div>

                    <!-- Inline edit card -->
                    <div *ngIf="editingId === service.id" class="bg-slate-50/70 p-4">
                      <form (ngSubmit)="submitEdit(service)" class="grid grid-cols-1 gap-3">
                        <input
                          name="edit_name_m"
                          [(ngModel)]="editForm.name"
                          placeholder="Name"
                          required
                          class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm"
                        />
                        <textarea
                          name="edit_description_m"
                          [(ngModel)]="editForm.description"
                          placeholder="Description (optional)"
                          rows="2"
                          class="resize-none rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm"
                        ></textarea>
                        <input
                          name="edit_price_m"
                          type="number"
                          min="0"
                          step="0.01"
                          [(ngModel)]="editForm.price"
                          placeholder="Price"
                          required
                          class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm"
                        />
                        <input
                          name="edit_duration_m"
                          type="number"
                          min="1"
                          [(ngModel)]="editForm.duration_minutes"
                          placeholder="Minutes"
                          class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm"
                        />
                        <div class="flex items-center gap-4">
                          <button
                            type="submit"
                            [disabled]="isSaving"
                            class="rounded-lg bg-[#1d4ed8] px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                          >
                            {{ isSaving ? 'Saving…' : 'Save' }}
                          </button>
                          <button type="button" (click)="editingId = null" class="text-sm text-slate-500 hover:text-slate-700">
                            Cancel
                          </button>
                        </div>
                        <p *ngIf="editError" class="text-sm text-red-500">{{ editError }}</p>
                      </form>
                    </div>
                  </ng-container>
                </div>
              </div>
            </div>
          </div>
        </div>
      </main>
    </div>
  `,
})
export class ClinicServicesComponent implements OnInit, OnDestroy {
  services: ClinicPricedService[] = [];
  isLoading = true;
  loadError = '';
  canManage = false;
  contextLoaded = false;
  busyId: number | null = null;
  rowError: Record<number, string> = {};

  searchTerm = '';

  isSaving = false;
  createError = '';
  createSuccess = '';
  createForm: { name: string; description: string; price: number | null; duration_minutes: number | null } = {
    name: '',
    description: '',
    price: null,
    duration_minutes: null,
  };

  editingId: number | null = null;
  editError = '';
  editForm: { name: string; description: string; price: number | null; duration_minutes: number | null } = {
    name: '',
    description: '',
    price: null,
    duration_minutes: null,
  };

  private contextSub?: Subscription;

  constructor(private clinicServicesService: ClinicServicesService, private clinicContextService: ClinicContextService) {}

  // RIVA-125 (visual redesign, pass 2): stat tiles and search/filter below
  // are purely presentational — derived entirely from the real `services`
  // array ClinicServiceController::index() already returned, never a
  // separate fetch or fabricated count.
  get activeCount(): number {
    return this.services.filter((s) => s.is_active).length;
  }

  get inactiveCount(): number {
    return this.services.filter((s) => !s.is_active).length;
  }

  get filteredServices(): ClinicPricedService[] {
    const term = this.searchTerm.trim().toLowerCase();
    if (!term) return this.services;
    return this.services.filter(
      (s) => s.name.toLowerCase().includes(term) || (s.description || '').toLowerCase().includes(term)
    );
  }

  ngOnInit(): void {
    this.loadServices();
    this.loadContext();
  }

  ngOnDestroy(): void {
    this.contextSub?.unsubscribe();
  }

  loadServices(): void {
    this.isLoading = true;
    this.loadError = '';
    this.clinicServicesService
      .list()
      .pipe(finalize(() => (this.isLoading = false)))
      .subscribe({
        next: (res) => (this.services = res.data || []),
        error: (err) => {
          this.loadError =
            err?.error?.message ||
            (err.status === 403
              ? 'You are not an active member of any clinic yet.'
              : 'Something went wrong loading services.');
        },
      });
  }

  private loadContext(): void {
    this.contextSub = this.clinicContextService.myContext().subscribe({
      next: (res) => {
        const entries: ClinicContextEntry[] = res.data?.clinics || [];
        this.canManage = entries.some(
          (e) => e.role === 'owner' || e.role === 'admin' || (e.role === 'staff' && e.permissions.includes('services.manage'))
        );
        this.contextLoaded = true;
      },
      error: () => {
        this.canManage = false;
        this.contextLoaded = true;
      },
    });
  }

  submitCreate(): void {
    if (this.createForm.price === null) return;
    this.isSaving = true;
    this.createError = '';
    this.createSuccess = '';

    const payload: ClinicServicePayload = {
      name: this.createForm.name,
      description: this.createForm.description || null,
      price: this.createForm.price,
      duration_minutes: this.createForm.duration_minutes || null,
    };

    this.clinicServicesService
      .create(payload)
      .pipe(finalize(() => (this.isSaving = false)))
      .subscribe({
        next: (res) => {
          this.services = [...this.services, res.data];
          this.createSuccess = `"${res.data.name}" added to your price list.`;
          this.createForm = { name: '', description: '', price: null, duration_minutes: null };
        },
        error: (err) => {
          this.createError = err?.error?.message || 'Failed to create service.';
        },
      });
  }

  openEditForm(service: ClinicPricedService): void {
    this.editingId = service.id;
    this.editError = '';
    this.editForm = {
      name: service.name,
      description: service.description || '',
      price: Number(service.price),
      duration_minutes: service.duration_minutes,
    };
  }

  submitEdit(service: ClinicPricedService): void {
    if (this.editForm.price === null) return;
    this.isSaving = true;
    this.editError = '';

    const payload: ClinicServicePayload = {
      name: this.editForm.name,
      description: this.editForm.description || null,
      price: this.editForm.price,
      duration_minutes: this.editForm.duration_minutes || null,
    };

    this.clinicServicesService
      .update(service.id, payload)
      .pipe(finalize(() => (this.isSaving = false)))
      .subscribe({
        next: (res) => {
          this.services = this.services.map((s) => (s.id === res.data.id ? res.data : s));
          this.editingId = null;
        },
        error: (err) => {
          this.editError = err?.error?.message || 'Failed to update service.';
        },
      });
  }

  toggleActive(service: ClinicPricedService): void {
    this.busyId = service.id;
    delete this.rowError[service.id];

    this.clinicServicesService
      .update(service.id, { is_active: !service.is_active })
      .pipe(finalize(() => (this.busyId = null)))
      .subscribe({
        next: (res) => {
          this.services = this.services.map((s) => (s.id === res.data.id ? res.data : s));
        },
        error: (err) => {
          this.rowError[service.id] = err?.error?.message || 'Action failed.';
        },
      });
  }

  deleteService(service: ClinicPricedService): void {
    this.busyId = service.id;
    delete this.rowError[service.id];

    this.clinicServicesService
      .remove(service.id)
      .pipe(finalize(() => (this.busyId = null)))
      .subscribe({
        next: () => {
          this.services = this.services.filter((s) => s.id !== service.id);
        },
        error: (err) => {
          // A real 409 means the service has appointment history — the
          // backend's own message already explains to deactivate instead.
          this.rowError[service.id] = err?.error?.message || 'Failed to delete service.';
        },
      });
  }
}
