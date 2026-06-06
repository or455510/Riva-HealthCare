// import { HttpClient, HttpHeaders } from '@angular/common/http';
// import { Injectable } from '@angular/core';
// import { Observable } from 'rxjs';
// import { API_BASE_URL } from '../constants';

// export interface User {
//   id: number;
//   first_name?: string;
//   last_name?: string;
//   name?: string;
//   email: string;
//   role: string;
//   phone?: string | null;
//   address?: string | null;
//   patient?: unknown;
//   doctor?: unknown;
//   caregiver?: unknown;
// }

// export interface LoginResponse {
//   message: string;
//   user: User;
//   token: string;
// }

// export interface RegisterPayload {
//   first_name: string;
//   last_name: string;
//   email: string;
//   phone?: string;
//   password: string;
//   password_confirmation: string;
//   role: 'patient' | 'doctor' | 'caregiver' | 'admin';
//   address?: string;
// }

// @Injectable({
//   providedIn: 'root',
// })
// export class AuthService {
//   [x: string]: any;
//   private readonly authUrl = `${API_BASE_URL}/auth`;
// private apiUrl = 'http://127.0.0.1:8000/api';
//   private get isBrowser(): boolean {
//     return typeof window !== 'undefined' && typeof localStorage !== 'undefined';
//   }

//   constructor(private http: HttpClient) {}

//   register(payload: RegisterPayload): Observable<LoginResponse> {
//     return this.http.post<LoginResponse>(`${this.authUrl}/register`, payload);
//   }

//   login(email: string, password: string): Observable<LoginResponse> {
//     return this.http.post<LoginResponse>(`${this.authUrl}/login`, { email, password });
//   }

//   me(): Observable<{ user: User }> {
//     return this.http.get<{ user: User }>(`${this.apiUrl}/me`);
//   }

//   getProfile(): Observable<any> {
//     return this.http.get(`${this.apiUrl}/profile`);
//   }

//   updateProfile(payload: Record<string, unknown>): Observable<any> {
//     return this.http.put(`${this.apiUrl}/profile`, payload);
//   }

//   completeProfile(payload: Record<string, unknown>): Observable<any> {
//     return this.http.post(`${this.apiUrl}/profile/complete`, payload);
//   }

//   changePassword(currentPassword: string, newPassword: string): Observable<any> {
//     const token = this.getToken();
//     return this.http.put(
//       `${this.apiUrl}/profile/password`,
//       { current_password: currentPassword, new_password: newPassword },
//       {
//         headers: new HttpHeaders({
//           Authorization: `Bearer ${token}`,
//           Accept: 'application/json',
//           'Content-Type': 'application/json',
//         }),
//       }
//     );
//   }

//   saveToken(response: LoginResponse): void {
//     if (!this.isBrowser) return;
//     localStorage.setItem('token', response.token);
//     localStorage.setItem('role', response.user.role);
//     localStorage.setItem('userRole', response.user.role);
//     localStorage.setItem('user', JSON.stringify(response.user));
//   }

//   getToken(): string {
//     if (!this.isBrowser) return '';
//     return localStorage.getItem('token') || '';
//   }

//   getUser(): User | null {
//     if (!this.isBrowser) return null;
//     try {
//       const raw = localStorage.getItem('user');
//       return raw ? JSON.parse(raw) as User : null;
//     } catch {
//       return null;
//     }
//   }

//   getUserRole(): string {
//     return this.getUser()?.role || (this.isBrowser ? localStorage.getItem('role') || '' : '');
//   }

//   isAuthenticated(): boolean {
//     return !!this.getToken();
//   }

//   logout(): void {
//     if (!this.isBrowser) return;
//     localStorage.removeItem('token');
//     localStorage.removeItem('role');
//     localStorage.removeItem('userRole');
//     localStorage.removeItem('user');
//   }
//   getAuthHeaders(): HttpHeaders {
//   return new HttpHeaders({
//     Authorization: `Bearer ${this.getToken()}`,
//     Accept: 'application/json',
//     'Content-Type': 'application/json',
//   });
// }
//   isAdmin(): boolean {
//     return localStorage.getItem('role') === 'admin';
//   }
// }
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../constants';

export interface User {
  id: number;
  first_name?: string;
  last_name?: string;
  name?: string;
  email: string;
  role: string;
  phone?: string | null;
  address?: string | null;
  profile_image_url?: string | null;
  profile_completed?: boolean;
  profile_completed_at?: string | null;
  patient?: unknown;
  doctor?: unknown;
  caregiver?: unknown;
}

export interface LoginResponse {
  message: string;
  user: User;
  token: string;
}

export interface RegisterPayload {
  first_name: string;
  last_name: string;
  email: string;
  phone?: string;
  password: string;
  password_confirmation: string;
  role: 'patient' | 'doctor' | 'caregiver' | 'admin';
  address?: string;
}

