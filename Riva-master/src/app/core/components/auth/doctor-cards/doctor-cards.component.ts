import { Component, CUSTOM_ELEMENTS_SCHEMA, OnInit } from '@angular/core';
import { Router, RouterModule } from '@angular/router';
import { CommonModule } from '@angular/common';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { finalize } from 'rxjs';
import { API_BASE_URL, resolveStorageUrl } from '../../../../constants';
import { SidebarComponent } from '../../../../components/sidebar';

@Component({
  selector: 'app-doctor-cards',
  standalone: true,
  imports: [RouterModule, CommonModule, HttpClientModule, SidebarComponent],
  templateUrl: './doctor-cards.component.html',
  styleUrls: ['./doctor-cards.component.css'],
  schemas: [CUSTOM_ELEMENTS_SCHEMA],
})
export class DoctorCardsComponent implements OnInit {
  isLoading = true;
  errorMessage = '';
  doctors: any[] = [];

  constructor(private http: HttpClient, private router: Router) {}

  ngOnInit(): void {
    this.http.get<any>(`${API_BASE_URL}/doctors`)
      .pipe(finalize(() => { this.isLoading = false; }))
      .subscribe({
        next: (response) => {
          const doctorsArray = Array.isArray(response) ? response : response?.data || response?.doctors || [];
          this.doctors = doctorsArray.map((doctor: any) => this.normalizeDoctor(doctor));
        },
        error: (error) => {
          console.error('[DoctorCards] failed to load doctors', error);
          this.errorMessage = 'Failed to load doctors';
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
    };
  }

  getDoctorName(doctor: any): string { return doctor._normalizedName || 'Unknown Doctor'; }

  getAvatarUrl(doctor: any): string {
    return doctor._normalizedPhoto
      || `https://ui-avatars.com/api/?name=${encodeURIComponent(this.getDoctorName(doctor))}&background=0052FF&color=fff&size=128&bold=true&rounded=true`;
  }

  getSpecialty(doctor: any): string  { return doctor.specialty || 'General'; }
  getBio(doctor: any):       string  { return doctor.bio || ''; }

  getRating(doctor: any): string | null {
    const rating = Number(doctor?.rating);
    return Number.isFinite(rating) && rating > 0 ? rating.toFixed(1) : null;
  }

  selectDoctor(doctor: any): void {
    localStorage.setItem('selectedDoctor', JSON.stringify(doctor));
    this.router.navigate(['/profile-d', doctor.id]);
  }
}
