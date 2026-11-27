// import { Component, OnInit } from '@angular/core';
// import { CommonModule } from '@angular/common';
// import { FormsModule } from '@angular/forms';
// import { HttpClient, HttpClientModule } from '@angular/common/http';
// import { finalize } from 'rxjs/operators';
// import { AuthService } from '../../../service/auth.service';
// import { API_BASE_URL } from '../../../constants';

// interface ActivityLog {
//   id: number;
//   timestamp: string;
//   user: string;
//   user_id: number;
//   role: string;
//   action: string;
//   description: string;
// }

// interface DashboardStats {
//   total_users: number;
//   total_patients: number;
//   total_doctors: number;
//   total_caregivers: number;
//   pending_doctor_verifications: number;
//   active_alerts: number;
//   high_risk_cases: number;
// }

// @Component({
//   selector: 'app-dashboard-admin',
//   standalone: true,
//   imports: [CommonModule, FormsModule, HttpClientModule],
//   templateUrl: './dashboard-admin.component.html',
//   styleUrls: ['./dashboard-admin.component.css']
// })
// export class DashboardAdminComponent implements OnInit {

//   stats: DashboardStats = {
//     total_users: 0, total_patients: 0, total_doctors: 0,
//     total_caregivers: 0, pending_doctor_verifications: 0,
//     active_alerts: 0, high_risk_cases: 0,
//   };
//   statsLoading = false;

//   allLogs: ActivityLog[] = [];
//   logs:    ActivityLog[] = [];
//   logsLoading = false;

//   recentRegistrations: any[] = [];
//   suspiciousLogins:    any[] = [];

//   selectedAction = '';
//   selectedDate   = '';
//   actionOptions  = ['All Actions'];

//   dateOptions = [
//     { label: 'Date Filter', value: '' },
//     { label: 'Today',       value: 'today' },
//     { label: 'Last 7 days', value: '7days' },
//     { label: 'Last 30 days',value: '30days' },
//   ];

//   currentPage = 1;
//   pageSize    = 10;

//   get totalPages(): number {
//     return Math.max(1, Math.ceil(this.filteredLogs.length / this.pageSize));
//   }

//   get pageNumbers(): number[] {
//     const pages: number[] = [];
//     const start = Math.max(1, this.currentPage - 1);
//     const end   = Math.min(this.totalPages, start + 2);
//     for (let i = start; i <= end; i++) pages.push(i);
//     return pages;
//   }

//   today = new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

//   private get authHeaders() {
//     return { Authorization: `Bearer ${this.authService.getToken()}`, 'Content-Type': 'application/json' };
//   }

//   constructor(private http: HttpClient, private authService: AuthService) {}

//   ngOnInit(): void { this.loadDashboard(); }

//   loadDashboard(): void {
//     this.statsLoading = true;
//     this.logsLoading  = true;

//     this.http.get<any>(`${API_BASE_URL}/admin/dashboard`, { headers: this.authHeaders })
//       .pipe(finalize(() => { this.statsLoading = false; this.logsLoading = false; }))
//       .subscribe({
//         next: (res) => {
//           const data = res?.data ?? res;

//           // ── Stats — exact field names from API ──
//           this.stats = {
//             total_users:                  data.total_users                  ?? 0,
//             total_patients:               data.total_patients               ?? 0,
//             total_doctors:                data.total_doctors                ?? 0,
//             total_caregivers:             data.total_caregivers             ?? 0,
//             pending_doctor_verifications: data.pending_doctor_verifications ?? 0,
//             active_alerts:                data.active_alerts                ?? 0,
//             high_risk_cases:              data.high_risk_cases              ?? 0,
//           };

//           this.recentRegistrations = data.recent_registrations      ?? [];
//           this.suspiciousLogins    = data.suspicious_login_attempts  ?? [];

//           // ── Logs — from system_activity (no separate endpoint) ──
//           const activity: any[] = data.system_activity ?? [];
//           this.allLogs = activity.map((log: any) => ({
//             id:          log.id,
//             timestamp:   log.created_at ?? '—',
//             user_id:     log.user_id,
//             user:        this.resolveUserName(log.user_id),
//             role:        this.resolveUserRole(log.user_id),
//             action:      log.action      ?? '—',
//             description: log.description ?? '—',
//           }));

