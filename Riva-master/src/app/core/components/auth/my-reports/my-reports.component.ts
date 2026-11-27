import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { RouterModule, Router } from '@angular/router';
import { finalize } from 'rxjs';
import { API_BASE_URL } from '../../../../constants';
import { AuthService } from '../../../../service/auth.service';
import { SidebarComponent } from '../../../../components/sidebar';

interface ReportComment {
  id: number;
  comment: string;
  commenter_role: 'doctor' | 'caregiver';
  commenter_name: string;
  commenter_avatar?: string;
  is_read: boolean;
  created_at: string;
}

interface SharedWithDoctor {
  doctor_id: number;
  doctor_name: string;
}

interface Report {
  id: number;
  title: string;
  summary: string;
  final_report: string;
  doctor: { id: number; name: string };
  patient: { id: number; name: string };
  comments: ReportComment[];
  unread_comments: number;
  is_shared_with_me?: boolean;
  // Only present when the caller is the owning patient (RIVA-142).
  shared_with?: SharedWithDoctor[];
  created_at: string;
}

interface MyDoctor {
  doctor_id: number;
  doctor_name: string;
}

@Component({
  selector: 'app-my-reports',
  standalone: true,
  imports: [CommonModule, FormsModule, HttpClientModule, RouterModule, SidebarComponent],
  templateUrl: './my-reports.component.html',
  styleUrl: './my-reports.component.css',
})
export class MyReportsComponent implements OnInit {
  reports: Report[] = [];
  isLoading = true;
  selectedReport!: Report;
  errorMessage = '';
  sidebarLinks: { route: string; icon: string }[] = [];

  userRole: 'patient' | 'doctor' | 'caregiver' = 'patient';
  get isDoctor():    boolean { return this.userRole === 'doctor';    }
  get isCaregiver(): boolean { return this.userRole === 'caregiver'; }
  get isPatient():   boolean { return this.userRole === 'patient';   }

  // RIVA-142: patient-controlled cross-doctor report sharing.
  myDoctors: MyDoctor[] = [];
  shareTargetDoctorId: number | null = null;
  isSharing = false;
  shareError = '';
  unsharingDoctorId: number | null = null;

  get accentColor(): string { return this.isCaregiver ? 'purple' : 'blue'; }

  private get authHeaders() {
    return {
      Authorization: `Bearer ${this.authService.getToken()}`,
      'Content-Type': 'application/json',
    };
  }

  constructor(
    private http: HttpClient,
    private authService: AuthService,
    private router: Router,
  ) {}

  ngOnInit(): void {
    this.resolveRole();
    this.loadReports();
    if (this.isPatient) {
      this.loadMyDoctors();
    }
  }

  private loadMyDoctors(): void {
    this.http
      .get<any>(`${API_BASE_URL}/patient/my-doctors`, { headers: this.authHeaders })
      .subscribe({
        next: (res) => {
          const list = res?.data ?? res ?? [];
          this.myDoctors = (Array.isArray(list) ? list : [])
            .map((rel: any) => ({
              doctor_id: rel.doctor_id ?? rel.doctor?.id,
              doctor_name: rel.doctor?.user?.name ?? 'Doctor',
            }))
            .filter((d: MyDoctor) => !!d.doctor_id);
        },
        error: () => { this.myDoctors = []; },
      });
  }

  private resolveRole(): void {
    const roleFromService = this.authService.getUserRole?.()?.toLowerCase() || '';
    if (roleFromService) {
      this.userRole = this.mapRole(roleFromService);
      this.buildSidebar();
      return;
    }
    try {
      for (const key of ['user', 'userData', 'authUser', 'currentUser']) {
        const str = localStorage.getItem(key);
        if (str) {
          const obj = JSON.parse(str);
          const r = (obj?.role || obj?.type || '').toLowerCase();
          if (r) { this.userRole = this.mapRole(r); this.buildSidebar(); return; }
        }
      }
    } catch {}
    this.userRole = 'patient';
    this.buildSidebar();
  }

  loadReports(): void {
    this.isLoading = true;
    this.http
      .get<any>(`${API_BASE_URL}/reports`, { headers: this.authHeaders })
      .pipe(finalize(() => (this.isLoading = false)))
      .subscribe({
        next: (res) => {
          this.reports = res?.data ?? res ?? [];
        },
        error: (err) => {
          this.errorMessage = err?.error?.message || err?.message || `Error ${err?.status}: Failed to load reports.`;
        },
      });
  }

