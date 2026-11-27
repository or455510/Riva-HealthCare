import { CommonModule } from '@angular/common';
import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { AuthService } from '../../service/auth.service';
import { AdminSidebarComponent } from './admin-sidebar.component';

@Component({
  selector: 'app-admin-layout',
  standalone: true,
  imports: [CommonModule, RouterOutlet, AdminSidebarComponent],
  template: `
    <div class="admin-shell">
      <app-admin-sidebar></app-admin-sidebar>

      <main class="admin-main">
        <header class="admin-topbar">
          <div class="title-block">
            <span class="eyebrow">Riva Command Center</span>
            <h1>Healthcare Operations</h1>
          </div>

          <div class="topbar-tools">
            <label class="global-search">
              <i class="fa-solid fa-magnifying-glass"></i>
              <input type="search" placeholder="Search users, alerts, reports..." />
            </label>

            <button class="icon-button" aria-label="Admin notifications">
              <i class="fa-regular fa-bell"></i>
              <span></span>
            </button>

            <div class="admin-profile">
              <div class="avatar">{{ initials }}</div>
              <div>
                <strong>{{ adminName }}</strong>
                <small>Platform Administrator</small>
              </div>
            </div>
          </div>
        </header>

        <section class="admin-content">
          <router-outlet></router-outlet>
        </section>
      </main>
    </div>
  `,
  styles: [`
    :host {
      display: block;
      min-height: 100vh;
      background: #F6F9FC;
      color: #0f172a;
    }

    .admin-shell {
      min-height: 100vh;
      background:
        radial-gradient(circle at 86% 0%, rgba(6, 182, 212, .12), transparent 30%),
        linear-gradient(180deg, #F6F9FC 0%, #F8FAFC 100%);
    }

    .admin-main {
      min-height: 100vh;
      margin-left: 84px;
      padding: 18px 24px 32px;
    }

    .admin-topbar {
      position: sticky;
      top: 0;
      z-index: 30;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 22px;
      min-height: 70px;
      padding: 8px 0 16px;
      background: linear-gradient(180deg, rgba(246,249,252,.96), rgba(246,249,252,.82));
      backdrop-filter: blur(18px);
    }

    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: #2563EB;
      font-size: 11px;
      font-weight: 900;
      letter-spacing: .08em;
      text-transform: uppercase;
    }

    .eyebrow::before {
      content: "";
      width: 8px;
      height: 8px;
      border-radius: 999px;
      background: #22c55e;
      box-shadow: 0 0 0 6px rgba(34, 197, 94, .12);
    }

    h1 {
      margin: 4px 0 0;
      font-size: clamp(28px, 2.4vw, 34px);
      font-weight: 850;
      letter-spacing: 0;
      color: #0f172a;
    }

    .topbar-tools {
      display: flex;
      align-items: center;
      gap: 12px;
      min-width: 0;
    }

    .global-search {
      display: flex;
      align-items: center;
      gap: 10px;
      width: min(420px, 32vw);
      min-height: 44px;
      padding: 0 14px;
      border: 1px solid #E5E7EB;
      border-radius: 15px;
      background: #ffffff;
      color: #64748b;
      box-shadow: 0 12px 30px rgba(15, 23, 42, .05);
    }

    .global-search input {
      width: 100%;
      border: 0;
      outline: 0;
      color: #0f172a;
      font-weight: 700;
      background: transparent;
    }

    .icon-button {
      position: relative;
      display: grid;
      place-items: center;
      width: 44px;
      height: 44px;
      border: 1px solid #E5E7EB;
      border-radius: 15px;
      background: #ffffff;
      color: #0f172a;
      box-shadow: 0 12px 30px rgba(15, 23, 42, .05);
      transition: transform .2s ease, box-shadow .2s ease;
      cursor: pointer;
    }

    .icon-button:hover {
      transform: scale(1.04);
      box-shadow: 0 18px 38px rgba(37, 99, 235, .12);
    }

    .icon-button span {
      position: absolute;
      top: 12px;
      right: 13px;
      width: 8px;
      height: 8px;
      border-radius: 999px;
      background: #ef4444;
      box-shadow: 0 0 0 4px rgba(239, 68, 68, .12);
    }

    .admin-profile {
      display: flex;
      align-items: center;
      gap: 12px;
      min-height: 48px;
      padding: 8px 12px 8px 8px;
      border: 1px solid #E5E7EB;
      border-radius: 16px;
      background: #ffffff;
      box-shadow: 0 14px 34px rgba(15, 23, 42, .06);
    }

    .avatar {
      display: grid;
      place-items: center;
      width: 36px;
      height: 36px;
      border-radius: 13px;
      background: linear-gradient(135deg, #2563EB, #06B6D4);
      color: #ffffff;
      font-weight: 850;
    }

    .admin-profile strong,
    .admin-profile small {
      display: block;
      white-space: nowrap;
    }

    .admin-profile strong {
      font-size: 13px;
      color: #0f172a;
    }

    .admin-profile small {
      color: #64748b;
      font-size: 11px;
      font-weight: 800;
    }

    .admin-content {
      animation: pageFade 160ms ease both;
    }

    @keyframes pageFade {
      from { opacity: 0; transform: translateY(6px); }
      to { opacity: 1; transform: translateY(0); }
    }

    @media (max-width: 1100px) { .global-search { width: 260px; } }

    @media (max-width: 760px) {
      .admin-main {
        margin-left: 0;
        padding: 14px 14px 92px;
      }

      .admin-topbar {
        position: relative;
        align-items: stretch;
        flex-direction: column;
      }

      .topbar-tools {
        flex-wrap: wrap;
      }

      .global-search {
        width: 100%;
      }

      .admin-profile {
        flex: 1;
      }
    }
  `]
})
export class AdminLayoutComponent {
  constructor(private auth: AuthService) {}

  get adminName(): string {
    const user = this.auth.getUser();
    return user?.name || `${user?.first_name || ''} ${user?.last_name || ''}`.trim() || 'Admin';
  }

  get initials(): string {
    return this.adminName.split(' ').map(part => part[0]).join('').slice(0, 2).toUpperCase();
  }
}
