import { Component, OnInit, OnDestroy } from '@angular/core';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule } from '@angular/forms';
import { RouterModule } from '@angular/router';
import { ProfileService, ProfileResponse } from './profile.service';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { SidebarComponent } from '../../../../components/sidebar';
import { AuthService } from '../../../../service/auth.service';

@Component({
  selector: 'app-profile',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, RouterModule, SidebarComponent],
  templateUrl: './profile.component.html',
  styleUrls: ['./profile.component.css']
})
export class ProfileComponent implements OnInit, OnDestroy {
  profileForm!: FormGroup;
  loading = false;
  submitting = false;
  imagePreview: string | null = null;
  selectedFile: File | null = null;
  imageLoadFailed = false;
  successMessage = '';
  errorMessage = '';
  userRole: 'patient' | 'doctor' | 'caregiver' | 'admin' = 'patient';
  activeProfileTab: 'personal' | 'medical' = 'personal';
  private destroy$ = new Subject<void>();

  // Form sections
  showPatientFields = false;
  showDoctorFields = false;
  showCaregiverFields = false;

  // Image upload states
  imageUploadProgress = 0;
  isUploadingImage = false;

  constructor(
    private fb: FormBuilder,
    private profileService: ProfileService,
    private authService: AuthService
  ) {}