  openReport(report: Report): void {
    this.isLoading = true;
    this.shareTargetDoctorId = null;
    this.shareError = '';
    this.http
      .get<any>(`${API_BASE_URL}/reports/${report.id}`, { headers: this.authHeaders })
      .pipe(finalize(() => (this.isLoading = false)))
      .subscribe({
        next: (res) => {
          this.selectedReport = res?.data ?? res;
          const idx = this.reports.findIndex(r => r.id === report.id);
          if (idx > -1) this.reports[idx].unread_comments = 0;
        },
        error: () => { this.selectedReport = report; },
      });
  }

  closeReport(): void {
    this.selectedReport = undefined!;
    this.shareTargetDoctorId = null;
    this.shareError = '';
  }

  // RIVA-142: doctors who could receive this report but haven't yet —
  // excludes the report's own author (backend rejects that as redundant)
  // and anyone already in shared_with.
  get shareableDoctors(): MyDoctor[] {
    if (!this.selectedReport) return [];
    const authorId = this.selectedReport.doctor?.id;
    const alreadySharedIds = new Set((this.selectedReport.shared_with ?? []).map(s => s.doctor_id));
    return this.myDoctors.filter(d => d.doctor_id !== authorId && !alreadySharedIds.has(d.doctor_id));
  }

  shareSelectedReport(): void {
    if (!this.selectedReport || !this.shareTargetDoctorId) return;
    this.isSharing = true;
    this.shareError = '';
    this.http
      .post<any>(
        `${API_BASE_URL}/reports/${this.selectedReport.id}/share`,
        { doctor_id: this.shareTargetDoctorId },
        { headers: this.authHeaders }
      )
      .pipe(finalize(() => (this.isSharing = false)))
      .subscribe({
        next: () => {
          const doctor = this.myDoctors.find(d => d.doctor_id === this.shareTargetDoctorId);
          if (this.selectedReport && doctor) {
            this.selectedReport.shared_with = [...(this.selectedReport.shared_with ?? []), doctor];
          }
          this.shareTargetDoctorId = null;
        },
        error: (err) => {
          this.shareError = err?.error?.message || 'Failed to share this report.';
        },
      });
  }

  unshareSelectedReport(doctorId: number): void {
    if (!this.selectedReport) return;
    this.unsharingDoctorId = doctorId;
    this.http
      .delete<any>(`${API_BASE_URL}/reports/${this.selectedReport.id}/share/${doctorId}`, { headers: this.authHeaders })
      .pipe(finalize(() => (this.unsharingDoctorId = null)))
      .subscribe({
        next: () => {
          if (this.selectedReport) {
            this.selectedReport.shared_with = (this.selectedReport.shared_with ?? []).filter(s => s.doctor_id !== doctorId);
          }
        },
        error: (err) => {
          this.shareError = err?.error?.message || 'Failed to revoke sharing.';
        },
      });
  }

  getRoleIcon(role: string): string {
    return role === 'doctor' ? '👨‍⚕️' : '🧑‍⚕️';
  }

  totalUnread(): number {
    return this.reports.reduce((sum, r) => sum + (r.unread_comments ?? 0), 0);
  }

  goBack(): void {
    this.router.navigate(['/dashboard-p']);
  }

  private mapRole(role: string): 'patient' | 'doctor' | 'caregiver' {
    if (role === 'doctor') return 'doctor';
    if (role === 'caregiver') return 'caregiver';
    return 'patient';
  }

  private buildSidebar(): void {
    if (this.isCaregiver) {
      this.sidebarLinks = [
        { icon: 'fas fa-home',              route: '/dashboard-caregiver' },
        { icon: 'fa-brands fa-rocketchat',  route: '/chat-c' },
        { icon: 'fa-solid fa-circle-user',  route: '/myprofile' },
        { icon: 'fa-solid fa-phone',        route: '/contact' },
        { icon: 'fa-solid fa-bed-pulse',    route: '/patient-cards' },
      ];
    } else if (this.isDoctor) {
      this.sidebarLinks = [
        { icon: 'fas fa-home',              route: '/dashboard' },
        { icon: 'fa-brands fa-rocketchat',  route: '/chat' },
        { icon: 'fa-solid fa-circle-user',  route: '/myprofile' },
        { icon: 'fa-solid fa-phone',        route: '/contact' },
        { icon: 'fa-solid fa-bed-pulse',    route: '/patient-cards' },
      ];
    } else {
      this.sidebarLinks = [
        { icon: 'fas fa-home',              route: '/dashboard-p' },
        { icon: 'fas fa-pills',             route: '/add-new-medication' },
        { icon: 'fa-solid fa-user-doctor',  route: '/doctor-cards' },
        { icon: 'fa-brands fa-rocketchat',  route: '/chat' },
        { icon: 'fa-solid fa-circle-user',  route: '/myprofile' },
        { icon: 'fa-solid fa-user-nurse',   route: '/caregiver-cards' },
        { icon: 'fa-solid fa-file-medical',  route: '/my-reports' },

      ];
    }
  }
}
