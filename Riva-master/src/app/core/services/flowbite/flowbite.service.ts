import { Injectable } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Observable, throwError, BehaviorSubject } from 'rxjs';
import { catchError, tap } from 'rxjs/operators';
import { API_BASE_URL } from '../../../constants';

export interface UserProfile {
  id: number;
  email: string;
  first_name: string;
  last_name: string;
  name: string;
  phone: string;
  address: string;
  profile_image: string | null;
  profile_image_url: string | null;
  role: 'patient' | 'doctor' | 'caregiver' | 'admin';
  is_active: boolean;
  email_verified_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface RoleProfile {
  id: number;
  user_id: number;
  // Patient fields
  gender?: string;
  age?: number;
  blood_type?: string;
  emergency_contact?: string;
  chronic_conditions?: string;
  medical_history?: string;
  // Doctor fields
  specialty?: string;
  years_of_experience?: number;
  fee?: number;
  bio?: string;
  license_number?: string;
  contact_info?: string;
  available_days?: string;
  is_verified?: boolean;
  // Caregiver fields
  experience_years?: number;
  salary?: number;
  is_available?: boolean;
  // Common
  about?: string;
  created_at?: string;
  updated_at?: string;
}

export interface ProfileResponse {
  user: UserProfile;
  role: string;
  role_profile: RoleProfile;
}

@Injectable({
  providedIn: 'root'
})
export class ProfileService {
  private apiUrl = `${API_BASE_URL}/profile`;
  private profileSubject = new BehaviorSubject<ProfileResponse | null>(null);
  public profile$ = this.profileSubject.asObservable();

  constructor(private http: HttpClient) {
    this.loadProfile();
  }

  // ✅ Get user profile
  getProfile(): Observable<ProfileResponse> {
    return this.http.get<ProfileResponse>(`${this.apiUrl}`)
      .pipe(
        tap(profile => this.profileSubject.next(profile)),
        catchError(this.handleError)
      );
  }

  // ✅ Update profile (with image)
  updateProfile(formData: FormData): Observable<any> {
    return this.http.post(`${this.apiUrl}`, formData, {
      headers: {
        // Don't set Content-Type header, let the browser set it
        // This is important for multipart/form-data
      }
    }).pipe(
      tap((response: any) => {
        if (response.data) {
          this.profileSubject.next(response.data);
        }
      }),
      catchError(this.handleError)
    );
  }

  // ✅ Update profile without image
  updateProfileData(data: Partial<UserProfile>): Observable<any> {
    return this.http.patch(`${this.apiUrl}`, data)
      .pipe(
        tap((response: any) => {
          if (response.data) {
            this.profileSubject.next(response.data);
          }
        }),
        catchError(this.handleError)
      );
  }

  // ✅ Change password
  changePassword(currentPassword: string, newPassword: string): Observable<any> {
    return this.http.post(`${this.apiUrl}/change-password`, {
      current_password: currentPassword,
      new_password: newPassword,
      new_password_confirmation: newPassword
    }).pipe(
      catchError(this.handleError)
    );
  }

  // ✅ Upload profile image
  uploadProfileImage(file: File): Observable<any> {
    const formData = new FormData();
    formData.append('profile_image', file);

    return this.http.post(`${this.apiUrl}/upload-image`, formData)
      .pipe(
        tap((response: any) => {
          const currentProfile = this.profileSubject.value;
          if (currentProfile) {
            currentProfile.user.profile_image = response.profile_image;
            currentProfile.user.profile_image_url = response.profile_image_url;
            this.profileSubject.next(currentProfile);
          }
        }),
        catchError(this.handleError)
      );
  }

  // ✅ Load and cache profile
  loadProfile(): void {
    this.getProfile().subscribe({
      next: (profile) => {
        this.profileSubject.next(profile);
      },
      error: (error) => {
        console.error('Failed to load profile:', error);
      }
    });
  }

  // ✅ Get cached profile
  getCachedProfile(): ProfileResponse | null {
    return this.profileSubject.value;
  }

  // ✅ Clear profile cache
  clearProfile(): void {
    this.profileSubject.next(null);
  }

  // ✅ Error handling
  private handleError(error: HttpErrorResponse) {
    let errorMessage = 'An error occurred';

    if (error.error instanceof ErrorEvent) {
      // Client-side error
      errorMessage = error.error.message;
    } else {
      // Server-side error
      errorMessage = error.error.message || `Server returned code ${error.status}`;
    }

    return throwError(() => ({
      status: error.status,
      message: errorMessage,
      error: error.error
    }));
  }
}