@Injectable({
  providedIn: 'root',
})
export class AuthService {
  
  private readonly authUrl = `${API_BASE_URL}/auth`;

  private get isBrowser(): boolean {
    return typeof window !== 'undefined' && typeof localStorage !== 'undefined';
  }

  constructor(private http: HttpClient) {}

  register(payload: RegisterPayload): Observable<LoginResponse> {
    return this.http.post<LoginResponse>(`${this.authUrl}/register`, payload);
  }

  login(email: string, password: string): Observable<LoginResponse> {
    return this.http.post<LoginResponse>(`${this.authUrl}/login`, { email, password });
  }

  forgotPassword(email: string): Observable<any> {
    return this.http.post(`${this.authUrl}/forgot-password`, { email });
  }

  verifyResetCode(email: string, code: string): Observable<any> {
    return this.http.post(`${this.authUrl}/verify-reset-code`, { email, code });
  }

  resetPassword(email: string, code: string, password: string, passwordConfirmation: string): Observable<any> {
    return this.http.post(`${this.authUrl}/reset-password`, {
      email,
      code,
      password,
      password_confirmation: passwordConfirmation
    });
  }

  getGoogleRedirectUrl(): Observable<{ data: { url: string } }> {
    return this.http.get<{ data: { url: string } }>(`${this.authUrl}/google/redirect`);
  }

  me(): Observable<{ user: User }> {
    return this.http.get<{ user: User }>(`${API_BASE_URL}/me`);
  }

  getProfile(): Observable<any> {
    return this.http.get(`${API_BASE_URL}/profile`);
  }

updateProfile(payload: Record<string, unknown>): Observable<any> {
    return this.http.put(`${API_BASE_URL}/profile`, payload);
  }

  updateProfileFormData(formData: FormData): Observable<any> {
    return this.http.post(`${API_BASE_URL}/profile`, formData, {
      headers: {
        Authorization: `Bearer ${this.getToken()}`,
        Accept: 'application/json',
      }
    });
  }

  completeProfile(payload: Record<string, unknown>): Observable<any> {
    return this.http.post(`${API_BASE_URL}/profile/complete`, payload);
  }

  changePassword(currentPassword: string, newPassword: string): Observable<any> {
    return this.http.put(
      `${API_BASE_URL}/profile/password`,
      { current_password: currentPassword, new_password: newPassword }
    );
  }

  saveToken(response: LoginResponse): void {
    if (!this.isBrowser) return;
    const user = this.normalizeUser(response.user);
    localStorage.setItem('token', response.token);
    localStorage.setItem('user', JSON.stringify(user));
  }

  saveUser(user: User): void {
    if (!this.isBrowser) return;
    localStorage.setItem('user', JSON.stringify(this.normalizeUser(user)));
  }

  getToken(): string {
    if (!this.isBrowser) return '';
    return localStorage.getItem('token') || '';
  }

  getUser(): User | null {
    if (!this.isBrowser) return null;
    try {
      const raw = localStorage.getItem('user');
      return raw ? JSON.parse(raw) as User : null;
    } catch {
      return null;
    }
  }

  getUserRole(): string {
    return (this.getUser()?.role || '').toLowerCase();
  }

  isAuthenticated(): boolean {
    return !!this.getToken();
  }

  isProfileCompleted(): boolean {
    const user = this.getUser();
    return !!(user?.profile_completed || user?.profile_completed_at);
  }

  logout(): void {
    if (!this.isBrowser) return;
    this.http.post(`${this.authUrl}/logout`, {}).subscribe({ next: () => {}, error: () => {} });
    this.clearSession();
  }

  clearSession(): void {
    if (!this.isBrowser) return;
    localStorage.removeItem('token');
    localStorage.removeItem('role');
    localStorage.removeItem('userRole');
    localStorage.removeItem('user');
    sessionStorage.removeItem('reset_email');
    sessionStorage.removeItem('reset_code');
  }

  getAuthHeaders(): HttpHeaders {
    return new HttpHeaders({
      Authorization: `Bearer ${this.getToken()}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
    });
  }

  isAdmin(): boolean {
    return this.getUserRole() === 'admin';
  }

  dashboardRouteForRole(role = this.getUserRole()): string {
    switch ((role || '').toLowerCase()) {
      case 'doctor':
        return '/dashboard';
      case 'caregiver':
        return '/dashboard-caregiver';
      case 'admin':
        return '/admin/dashboard-admin';
      default:
        return '/dashboard-p';
    }
  }

  completionRouteForRole(role = this.getUserRole()): string {
    switch ((role || '').toLowerCase()) {
      case 'doctor':
        return '/complete-profile/doctor';
      case 'caregiver':
        return '/complete-profile/caregiver';
      case 'patient':
      default:
        return '/complete-profile/patient';
    }
  }

  private normalizeUser(user: User): User {
    return {
      ...user,
      role: (user.role || '').toLowerCase(),
    };
  }
}
