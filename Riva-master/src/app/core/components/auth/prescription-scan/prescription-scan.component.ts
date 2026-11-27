import { CommonModule } from '@angular/common';
import { Component, ElementRef, OnDestroy, OnInit, ViewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { finalize } from 'rxjs/operators';
import { API_BASE_URL } from '../../../../constants';
import { AuthService } from '../../../../service/auth.service';

interface ScannedMedication {
  name: string;
  dosage: string;
  frequency: string;
  duration: string;
  instructions: string;
  // RIVA-130 follow-up (product-owner feedback, 2026-08-25): "the scan
  // misreads the doctors' odd handwriting" — the backend now rates how
  // confident it is in each medication NAME it read (see
  // PrescriptionScanController::scan()). 'manual' marks rows the doctor
  // added themselves (never scanned), which are never flagged as
  // uncertain since there's nothing to double-check them against.
  confidence: 'high' | 'medium' | 'low' | 'manual';
}

// RIVA-130 follow-up (product-owner feedback, 2026-08-25): "make fields a
// dropdown to make it easier on the doctor, not manual typing." These are
// real, common real-world values (not a fabricated medical taxonomy) —
// every row still allows free typing via the "Custom…" option, since no
// fixed list can cover every prescription.
const FREQUENCY_OPTIONS = [
  'Once daily', 'Twice daily', 'Three times daily', 'Four times daily',
  'Every 4 hours', 'Every 6 hours', 'Every 8 hours', 'Every 12 hours',
  'As needed', 'Before meals', 'After meals', 'At bedtime',
];
const DURATION_OPTIONS = ['3 days', '5 days', '7 days', '10 days', '14 days', '1 month', '3 months', 'Ongoing', 'Until finished'];
const DOSAGE_SUGGESTIONS = ['250mg', '500mg', '1000mg', '1 tablet', '2 tablets', '5ml', '10ml', '1 capsule', '2 capsules'];
const CUSTOM_SENTINEL = '__custom__';

interface PharmacyAvailability {
  branch_id: number;
  branch_name: string;
  pharmacy_name: string | null;
  address: string;
  city: string | null;
  phone: string | null;
  price: number | null;
  reported_at: string | null;
}

interface RegisteredMedicationResult {
  medication: { id: number; name: string; dosage: string; frequency: string; instructions: string | null };
  matched_medicine_id: number | null;
  matched_medicine_name: string | null;
  pharmacies: PharmacyAvailability[];
  isLoadingPharmacies: boolean;
  pharmacyChecked: boolean;
  pharmacyError: string;
}

/**
 * RIVA-130 (product-owner request, recorded 2026-08-25): real photo-based
 * prescription capture. Photograph or upload a prescription -> Gemini (the
 * same integration already powering the AI assistant chat) extracts a
 * candidate medication list -> the DOCTOR reviews and corrects every field
 * before anything is saved (handwriting reading is never 100% reliable,
 * and the backend explicitly tells the model not to invent what it can't
 * read) -> confirmed medications become real Medication rows for this
 * patient, which flow into the existing reminder/adherence system
 * automatically (no separate wiring needed - see MedicationAdherenceService,
 * already used by patient dashboards for medication reminders). If a
 * registered medication matches a real entry in the medicine catalog, this
 * page also offers a live "find nearby pharmacies" lookup via the
 * already-existing GET /medicines/{id}/availability - honestly showing
 * "not in our catalog yet" or "no pharmacy has reported stock yet" rather
 * than ever fabricating a result, since there is no seeded pharmacy data
 * anywhere in this system by design.
 */
@Component({
  selector: 'app-prescription-scan',
  standalone: true,
  imports: [CommonModule, FormsModule, HttpClientModule, RouterModule],
  template: `
    <div class="p-4 md:p-6 bg-[#F8FAFC] min-h-screen">
      <div class="max-w-4xl mx-auto space-y-6">

        <!-- Header -->
        <div class="flex items-center justify-between">
          <div>
            <h1 class="text-2xl font-bold text-[#1E293B]">📷 Scan Prescription</h1>
            <p class="text-sm text-gray-400 mt-1">Photograph a prescription, review the extracted medications, then save.</p>
          </div>
          <button (click)="goBack()" class="text-sm text-gray-500 hover:text-gray-800 flex items-center gap-1 transition-colors">
            ← Back to Reports
          </button>
        </div>

        <!-- Step 1: Upload -->
        <div *ngIf="!hasScanned" class="bg-white rounded-[24px] border border-gray-100 shadow-sm p-6">
          <p class="text-xs font-black uppercase tracking-widest text-blue-500 mb-2">Step 1</p>
          <h2 class="text-lg font-bold text-[#1E293B] mb-1">Upload or take a photo</h2>
          <p class="text-sm text-gray-400 mb-4">JPEG, PNG, or WEBP — max 8MB.</p>

          <!-- RIVA-130 follow-up (product-owner feedback, 2026-08-25): "why
               is there no camera in the scan so it opens my camera and
               photographs the prescription — keep the camera AND file
               upload both." The old <input capture="environment"> attribute
               only opens a real camera on mobile browsers; on desktop
               Chrome (where this doctor is running Riva) it silently falls
               back to a plain file picker, which is what looked like a
               missing feature. This uses getUserMedia for a real, working
               desktop-and-mobile camera capture, alongside the unchanged
               file picker. -->
          <div *ngIf="!cameraActive" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label class="flex flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed border-blue-200 bg-blue-50/40 p-8 cursor-pointer hover:bg-blue-50 transition-colors">
              <img *ngIf="imagePreviewUrl" [src]="imagePreviewUrl" class="max-h-64 rounded-xl shadow-sm object-contain" />
              <ng-container *ngIf="!imagePreviewUrl">
                <span class="text-4xl">🧾</span>
                <span class="text-sm font-semibold text-blue-600">Tap to choose an image</span>
              </ng-container>
              <input type="file" accept="image/jpeg,image/png,image/webp" capture="environment" class="hidden" (change)="onFileSelected($event)" />
            </label>

            <button
              type="button"
              (click)="startCamera()"
              class="flex flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50/60 p-8 hover:bg-slate-100 transition-colors"
            >
              <span class="text-4xl">📷</span>
              <span class="text-sm font-semibold text-slate-600">Use camera</span>
            </button>
          </div>

          <p *ngIf="cameraError" class="mt-3 text-sm text-red-600 bg-red-50 border border-red-100 rounded-xl px-4 py-3">{{ cameraError }}</p>

          <div *ngIf="cameraActive" class="rounded-2xl border border-slate-200 bg-black overflow-hidden">
            <video #videoEl autoplay playsinline muted class="w-full max-h-[420px] object-contain bg-black"></video>
          </div>
          <div *ngIf="cameraActive" class="mt-4 flex items-center gap-3">
            <button
              type="button"
              (click)="capturePhoto()"
              class="rounded-xl bg-[#1d4ed8] hover:bg-blue-700 text-white font-semibold px-6 py-2.5 text-sm transition-all"
            >
              📸 Capture photo
            </button>
            <button type="button" (click)="stopCamera()" class="text-sm font-semibold text-gray-500 hover:text-gray-800">
              Cancel
            </button>
          </div>
          <canvas #canvasEl class="hidden"></canvas>

          <p *ngIf="scanError" class="mt-4 text-sm text-red-600 bg-red-50 border border-red-100 rounded-xl px-4 py-3">{{ scanError }}</p>

          <div class="mt-5 flex items-center gap-3">
            <button
              (click)="scanImage()"
              [disabled]="!selectedFile || isScanning"
              class="rounded-xl bg-[#1E293B] hover:bg-[#0f172a] text-white font-semibold px-6 py-2.5 text-sm transition-all disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {{ isScanning ? 'Reading prescription…' : 'Scan prescription' }}
            </button>
            <button
              *ngIf="selectedFile"
              (click)="skipToManualEntry()"
              type="button"
              class="text-sm font-semibold text-gray-500 hover:text-gray-800"
            >
              Skip scanning, enter manually
            </button>
          </div>
        </div>

        <!-- Step 2: Review / Edit -->
        <div *ngIf="hasScanned && !registeredResults" class="bg-white rounded-[24px] border border-gray-100 shadow-sm p-6">
          <p class="text-xs font-black uppercase tracking-widest text-blue-500 mb-2">Step 2</p>
          <h2 class="text-lg font-bold text-[#1E293B] mb-1">Review before saving</h2>
          <p class="text-sm text-gray-400 mb-4">Correct anything the scan misread — nothing is saved until you confirm below.</p>

          <div *ngIf="scanNotes" class="mb-4 rounded-xl bg-amber-50 border border-amber-100 px-4 py-3 text-sm text-amber-700">
            <span class="font-semibold">Scan note:</span> {{ scanNotes }}
          </div>

          <div *ngIf="medications.length === 0" class="text-center py-8 text-gray-300">
            <div class="text-3xl mb-2">🧾</div>
            <p class="text-sm text-gray-500">No medications yet — add one below.</p>
          </div>

          <div class="space-y-3">
            <div *ngFor="let med of medications; let i = index" class="rounded-2xl border p-4 grid grid-cols-1 sm:grid-cols-2 gap-3" [ngClass]="med.confidence === 'low' ? 'border-amber-300 bg-amber-50/40' : 'border-gray-100'">
              <div *ngIf="med.confidence === 'low'" class="sm:col-span-2 flex items-center gap-2 rounded-lg bg-amber-100 text-amber-800 text-xs font-semibold px-3 py-2">
                ⚠️ Low-confidence read — the handwriting was hard to make out. Double-check this row against the original prescription.
              </div>
              <input [(ngModel)]="med.name" placeholder="Medication name *" class="rounded-xl border border-gray-200 px-3 py-2 text-sm sm:col-span-2 focus:border-blue-400 focus:outline-none" />

              <input [(ngModel)]="med.dosage" list="dosage-suggestions" placeholder="Dosage (e.g. 500mg)" class="rounded-xl border border-gray-200 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none" />

              <!-- RIVA-130 follow-up: dropdown of common real frequencies,
                   with a "Custom…" escape hatch that reveals a text input —
                   never lossy for an unusual scanned value. -->
              <div>
                <select
                  [ngModel]="isPresetFrequency(med.frequency) ? med.frequency : CUSTOM_SENTINEL"
                  (ngModelChange)="onFrequencySelect(med, $event)"
                  class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none"
                >
                  <option value="">Frequency…</option>
                  <option *ngFor="let f of FREQUENCY_OPTIONS" [value]="f">{{ f }}</option>
                  <option [value]="CUSTOM_SENTINEL">Custom…</option>
                </select>
                <input
                  *ngIf="!isPresetFrequency(med.frequency)"
                  [(ngModel)]="med.frequency"
                  placeholder="Custom frequency"
                  class="mt-2 w-full rounded-xl border border-gray-200 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none"
                />
              </div>

              <div>
                <select
                  [ngModel]="isPresetDuration(med.duration) ? med.duration : CUSTOM_SENTINEL"
                  (ngModelChange)="onDurationSelect(med, $event)"
                  class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none"
                >
                  <option value="">Duration…</option>
                  <option *ngFor="let d of DURATION_OPTIONS" [value]="d">{{ d }}</option>
                  <option [value]="CUSTOM_SENTINEL">Custom…</option>
                </select>
                <input
                  *ngIf="!isPresetDuration(med.duration)"
                  [(ngModel)]="med.duration"
                  placeholder="Custom duration"
                  class="mt-2 w-full rounded-xl border border-gray-200 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none"
                />
              </div>

              <input [(ngModel)]="med.instructions" placeholder="Instructions (e.g. after meals)" class="rounded-xl border border-gray-200 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none" />
              <button (click)="removeRow(i)" type="button" class="sm:col-span-2 text-right text-xs font-semibold text-red-500 hover:text-red-700">Remove</button>
            </div>
            <datalist id="dosage-suggestions">
              <option *ngFor="let d of DOSAGE_SUGGESTIONS" [value]="d"></option>
            </datalist>
          </div>

          <button (click)="addManualRow()" type="button" class="mt-4 text-sm font-semibold text-blue-600 hover:text-blue-800">
            + Add another medication
          </button>

          <p *ngIf="registerError" class="mt-4 text-sm text-red-600 bg-red-50 border border-red-100 rounded-xl px-4 py-3">{{ registerError }}</p>

          <div class="mt-5 flex items-center gap-3 border-t border-gray-50 pt-4">
            <button
              (click)="registerMedications()"
              [disabled]="!hasAtLeastOneNamedMedication || isRegistering"
              class="rounded-xl bg-[#1d4ed8] hover:bg-blue-700 text-white font-semibold px-6 py-2.5 text-sm transition-all disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {{ isRegistering ? 'Saving…' : 'Confirm and save to patient' }}
            </button>
            <button (click)="startOver()" type="button" class="text-sm font-semibold text-gray-500 hover:text-gray-800">
              Start over
            </button>
          </div>
        </div>

        <!-- Step 3: Registered -->
        <div *ngIf="registeredResults" class="bg-white rounded-[24px] border border-gray-100 shadow-sm p-6">
          <p class="text-xs font-black uppercase tracking-widest text-emerald-500 mb-2">Done</p>
          <h2 class="text-lg font-bold text-[#1E293B] mb-4">{{ registeredResults.length }} medication(s) saved to this patient</h2>

          <div class="space-y-3">
            <div *ngFor="let result of registeredResults" class="rounded-2xl border border-gray-100 p-4">
              <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                  <p class="font-bold text-[#1E293B]">{{ result.medication.name }}</p>
                  <p class="text-sm text-gray-400">{{ result.medication.dosage }} · {{ result.medication.frequency }}</p>
                  <p *ngIf="result.medication.instructions" class="text-xs text-gray-400 mt-1">{{ result.medication.instructions }}</p>
                </div>

                <button
                  *ngIf="result.matched_medicine_id && !result.pharmacyChecked"
                  (click)="findPharmacies(result)"
                  [disabled]="result.isLoadingPharmacies"
                  class="rounded-xl bg-blue-50 text-blue-700 hover:bg-blue-100 px-4 py-2 text-xs font-bold transition-colors disabled:opacity-50"
                >
                  {{ result.isLoadingPharmacies ? 'Checking…' : '📍 Find nearby pharmacies' }}
                </button>

                <span *ngIf="!result.matched_medicine_id" class="rounded-full bg-gray-100 text-gray-500 px-3 py-1.5 text-[11px] font-semibold">
                  Not in our pharmacy catalog yet
                </span>
              </div>

              <div *ngIf="result.pharmacyChecked" class="mt-3 border-t border-gray-50 pt-3">
                <p *ngIf="result.pharmacyError" class="text-sm text-red-500">{{ result.pharmacyError }}</p>

                <div *ngIf="!result.pharmacyError && result.pharmacies.length === 0" class="text-sm text-gray-400">
                  No pharmacy has reported this medicine as available yet.
                </div>

                <ul *ngIf="!result.pharmacyError && result.pharmacies.length > 0" class="space-y-2">
                  <li *ngFor="let branch of result.pharmacies" class="rounded-xl bg-slate-50 px-3 py-2 text-sm">
                    <p class="font-semibold text-[#1E293B]">{{ branch.pharmacy_name || branch.branch_name }}</p>
                    <p class="text-xs text-gray-500">{{ branch.address }}<span *ngIf="branch.city">, {{ branch.city }}</span></p>
                    <p class="text-xs text-gray-400 mt-0.5">
                      <span *ngIf="branch.phone">📞 {{ branch.phone }}</span>
                      <span *ngIf="branch.price != null" class="ml-2">💰 {{ branch.price }} EGP</span>
                    </p>
                  </li>
                </ul>
              </div>
            </div>
          </div>

          <div class="mt-5 flex items-center gap-3 border-t border-gray-50 pt-4">
            <button (click)="startOver()" class="rounded-xl bg-[#1E293B] hover:bg-[#0f172a] text-white font-semibold px-6 py-2.5 text-sm transition-all">
              Scan another prescription
            </button>
            <button (click)="goBack()" type="button" class="text-sm font-semibold text-gray-500 hover:text-gray-800">
              Back to reports
            </button>
          </div>
        </div>

      </div>
    </div>
  `,
})
export class PrescriptionScanComponent implements OnInit, OnDestroy {
  @ViewChild('videoEl') videoEl?: ElementRef<HTMLVideoElement>;
  @ViewChild('canvasEl') canvasEl?: ElementRef<HTMLCanvasElement>;

  readonly FREQUENCY_OPTIONS = FREQUENCY_OPTIONS;
  readonly DURATION_OPTIONS = DURATION_OPTIONS;
  readonly DOSAGE_SUGGESTIONS = DOSAGE_SUGGESTIONS;
  readonly CUSTOM_SENTINEL = CUSTOM_SENTINEL;

  patientId: number | null = null;

  selectedFile: File | null = null;
  imagePreviewUrl: string | null = null;
  isScanning = false;
  scanError = '';
  scanNotes: string | null = null;
  hasScanned = false;

  cameraActive = false;
  cameraError = '';
  private cameraStream: MediaStream | null = null;

  medications: ScannedMedication[] = [];

  isRegistering = false;
  registerError = '';
  registeredResults: RegisteredMedicationResult[] | null = null;

  private get authHeaders() {
    return { Authorization: `Bearer ${this.authService.getToken()}` };
  }

  constructor(
    private http: HttpClient,
    private authService: AuthService,
    private route: ActivatedRoute,
    private router: Router,
  ) {}

  ngOnInit(): void {
    const id = this.route.snapshot.paramMap.get('patientId');
    this.patientId = id ? Number(id) : null;
  }

  ngOnDestroy(): void {
    // Never leave the camera light on if the doctor navigates away mid-capture.
    this.stopCamera();
  }

  // --- RIVA-130 follow-up: real desktop+mobile camera capture ---

  async startCamera(): Promise<void> {
    this.cameraError = '';
    try {
      this.cameraStream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'environment' },
        audio: false,
      });
      this.cameraActive = true;
      // The <video> element only exists once cameraActive flips the *ngIf —
      // wait a tick for Angular to render it before attaching the stream.
      setTimeout(() => {
        if (this.videoEl?.nativeElement) {
          this.videoEl.nativeElement.srcObject = this.cameraStream;
        }
      });
    } catch (err: any) {
      this.cameraError = err?.name === 'NotAllowedError'
        ? 'Camera access was denied. Allow camera permission in the browser, or use "Tap to choose an image" instead.'
        : 'Could not access the camera. You can still upload an image file instead.';
    }
  }

  capturePhoto(): void {
    const video = this.videoEl?.nativeElement;
    const canvas = this.canvasEl?.nativeElement;
    if (!video || !canvas || video.videoWidth === 0) return;

    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d')?.drawImage(video, 0, 0, canvas.width, canvas.height);

    canvas.toBlob((blob) => {
      if (!blob) return;
      const file = new File([blob], `prescription-${Date.now()}.jpg`, { type: 'image/jpeg' });
      this.selectedFile = file;
      this.scanError = '';
      if (this.imagePreviewUrl) URL.revokeObjectURL(this.imagePreviewUrl);
      this.imagePreviewUrl = URL.createObjectURL(file);
      this.stopCamera();
    }, 'image/jpeg', 0.92);
  }

  stopCamera(): void {
    this.cameraStream?.getTracks().forEach((track) => track.stop());
    this.cameraStream = null;
    this.cameraActive = false;
  }

  // --- RIVA-130 follow-up: dropdown helpers for frequency/duration ---

  isPresetFrequency(value: string): boolean {
    return value === '' || FREQUENCY_OPTIONS.includes(value);
  }

  onFrequencySelect(med: ScannedMedication, value: string): void {
    if (value !== CUSTOM_SENTINEL) med.frequency = value;
  }

  isPresetDuration(value: string): boolean {
    return value === '' || DURATION_OPTIONS.includes(value);
  }

  onDurationSelect(med: ScannedMedication, value: string): void {
    if (value !== CUSTOM_SENTINEL) med.duration = value;
  }

  onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    this.selectedFile = file;
    this.scanError = '';

    if (this.imagePreviewUrl) URL.revokeObjectURL(this.imagePreviewUrl);
    this.imagePreviewUrl = file ? URL.createObjectURL(file) : null;
  }

  scanImage(): void {
    if (!this.selectedFile || !this.patientId) return;
    this.isScanning = true;
    this.scanError = '';

    const formData = new FormData();
    formData.append('patient_id', String(this.patientId));
    formData.append('image', this.selectedFile);

    this.http.post<any>(`${API_BASE_URL}/prescriptions/scan`, formData, { headers: this.authHeaders })
      .pipe(finalize(() => (this.isScanning = false)))
      .subscribe({
        next: (res) => {
          const data = res?.data ?? res;
          this.medications = (data.medications || []).map((m: any) => ({
            name: m.name || '', dosage: m.dosage || '', frequency: m.frequency || '',
            duration: m.duration || '', instructions: m.instructions || '',
            confidence: ['high', 'medium', 'low'].includes(m.confidence) ? m.confidence : 'medium',
          }));
          this.scanNotes = data.scan_notes || null;
          this.hasScanned = true;
          if (this.medications.length === 0) this.addManualRow();
        },
        error: (err) => {
          this.scanError = err?.error?.message || 'Scanning failed. You can add medications manually below.';
          this.hasScanned = true;
          if (this.medications.length === 0) this.addManualRow();
        },
      });
  }

  skipToManualEntry(): void {
    this.hasScanned = true;
    this.scanNotes = null;
    if (this.medications.length === 0) this.addManualRow();
  }

  addManualRow(): void {
    this.medications = [...this.medications, { name: '', dosage: '', frequency: '', duration: '', instructions: '', confidence: 'manual' }];
  }

  removeRow(index: number): void {
    this.medications = this.medications.filter((_, i) => i !== index);
  }

  get hasAtLeastOneNamedMedication(): boolean {
    return this.medications.some((m) => m.name.trim().length > 0);
  }

  registerMedications(): void {
    if (!this.patientId) return;

    const payload = this.medications
      .filter((m) => m.name.trim().length > 0)
      .map((m) => ({
        name: m.name.trim(),
        dosage: m.dosage.trim() || undefined,
        frequency: m.frequency.trim() || undefined,
        duration: m.duration.trim() || undefined,
        instructions: m.instructions.trim() || undefined,
      }));
    if (payload.length === 0) return;

    this.isRegistering = true;
    this.registerError = '';

    this.http.post<any>(
      `${API_BASE_URL}/prescriptions/register`,
      { patient_id: this.patientId, medications: payload },
      { headers: this.authHeaders }
    )
      .pipe(finalize(() => (this.isRegistering = false)))
      .subscribe({
        next: (res) => {
          const data = res?.data ?? res;
          this.registeredResults = (data.registered || []).map((r: any) => ({
            ...r,
            pharmacies: [],
            isLoadingPharmacies: false,
            pharmacyChecked: false,
            pharmacyError: '',
          }));
        },
        error: (err) => {
          this.registerError = err?.error?.message || 'Failed to save these medications. Please try again.';
        },
      });
  }

  findPharmacies(result: RegisteredMedicationResult): void {
    if (!result.matched_medicine_id) return;
    result.isLoadingPharmacies = true;
    result.pharmacyError = '';

    this.http.get<any>(`${API_BASE_URL}/medicines/${result.matched_medicine_id}/availability`, { headers: this.authHeaders })
      .pipe(finalize(() => { result.isLoadingPharmacies = false; result.pharmacyChecked = true; }))
      .subscribe({
        next: (res) => {
          const data = res?.data ?? res;
          result.pharmacies = data?.available_at || [];
        },
        error: () => {
          result.pharmacyError = 'Could not check pharmacy availability right now.';
        },
      });
  }

  startOver(): void {
    this.stopCamera();
    this.selectedFile = null;
    if (this.imagePreviewUrl) URL.revokeObjectURL(this.imagePreviewUrl);
    this.imagePreviewUrl = null;
    this.medications = [];
    this.hasScanned = false;
    this.scanError = '';
    this.scanNotes = null;
    this.registeredResults = null;
    this.registerError = '';
  }

  goBack(): void {
    if (this.patientId) this.router.navigate(['/report', this.patientId]);
    else this.router.navigate(['/patient-cards']);
  }
}
