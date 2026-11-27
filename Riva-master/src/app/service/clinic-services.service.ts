import { HttpClient } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../constants';
import { ClinicApiEnvelope } from './clinic.service';
import { AuthService } from './auth.service';

/**
 * Vertical slice (Phase C, Services & Prices, principal-engineering
 * directive): the Angular consumer for the real, tested
 * ClinicServiceController. Field names match
 * ClinicServiceController::serialize() exactly. index() is readable by any
 * active clinic member; create/update/delete are backend-gated behind
 * services.manage (owner/admin always pass) — this service does not
 * duplicate that check, it just calls the real endpoints and surfaces
 * whatever the backend actually decides.
 */

export interface ClinicPricedService {
  id: number;
  clinic_id: number;
  name: string;
  description: string | null;
  price: string;
  duration_minutes: number | null;
  is_active: boolean;
  created_at: string | null;
  updated_at: string | null;
}

export interface ClinicServicePayload {
  name?: string;
  description?: string | null;
  price?: number;
  duration_minutes?: number | null;
  is_active?: boolean;
}

/** Public, unauthenticated shape returned by GET doctors/{doctor}/services. */
export interface DoctorService {
  id: number;
  clinic_id: number;
  name: string;
  description: string | null;
  price: string;
  duration_minutes: number | null;
}

@Injectable({ providedIn: 'root' })
export class ClinicServicesService {
  private readonly baseUrl = `${API_BASE_URL}/clinic/services`;

  constructor(private http: HttpClient, private authService: AuthService) {}

  private get authHeaders() {
    return { Authorization: `Bearer ${this.authService.getToken()}` };
  }

  list(): Observable<ClinicApiEnvelope<ClinicPricedService[]>> {
    return this.http.get<ClinicApiEnvelope<ClinicPricedService[]>>(this.baseUrl, {
      headers: this.authHeaders,
    });
  }

  create(payload: ClinicServicePayload): Observable<ClinicApiEnvelope<ClinicPricedService>> {
    return this.http.post<ClinicApiEnvelope<ClinicPricedService>>(this.baseUrl, payload, {
      headers: this.authHeaders,
    });
  }

  update(serviceId: number, payload: ClinicServicePayload): Observable<ClinicApiEnvelope<ClinicPricedService>> {
    return this.http.patch<ClinicApiEnvelope<ClinicPricedService>>(`${this.baseUrl}/${serviceId}`, payload, {
      headers: this.authHeaders,
    });
  }

  remove(serviceId: number): Observable<ClinicApiEnvelope<null>> {
    return this.http.delete<ClinicApiEnvelope<null>>(`${this.baseUrl}/${serviceId}`, {
      headers: this.authHeaders,
    });
  }

  /**
   * Public booking-flow lookup — no clinic context required, mirrors the
   * existing public visibility of Doctor::fee. Used by BookingComponent to
   * offer a specific priced service instead of only the doctor's flat fee.
   */
  listForDoctor(doctorId: number): Observable<{ success: boolean; message: string; data: DoctorService[] }> {
    return this.http.get<{ success: boolean; message: string; data: DoctorService[] }>(
      `${API_BASE_URL}/doctors/${doctorId}/services`,
      { headers: this.authHeaders }
    );
  }
}
