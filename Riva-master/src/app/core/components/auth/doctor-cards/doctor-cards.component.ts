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
  selector: 'app-doctor-cards',
  standalone: true,
  imports: [RouterModule, CommonModule, FormsModule, HttpClientModule, SidebarComponent],
  templateUrl: './doctor-cards.component.html',
  styleUrls: ['./doctor-cards.component.css'],
  schemas: [CUSTOM_ELEMENTS_SCHEMA],
})
export class DoctorCardsComponent implements OnInit, OnDestroy {
  isLoading = true;
  errorMessage = '';
  doctors: any[] = [];
  submittingDoctorId: number | null = null;

  // RIVA-141: real backend-filtered search — these two fields are sent as
  // real `specialty`/`location` query params to GET /doctors (see
  // DoctorController::index()), not filtered client-side over an
  // already-fetched list. `location` is a plain text match against the
  // doctor's address (no lat/lng exists anywhere on User/Doctor in this
  // codebase), so this is honestly a text filter, not a "near me" distance
  // computation — that would need real geocoding first.
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
  // pick from." Populated from GET /doctors/filter-options — real, distinct
  // specialty/address values that already exist among active doctors, never
  // a fabricated taxonomy. Bound to the inputs below via native <datalist>,
  // which gives a real browser dropdown of suggestions while still letting
  // the doctor type anything (e.g. a specialty no one has entered yet).
  specialtyOptions: string[] = [];
  locationOptions: string[] = [];

  constructor(private http: HttpClient, private router: Router) {}

  ngOnInit(): void {
    this.filterSub = this.filterChanged
      .pipe(debounceTime(350), distinctUntilChanged())
      .subscribe(() => this.loadDoctors());

    this.loadDoctors();
    this.loadFilterOptions();
  }