//           // build dynamic action filter
//           const unique = [...new Set(this.allLogs.map(l => l.action))];
//           this.actionOptions = ['All Actions', ...unique];

//           this.applyFilters();
//         },
//         error: () => { this.allLogs = []; this.logs = []; }
//       });
//   }

//   private resolveUserName(userId: number): string {
//     const u = this.recentRegistrations.find((x: any) => x.id === userId);
//     return u ? (u.name || `${u.first_name} ${u.last_name}`.trim()) : `User #${userId}`;
//   }

//   private resolveUserRole(userId: number): string {
//     return this.recentRegistrations.find((x: any) => x.id === userId)?.role ?? 'unknown';
//   }

//   get filteredLogs(): ActivityLog[] {
//     let result = [...this.allLogs];

//     if (this.selectedAction && this.selectedAction !== 'All Actions') {
//       result = result.filter(l => l.action === this.selectedAction);
//     }

//     if (this.selectedDate) {
//       const now  = new Date();
//       result = result.filter(l => {
//         const d    = new Date(l.timestamp);
//         const diff = (now.getTime() - d.getTime()) / 86400000;
//         if (this.selectedDate === 'today')  return d.toDateString() === now.toDateString();
//         if (this.selectedDate === '7days')  return diff <= 7;
//         if (this.selectedDate === '30days') return diff <= 30;
//         return true;
//       });
//     }

//     return result;
//   }

//   applyFilters(): void {
//     this.currentPage = 1;
//     const start      = 0;
//     this.logs        = this.filteredLogs.slice(start, this.pageSize);
//   }

//   goToPage(page: number): void {
//     if (page < 1 || page > this.totalPages) return;
//     this.currentPage = page;
//     const start      = (page - 1) * this.pageSize;
//     this.logs        = this.filteredLogs.slice(start, start + this.pageSize);
//   }

//   onActionFilterChange(value: string): void { this.selectedAction = value; this.applyFilters(); }
//   onDateFilterChange(value: string):   void { this.selectedDate   = value; this.applyFilters(); }
//   reload(): void { this.loadDashboard(); }

//   verifyDoctor(doctorId: number): void {
//     this.http.post(`${API_BASE_URL}/verify-doctor/${doctorId}`, {}, { headers: this.authHeaders })
//       .subscribe({ next: () => this.loadDashboard(), error: () => {} });
//   }
// }
import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { finalize } from 'rxjs/operators';
import { AuthService } from '../../../service/auth.service';
import { API_BASE_URL } from '../../../constants';
import { LogoutButtonComponent } from '../../../components/logout-button';
import { SidebarComponent } from '../../../components/sidebar';

interface ActivityLog {
  id: number;
  timestamp: string;
  user: string;
  user_id: number;
  role: string;
  action: string;
  description: string;
}

interface DashboardStats {
  total_users: number;
  total_patients: number;
  total_doctors: number;
  total_caregivers: number;
  pending_doctor_verifications: number;
  active_alerts: number;
  high_risk_cases: number;
}

@Component({
  selector: 'app-dashboard-admin',
  standalone: true,
  imports: [CommonModule, FormsModule, HttpClientModule, LogoutButtonComponent, SidebarComponent],
  templateUrl: './dashboard-admin.component.html',
  styleUrls: ['./dashboard-admin.component.css']
})
export class DashboardAdminComponent implements OnInit {

  stats: DashboardStats = {
    total_users: 0, total_patients: 0, total_doctors: 0,
    total_caregivers: 0, pending_doctor_verifications: 0,
    active_alerts: 0, high_risk_cases: 0,
  };
  statsLoading = false;

  allLogs: ActivityLog[] = [];
  logs:    ActivityLog[] = [];
  logsLoading = false;

  recentRegistrations: any[] = [];
  suspiciousLogins:    any[] = [];

  selectedAction = '';
  selectedDate   = '';
  actionOptions  = ['All Actions'];

  dateOptions = [
    { label: 'Date Filter', value: '' },
    { label: 'Today',       value: 'today' },
    { label: 'Last 7 days', value: '7days' },
    { label: 'Last 30 days',value: '30days' },
  ];

  currentPage = 1;
  pageSize    = 10;

  get totalPages(): number {
    return Math.max(1, Math.ceil(this.filteredLogs.length / this.pageSize));
  }

