import { HttpClient } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../constants';
import { ClinicApiEnvelope } from './clinic.service';
import { AuthService } from './auth.service';

/**
 * RIVA audit (Section 2 — Doctor public credentials/certificates,
 * 2026-08-28): Angular consumer for the new DoctorCredentialController.
 * Field names match DoctorCredentialController's serialization exactly.
 * Distinct from doctor verification (DoctorVerificationController, private
 * documents only) — see that controller's docblock for the split.
 */

export type CredentialType =
  | 'bachelor'
  | 'master'
  | 'phd'
  | 'certificate'
  | 'training'
  | 'professional_license'
  | 'other';

export interface DoctorCredential {
  id: number;
  doctor_id: number;
  title: string;
  credential_type: CredentialType;
  issuing_organization: string | null;
  year: number | null;
  description: string | null;
  file_path?: string | null;
  file_url: string | null;
  is_public: boolean;
  created_at?: string;
  updated_at?: string;
}

@Injectable({ providedIn: 'root' })
export class DoctorCredentialsService {
  private readonly ownUrl = `${API_BASE_URL}/doctor/credentials`;

  constructor(private http: HttpClient, private authService: AuthService) {}

  private get authHeaders() {
    return { Authorization: `Bearer ${this.authService.getToken()}` };
  }

  /** The authenticated doctor's own credentials, public and private alike. */
  list(): Observable<ClinicApiEnvelope<DoctorCredential[]>> {
    return this.http.get<ClinicApiEnvelope<DoctorCredential[]>>(this.ownUrl, { headers: this.authHeaders });
  }

  create(payload: FormData): Observable<ClinicApiEnvelope<DoctorCredential>> {
    return this.http.post<ClinicApiEnvelope<DoctorCredential>>(this.ownUrl, payload, { headers: this.authHeaders });
  }

  update(id: number, payload: Partial<DoctorCredential> | FormData): Observable<ClinicApiEnvelope<DoctorCredential>> {
    if (payload instanceof FormData) {
      // Laravel doesn't parse multipart bodies on PATCH — the standard
      // Laravel-side workaround is a POST with _method=PATCH, mirrored here
      // only for the file-replacement case; a plain JSON PATCH is used for
      // every other update (e.g. toggling is_public) below.
      payload.append('_method', 'PATCH');
      return this.http.post<ClinicApiEnvelope<DoctorCredential>>(`${this.ownUrl}/${id}`, payload, { headers: this.authHeaders });
    }
    return this.http.patch<ClinicApiEnvelope<DoctorCredential>>(`${this.ownUrl}/${id}`, payload, { headers: this.authHeaders });
  }

  remove(id: number): Observable<ClinicApiEnvelope<null>> {
    return this.http.delete<ClinicApiEnvelope<null>>(`${this.ownUrl}/${id}`, { headers: this.authHeaders });
  }

  /** Public — no auth required, only credentials the doctor made public. */
  forDoctor(doctorId: number): Observable<ClinicApiEnvelope<DoctorCredential[]>> {
    return this.http.get<ClinicApiEnvelope<DoctorCredential[]>>(`${API_BASE_URL}/doctors/${doctorId}/credentials`);
  }
}
