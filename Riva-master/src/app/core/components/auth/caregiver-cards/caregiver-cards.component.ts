import { Component, CUSTOM_ELEMENTS_SCHEMA, OnDestroy, OnInit } from '@angular/core';
import { Router, RouterModule } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient, HttpClientModule, HttpParams } from '@angular/common/http';
import { Subject, Subscription } from 'rxjs';
import { debounceTime, distinctUntilChanged, finalize } from 'rxjs/operators';
import { API_BASE_URL, resolveStorageUrl } from '../../../../constants';
import { SidebarComponent } from '../../../../components/sidebar';

@Component({
  selector: 'app-caregiver-cards',
  standalone: true,
  imports: [RouterModule, CommonModule, FormsModule, HttpClientModule, SidebarComponent],
  templateUrl: './caregiver-cards.component.html',
  styleUrl: './caregiver-cards.component.css',
  schemas: [CUSTOM_ELEMENTS_SCHEMA],
})
export class CaregiverCardsComponent implements OnInit, OnDestroy {
  isLoading = true;
  errorMessage = '';
  caregivers: any[] = [];
  submittingCaregiverId: number | null = null;

  // RIVA-141: real backend-filtered search — see the identical pattern and
  // caveats (no lat/lng anywhere, so `location` is a text match, not a
  // "near me" distance computation) in DoctorCardsComponent.
  specialtyFilter = '';
  locationFilter = '';
  // RIVA notification/search audit (product-owner feedback, 2026-08-25):
  // this was a Subject<void> — distinctUntilChanged() on a stream where
  // every emission is literally `undefined` treats every emission after
  // the first as a duplicate of the previous one and silently drops it, so
  // only the very first debounced search ever actually fired; every
  // keystroke after that was swallowed with no re-search and no error.
  // Carrying the real combined filter string lets distinctUntilChanged do
  // its actual job (skip a genuinely-unchanged state) without breaking
  // every subsequent real change.
  private filterChanged = new Subject<string>();
  private filterSub?: Subscription;

  // RIVA-141 follow-up (product-owner request, recorded 2026-08-25): "the
  // filtering is just text boxes, there's no dropdown suggesting options to
  // pick from." Same pattern as DoctorCardsComponent — real, distinct
  // specialty/address values from GET /caregivers/filter-options, offered
  // via native <datalist> while still allowing free typing.
  specialtyOptions: string[] = [];
  locationOptions: string[] = [];

  constructor(private http: HttpClient, private router: Router) {}

  ngOnInit(): void {
    this.filterSub = this.filterChanged
      .pipe(debounceTime(350), distinctUntilChanged())
      .subscribe(() => this.loadCaregivers());

    this.loadCaregivers();
    this.loadFilterOptions();
  }

  private loadFilterOptions(): void {
    this.http.get<any>(`${API_BASE_URL}/caregivers/filter-options`).subscribe({
      next: (res) => {
        const data = res?.data ?? res;
        this.specialtyOptions = Array.isArray(data?.specialties) ? data.specialties : [];
        this.locationOptions = Array.isArray(data?.locations) ? data.locations : [];
      },
      error: (err) => console.error('[CaregiverCards] failed to load filter options', err),
    });
  }

  ngOnDestroy(): void {
    this.filterSub?.unsubscribe();
  }

  onFilterInput(): void {
    this.filterChanged.next(`${this.specialtyFilter}|${this.locationFilter}`);
  }

  clearFilters(): void {
    this.specialtyFilter = '';
    this.locationFilter = '';
    this.loadCaregivers();
  }

  get hasActiveFilters(): boolean {
    return !!(this.specialtyFilter.trim() || this.locationFilter.trim());
  }

