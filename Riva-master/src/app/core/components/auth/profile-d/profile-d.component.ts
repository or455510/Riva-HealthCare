import { CommonModule, isPlatformBrowser } from '@angular/common';
import { Component, Inject, NgZone, OnInit, PLATFORM_ID, ChangeDetectorRef } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { finalize } from 'rxjs';
import { API_BASE_URL, resolveStorageUrl } from '../../../../constants';
import { AuthService } from '../../../../service/auth.service';
import { SidebarComponent } from '../../../../components/sidebar';
// RIVA audit (Section 5 — services not connected to the profile): the
// backend chain (ClinicServiceController -> GET doctors/{doctor}/services)
// and this exact Angular method already existed and already worked — only
// BookingComponent called it. The public Doctor profile itself never did,
// which is the confirmed, narrow gap. Reusing the existing service here
// rather than adding a second HTTP client for the same endpoint (Rule 1).
import { ClinicServicesService, DoctorService } from '../../../../service/clinic-services.service';
// RIVA audit (Section 2 — Public Credentials): new, purpose-built endpoint
// (no existing one to reuse) since no credentials/certificates architecture
// existed anywhere in the backend before this pass. Only returns rows the
// doctor has explicitly marked public — see DoctorCredentialController.
import { DoctorCredential, DoctorCredentialsService } from '../../../../service/doctor-credentials.service';

interface Review {
  id: number;
  patientName: string;
  patientAvatar: string;
  rating: number;
  comment: string;
  date: string;
}

@Component({
  selector: 'app-profile-d',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterModule, HttpClientModule, SidebarComponent],
  templateUrl: './profile-d.component.html',
  styleUrl: './profile-d.component.css',
})
export class ProfileDComponent implements OnInit {
  doctorId: number | null = null;
  doctorName        = '';
  doctorAvatar      = '';
  doctorEmail       = '';
  doctorPhone       = '';
  specialty         = '';
  experienceYears: number | null = null;
  fee: number | null = null;
  clinicAddress     = '';
  availableDays: string[] = [];
  about             = '';
  isVerifiedDoctor  = false;

  isLoading          = true;
  // RIVA audit (Section 4 — follow state not reflected after approval): this
  // used to be a plain `isFollowing` boolean set from
  // `follow_status === 'active' || follow_status === 'pending'`, which
  // rendered a *pending* follow request identically to an *approved* one
  // (both just showed "Following") — the one demonstrable defect found here,
  // since a fresh page load already fetches the doctor fresh from the
  // backend on every ngOnInit (DoctorController::show() -> transformDoctor(),
  // the same authoritative field doctor-cards.component.ts already reads
  // correctly). Mirrors doctor-cards.component.ts's existing, correct
  // 4-state handling exactly, so there is one shared vocabulary for this
  // field across every surface that renders it (Rule 6).
  followStatus: 'pending' | 'active' | 'accepted' | 'rejected' | null = null;
  isSubmittingFollow = false;
  isSubmittingReview = false;
  errorMessage       = '';
  successMessage     = '';

  reviews: Review[] = [];
  averageRating      = 0;
  totalReviews       = 0;

  // Section 5 — real, doctor-configured bookable services for this doctor.
  services: DoctorService[] = [];
  isLoadingServices = false;
  servicesError     = '';

  // Section 2 — public professional credentials for this doctor.
  credentials: DoctorCredential[] = [];
  isLoadingCredentials = false;

  newRating   = 0;
  newComment  = '';
  hoveredStar = 0;

  readonly allDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

  private get authHeaders() {
    return { Authorization: `Bearer ${this.authService.getToken()}` };
  }

  constructor(
    private authService: AuthService,
    private http: HttpClient,
    private route: ActivatedRoute,
    private router: Router,
    private cdr: ChangeDetectorRef,
    private zone: NgZone,
    private clinicServicesService: ClinicServicesService,
    private doctorCredentialsService: DoctorCredentialsService,
    @Inject(PLATFORM_ID) private platformId: Object
  ) {}

  ngOnInit(): void {
    if (!isPlatformBrowser(this.platformId)) return;
    this.doctorId = Number(this.route.snapshot.paramMap.get('id') || 0) || null;
    this.loadDoctorProfile();
    this.loadReviews();
    this.loadServices();
    this.loadCredentials();
  }

