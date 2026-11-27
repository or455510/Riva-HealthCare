import { HttpClient } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../constants';
import { ClinicApiEnvelope } from './clinic.service';
import { AuthService } from './auth.service';

/**
 * Vertical slice (Phase C, principal-engineering directive): the Angular
 * consumer for the real, tested QueueController. Field names and states
 * match QueueController::serialize() and its state machine exactly — see
 * that controller's docblock for the full waiting -> called -> in_service
 * -> completed lifecycle and the no_show/skipped/remove alternate paths.
 */

export type QueueStatus = 'waiting' | 'called' | 'in_service' | 'completed' | 'no_show' | 'skipped' | null;

export interface QueueEntry {
  id: number;
  patient_id: number;
  patient_name: string | null;
  doctor_id: number;
  doctor_name: string | null;
  appointment_date: string;
  appointment_time: string;
  type: string | null;
  status: string;
  payment_status: string;
  queue_status: QueueStatus;
  queue_position: number | null;
  checked_in_at: string | null;
  called_at: string | null;
  queue_completed_at: string | null;
}

@Injectable({ providedIn: 'root' })
export class QueueService {
  private readonly baseUrl = `${API_BASE_URL}/clinic/queue`;

  constructor(private http: HttpClient, private authService: AuthService) {}

  private get authHeaders() {
    return { Authorization: `Bearer ${this.authService.getToken()}` };
  }

  list(): Observable<ClinicApiEnvelope<QueueEntry[]>> {
    return this.http.get<ClinicApiEnvelope<QueueEntry[]>>(this.baseUrl, { headers: this.authHeaders });
  }

  private action(appointmentId: number, action: string): Observable<ClinicApiEnvelope<QueueEntry>> {
    return this.http.post<ClinicApiEnvelope<QueueEntry>>(
      `${this.baseUrl}/${appointmentId}/${action}`,
      {},
      { headers: this.authHeaders }
    );
  }

  checkIn(appointmentId: number): Observable<ClinicApiEnvelope<QueueEntry>> {
    return this.action(appointmentId, 'check-in');
  }

  call(appointmentId: number): Observable<ClinicApiEnvelope<QueueEntry>> {
    return this.action(appointmentId, 'call');
  }

  start(appointmentId: number): Observable<ClinicApiEnvelope<QueueEntry>> {
    return this.action(appointmentId, 'start');
  }

  complete(appointmentId: number): Observable<ClinicApiEnvelope<QueueEntry>> {
    return this.action(appointmentId, 'complete');
  }

  noShow(appointmentId: number): Observable<ClinicApiEnvelope<QueueEntry>> {
    return this.action(appointmentId, 'no-show');
  }

  skip(appointmentId: number): Observable<ClinicApiEnvelope<QueueEntry>> {
    return this.action(appointmentId, 'skip');
  }

  remove(appointmentId: number): Observable<ClinicApiEnvelope<QueueEntry>> {
    return this.action(appointmentId, 'remove');
  }
}
