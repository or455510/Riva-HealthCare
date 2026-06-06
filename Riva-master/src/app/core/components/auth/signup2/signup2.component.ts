import { CommonModule } from '@angular/common';
import { Component, CUSTOM_ELEMENTS_SCHEMA, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { finalize } from 'rxjs';
import { HttpClient } from '@angular/common/http';
import { AuthService } from '../../../../service/auth.service';
import { API_BASE_URL } from '../../../../constants';

@Component({
  selector: 'app-signup2',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './signup2.component.html',
  styleUrl: './signup2.component.css',
  schemas: [CUSTOM_ELEMENTS_SCHEMA],
})
export class Signup2Component implements OnInit {
  userRole: 'patient' | 'doctor' | 'caregiver' = 'patient';
  profileImageUrl = 'https://ui-avatars.com/api/?name=User&background=E6F0FF&color=2D5BFF';
  selectedFile: File | null = null;
  hasCustomImage = false;
  gender = '';
  description = '';
  isLoading = false;
  errorMessage = '';
  successMessage = '';

  age: number | null = null;
  address = '';
  conditions = { diabetes: false, hypertension: false, cancer: false };

  specialization = '';
  yearsOfExperience: number | null = null;
  consultationFee: number | null = null;
  clinicAddress = '';
  availableDays: string[] = [];
  salary: number | null = null;

  readonly dayOptions = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
  readonly specializationOptions = [
    'General Practice',
    'Cardiology',
    'Endocrinology',
    'Neurology',
    'Oncology',
    'Psychiatry',
    'Radiology',
    'Surgery',
  ];

  constructor(
    private authService: AuthService,
    private router: Router,
    private http: HttpClient
  ) {}

  ngOnInit(): void {
    const role = this.authService.getUserRole().toLowerCase();
    this.userRole = role === 'doctor' || role === 'caregiver' ? role : 'patient';
  }

  get isDoctor(): boolean { return this.userRole === 'doctor'; }
  get isPatient(): boolean { return this.userRole === 'patient'; }
  get isCaregiver(): boolean { return this.userRole === 'caregiver'; }

  onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    if (!input.files?.[0]) return;
    const file = input.files[0];
    if (file.size > 2 * 1024 * 1024) {
      this.errorMessage = 'File size exceeds 2MB.';
      return;
    }
    this.selectedFile = file;
    const reader = new FileReader();
    reader.onload = (e) => {
      this.profileImageUrl = e.target?.result as string;
      this.hasCustomImage = true;
    };
    reader.readAsDataURL(file);
  }

  triggerFileInput(): void {
    (document.getElementById('profilePhotoInput') as HTMLInputElement | null)?.click();
  }

  clearImage(): void {
    this.profileImageUrl = 'https://ui-avatars.com/api/?name=User&background=E6F0FF&color=2D5BFF';
    this.selectedFile = null;
    this.hasCustomImage = false;
    localStorage.removeItem('profileImage');
  }

  get selectedConditionsArray(): string[] {
    return Object.entries(this.conditions)
      .filter(([, value]) => value)
      .map(([key]) => key);
  }

  get selectedConditionsText(): string {
    return this.selectedConditionsArray.join(', ') || 'Select all that apply';
  }

  toggleDay(day: string): void {
    const index = this.availableDays.indexOf(day);
    if (index === -1) {
      this.availableDays.push(day);
    } else {
      this.availableDays.splice(index, 1);
    }
  }

  isDaySelected(day: string): boolean {
    return this.availableDays.includes(day);
  }

  goBack(): void {
    this.router.navigate(['/signup']);
  }

  onSubmit(): void {
    const missing: string[] = [];

    if (!this.gender) missing.push('Gender');
    if (!this.description.trim()) missing.push('Description');

    if (this.isDoctor) {
      if (!this.specialization) missing.push('Specialization');
      if (!this.yearsOfExperience) missing.push('Years of Experience');
      if (!this.consultationFee) missing.push('Consultation Fee');
    } else if (this.isCaregiver) {
      if (!this.specialization) missing.push('Specialty');
    } else {
      if (!this.age) missing.push('Age');
      if (!this.address.trim()) missing.push('Address');
    }

    if (missing.length) {
      this.errorMessage = `Missing required fields: ${missing.join(', ')}`;
      return;
    }

    this.isLoading = true;
    this.errorMessage = '';
    this.successMessage = '';

    const formData = new FormData();
    const payload = this.buildPayload();
    Object.entries(payload).forEach(([key, value]) => {
      if (value !== null && value !== undefined) {
        formData.append(key, String(value));
      }
    });
    if (this.selectedFile) {
      formData.append('profile_image', this.selectedFile, this.selectedFile.name);
    }

    this.http.post(`${API_BASE_URL}/profile/complete`, formData, {
      headers: { Authorization: `Bearer ${this.authService.getToken()}` }
    })
    .pipe(finalize(() => { this.isLoading = false; }))
    .subscribe({
      next: (res: any) => {
        this.successMessage = 'Profile updated successfully!';
        const user = res?.data?.user;
        const imageUrl = user?.profile_image_url;
        if (imageUrl) localStorage.setItem('profileImage', imageUrl);
        if (user) {
          this.authService.saveUser({ ...user, profile_completed: true });
        }
        setTimeout(() => this.router.navigate([this.authService.dashboardRouteForRole(this.userRole)]), 1000);
      },
      error: (error) => {
        console.error('[Signup2] profile complete failed', error);
        this.errorMessage = error?.error?.message || 'Failed to save profile data.';
      },
    });
  }

  private buildPayload(): Record<string, unknown> {
    if (this.isDoctor) {
      return {
        gender: this.gender,
        about: this.description,
        specialty: this.specialization,
        years_of_experience: this.yearsOfExperience,
        fee: this.consultationFee,
        address: this.clinicAddress,
        available_days: this.availableDays.join(','),
      };
    }

    if (this.isCaregiver) {
      return {
        gender: this.gender,
        about: this.description,
        specialty: this.specialization,
        experience_years: this.yearsOfExperience,
        salary: this.salary,
      };
    }

    return {
      gender: this.gender,
      about: this.description,
      age: this.age,
      address: this.address,
      chronic_conditions: this.selectedConditionsArray.join(', '),
      medical_history: this.selectedConditionsArray.join(', '),
    };
  }
}
