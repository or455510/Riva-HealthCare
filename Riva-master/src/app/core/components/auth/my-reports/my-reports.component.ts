import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
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

interface Report {
  id: number;
  title: string;
  summary: string;
  final_report: string;
  doctor: { id: number; name: string };
  patient: { id: number; name: string };
  comments: ReportComment[];
  unread_comments: number;
  created_at: string;
}

@Component({
  selector: 'app-my-reports',
  standalone: true,
  imports: [CommonModule, HttpClientModule, RouterModule, SidebarComponent],
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