  // ── Load Credentials (Section 2) ─────────────────────────────────────
  loadCredentials(): void {
    if (!this.doctorId) return;

    this.isLoadingCredentials = true;
    this.doctorCredentialsService
      .forDoctor(this.doctorId)
      .pipe(finalize(() => { this.isLoadingCredentials = false; this.cdr.detectChanges(); }))
      .subscribe({
        next: (res) => { this.credentials = res?.data || []; },
        error: () => { this.credentials = []; },
      });
  }

  credentialTypeLabel(type: string): string {
    const labels: Record<string, string> = {
      bachelor: 'Bachelor', master: "Master's", phd: 'PhD',
      certificate: 'Certificate', training: 'Training', professional_license: 'Professional License', other: 'Credential',
    };
    return labels[type] || 'Credential';
  }

  // ── Load Services (Section 5) ────────────────────────────────────────
  loadServices(): void {
    if (!this.doctorId) return;

    this.isLoadingServices = true;
    this.servicesError     = '';

    this.clinicServicesService
      .listForDoctor(this.doctorId)
      .pipe(finalize(() => { this.isLoadingServices = false; this.cdr.detectChanges(); }))
      .subscribe({
        next: (res) => { this.services = res?.data || []; },
        error: (err) => {
          // Honest empty state, not a fabricated fallback list (Rule 2) —
          // a doctor with no active clinic services simply has none to show.
          this.services = [];
          this.servicesError = err?.error?.message || '';
        },
      });
  }

  bookService(service: DoctorService): void {
    if (!this.doctorId) return;
    // The existing booking flow (BookingComponent) already loads this same
    // doctor's real services itself and lets the patient pick one there —
    // this CTA takes them straight to that step rather than duplicating
    // service-selection logic in a second place (Rule 1).
    this.router.navigate(['/appointments/book', this.doctorId]);
  }

  // ── Submit Review ────────────────────────────────────────────────────
  submitReview(): void {
    if (!this.doctorId || !this.newRating || !this.newComment.trim()) {
      this.errorMessage = 'Please add a rating and comment.';
      return;
    }

    this.isSubmittingReview = true;
    this.errorMessage       = '';

    this.http
      .post<any>(
        `${API_BASE_URL}/doctors/${this.doctorId}/reviews`,
        { rating: this.newRating, comment: this.newComment },
        { headers: this.authHeaders }   // ✅ token مضاف
      )
      .pipe(finalize(() => { this.isSubmittingReview = false; this.cdr.detectChanges(); }))
      .subscribe({
        next: () => {
          this.successMessage = '✅ Review submitted successfully!';
          this.newRating  = 0;
          this.newComment = '';
          this.loadReviews();
          setTimeout(() => (this.successMessage = ''), 3000);
        },
        error: (err) => {
          console.error('[ProfileD] submitReview failed', err);
          this.errorMessage = err?.error?.message || 'Failed to submit review.';
        },
      });
  }

  // ── Load Doctor Profile ──────────────────────────────────────────────
  loadDoctorProfile(): void {
    if (!this.doctorId) { this.isLoading = false; return; }

    this.isLoading = true;
    this.http
      .get<any>(`${API_BASE_URL}/doctors/${this.doctorId}`)
      .pipe(finalize(() => { this.isLoading = false; this.cdr.detectChanges(); }))
      .subscribe({
        next: (response) => {
          const raw  = response?.data || response;
          const user = raw.user || {};
          this.doctorId        = raw.id || this.doctorId;
          this.doctorName      = `${user.first_name || ''} ${user.last_name || ''}`.trim() || raw.name || 'Doctor';
          this.doctorEmail     = user.email || '';
          this.doctorPhone     = user.phone || '';
          this.specialty       = raw.specialty || '';
          this.experienceYears = raw.years_of_experience || null;
          this.fee             = raw.fee || null;
          this.clinicAddress   = raw.address || user.address || '';
          this.about           = raw.about || raw.bio || '';
          this.isVerifiedDoctor = !!(raw.is_verified || raw.doctor_verified_at || user.doctor_verified_at);
          this.availableDays   = raw.available_days
            ? raw.available_days.split(',').map((d: string) => d.trim()).filter(Boolean)
            : [];
          // Fix (RIVA audit, profile-d): was reading the raw `profile_image` path
          // directly, which is a relative storage path (e.g. "avatars/x.jpg"), not
          // a usable <img src>. Every other profile-viewing screen in the app
          // (doctor-cards, booking, profile-p, profile-c) resolves the backend's
          // already-absolute `profile_image_url` first and falls back to
          // resolveStorageUrl(profile_image) — same real fields the API already
          // returns on User, just wired the same way the rest of the app wires them.
          this.doctorAvatar    = resolveStorageUrl(user.profile_image_url || user.profile_image) || '';
          this.followStatus    = raw.follow_status ?? null;
          localStorage.setItem('selectedDoctor', JSON.stringify(raw));
        },
        error: (err) => {
          console.error('[ProfileD] loadDoctorProfile failed', err);
          this.errorMessage = 'Failed to load doctor profile.';
        },
      });
  }