  ngOnInit(): void {
    this.initializeForm();
    this.loadProfile();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // ✅ Initialize form with all possible fields
  private initializeForm(): void {
    this.profileForm = this.fb.group({
      // General fields
      first_name: ['', [Validators.required, Validators.minLength(2)]],
      last_name: ['', [Validators.required, Validators.minLength(2)]],
      phone: ['', [Validators.pattern(/^[0-9\-\+\(\)\s]+$/)]],
      address: [''],
      email: [{ value: '', disabled: true }],

      // Patient fields
      gender: [''],
      age: ['', [Validators.min(0), Validators.max(150)]],
      blood_type: [''],
      emergency_contact: [''],
      chronic_conditions: [''],
      medical_history: [''],

      // Doctor fields
      specialty: [''],
      years_of_experience: ['', [Validators.min(0), Validators.max(70)]],
      fee: ['', [Validators.min(0)]],
      bio: [''],
      license_number: [''],
      contact_info: [''],
      available_days: [''],

      // Caregiver fields
      experience_years: ['', [Validators.min(0), Validators.max(70)]],
      salary: ['', [Validators.min(0)]],

      // Common field
      about: ['', [Validators.maxLength(1000)]],
    });
  }

  // ✅ Load profile data
  loadProfile(): void {
    this.loading = true;
    this.profileService.getProfile()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          this.populateForm(response);
          this.loading = false;
        },
        error: (error) => {
          this.errorMessage = error.message || 'Failed to load profile';
          this.loading = false;
        }
      });
  }

  // ✅ Populate form with profile data
  private populateForm(data: ProfileResponse): void {
    const { user, role_profile, role } = data;
    this.userRole = role as any;

    // Set general fields
    this.profileForm.patchValue({
      first_name: user.first_name || '',
      last_name: user.last_name || '',
      phone: user.phone || '',
      address: user.address || '',
      email: user.email,
      about: role_profile?.['about'] || '',
    });

    // Set profile image
    this.imageLoadFailed = false;
    if (user.profile_image_url) {
      this.imagePreview = user.profile_image_url;
    }

    // Enable and populate role-specific fields
    if (role === 'patient' && role_profile) {
      this.enablePatientFields(role_profile);
    } else if (role === 'doctor' && role_profile) {
      this.enableDoctorFields(role_profile);
    } else if (role === 'caregiver' && role_profile) {
      this.enableCaregiverFields(role_profile);
    }
  }

  // ✅ Enable patient fields
  private enablePatientFields(roleProfile: any): void {
    this.showPatientFields = true;
    this.showDoctorFields = false;
    this.showCaregiverFields = false;

    const patientControls = ['gender', 'age', 'blood_type', 'emergency_contact', 'chronic_conditions', 'medical_history'];

    patientControls.forEach(field => {
      const control = this.profileForm.get(field);
      if (control) {
        control.setValue(roleProfile[field] || '');
      }
    });
  }

  // ✅ Enable doctor fields
  private enableDoctorFields(roleProfile: any): void {
    this.showPatientFields = false;
    this.showDoctorFields = true;
    this.showCaregiverFields = false;

    const doctorControls = ['specialty', 'years_of_experience', 'fee', 'bio', 'license_number', 'contact_info', 'available_days'];

    doctorControls.forEach(field => {
      const control = this.profileForm.get(field);
      if (control) {
        control.setValue(roleProfile[field] || '');
      }
    });
  }

  // ✅ Enable caregiver fields
  private enableCaregiverFields(roleProfile: any): void {
    this.showPatientFields = false;
    this.showDoctorFields = false;
    this.showCaregiverFields = true;

    const caregiverControls = ['specialty', 'experience_years', 'salary', 'bio'];

    caregiverControls.forEach(field => {
      const control = this.profileForm.get(field);
      if (control) {
        control.setValue(roleProfile[field] || '');
      }
    });
  }

  // ✅ Handle image selection
  onImageSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) return;

    // Validate file type
    if (!file.type.startsWith('image/')) {
      this.errorMessage = 'Please select a valid image file (JPEG, PNG, GIF)';
      return;
    }

    // Validate file size (2MB max)
    const maxSize = 2 * 1024 * 1024;
    if (file.size > maxSize) {
      this.errorMessage = 'Image size must be less than 2MB';
      return;
    }

    this.selectedFile = file;

    // Show preview
    const reader = new FileReader();
    reader.onload = (e) => {
      this.imagePreview = e.target?.result as string;
      this.successMessage = 'Image selected. Click save to upload.';
    };
    reader.readAsDataURL(file);
  }

  // ✅ Remove selected image
  removeImage(): void {
    this.selectedFile = null;
    this.imagePreview = null;
    this.imageLoadFailed = false;
    const fileInput = document.querySelector('input[type="file"]') as HTMLInputElement;
    if (fileInput) {
      fileInput.value = '';
    }
  }

  // ✅ Submit form
  onSubmit(): void {
    if (this.profileForm.invalid) {
      this.markFormGroupTouched(this.profileForm);
      this.errorMessage = 'Please fill all required fields correctly';
      return;
    }

    this.submitting = true;
    const formData = new FormData();

    // Add all form values
    Object.keys(this.profileForm.getRawValue()).forEach(key => {
      const control = this.profileForm.get(key);
      const value = control?.value;

      // Only add non-empty, non-null values
      if (value !== null && value !== '' && value !== undefined) {
        formData.append(key, value);
      }
    });

    // Add image if selected
    if (this.selectedFile) {
      formData.append('profile_image', this.selectedFile);
    }

    this.profileService.updateProfile(formData)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          this.successMessage = 'Profile updated successfully';
          this.errorMessage = '';
          this.selectedFile = null;
          if (response?.user) {
            this.authService.saveUser(response.user as any);
          }
          this.loadProfile();
          this.submitting = false;
        },
        error: (error) => {
          const errorMsg = error.error?.errors
            ? Object.values(error.error.errors).flat().join(' ')
            : error.message || 'Failed to update profile';
          this.errorMessage = errorMsg;
          this.submitting = false;
        }
      });
  }

  // ✅ Mark all fields as touched
  private markFormGroupTouched(formGroup: FormGroup): void {
    Object.keys(formGroup.controls).forEach(key => {
      const control = formGroup.get(key);
      control?.markAsTouched();

      if (control instanceof FormGroup) {
        this.markFormGroupTouched(control);
      }
    });
  }

  // ✅ Helper methods for template
  hasError(fieldName: string, errorType: string): boolean {
    const field = this.profileForm.get(fieldName);
    return !!(field && field.hasError(errorType) && (field.dirty || field.touched));
  }

  isFieldInvalid(fieldName: string): boolean {
    const field = this.profileForm.get(fieldName);
    return !!(field && field.invalid && (field.dirty || field.touched));
  }

  getFieldError(fieldName: string): string {
    const control = this.profileForm.get(fieldName);
    if (!control?.errors) return '';

    if (control.hasError('required')) return 'This field is required';
    if (control.hasError('minlength')) return `Minimum length is ${control.getError('minlength')?.requiredLength}`;
    if (control.hasError('maxlength')) return `Maximum length is ${control.getError('maxlength')?.requiredLength}`;
    if (control.hasError('min')) return `Minimum value is ${control.getError('min')?.min}`;
    if (control.hasError('max')) return `Maximum value is ${control.getError('max')?.max}`;
    if (control.hasError('pattern')) return 'Invalid format';

    return 'Invalid field';
  }

  get displayName(): string {
    const first = this.profileForm?.get('first_name')?.value || '';
    const last = this.profileForm?.get('last_name')?.value || '';
    return `${first} ${last}`.trim() || 'Riva User';
  }

  get roleLabel(): string {
    return (this.userRole || 'patient').toUpperCase();
  }

  get initials(): string {
    const first = this.profileForm?.get('first_name')?.value || '';
    const last = this.profileForm?.get('last_name')?.value || '';
    const value = `${first.charAt(0)}${last.charAt(0)}`.trim();
    return value ? value.toUpperCase() : 'RV';
  }

  get showImage(): boolean {
    return !!this.imagePreview && !this.imageLoadFailed;
  }

  onImageError(): void {
    this.imageLoadFailed = true;
  }
}
