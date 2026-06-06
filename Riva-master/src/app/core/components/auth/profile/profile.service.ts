import { Injectable } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { BehaviorSubject, Observable, throwError } from 'rxjs';
import { catchError, map, tap } from 'rxjs/operators';
import { API_BASE_URL } from '../../../../constants';

export interface UserProfile {
  id: number;
  email: string;
  first_name: string;
  last_name: string;
  name: string;
  phone: string | null;
  address: string | null;
  profile_image: string | null;
  profile_image_url: string | null;
  role: 'patient' | 'doctor' | 'caregiver' | 'admin';
}

export interface RoleProfile {
  id: number;
  user_id: number;
  [key: string]: unknown;
}

export interface ProfileResponse {
  user: UserProfile;
  role: string;
  role_profile: RoleProfile | null;
}

@Injectable({ providedIn: 'root' })
export class ProfileService {
  private readonly apiUrl = `${API_BASE_URL}/profile`;
  private readonly profileSubject = new BehaviorSubject<ProfileResponse | null>(null);
  readonly profile$ = this.profileSubject.asObservable();

  constructor(private http: HttpClient) {}

  getProfile(): Observable<ProfileResponse> {
    return this.http.get<ProfileResponse>(this.apiUrl).pipe(
      tap(profile => this.profileSubject.next(profile)),
      catchError(this.handleError)
    );
  }

  updateProfile(formData: FormData): Observable<ProfileResponse> {
    return this.http.post<{ data: ProfileResponse }>(this.apiUrl, formData).pipe(
      map(response => response.data),
      tap(profile => this.profileSubject.next(profile)),
      catchError(this.handleError)
    );
  }

  clearProfile(): void {
    this.profileSubject.next(null);
  }

  private handleError(error: HttpErrorResponse) {
    return throwError(() => ({
      status: error.status,
      message: error.error?.message || error.message || 'Profile request failed',
      error: error.error
    }));
  }
}