  loadCaregivers(): void {
    this.isLoading = true;
    this.errorMessage = '';

    let params = new HttpParams();
    if (this.specialtyFilter.trim()) params = params.set('specialty', this.specialtyFilter.trim());
    if (this.locationFilter.trim()) params = params.set('location', this.locationFilter.trim());

    this.http.get<any>(`${API_BASE_URL}/caregivers`, { params })
      .pipe(finalize(() => { this.isLoading = false; }))
      .subscribe({
        next: (response) => {
          const caregiversArray = Array.isArray(response)
            ? response
            : response?.data || response?.caregivers || [];
          this.caregivers = caregiversArray.map((c: any) => this.normalizeCaregiver(c));
        },
        error: (error) => {
          console.error('[CaregiverCards] failed to load caregivers', error);
          this.errorMessage = error?.error?.message || 'Failed to load caregivers';
        },
      });
  }

  // ✅ helper: يضمن full URL للصورة
  private resolveImageUrl(path: string | null | undefined): string | null {
    if (!path) return null;
    if (path.startsWith('http://') || path.startsWith('https://')) return path;
    return resolveStorageUrl(path);
  }

  normalizeCaregiver(caregiver: any): any {
    const firstName = caregiver.user?.first_name || caregiver.first_name || '';
    const lastName  = caregiver.user?.last_name  || caregiver.last_name  || '';
    const name      = caregiver.user?.name || caregiver.name || `${firstName} ${lastName}`.trim() || 'Caregiver';

    // ✅ resolve الصورة بـ full URL
    const photo = this.resolveImageUrl(caregiver.user?.profile_image_url)
               || this.resolveImageUrl(caregiver.user?.profile_image)
               || this.resolveImageUrl(caregiver.profile_image_url)
               || this.resolveImageUrl(caregiver.profile_image)
               || null;

    return {
      ...caregiver,
      _normalizedName:  name,
      _normalizedPhoto: photo,
      specialty:        caregiver.specialty || 'General Care',
      bio:              caregiver.about || caregiver.bio || 'No description available',
      experience_years: caregiver.experience_years || caregiver.years_of_experience || null,
      salary:           caregiver.salary || null,
    };
  }

  getCaregiverName(caregiver: any): string { return caregiver._normalizedName || 'Unknown Caregiver'; }

  getAvatarUrl(caregiver: any): string {
    return caregiver._normalizedPhoto
      || `https://ui-avatars.com/api/?name=${encodeURIComponent(this.getCaregiverName(caregiver))}&background=7C3AED&color=fff&size=128&bold=true&rounded=true`;
  }

  getSpecialty(caregiver: any): string  { return caregiver.specialty || 'General Care'; }
  getBio(caregiver: any):       string  { return caregiver.bio || ''; }

  getExperience(caregiver: any): string | null {
    const exp = caregiver.experience_years;
    return exp ? `${exp} yrs exp` : null;
  }

  getSalary(caregiver: any): string | null {
    const salary = caregiver.salary;
    return salary ? `${salary} EGP` : null;
  }

  getRating(caregiver: any): string | null {
    const rating = Number(caregiver?.rating);
    return Number.isFinite(rating) && rating > 0 ? rating.toFixed(1) : null;
  }

  selectCaregiver(caregiver: any): void {
    localStorage.setItem('selectedCaregiver', JSON.stringify(caregiver));
    this.router.navigate(['/profile-c', caregiver.id]);
  }

  requestCaregiver(caregiver: any, event: Event): void {
    event.stopPropagation();
    if (!caregiver?.id || ['pending', 'active', 'accepted'].includes(caregiver.follow_status)) return;
    this.submittingCaregiverId = caregiver.id;
    this.http.post<any>(`${API_BASE_URL}/caregivers/${caregiver.id}/follow-request`, {})
      .pipe(finalize(() => { this.submittingCaregiverId = null; }))
      .subscribe({
        next: (res) => caregiver.follow_status = res?.data?.status || 'pending',
        error: (err) => this.errorMessage = err?.error?.message || 'Unable to send caregiver request.',
      });
  }

  followButtonLabel(caregiver: any): string {
    if (this.submittingCaregiverId === caregiver.id) return 'Sending...';
    if (caregiver.follow_status === 'pending') return 'Pending';
    if (caregiver.follow_status === 'active' || caregiver.follow_status === 'accepted') return 'Following';
    if (caregiver.follow_status === 'rejected') return 'Try Again';
    return 'Request Caregiver';
  }
}
