import { Component, CUSTOM_ELEMENTS_SCHEMA, OnInit, ViewChild } from "@angular/core";
import { CommonModule } from "@angular/common";
import { HttpClient, HttpClientModule } from "@angular/common/http";
import { Router, RouterModule } from "@angular/router";
import { AuthService } from '../../../../service/auth.service';
import { API_BASE_URL } from '../../../../constants';
import { LogoutButtonComponent } from '../../../../components/logout-button';
import { SidebarComponent } from '../../../../components/sidebar';
import {
  ApexChart, ApexNonAxisChartSeries, ApexStroke, ApexXAxis,
  ChartComponent, NgApexchartsModule
} from 'ng-apexcharts';

@Component({
  selector: 'app-dashboard-caregiver',
  templateUrl: './dashboard-caregiver.component.html',
  styleUrl: './dashboard-caregiver.component.css',
  standalone: true,
  imports: [CommonModule, NgApexchartsModule, HttpClientModule, RouterModule, LogoutButtonComponent, SidebarComponent],
  schemas: [CUSTOM_ELEMENTS_SCHEMA]
})
export class DashboardCaregiverComponent implements OnInit {

  @ViewChild("chart") chart!: ChartComponent;

  currentCaregiver = { name: '', status: 'Online', avatar: '' };
  pendingRequests: any[] = [];
  isLoadingRequests = true;
  patients: any[] = [];
  isLoadingPatients = true;
  stats = { total: 0, highRisk: 0, pdfs: 0, adherence: 0 };
  hasPatientAnalytics = false;

  public pieOptions = {
    series: [0, 0, 0] as ApexNonAxisChartSeries,
    chart: { type: "donut" as const, height: 280 } as ApexChart,
    labels: ["Stable", "Attention", "Critical"],
    colors: ["#2D9CDB", "#F2994A", "#EB5757"],
    legend: { position: 'bottom' as const },
    responsive: [{ breakpoint: 480, options: { chart: { width: 200 } } }]
  };

  public lineOptions = {
    series: [{ name: "Patients", data: [0, 0, 0, 0, 0, 0, 0] }],
    chart: { type: "area" as const, height: 280, toolbar: { show: false } } as ApexChart,
    xaxis: { categories: ["Mon","Tue","Wed","Thu","Fri","Sat","Sun"] } as ApexXAxis,
    stroke: { curve: "smooth" as const, width: 3 } as ApexStroke,
    colors: ["#7C3AED"]
  };

  private get authHeaders() {
    return { Authorization: `Bearer ${this.authService.getToken()}` };
  }

  constructor(
    private http: HttpClient,
    private authService: AuthService,
    private router: Router
  ) {}

  ngOnInit(): void {
    this.loadCaregiverProfile();
    this.loadPendingRequests();
    this.loadAcceptedPatients();
  }

  openAiAssistant(): void {
    this.router.navigate(['/ai-chat']);
  }

  loadCaregiverProfile(): void {
    this.http.get<any>(`${API_BASE_URL}/profile`, { headers: this.authHeaders }).subscribe({
      next: (res) => {
        const user = res.user || res;
        this.currentCaregiver.name   = `${user.first_name || ''} ${user.last_name || ''}`.trim() || 'Caregiver';
        this.currentCaregiver.avatar = user.profile_image_url
          || (user.profile_image ? `http://https://riva-healthcare-tm.gamer.gd/storage/${user.profile_image}` : null)
          || `https://ui-avatars.com/api/?name=${encodeURIComponent(this.currentCaregiver.name)}&background=F5F0FF&color=7C3AED`;
      },
      error: () => {}
    });
  }