  get pageNumbers(): number[] {
    const pages: number[] = [];
    const start = Math.max(1, this.currentPage - 1);
    const end   = Math.min(this.totalPages, start + 2);
    for (let i = start; i <= end; i++) pages.push(i);
    return pages;
  }

  today = new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

  private get authHeaders() {
    return { Authorization: `Bearer ${this.authService.getToken()}`, 'Content-Type': 'application/json' };
  }

  constructor(private http: HttpClient, private authService: AuthService) {}

  ngOnInit(): void { this.loadDashboard(); }

  loadDashboard(): void {
    this.statsLoading = true;
    this.logsLoading  = true;

    this.http.get<any>(`${API_BASE_URL}/admin/dashboard`, { headers: this.authHeaders })
      .pipe(finalize(() => { this.statsLoading = false; this.logsLoading = false; }))
      .subscribe({
        next: (res) => {
          const data = res?.data ?? res;

          // ── Stats — exact field names from API ──
          this.stats = {
            total_users:                  data.total_users                  ?? 0,
            total_patients:               data.total_patients               ?? 0,
            total_doctors:                data.total_doctors                ?? 0,
            total_caregivers:             data.total_caregivers             ?? 0,
            pending_doctor_verifications: data.pending_doctor_verifications ?? 0,
            active_alerts:                data.active_alerts                ?? 0,
            high_risk_cases:              data.high_risk_cases              ?? 0,
          };

          this.recentRegistrations = data.recent_registrations      ?? [];
          this.suspiciousLogins    = data.suspicious_login_attempts  ?? [];

          // ── Logs — from system_activity (no separate endpoint) ──
          const activity: any[] = data.system_activity ?? [];
          this.allLogs = activity.map((log: any) => ({
            id:          log.id,
            timestamp:   log.created_at ?? '—',
            user_id:     log.user_id,
            user:        this.resolveUserName(log.user_id),
            role:        this.resolveUserRole(log.user_id),
            action:      log.action      ?? '—',
            description: log.description ?? '—',
          }));

          // build dynamic action filter
          const unique = [...new Set(this.allLogs.map(l => l.action))];
          this.actionOptions = ['All Actions', ...unique];

          this.applyFilters();
        },
        error: () => { this.allLogs = []; this.logs = []; }
      });
  }

  private resolveUserName(userId: number): string {
    const u = this.recentRegistrations.find((x: any) => x.id === userId);
    return u ? (u.name || `${u.first_name} ${u.last_name}`.trim()) : `User #${userId}`;
  }

  private resolveUserRole(userId: number): string {
    return this.recentRegistrations.find((x: any) => x.id === userId)?.role ?? 'unknown';
  }

  get filteredLogs(): ActivityLog[] {
    let result = [...this.allLogs];

    if (this.selectedAction && this.selectedAction !== 'All Actions') {
      result = result.filter(l => l.action === this.selectedAction);
    }

    if (this.selectedDate) {
      const now  = new Date();
      result = result.filter(l => {
        const d    = new Date(l.timestamp);
        const diff = (now.getTime() - d.getTime()) / 86400000;
        if (this.selectedDate === 'today')  return d.toDateString() === now.toDateString();
        if (this.selectedDate === '7days')  return diff <= 7;
        if (this.selectedDate === '30days') return diff <= 30;
        return true;
      });
    }

    return result;
  }

  applyFilters(): void {
    this.currentPage = 1;
    const start      = 0;
    this.logs        = this.filteredLogs.slice(start, this.pageSize);
  }

  goToPage(page: number): void {
    if (page < 1 || page > this.totalPages) return;
    this.currentPage = page;
    const start      = (page - 1) * this.pageSize;
    this.logs        = this.filteredLogs.slice(start, start + this.pageSize);
  }

  onActionFilterChange(value: string): void { this.selectedAction = value; this.applyFilters(); }
  onDateFilterChange(value: string):   void { this.selectedDate   = value; this.applyFilters(); }
  reload(): void { this.loadDashboard(); }

  verifyDoctor(doctorId: number): void {
    this.http.patch(`${API_BASE_URL}/admin/doctors/${doctorId}/verify`, {}, { headers: this.authHeaders })
      .subscribe({ next: () => this.loadDashboard(), error: () => {} });
  }
}
