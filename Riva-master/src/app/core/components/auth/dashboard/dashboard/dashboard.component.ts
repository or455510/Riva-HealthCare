import { Component, CUSTOM_ELEMENTS_SCHEMA, OnInit, ViewChild } from "@angular/core";
import { CommonModule } from "@angular/common";
import { HttpClient, HttpClientModule } from "@angular/common/http";
import { RouterModule, Router } from "@angular/router";
import { AuthService } from '../../../../../service/auth.service';
import { API_BASE_URL } from '../../../../../constants';
import { LogoutButtonComponent } from '../../../../../components/logout-button';
import { SidebarComponent } from '../../../../../components/sidebar';

import {
  ChartComponent,
  NgApexchartsModule,
  ApexChart,
  ApexNonAxisChartSeries,
  ApexStroke,
  ApexXAxis
} from 'ng-apexcharts';

@Component({
  selector: 'app-dashboard',
  templateUrl: './dashboard.component.html',
  styleUrl: './dashboard.component.css',
  standalone: true,
  imports: [CommonModule, NgApexchartsModule, HttpClientModule, RouterModule, LogoutButtonComponent, SidebarComponent],
  schemas: [CUSTOM_ELEMENTS_SCHEMA]
})
export class DashboardComponent implements OnInit {

  @ViewChild("chart") chart!: ChartComponent;

  currentDoctor = { name: '', status: 'Online', avatar: '' };
  pendingRequests: any[] = [];
  patients: any[] = [];
  isLoadingRequests = true;
  isLoadingPatients = true;
  stats = { total: 0, highRisk: 0, pdfs: 0, adherence: 0 };
  hasPatientAnalytics = false;

  public pieOptions = {
    series: [0, 0, 0] as ApexNonAxisChartSeries,
    chart: { type: "donut" as const, height: 280 } as ApexChart,
    labels: ["Stable", "Attention", "Critical"],
    colors: ["#0ea5e9", "#f59e0b", "#f43f5e"],
    legend: { position: 'bottom' as const },
    responsive: [{ breakpoint: 480, options: { chart: { width: 200 } } }]
  };

  public lineOptions = {
    series: [{ name: "Patients", data: [0, 0, 0, 0, 0, 0, 0] }],
    chart: { type: "area" as const, height: 280, toolbar: { show: false } } as ApexChart,
    xaxis: { categories: ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"] } as ApexXAxis,
    stroke: { curve: "smooth" as const, width: 3 } as ApexStroke,
    colors: ["#0ea5e9"]
  };

  constructor(
    private http: HttpClient,
    private authService: AuthService,
    public router: Router
  ) {}

  private get authHeaders() {
    return { Authorization: `Bearer ${this.authService.getToken()}` };
  }

  ngOnInit(): void {
    this.loadDoctorProfile();
    this.loadPendingRequests();
    this.loadDoctorPatients();
  }

  openAiAssistant(): void {
    this.router.navigate(['/ai-chat']);
  }

  loadDoctorProfile(): void {
    this.http.get<any>(`${API_BASE_URL}/profile`, {
      headers: this.authHeaders
    }).subscribe(res => {
      const user = res.user || res;
      this.currentDoctor.name = `${user.first_name || ''} ${user.last_name || ''}`.trim() || 'Doctor';
      this.currentDoctor.avatar = user.profile_image_url
        || (user.profile_image ? `http://https://riva-healthcare-tm.gamer.gd/storage/${user.profile_image}` : null)
        || `https://ui-avatars.com/api/?name=${encodeURIComponent(this.currentDoctor.name)}&background=E0F2FE&color=0EA5E9`;
    });
  }

  loadPendingRequests(): void {
    this.isLoadingRequests = true;
    this.http.get<any>(`${API_BASE_URL}/doctor/follow-requests`, { headers: this.authHeaders }).subscribe({
      next: (res) => {
        const list = res.data || res.requests || [];
        // ✅ خد بس الـ pending — الـ API بيرجع كل الـ requests حتى الـ active
        this.pendingRequests = list
          .filter((r: any) => r.status === 'pending')
          .map((r: any) => ({
            id:          r.id,
            name:        `${r.patient?.user?.first_name || ''} ${r.patient?.user?.last_name || ''}`.trim() || 'Patient',
            avatar:      r.patient?.user?.profile_image
              || `https://ui-avatars.com/api/?name=P&background=E0F2FE&color=0EA5E9`,
            condition:   r.patient?.chronic_conditions || r.patient?.medical_history || '—',
            requestedAt: r.created_at
              ? new Date(r.created_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })
              : '',
            isProcessing: false
          }));
        this.isLoadingRequests = false;
      },
      error: () => { this.isLoadingRequests = false; }
    });
  }

loadDoctorPatients(): void {
  this.isLoadingPatients = true;
  this.http.get<any>(`${API_BASE_URL}/dashboard/doctor`, { headers: this.authHeaders }).subscribe({
    next: (res) => {
      const list = res.data?.patients || [];
      this.patients = list.filter((r: any) => r.status === 'active').map((r: any) => {
        const patient = r.patient || {};
        const user    = patient.user || {};
        const name    = `${user.first_name || ''} ${user.last_name || ''}`.trim() || 'Patient';
        return {
          id:        patient.id,
          userId:    user.id,
          name,
          img: user.profile_image_url
            || (user.profile_image ? `http://https://riva-healthcare-tm.gamer.gd/storage/${user.profile_image}` : null)
            || `https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=E0F2FE&color=0EA5E9`,
          condition: patient.chronic_conditions || patient.medical_history || 'N/A',
          status:    r.status || 'Stable',
          room:      r.room || '—'
        };
      });
      const data = res.data || {};
      const distribution = data.patient_status_distribution || {};
      const pieSeries: number[] = [
        Number(distribution.stable ?? data.stable_patients ?? 0),
        Number(distribution.attention ?? data.attention_patients ?? 0),
        Number(distribution.critical ?? data.critical_patients ?? data.high_risk_patients ?? 0),
      ];
      const weekly: number[] = Array.isArray(data.weekly_patients) ? data.weekly_patients.map((value: any) => Number(value) || 0) : [0, 0, 0, 0, 0, 0, 0];

      this.stats.total = Number(data.total_patients ?? data.total_assigned_patients ?? this.patients.length) || 0;
      this.stats.highRisk = Number(data.critical_patients ?? data.high_risk_patients ?? 0) || 0;
      this.stats.pdfs = Number(data.reports_count ?? 0) || 0;
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
    this.http.post(`${API_BASE_URL}/doctor/follow-requests/${req.id}/accept`, {}, { headers: this.authHeaders }).subscribe({
      next: () => {
        // ✅ reload من الـ API بدل الحذف اليدوي
        this.loadPendingRequests();
        this.loadDoctorPatients();
      },
      error: (err) => { console.error(err); req.isProcessing = false; }
    });
  }

  rejectRequest(req: any): void {
    req.isProcessing = true;
    this.http.post(`${API_BASE_URL}/doctor/follow-requests/${req.id}/reject`, {}, { headers: this.authHeaders }).subscribe({
      next: () => {
        // ✅ reload من الـ API بدل الحذف اليدوي
        this.loadPendingRequests();
      },
      error: (err) => { console.error(err); req.isProcessing = false; }
    });
  }
}