  loadPendingRequests(): void {
    this.isLoadingRequests = true;
    this.http.get<any>(`${API_BASE_URL}/caregiver/follow-requests`, { headers: this.authHeaders }).subscribe({
      next: (res) => {
        const list = res.data || res.requests || (Array.isArray(res) ? res : []);
        // ✅ خد بس الـ pending — الـ API بيرجع كل الـ requests حتى الـ active
        this.pendingRequests = list
          .filter((r: any) => r.status === 'pending')
          .map((r: any) => ({
          id:           r.id,
          patientId:    r.patient_id || r.user_id || r.id,
          name:         `${r.patient?.user?.first_name || r.patient?.first_name || ''} ${r.patient?.user?.last_name || r.patient?.last_name || ''}`.trim() || 'Patient',
          avatar:       r.patient?.user?.profile_image || r.patient?.profile_image
            || `https://ui-avatars.com/api/?name=P&background=F5F0FF&color=7C3AED`,
          condition:    r.patient?.chronic_conditions || r.patient?.medical_history || '—',
          requestedAt:  r.created_at ? new Date(r.created_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) : '',
          isProcessing: false,
        }));
        this.isLoadingRequests = false;
      },
      error: () => { this.isLoadingRequests = false; }
    });
  }

loadAcceptedPatients(): void {
  this.isLoadingPatients = true;
  this.http.get<any>(`${API_BASE_URL}/dashboard/caregiver`, { headers: this.authHeaders }).subscribe({
    next: (res) => {
      const list = res.data?.assigned_patients || [];
      this.patients = list.map((r: any) => {
        const patient = r.patient || {};
        const user    = patient.user || {};
        const name    = `${user.first_name || ''} ${user.last_name || ''}`.trim() || 'Patient';
        return {
          id:        r.patient_id || patient.id,
          name,
          img: user.profile_image_url
            || (user.profile_image ? `http://https://riva-healthcare-tm.gamer.gd/storage/${user.profile_image}` : null)
            || `https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=F5F0FF&color=7C3AED`,
          condition: patient.chronic_conditions || patient.medical_history || 'N/A',
          status:    r.status === 'active' ? 'Stable' : (r.status || 'Stable'),
          room:      r.room || '—',
        };
      });
      const data = res.data || {};
      const distribution = data.patient_status_distribution || {};
      const pieSeries: number[] = [
        Number(distribution.stable ?? data.stable_patients ?? 0),
        Number(distribution.attention ?? data.attention_patients ?? 0),
        Number(distribution.critical ?? data.critical_patients ?? 0),
      ];
      const weekly: number[] = Array.isArray(data.weekly_patients) ? data.weekly_patients.map((value: any) => Number(value) || 0) : [0, 0, 0, 0, 0, 0, 0];

      this.stats.total = Number(data.assigned_patients_count ?? data.total_patients ?? this.patients.length) || 0;
      this.stats.highRisk = Number(data.critical_patients ?? 0) || 0;
      this.stats.pdfs = Number(data.weekly_reports ?? 0) || 0;
      this.stats.adherence = Number(data.average_adherence ?? 0) || 0;
      this.pieOptions = { ...this.pieOptions, series: pieSeries as ApexNonAxisChartSeries };
      this.lineOptions = { ...this.lineOptions, series: [{ name: 'Patients', data: weekly }] };
      this.hasPatientAnalytics = this.stats.total > 0 && (pieSeries.some(value => value > 0) || weekly.some(value => value > 0));
      this.isLoadingPatients = false;
    },
    error: () => { this.isLoadingPatients = false; }
  });
}

  acceptRequest(req: any): void {
    req.isProcessing = true;
    this.http.post<any>(
      `${API_BASE_URL}/caregiver/follow-requests/${req.id}/accept`, {},
      { headers: { ...this.authHeaders, 'Content-Type': 'application/json' } }
    ).subscribe({
      next: () => {
        // ✅ إعادة تحميل الـ lists من الـ API بدل الحذف اليدوي
        this.loadPendingRequests();
        this.loadAcceptedPatients();
      },
      error: (err) => { req.isProcessing = false; console.error(err); }
    });
  }

  rejectRequest(req: any): void {
    req.isProcessing = true;
    this.http.post<any>(
      `${API_BASE_URL}/caregiver/follow-requests/${req.id}/reject`, {},
      { headers: { ...this.authHeaders, 'Content-Type': 'application/json' } }
    ).subscribe({
      next: () => {
        // ✅ إعادة تحميل من الـ API بدل الحذف اليدوي
        this.loadPendingRequests();
      },
      error: (err) => { req.isProcessing = false; console.error(err); }
    });
  }
}
