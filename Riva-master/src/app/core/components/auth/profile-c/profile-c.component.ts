import { CommonModule, isPlatformBrowser } from '@angular/common';
import { Component, Inject, OnInit, PLATFORM_ID, ChangeDetectorRef } from '@angular/core';
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
  selector: 'app-profile-c',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterModule, HttpClientModule, SidebarComponent],
  templateUrl: './profile-c.component.html',
  styleUrl: './profile-c.component.css',
})
export class ProfileCComponent implements OnInit {
  caregiverId: number | null = null;
  caregiverName    = '';
  caregiverAvatar  = '';
  caregiverEmail   = '';
  caregiverPhone   = '';
  specialty        = '';
  experienceYears: number | null = null;
  salary: number | null = null;
  about            = '';

  isLoading          = true;
  followStatus       = '';
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

  private get authHeaders() {
    return { Authorization: `Bearer ${this.authService.getToken()}` };
  }

  constructor(
    private authService: AuthService,
    private http: HttpClient,
    private route: ActivatedRoute,
    private router: Router,
    private cdr: ChangeDetectorRef,
    @Inject(PLATFORM_ID) private platformId: Object
  ) {}

  ngOnInit(): void {
    if (!isPlatformBrowser(this.platformId)) return;
    this.caregiverId = Number(this.route.snapshot.paramMap.get('id') || 0) || null;
    this.loadProfile();
    this.loadReviews();
  }

private resolveImageUrl(url: string | null | undefined): string {
  if (!url) return '';
  if (url.startsWith('http://') || url.startsWith('https://')) return url;
  const base = API_BASE_URL.replace('/api', '');
  return `${base}/storage/${url.replace(/^\//, '')}`;
}

  loadProfile(): void {
    if (!this.caregiverId) { this.isLoading = false; return; }
    this.isLoading = true;
    this.http
      .get<any>(`${API_BASE_URL}/caregivers/${this.caregiverId}`, { headers: this.authHeaders })
      .pipe(finalize(() => { this.isLoading = false; this.cdr.detectChanges(); }))
      .subscribe({
        next: (res) => {
          const raw  = res?.data ?? res;
          const user = raw.user || {};
          this.caregiverName   = user.name || `${user.first_name || ''} ${user.last_name || ''}`.trim() || 'Caregiver';
          this.caregiverEmail  = user.email || '';
          this.caregiverPhone  = user.phone || '';
          this.specialty       = raw.specialty || '';
          this.experienceYears = raw.experience_years || null;
          this.salary          = raw.salary || null;
          this.about           = raw.about || '';
          this.followStatus    = raw.follow_status || '';
          this.caregiverAvatar = this.resolveImageUrl(user.profile_image_url)
        || this.resolveImageUrl(user.profile_image)
            || `https://ui-avatars.com/api/?name=${encodeURIComponent(this.caregiverName)}&background=F5F0FF&color=7C3AED`;
        },
        error: () => { this.errorMessage = 'Failed to load caregiver profile.'; },
      });
  }

  get followLabel(): string {
    if (this.isSubmittingFollow) return 'Sending...';
    if (this.followStatus === 'pending') return 'Pending';
    if (this.followStatus === 'active' || this.followStatus === 'accepted') return 'Following';
    if (this.followStatus === 'rejected') return 'Try Again';
    return 'Request Caregiver';
  }

  get followDisabled(): boolean {
    return this.isSubmittingFollow || ['pending', 'active', 'accepted'].includes(this.followStatus);
  }

  requestCaregiver(): void {
    if (!this.caregiverId || this.followDisabled) return;
    this.isSubmittingFollow = true;
    this.errorMessage = '';
    this.http.post<any>(`${API_BASE_URL}/caregivers/${this.caregiverId}/follow-request`, {}, { headers: this.authHeaders })
      .pipe(finalize(() => { this.isSubmittingFollow = false; this.cdr.detectChanges(); }))
      .subscribe({
        next: (res) => {
          this.followStatus = res?.data?.status || 'pending';
          this.successMessage = 'Caregiver request sent successfully.';
          setTimeout(() => this.successMessage = '', 3000);
        },
        error: (err) => {
          this.errorMessage = err?.error?.message || 'Unable to send caregiver request.';
        }
      });
  }

  loadReviews(): void {
    if (!this.caregiverId) return;
    this.http
      .get<any>(`${API_BASE_URL}/caregivers/${this.caregiverId}/reviews`)
      .subscribe({
        next: (res) => {
          const list = res?.data ?? res ?? [];
          this.reviews = list.map((r: any) => ({
            id:            r.id,
            patientName:   r.patient_name || 'Anonymous',
            patientAvatar: `https://ui-avatars.com/api/?name=${encodeURIComponent(r.patient_name || 'P')}&background=F0F4FF&color=6366F1`,
            rating:        r.rating || 0,
            comment:       r.comment || '',
            date: r.created_at
              ? new Date(r.created_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
              : '',
          }));
          this.totalReviews  = this.reviews.length;
          this.averageRating = this.totalReviews
            ? Math.round((this.reviews.reduce((s, r) => s + r.rating, 0) / this.totalReviews) * 10) / 10
            : 0;
          this.cdr.detectChanges();
        },
        error: () => { this.reviews = []; },
      });
  }

  submitReview(): void {
    if (!this.caregiverId || !this.newRating) {
      this.errorMessage = 'Please select a rating.';
      return;
    }
    this.isSubmittingReview = true;
    this.errorMessage       = '';
    this.http
      .post<any>(
        `${API_BASE_URL}/caregivers/${this.caregiverId}/reviews`,
        { rating: this.newRating, comment: this.newComment },
        { headers: this.authHeaders }
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
          this.errorMessage = err?.error?.message || 'Failed to submit review.';
        },
      });
  }

  onAvatarError(event: Event): void {
    (event.target as HTMLImageElement).src =
      `https://ui-avatars.com/api/?name=${encodeURIComponent(this.caregiverName || 'C')}&background=F5F0FF&color=7C3AED`;
  }

  setRating(star: number)  { this.newRating = star; }
  hoverStar(star: number)  { this.hoveredStar = star; }
  clearHover()             { this.hoveredStar = 0; }
  getStars(rating: number) { return Array.from({ length: 5 }, (_, i) => i < Math.round(rating)); }
  goBack()                 { this.router.navigate(['/dashboard-p']); }
}