  // ── Toggle Follow / Follow Request ───────────────────────────────────
  // RIVA audit (Section 4): guard and success handler now key off the real
  // relationship status (not a collapsed boolean), matching
  // doctor-cards.component.ts's requestFollow() exactly — a doctor already
  // 'pending' or 'active'/'accepted' cannot re-submit, a 'rejected' one can.
  toggleFollow(): void {
    if (!this.doctorId || this.isSubmittingFollow || this.isFollowActionDisabled()) return;

    this.isSubmittingFollow = true;
    this.errorMessage       = '';

    this.http
      .post<any>(
        `${API_BASE_URL}/doctors/${this.doctorId}/follow-request`,
        {},
        { headers: this.authHeaders }
      )
      .pipe(finalize(() => { this.isSubmittingFollow = false; this.cdr.detectChanges(); }))
      .subscribe({
        next: (res) => {
          this.followStatus   = res?.data?.status || 'pending';
          this.successMessage = '✅ Follow request sent!';
          setTimeout(() => (this.successMessage = ''), 3000);
        },
        error: (err) => {
          this.errorMessage = err?.error?.message || 'Failed to send follow request.';
        },
      });
  }

  followButtonLabel(): string {
    if (this.isSubmittingFollow) return 'Sending…';
    if (this.followStatus === 'pending') return 'Pending';
    if (this.followStatus === 'active' || this.followStatus === 'accepted') return 'Following';
    if (this.followStatus === 'rejected') return 'Try Again';
    return 'Follow';
  }

  isFollowActionDisabled(): boolean {
    return this.isSubmittingFollow
      || this.followStatus === 'pending'
      || this.followStatus === 'active'
      || this.followStatus === 'accepted';
  }

  bookConsultation(): void {
    if (!this.doctorId) return;
    this.router.navigate(['/appointments/book', this.doctorId]);
  }

  // ── Load Reviews ─────────────────────────────────────────────────────
  loadReviews(): void {
    if (!this.doctorId) return;

    this.http
      .get<any>(`${API_BASE_URL}/doctors/${this.doctorId}/reviews`)
      .subscribe({
        next: (response) => {
          const list = response?.data || response || [];
          this.reviews = list.map((r: any) => ({
            id:            r.id,
            patientName:   r.patient_name || 'Anonymous',
            patientAvatar: '',
            rating:        r.rating || 0,
            comment:       r.comment || '',
            date: r.created_at
              ? new Date(r.created_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
              : '',
          }));
          this.totalReviews  = this.reviews.length;
          this.averageRating = this.totalReviews
            ? Math.round((this.reviews.reduce((sum, r) => sum + r.rating, 0) / this.totalReviews) * 10) / 10
            : 0;
          this.cdr.detectChanges();
        },
        error: () => { this.reviews = []; this.totalReviews = 0; this.averageRating = 0; },
      });
  }

  checkFollowStatus(): void {}
  setRating(star: number)  { this.newRating = star; }
  hoverStar(star: number)  { this.hoveredStar = star; }
  clearHover()             { this.hoveredStar = 0; }
  getStars(rating: number) { return Array.from({ length: 5 }, (_, i) => i < Math.round(rating)); }
  isDayAvailable(day: string) { return this.availableDays.includes(day); }
  goBack()                 { this.router.navigate(['/doctor-cards']); }
}
