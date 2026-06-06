import { CommonModule, isPlatformBrowser } from '@angular/common';
import { Component, Inject, NgZone, OnInit, PLATFORM_ID, ChangeDetectorRef } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { finalize } from 'rxjs';
import { API_BASE_URL } from '../../../../constants';
import { AuthService } from '../../../../service/auth.service';
import { SidebarComponent } from '../../../../components/sidebar';

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

  isLoading          = true;
  isFollowing        = false;
  isSubmittingFollow = false;
  isSubmittingReview = false;
  errorMessage       = '';
  successMessage     = '';

  reviews: Review[] = [];
  averageRating      = 0;
  totalReviews       = 0;

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
    @Inject(PLATFORM_ID) private platformId: Object
  ) {}

  ngOnInit(): void {
    if (!isPlatformBrowser(this.platformId)) return;
    this.doctorId = Number(this.route.snapshot.paramMap.get('id') || 0) || null;
    this.loadDoctorProfile();
    this.loadReviews();
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
          this.availableDays   = raw.available_days
            ? raw.available_days.split(',').map((d: string) => d.trim()).filter(Boolean)
            : [];
          this.doctorAvatar    = user.profile_image || '';
          this.isFollowing     = raw.follow_status === 'active' || raw.follow_status === 'pending';
          localStorage.setItem('selectedDoctor', JSON.stringify(raw));
        },
        error: (err) => {
          console.error('[ProfileD] loadDoctorProfile failed', err);
          this.errorMessage = 'Failed to load doctor profile.';
        },
      });
  }

  // ── Toggle Follow / Follow Request ───────────────────────────────────
  toggleFollow(): void {
    if (!this.doctorId || this.isFollowing || this.isSubmittingFollow) return;

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
        next: () => {
          this.isFollowing    = true;
          this.successMessage = '✅ Follow request sent!';
          setTimeout(() => (this.successMessage = ''), 3000);
        },
        error: (err) => {
          this.errorMessage = err?.error?.message || 'Failed to send follow request.';
        },
      });
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
