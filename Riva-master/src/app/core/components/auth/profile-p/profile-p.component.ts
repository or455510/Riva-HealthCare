import { CommonModule, isPlatformBrowser, TitleCasePipe, DatePipe } from '@angular/common';
import { Component, Inject, NgZone, OnInit, PLATFORM_ID, ChangeDetectorRef } from '@angular/core';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { finalize } from 'rxjs';
import { API_BASE_URL } from '../../../../constants';
import { AuthService } from '../../../../service/auth.service';
import { SidebarComponent } from '../../../../components/sidebar';

interface DailyStatus {
  mood: string;
  pain_level: number;
  sleep_quality: string;
  symptoms: string;
  notes: string;
  medication_taken: boolean;
  created_at: string;
}

@Component({
  selector: 'app-profile-p',
  standalone: true,
  imports: [CommonModule, RouterModule, HttpClientModule, TitleCasePipe, DatePipe, SidebarComponent],
  templateUrl: './profile-p.component.html',
  styleUrl: './profile-p.component.css',
})
export class ProfilePComponent implements OnInit {

  patientName       = '';
  patientAvatar     = '';
  patientEmail      = '';
  patientPhone      = '';
  patientAddress    = '';
  gender            = '';
  age: number | null = null;
  bloodType         = '';
  about             = '';
  emergencyContact  = '';
  medicalHistory    = '';
  chronicConditions: string[] = [];
  patientId: number | null = null;

  latestStatus: DailyStatus | null = null;

  isLoading      = true;
  errorMessage   = '';
  successMessage = '';

  private get authHeaders() {
    return { Authorization: `Bearer ${this.authService.getToken()}` };
  }

  constructor(
    private authService: AuthService,
    private http: HttpClient,
    private router: Router,
    private route: ActivatedRoute,
    private cdr: ChangeDetectorRef,
    private zone: NgZone,
    @Inject(PLATFORM_ID) private platformId: Object
  ) {}

  ngOnInit(): void {
    if (!isPlatformBrowser(this.platformId)) return;

    const routeId = this.route.snapshot.paramMap.get('id');
    this.patientId = routeId ? Number(routeId) : null;

    if (!this.patientId) {
      this.errorMessage = 'Patient not found.';
      this.isLoading = false;
      return;
    }

    this.loadProfile();
    this.loadLatestStatus();
  }

  // ✅ يضمن full URL للصورة في كل الحالات
  private resolveImageUrl(path: string | null | undefined): string | null {
    if (!path) return null;
    if (path.startsWith('http://') || path.startsWith('https://')) return path;
    return `http://127.0.0.1:8000/storage/${path}`;
  }

  /**
   * RIVA-137: this used to always call the doctor-only /dashboard/doctor
   * endpoint (or /dashboard/caregiver) and search its patient list
   * client-side — which 403'd for an admin viewer (no Doctor row exists for
   * an admin account) and was an expensive way to fetch one patient record
   * regardless of role. Now calls the dedicated, role-correct
   * GET /patients/{id}/profile (DashboardController::patientProfile()),
   * which works for doctor, caregiver, AND admin.
   */
  loadProfile(): void {
    this.isLoading = true;

    this.http.get<any>(`${API_BASE_URL}/patients/${this.patientId}/profile`, { headers: this.authHeaders })
      .pipe(finalize(() => {
        this.isLoading = false;
        this.cdr.detectChanges();
      }))
      .subscribe({
        next: (response) => {
          const patient = response?.data?.patient || response?.patient;
          if (patient) {
            this.mapPatient({ patient });
          } else {
            this.errorMessage = 'Patient not found.';
          }
        },
        error: (err) => {
          console.error('[ProfileP] loadProfile failed', err);
          this.errorMessage = err?.status === 403
            ? 'You do not have access to this patient\'s record.'
            : 'Failed to load patient data.';
        }
      });
  }

  private mapPatient(item: any): void {
    const patient = item.patient || {};
    const user    = patient.user || {};

    this.patientName      = `${user.first_name || ''} ${user.last_name || ''}`.trim() || 'Patient';
    this.patientEmail     = user.email                || '';
    this.patientPhone     = user.phone                || '';
    this.patientAddress   = user.address              || '';
    this.gender           = patient.gender            || '';
    this.age              = patient.age               || null;
    this.bloodType        = patient.blood_type        || '';
    this.about            = patient.about             || '';
    this.emergencyContact = patient.emergency_contact || '';
    this.medicalHistory   = patient.medical_history   || '';

    const raw = patient.chronic_conditions || '';
    this.chronicConditions = typeof raw === 'string'
      ? raw.split(',').map((s: string) => s.trim()).filter(Boolean)
      : (Array.isArray(raw) ? raw : []);

    // ✅ resolve الصورة مع ضمان full URL
    const profileImage =
      this.resolveImageUrl(user.profile_image_url) ||
      this.resolveImageUrl(user.profile_image);

    this.patientAvatar = profileImage
      || `https://ui-avatars.com/api/?name=${encodeURIComponent(this.patientName)}&background=E6F0FF&color=2D5BFF`;
  }

  loadLatestStatus(): void {
    if (!this.patientId) return;

    this.http.get<any>(`${API_BASE_URL}/patients/${this.patientId}/daily-status`, {
      headers: this.authHeaders
    }).subscribe({
      next: (response) => {
        const list = response?.data ?? response ?? [];
        this.latestStatus = Array.isArray(list) ? list[0] || null : list;
        this.cdr.detectChanges();
      },
      error: () => { this.latestStatus = null; }
    });
  }

  getPainColor(level: number): string {
    if (level <= 3) return 'text-green-600';
    if (level <= 6) return 'text-yellow-500';
    return 'text-red-500';
  }

  goBack(): void     { this.router.navigate(['/patient-cards']); }
  goToReport(): void { this.router.navigate(['/report', this.patientId]); }
}
