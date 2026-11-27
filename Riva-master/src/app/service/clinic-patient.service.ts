import { HttpClient } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../constants';
import { ClinicApiEnvelope } from './clinic.service';
import { AuthService } from './auth.service';

/**
 * Vertical slice (Phase C, principal-engineering directive): the Angular
 * consumer for the real, tested ClinicPatientController. Search is
 * deliberately exact-match only (email or phone) — there is no partial-name
 * search parameter here because the backend does not support one, by
 * design (see that controller's docblock on why a fuzzy patient-name search
 * would be a real PII exposure).
 *
 * RIVA-141: searchByName() is a SEPARATE, additionally-scoped endpoint
 * (GET /clinic/patients/search-by-name) — it stays scoped server-side to
 * patients who already have a real relationship with a doctor at this
 * clinic, never a platform-wide browse. See ClinicPatientController's
 * docblock on searchByName() for the exact scoping rules.
 */

export interface ClinicPatient {
  user_id: number;
  patient_id: number | null;
  name: string | null;
  email: string;
  phone: string | null;
  gender: string | null;
  age: number | null;
  profile_completed: boolean;
}

export interface PatientSearchResult {
  found: boolean;
  patient?: ClinicPatient;
}

export interface PatientNameSearchResult {
  patients: ClinicPatient[];
}

export interface RegisterPatientPayload {
  first_name: string;
  last_name: string;
  email: string;
  phone?: string;
  gender?: string;
  age?: number;
  blood_type?: string;
  emergency_contact?: string;
}

@Injectable({ providedIn: 'root' })
export class ClinicPatientService {
  private readonly baseUrl = `${API_BASE_URL}/clinic/patients`;

  constructor(private http: HttpClient, private authService: AuthService) {}

  private get authHeaders() {
    return { Authorization: `Bearer ${this.authService.getToken()}` };
  }

  searchByEmail(email: string): Observable<ClinicApiEnvelope<PatientSearchResult>> {
    return this.http.get<ClinicApiEnvelope<PatientSearchResult>>(`${this.baseUrl}/search`, {
      headers: this.authHeaders,
      params: { email },
    });
  }

  searchByPhone(phone: string): Observable<ClinicApiEnvelope<PatientSearchResult>> {
    return this.http.get<ClinicApiEnvelope<PatientSearchResult>>(`${this.baseUrl}/search`, {
      headers: this.authHeaders,
      params: { phone },
    });
  }

  searchByName(q: string): Observable<ClinicApiEnvelope<PatientNameSearchResult>> {
    return this.http.get<ClinicApiEnvelope<PatientNameSearchResult>>(`${this.baseUrl}/search-by-name`, {
      headers: this.authHeaders,
      params: { q },
    });
  }

  register(payload: RegisterPatientPayload): Observable<ClinicApiEnvelope<ClinicPatient>> {
    return this.http.post<ClinicApiEnvelope<ClinicPatient>>(this.baseUrl, payload, {
      headers: this.authHeaders,
    });
  }
}
