import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { finalize } from 'rxjs';
import { API_BASE_URL } from '../../../../constants';
import { AuthService } from '../../../../service/auth.service';

interface DailyStatus {
  id: number;
  mood: string;
  pain_level: number;
  sleep_quality: string;
  symptoms: string;
  notes: string;
  medication_taken: boolean;
  created_at: string;
}

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
  selector: 'app-report',
  standalone: true,
  imports: [CommonModule, FormsModule, HttpClientModule, RouterModule],
  templateUrl: './report.component.html',
  styleUrl: './report.component.css',
})
export class ReportComponent implements OnInit {
  patientId: number | null = null;
  currentRole: string = '';

  dailyStatuses: DailyStatus[] = [];
  statusLoading = false;

  existingReports: Report[] = [];
  reportsLoading = false;
  reportAccessMode: 'full' | 'preview' | 'none' = 'full';
  patientPreview: any = null;

  selectedReport!: Report;
  newComment = '';
  commentLoading = false;

  bloodPressure  = '';
  heartRate: number | null = null;
  temperature    = '';
  glucoseLevel   = '';
  detailedReport = '';
  reportTitle    = 'Medical Report';

  activeTab: 'write' | 'history' = 'write';
  isLoading     = false;
  errorMessage  = '';
  successMessage = '';

  get canWriteReport(): boolean {
    return ['doctor', 'admin'].includes(this.currentRole) && this.reportAccessMode === 'full';
  }

  get canComment(): boolean {
    return this.reportAccessMode === 'full' && ['doctor', 'caregiver', 'admin'].includes(this.currentRole);
  }

  private get authHeaders() {
    return {
      Authorization: `Bearer ${this.authService.getToken()}`,
      'Content-Type': 'application/json',
    };
  }

  constructor(
    private http: HttpClient,
    private authService: AuthService,
    private route: ActivatedRoute,
    private router: Router,
  ) {}

  ngOnInit(): void {
    const id = this.route.snapshot.paramMap.get('id');
    this.patientId = id ? Number(id) : null;
    this.currentRole = (this.authService.getUserRole() || this.authService.getUser()?.role || '').toLowerCase();
    this.activeTab = this.canWriteReport ? 'write' : 'history';

    if (this.patientId) {
      this.loadDailyStatuses();
      this.loadExistingReports();
    }
  }

  switchTab(tab: 'write' | 'history'): void {
    if (tab === 'write' && !this.canWriteReport) {
      this.activeTab = 'history';
      this.loadExistingReports();
      return;
    }

    this.activeTab = tab;
    if (tab === 'history') {
      this.loadExistingReports();
    } else {
      this.loadDailyStatuses();
    }
  }

  loadDailyStatuses(): void {
    this.statusLoading = true;
    this.http
      .get<any>(`${API_BASE_URL}/patients/${this.patientId}/daily-status`, { headers: this.authHeaders })
      .pipe(finalize(() => (this.statusLoading = false)))
      .subscribe({
        next: (res) => { this.dailyStatuses = res?.data ?? res ?? []; },
        error: (err) => {
          this.dailyStatuses = [];
          this.errorMessage = err?.status === 403
            ? 'You are not allowed to view this patient check-in history.'
            : (err?.error?.message || 'Failed to load patient check-ins.');
        },
      });
  }

  loadExistingReports(): void {
    this.reportsLoading = true;
    this.http
      .get<any>(`${API_BASE_URL}/patients/${this.patientId}/reports`, { headers: this.authHeaders })
      .pipe(finalize(() => (this.reportsLoading = false)))
      .subscribe({
        next: (res) => {
          const data = res?.data ?? res ?? [];
          if (Array.isArray(data)) {
            this.reportAccessMode = 'full';
            this.existingReports = data;
            this.patientPreview = null;
          } else {
            this.reportAccessMode = data.access_mode || 'full';
            this.existingReports = data.reports || [];
            this.patientPreview = data.preview || null;
          }
          if (this.reportAccessMode === 'preview') {
            this.activeTab = 'history';
          }
        },
        error: (err) => {
          this.existingReports = [];
          this.errorMessage = err?.status === 403
            ? 'You are not allowed to view this patient report history.'
            : (err?.error?.message || 'Failed to load reports.');
        },
      });
  }

