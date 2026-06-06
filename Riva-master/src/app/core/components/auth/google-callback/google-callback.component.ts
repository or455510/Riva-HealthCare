import { CommonModule } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { AuthService } from '../../../../service/auth.service';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { API_BASE_URL } from '../../../../constants';

@Component({
  selector: 'app-google-callback',
  standalone: true,
  imports: [CommonModule, RouterModule, HttpClientModule],
  template: `
    <main class="min-h-screen bg-slate-50 flex items-center justify-center p-6">

      <!-- Loading -->
      <section *ngIf="step === 'loading'"
        class="bg-white border border-slate-100 shadow-sm rounded-3xl p-8 max-w-md w-full text-center">
        <div class="w-12 h-12 mx-auto rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center mb-4">
          <i class="fa-brands fa-google"></i>
        </div>
        <h1 class="text-xl font-black text-slate-800">Signing you in</h1>
        <p class="text-sm text-slate-500 mt-2">{{ message }}</p>
      </section>

      <!-- Role Picker -->
      <section *ngIf="step === 'pick-role'"
        class="bg-white border border-slate-100 shadow-sm rounded-3xl p-8 max-w-md w-full">
        <div class="w-12 h-12 mx-auto rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center mb-5">
          <i class="fa-brands fa-google"></i>
        </div>
        <h1 class="text-xl font-black text-slate-800 text-center mb-1">Welcome!</h1>
        <p class="text-sm text-slate-500 text-center mb-7">Who are you signing in as?</p>

        <div class="flex flex-col gap-3">

          <!-- Patient -->
          <button (click)="selectRole('patient')"
            class="flex items-center gap-4 p-4 rounded-2xl border-2 transition-all cursor-pointer text-left"
            [ngClass]="selectedRole === 'patient'
              ? 'border-blue-500 bg-blue-50'
              : 'border-slate-100 hover:border-blue-200 hover:bg-slate-50'">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0"
              [ngClass]="selectedRole === 'patient' ? 'bg-blue-100' : 'bg-slate-100'">
              <i class="fa-solid fa-user-injured text-lg"
                [ngClass]="selectedRole === 'patient' ? 'text-blue-600' : 'text-slate-400'"></i>
            </div>
            <div class="flex-1">
              <p class="font-bold text-slate-800 text-sm">Patient</p>
              <p class="text-xs text-slate-400 mt-0.5">Track health & connect with doctors</p>
            </div>
            <i *ngIf="selectedRole === 'patient'" class="fa-solid fa-circle-check text-blue-500 text-lg"></i>
          </button>

          <!-- Doctor -->
          <button (click)="selectRole('doctor')"
            class="flex items-center gap-4 p-4 rounded-2xl border-2 transition-all cursor-pointer text-left"
            [ngClass]="selectedRole === 'doctor'
              ? 'border-blue-500 bg-blue-50'
              : 'border-slate-100 hover:border-blue-200 hover:bg-slate-50'">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0"
              [ngClass]="selectedRole === 'doctor' ? 'bg-blue-100' : 'bg-slate-100'">
              <i class="fa-solid fa-user-doctor text-lg"
                [ngClass]="selectedRole === 'doctor' ? 'text-blue-600' : 'text-slate-400'"></i>
            </div>
            <div class="flex-1">
              <p class="font-bold text-slate-800 text-sm">Doctor</p>
              <p class="text-xs text-slate-400 mt-0.5">Manage patients & consultations</p>
            </div>
            <i *ngIf="selectedRole === 'doctor'" class="fa-solid fa-circle-check text-blue-500 text-lg"></i>
          </button>

          <!-- Caregiver -->
          <button (click)="selectRole('caregiver')"
            class="flex items-center gap-4 p-4 rounded-2xl border-2 transition-all cursor-pointer text-left"
            [ngClass]="selectedRole === 'caregiver'
              ? 'border-purple-500 bg-purple-50'
              : 'border-slate-100 hover:border-purple-200 hover:bg-slate-50'">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0"
              [ngClass]="selectedRole === 'caregiver' ? 'bg-purple-100' : 'bg-slate-100'">
              <i class="fa-solid fa-user-nurse"
                [ngClass]="selectedRole === 'caregiver' ? 'text-purple-600' : 'text-slate-400'"></i>
            </div>
            <div class="flex-1">
              <p class="font-bold text-slate-800 text-sm">Caregiver</p>
              <p class="text-xs text-slate-400 mt-0.5">Support & monitor your patients</p>
            </div>
            <i *ngIf="selectedRole === 'caregiver'" class="fa-solid fa-circle-check text-purple-500 text-lg"></i>
          </button>

        </div>

        <p *ngIf="roleError" class="text-red-500 text-xs text-center mt-4">{{ roleError }}</p>

        <button (click)="confirmRole()"
          [disabled]="!selectedRole || isConfirming"
          class="mt-6 w-full py-3 rounded-2xl font-bold text-sm transition-all disabled:opacity-50 disabled:cursor-not-allowed"
          [ngClass]="selectedRole === 'caregiver'
            ? 'bg-purple-600 hover:bg-purple-700 text-white shadow-lg shadow-purple-100'
            : 'bg-blue-600 hover:bg-blue-700 text-white shadow-lg shadow-blue-100'">
          <i *ngIf="isConfirming" class="fa-solid fa-spinner fa-spin mr-2"></i>
          {{ isConfirming ? 'Setting up your account...' : 'Continue' }}
        </button>

      </section>

    </main>
  `
})
export class GoogleCallbackComponent implements OnInit {
  step: 'loading' | 'pick-role' = 'loading';
  message = 'Please wait while we finish Google authentication.';

