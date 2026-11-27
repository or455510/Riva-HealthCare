import { HttpClient } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../constants';
import { AuthService } from './auth.service';

/**
 * Vertical slice (Phase B, principal-engineering directive): the Angular
 * consumer for the already-tested backend clinic staff/RBAC endpoints
 * (ClinicMembershipController). Every method here maps 1:1 to a real route —
 * nothing here is speculative. Field names match
 * ClinicMembershipController::serializeMember() exactly.
 */

export type ClinicMemberRole = 'owner' | 'admin' | 'doctor' | 'staff';
export type ClinicMemberStatus = 'invited' | 'active' | 'suspended';

export const CLINIC_PERMISSIONS: { value: string; label: string }[] = [
  { value: 'patients.register', label: 'Register patients' },
  { value: 'appointments.manage', label: 'Manage appointments' },
  { value: 'queue.manage', label: 'Manage queue' },
  { value: 'payments.record', label: 'Record payments' },
  { value: 'services.manage', label: 'Manage services' },
];

export interface ClinicMember {
  id: number;
  user_id: number;
  name: string | null;
  email: string | null;
  role: ClinicMemberRole;
  status: ClinicMemberStatus;
  permissions: string[];
  invited_at: string | null;
  joined_at: string | null;
  // RIVA-143: present only in the response of the invite that just created
  // them, and only when the email had no prior Riva account — a one-time
  // link the inviter can hand to the new hire (no email delivery exists in
  // this environment). Never re-appears from listMembers() afterward.
  invite_link?: string | null;
  is_new_account?: boolean;
}

export interface ClinicInviteDetails {
  clinic_name: string | null;
  role: ClinicMemberRole;
  email: string | null;
}

export interface AcceptInviteResult {
  user: { id: number; name: string; email: string; role: string };
  token: string;
  clinic_name: string | null;
  clinic_role: ClinicMemberRole;
}

export interface ClinicApiEnvelope<T> {
  success: boolean;
  message: string;
  data: T;
}

export interface ClinicContextEntry {
  clinic_id: number;
  clinic_name: string | null;
  role: ClinicMemberRole;
  permissions: string[];
}

export interface ClinicContext {
  has_clinic: boolean;
  ambiguous?: boolean;
  clinics?: ClinicContextEntry[];
}

@Injectable({ providedIn: 'root' })
export class ClinicService {
  private readonly baseUrl = `${API_BASE_URL}/clinic/members`;

  constructor(private http: HttpClient, private authService: AuthService) {}

  private get authHeaders() {
    return { Authorization: `Bearer ${this.authService.getToken()}` };
  }

  /**
   * Vertical slice (Phase C, principal-engineering directive): a graceful
   * "do I have clinic access, and as what role" probe (GET
   * /api/clinic/my-context — deliberately outside the clinic.context
   * middleware, so it never 403s/409s). Used by the sidebar to decide
   * whether to render clinic-staff navigation at all, since a clinic
   * "secretary" has no dedicated platform role in this system — they are
   * whatever platform role they registered as (patient/doctor/caregiver),
   * plus a clinic_user membership. Never throws for the common case of no
   * clinic membership; callers should still catch network/auth failures.
   */
  myContext(): Observable<ClinicApiEnvelope<ClinicContext>> {
    return this.http.get<ClinicApiEnvelope<ClinicContext>>(`${API_BASE_URL}/clinic/my-context`, {
      headers: this.authHeaders,
    });
  }

  listMembers(): Observable<ClinicApiEnvelope<ClinicMember[]>> {
    return this.http.get<ClinicApiEnvelope<ClinicMember[]>>(this.baseUrl, {
      headers: this.authHeaders,
    });
  }

  inviteMember(email: string, role: ClinicMemberRole): Observable<ClinicApiEnvelope<ClinicMember>> {
    return this.http.post<ClinicApiEnvelope<ClinicMember>>(
      this.baseUrl,
      { email, role },
      { headers: this.authHeaders }
    );
  }

  updateRole(memberId: number, role: ClinicMemberRole): Observable<ClinicApiEnvelope<ClinicMember>> {
    return this.http.patch<ClinicApiEnvelope<ClinicMember>>(
      `${this.baseUrl}/${memberId}/role`,
      { role },
      { headers: this.authHeaders }
    );
  }

  updateStatus(memberId: number, status: 'active' | 'suspended'): Observable<ClinicApiEnvelope<ClinicMember>> {
    return this.http.patch<ClinicApiEnvelope<ClinicMember>>(
      `${this.baseUrl}/${memberId}/status`,
      { status },
      { headers: this.authHeaders }
    );
  }

  updatePermissions(memberId: number, permissions: string[]): Observable<ClinicApiEnvelope<ClinicMember>> {
    return this.http.patch<ClinicApiEnvelope<ClinicMember>>(
      `${this.baseUrl}/${memberId}/permissions`,
      { permissions },
      { headers: this.authHeaders }
    );
  }

  removeMember(memberId: number): Observable<ClinicApiEnvelope<null>> {
    return this.http.delete<ClinicApiEnvelope<null>>(`${this.baseUrl}/${memberId}`, {
      headers: this.authHeaders,
    });
  }

  // RIVA-143: deliberately unauthenticated — the invitee has no account/
  // token yet when they open this link.
  getInviteDetails(token: string): Observable<ClinicApiEnvelope<ClinicInviteDetails>> {
    return this.http.get<ClinicApiEnvelope<ClinicInviteDetails>>(`${API_BASE_URL}/clinic-invites/${token}`);
  }

  acceptInvite(
    token: string,
    payload: { first_name: string; last_name: string; password: string; password_confirmation: string }
  ): Observable<ClinicApiEnvelope<AcceptInviteResult>> {
    return this.http.post<ClinicApiEnvelope<AcceptInviteResult>>(
      `${API_BASE_URL}/clinic-invites/${token}/accept`,
      payload
    );
  }
}