  getMoodEmoji(mood: string): string {
    const map: Record<string, string> = {
      great: '🤩', good: '😃', okay: '😊', low: '😔', terrible: '😠',
    };
    return map[mood] ?? '😊';
  }

  getPainColor(level: number): string {
    if (level <= 3) return 'text-green-500 bg-green-50';
    if (level <= 6) return 'text-amber-500 bg-amber-50';
    return 'text-red-500 bg-red-50';
  }

  getRoleIcon(role: string): string {
    return role === 'doctor' ? '👨‍⚕️' : '🧑‍⚕️';
  }

  onSubmit(): void {
    if (!this.canWriteReport) {
      this.errorMessage = 'Caregivers can view patient reports and add comments, but only doctors can write medical reports.';
      this.switchTab('history');
      return;
    }

    if (!this.detailedReport.trim()) {
      this.errorMessage = 'Please enter a detailed report.';
      return;
    }

    this.isLoading    = true;
    this.errorMessage  = '';
    this.successMessage = '';

    const vitalsSummary = [
      this.bloodPressure ? `BP: ${this.bloodPressure}`     : '',
      this.heartRate     ? `HR: ${this.heartRate} BPM`     : '',
      this.temperature   ? `Temp: ${this.temperature}°C`   : '',
      this.glucoseLevel  ? `Glucose: ${this.glucoseLevel}` : '',
    ].filter(Boolean).join(' | ');

    const payload: Record<string, unknown> = {
      title:        this.reportTitle || 'Medical Report',
      summary:      vitalsSummary || 'Daily check-in',
      final_report: this.detailedReport,
    };
    if (this.patientId) payload['patient_id'] = this.patientId;

    this.http
      .post<any>(`${API_BASE_URL}/reports`, payload, { headers: this.authHeaders })
      .pipe(finalize(() => (this.isLoading = false)))
      .subscribe({
        next: (res) => {
          this.successMessage = '✅ Report saved and patient notified!';

          // أضف الـ report الجديد مباشرةً من الـ response
          const newReport = res?.data ?? res;
          if (newReport && newReport.id) {
            this.existingReports = [newReport, ...this.existingReports];
          }

          this.resetForm();
          this.switchTab('history');
          this.loadExistingReports();
        },
        error: (err) => {
          this.errorMessage = err?.error?.message || 'Failed to save report.';
        },
      });
  }

  openReportForComment(report: Report): void {
    this.selectedReport = report;
    this.newComment = '';
  }

  closeComment(): void {
    this.selectedReport = undefined!;
    this.newComment = '';
  }

  submitComment(): void {
    if (!this.newComment.trim() || !this.selectedReport) return;

    this.commentLoading = true;
    this.http
      .post<any>(
        `${API_BASE_URL}/reports/${this.selectedReport.id}/comments`,
        { comment: this.newComment },
        { headers: this.authHeaders }
      )
      .pipe(finalize(() => (this.commentLoading = false)))
      .subscribe({
        next: (res) => {
          const newC = res?.data ?? res;
          if (this.selectedReport) {
            this.selectedReport.comments = [...(this.selectedReport.comments ?? []), newC];
          }
          this.newComment = '';
          this.successMessage = '✅ Comment sent to patient!';
          this.loadExistingReports();
          setTimeout(() => (this.successMessage = ''), 3000);
        },
        error: (err) => {
          this.errorMessage = err?.error?.message || 'Failed to send comment.';
        },
      });
  }

  resetForm(): void {
    this.bloodPressure  = '';
    this.heartRate      = null;
    this.temperature    = '';
    this.glucoseLevel   = '';
    this.detailedReport = '';
    this.reportTitle    = 'Medical Report';
  }

  onCancel(): void {
    this.router.navigate(['/patient-cards']);
  }
}