  selectedRole: 'patient' | 'doctor' | 'caregiver' | null = null;
  isConfirming = false;
  roleError = '';

  private token = '';
  private user: any = null;

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private authService: AuthService,
    private http: HttpClient,
  ) {}

  ngOnInit(): void {
    this.token = this.route.snapshot.queryParamMap.get('token') || '';

    if (!this.token) {
      this.message = 'Google sign-in could not be completed. Please try again.';
      setTimeout(() => this.router.navigate(['/signin']), 1200);
      return;
    }

    localStorage.setItem('token', this.token);

   this.authService.me().subscribe({
  next: (res: any) => {
    this.user = res?.user || res;
    localStorage.setItem('user', JSON.stringify(this.user));

    if (this.user?.profile_completed && this.user?.role) {
      this.router.navigate([this.authService.dashboardRouteForRole(this.user.role)]);
      return;
    }

    this.step = 'pick-role';
  },
  error: () => {
    localStorage.removeItem('token');
    this.message = 'Google sign-in failed. Please try again.';
    setTimeout(() => this.router.navigate(['/signin']), 1200);
  }
});
  }

  selectRole(role: 'patient' | 'doctor' | 'caregiver'): void {
    this.selectedRole = role;
    this.roleError = '';
  }

  confirmRole(): void {
    if (!this.selectedRole) return;
    this.isConfirming = true;
    this.roleError = '';

    const headers = { Authorization: `Bearer ${this.token}` };

    this.http.post<any>(
      `${API_BASE_URL}/auth/set-role`,
      { role: this.selectedRole },
      { headers }
    ).subscribe({
next: (res: any) => {
  // ✅ السيرفر دلوقتي بيرجع token جديد فيه الـ role الصح
  if (res?.token) {
    localStorage.setItem('token', res.token);
  }

  const updatedUser = {
    ...(res?.user || this.user),
    profile_completed: false,
  };
  localStorage.setItem('user', JSON.stringify(updatedUser));

  this.router.navigate([
    this.authService.completionRouteForRole(this.selectedRole!)
  ]);
},
      error: (err: any) => {
        console.error('[GoogleCallback] set-role failed:', err);
        // ✅ Fallback
        const fallbackUser = {
          ...this.user,
          role: this.selectedRole,
          profile_completed: false,
        };
        localStorage.setItem('user', JSON.stringify(fallbackUser));
        this.router.navigate([this.authService.completionRouteForRole(this.selectedRole!)]);
      }
    });
  }
}