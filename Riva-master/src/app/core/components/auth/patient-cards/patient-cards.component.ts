import { Component, CUSTOM_ELEMENTS_SCHEMA, OnInit } from '@angular/core';
import { Router, RouterModule } from '@angular/router';
import { CommonModule } from '@angular/common';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { finalize } from 'rxjs';
import { API_BASE_URL } from '../../../../constants';
import { AuthService } from '../../../../service/auth.service';
import { SidebarComponent } from '../../../../components/sidebar';

@Component({
  selector: 'app-patient-cards',
  standalone: true,
  imports: [RouterModule, CommonModule, HttpClientModule, SidebarComponent],
  templateUrl: './patient-cards.component.html',
  styleUrls: ['./patient-cards.component.css'],
  schemas: [CUSTOM_ELEMENTS_SCHEMA],
})
export class PatientCardsComponent implements OnInit {

  isLoading = true;
  errorMessage = '';
  patients: any[] = [];
  sidebarLinks: { icon: string; route: string }[] = [];
  userRole: 'patient' | 'doctor' | 'caregiver' = 'patient';

  get isDoctor():    boolean { return this.userRole === 'doctor';    }
  get isCaregiver(): boolean { return this.userRole === 'caregiver'; }
  get isPatient():   boolean { return this.userRole === 'patient';   }

  private get authHeaders() {
    return { Authorization: `Bearer ${this.authService.getToken()}` };
  }

  constructor(
    private http: HttpClient,
    private router: Router,
    private authService: AuthService
  ) {}

  ngOnInit(): void {
    const role = this.authService.getUserRole();
    if (role === 'doctor' || role === 'caregiver' || role === 'patient') {
      this.userRole = role;
    }
    this.setSidebar();
    this.loadPatients();
  }

  private setSidebar(): void {
    if (this.isCaregiver) {
      this.sidebarLinks = [
        { icon: 'fas fa-home',              route: '/dashboard-caregiver' },
        { icon: 'fa-brands fa-rocketchat',  route: '/chat-c' },
        { icon: 'fa-solid fa-circle-user',  route: '/myprofile' },
        { icon: 'fa-solid fa-phone',        route: '/contact' },
        { icon: 'fa-solid fa-bed-pulse',    route: '/patient-cards' },
      ];
    } else if (this.isDoctor) {
      this.sidebarLinks = [
        { icon: 'fas fa-home',              route: '/dashboard' },
        { icon: 'fa-brands fa-rocketchat',  route: '/chat' },
        { icon: 'fa-solid fa-circle-user',  route: '/myprofile' },
        { icon: 'fa-solid fa-phone',        route: '/contact' },
        { icon: 'fa-solid fa-bed-pulse',    route: '/patient-cards' },
      ];
    } else {
      this.sidebarLinks = [
        { icon: 'fas fa-home',              route: '/dashboard-p' },
        { icon: 'fas fa-pills',             route: '/add-new-medication' },
        { icon: 'fa-solid fa-user-doctor',  route: '/doctor-cards' },
        { icon: 'fa-brands fa-rocketchat',  route: '/chat' },
        { icon: 'fa-solid fa-circle-user',  route: '/myprofile' },
        { icon: 'fa-solid fa-user-nurse',   route: '/caregiver-cards' },
      ];
    }
  }

  // ✅ يضمن full URL للصورة في كل الحالات
  private resolveImageUrl(path: string | null | undefined): string | null {
    if (!path) return null;
    if (path.startsWith('http://') || path.startsWith('https://')) return path;
    return `http://127.0.0.1:8000/storage/${path}`;
  }

  loadPatients(): void {
    this.isLoading = true;
    this.errorMessage = '';

    const role = this.authService.getUserRole();
    let endpoint = '';

    if (role === 'caregiver') {
      endpoint = `${API_BASE_URL}/dashboard/caregiver`;
    } else if (role === 'doctor') {
      endpoint = `${API_BASE_URL}/dashboard/doctor`;
    } else {
      this.isLoading = false;
      this.errorMessage = 'Unauthorized: Only doctors and caregivers can view patients.';
      return;
    }

    this.http.get<any>(endpoint, { headers: this.authHeaders })
      .pipe(finalize(() => { this.isLoading = false; }))
      .subscribe({
        next: (response) => {
          const data = response?.data || response;
          let list: any[] = [];

          if (role === 'caregiver') {
            list = data.assigned_patients || [];
          } else if (role === 'doctor') {
            list = (data.patients || []).filter((rel: any) => rel.status === 'active');
          }

          this.patients = list.map((item: any) => this.normalizePatient(item, role));
        },
        error: (error) => {
          console.error('[PatientCards] failed to load', error);
          this.errorMessage = 'Failed to load patients. Please try again.';
        },
      });
  }

  normalizePatient(item: any, role?: string): any {
    const patient = item.patient || {};
    const user    = patient.user || {};
    const name    = `${user.first_name || ''} ${user.last_name || ''}`.trim() || 'Patient';

    // ✅ resolve الصورة بـ full URL
    const photo = this.resolveImageUrl(user.profile_image_url)
               || this.resolveImageUrl(user.profile_image)
               || `https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=F5F0FF&color=7C3AED`;

    return {
      id:                item.patient_id || patient.id,
      user_id:           user.id         || patient.user_id,
      name,
      photo,
      specialty:         'Patient',
      bio:               patient.chronic_conditions || patient.medical_history || 'No description available',
      firstName:         user.first_name          || '',
      lastName:          user.last_name           || '',
      email:             user.email               || '',
      phone:             user.phone               || '',
      gender:            patient.gender           || '',
      age:               patient.age              || null,
      bloodType:         patient.blood_type       || '',
      address:           patient.address          || '',
      about:             patient.about            || '',
      emergencyContact:  patient.emergency_contact   || '',
      medicalHistory:    patient.medical_history     || '',
      chronicConditions: patient.chronic_conditions  || '',
      profileImage:      photo,
      status:            item.status              || 'active',
    };
  }

  getName(patient: any):      string      { return patient.name      || 'Unknown'; }
  getAvatar(patient: any):    string      { return patient.photo; }
  getSpecialty(patient: any): string      { return patient.specialty || 'Patient'; }
  getBio(patient: any):       string      { return patient.bio       || ''; }
  getRating(_: any):          string|null { return null; }

  selectPatient(patient: any): void {
    this.router.navigate(['/profile-p', patient.id]);
  }
}