  private loadFilterOptions(): void {
    this.http.get<any>(`${API_BASE_URL}/doctors/filter-options`).subscribe({
      next: (res) => {
        const data = res?.data ?? res;
        this.specialtyOptions = Array.isArray(data?.specialties) ? data.specialties : [];
        this.locationOptions = Array.isArray(data?.locations) ? data.locations : [];
      },
      error: (err) => console.error('[DoctorCards] failed to load filter options', err),
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
    this.loadDoctors();
  }

  get hasActiveFilters(): boolean {
    return !!(this.specialtyFilter.trim() || this.locationFilter.trim());
  }

  loadDoctors(): void {
    this.isLoading = true;
    this.errorMessage = '';

    let params = new HttpParams();
    if (this.specialtyFilter.trim()) params = params.set('specialty', this.specialtyFilter.trim());
    if (this.locationFilter.trim()) params = params.set('location', this.locationFilter.trim());

    this.http.get<any>(`${API_BASE_URL}/doctors`, { params })
      .pipe(finalize(() => { this.isLoading = false; }))
      .subscribe({
        next: (response) => {
          const doctorsArray = Array.isArray(response) ? response : response?.data || response?.doctors || [];
          this.doctors = doctorsArray.map((doctor: any) => this.normalizeDoctor(doctor));
        },
        error: (error) => {
          console.error('[DoctorCards] failed to load doctors', error);
          // RIVA (image/auth bug audit, 2026-08-25): GET /doctors is a
          // genuinely public route (no auth:sanctum middleware) — it should
          // never 401. Surfacing the real backend message here (instead of
          // a hardcoded generic one) means a stray 401/expired-token issue
          // is visible and diagnosable instead of silently looking like a
          // normal load failure.
          this.errorMessage = error?.error?.message || 'Failed to load doctors';
        },
      });
  }

  // ✅ helper: يضمن full URL للصورة
  private resolveImageUrl(path: string | null | undefined): string | null {
    if (!path) return null;
    if (path.startsWith('http://') || path.startsWith('https://')) return path;
    return resolveStorageUrl(path);
  }

  normalizeDoctor(doctor: any): any {
    const firstName = doctor.user?.first_name || doctor.first_name || '';
    const lastName  = doctor.user?.last_name  || doctor.last_name  || '';
    const name      = doctor.user?.name || doctor.name || `${firstName} ${lastName}`.trim() || 'Doctor';

    // ✅ resolve الصورة بـ full URL
    const photo = this.resolveImageUrl(doctor.user?.profile_image_url)
               || this.resolveImageUrl(doctor.user?.profile_image)
               || this.resolveImageUrl(doctor.profile_image_url)
               || this.resolveImageUrl(doctor.profile_image)
               || null;

    return {
      ...doctor,
      _normalizedName:  name,
      _normalizedPhoto: photo,
      specialty:        doctor.specialty || 'General',
      bio:              doctor.about || doctor.bio || 'No description available',
      is_verified:      !!(doctor.is_verified || doctor.doctor_verified_at || doctor.user?.doctor_verified_at),
    };
  }

  getDoctorName(doctor: any): string { return doctor._normalizedName || 'Unknown Doctor'; }

  getAvatarUrl(doctor: any): string {
    return doctor._normalizedPhoto
      || `https://ui-avatars.com/api/?name=${encodeURIComponent(this.getDoctorName(doctor))}&background=0052FF&color=fff&size=128&bold=true&rounded=true`;
  }

  getSpecialty(doctor: any): string  { return doctor.specialty || 'General'; }
  getBio(doctor: any):       string  { return doctor.bio || ''; }
  isDoctorVerified(doctor: any): boolean { return !!doctor.is_verified; }

  getRating(doctor: any): string | null {
    const rating = Number(doctor?.rating);
    return Number.isFinite(rating) && rating > 0 ? rating.toFixed(1) : null;
  }

  selectDoctor(doctor: any): void {
    localStorage.setItem('selectedDoctor', JSON.stringify(doctor));
    this.router.navigate(['/profile-d', doctor.id]);
  }

  // RIVA audit (doctor-cards): DoctorController::index() already returns a
  // real `follow_status` per doctor for the viewing patient (batch-loaded
  // from PatientDoctorRelationship — see DoctorController.php), and
  // POST /doctors/{doctor}/follow-request (RelationshipController) already
  // exists and is used from profile-d. This card grid received the field
  // and could reach the endpoint but never rendered either — the exact
  // capability the sibling caregiver-cards page already exposes on its own
  // cards via the same field/endpoint shape. Wiring it here, not fabricating
  // it: everything below reflects only what the API actually returned.
  requestFollow(doctor: any, event: Event): void {
    event.stopPropagation();
    if (!doctor?.id || ['pending', 'active', 'accepted'].includes(doctor.follow_status)) return;
    this.submittingDoctorId = doctor.id;
    this.http.post<any>(`${API_BASE_URL}/doctors/${doctor.id}/follow-request`, {})
      .pipe(finalize(() => { this.submittingDoctorId = null; }))
      .subscribe({
        next: (res) => { doctor.follow_status = res?.data?.status || 'pending'; },
        error: (err) => { this.errorMessage = err?.error?.message || 'Unable to send follow request.'; },
      });
  }

  followButtonLabel(doctor: any): string {
    if (this.submittingDoctorId === doctor.id) return 'Sending...';
    if (doctor.follow_status === 'pending') return 'Pending';
    if (doctor.follow_status === 'active' || doctor.follow_status === 'accepted') return 'Following';
    if (doctor.follow_status === 'rejected') return 'Try Again';
    return 'Follow Doctor';
  }

  isFollowActionDisabled(doctor: any): boolean {
    return this.submittingDoctorId === doctor.id
      || ['pending', 'active', 'accepted'].includes(doctor.follow_status);
  }

  // Purely-derived summary tiles over the same real `doctors` array the
  // cards below render — no separate fetch, no fabricated numbers (mirrors
  // ClinicStaffComponent.activeCount/invitedCount).
  get verifiedCount(): number {
    return this.doctors.filter((d) => this.isDoctorVerified(d)).length;
  }

  get followingCount(): number {
    return this.doctors.filter((d) => d.follow_status === 'active' || d.follow_status === 'accepted').length;
  }
}
