import { CommonModule } from '@angular/common';
import { Component } from '@angular/core';
import { RouterModule } from '@angular/router';
import { AuthService } from '../service/auth.service';

@Component({
  selector: 'app-unauthorized',
  standalone: true,
  imports: [CommonModule, RouterModule],
  template: `
    <main class="min-h-screen bg-slate-50 flex items-center justify-center px-4">
      <section class="w-full max-w-md bg-white rounded-2xl border border-slate-100 shadow-sm p-8 text-center">
        <div class="mx-auto w-14 h-14 rounded-full bg-red-50 text-red-600 flex items-center justify-center mb-5">
          <i class="fas fa-lock"></i>
        </div>
        <h1 class="text-2xl font-black text-slate-800">Unauthorized</h1>
        <p class="mt-3 text-sm text-slate-500">Your account does not have access to this page.</p>
        <a [routerLink]="dashboardRoute" class="mt-6 inline-flex items-center justify-center rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white">
          Back to dashboard
        </a>
      </section>
    </main>
  `,
})
export class UnauthorizedComponent {
  dashboardRoute: string;

  constructor(private auth: AuthService) {
    this.dashboardRoute = this.auth.dashboardRouteForRole();
  }
}
