import { Component, CUSTOM_ELEMENTS_SCHEMA, OnInit } from '@angular/core';
import { Router, RouterModule } from '@angular/router';
import { CommonModule } from '@angular/common';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { finalize } from 'rxjs';
import { API_BASE_URL, resolveStorageUrl } from '../../../../constants';
import { SidebarComponent } from '../../../../components/sidebar';

@Component({
  selector: 'app-caregiver-cards',
  standalone: true,
  imports: [RouterModule, CommonModule, HttpClientModule, SidebarComponent],
  templateUrl: './caregiver-cards.component.html',
  styleUrl: './caregiver-cards.component.css',
  schemas: [CUSTOM_ELEMENTS_SCHEMA],
})
export class CaregiverCardsComponent implements OnInit {
  isLoading = true;
  errorMessage = '';
  caregivers: any[] = [];
  submittingCaregiverId: number | null = null;

  constructor(private http: HttpClient, private router: Router) {}

  ngOnInit(): void {
    this.http.get<any>(`${API_BASE_URL}/caregivers`)
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
          this.errorMessage = 'Failed to load caregivers';
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
