import { CommonModule } from '@angular/common';
import { Component } from '@angular/core';
import { Router, RouterLink, RouterLinkActive } from '@angular/router';
import { AuthService } from '../../service/auth.service';

interface AdminNavItem {
  label: string;
  route: string;
  icon: string;
  tone?: 'danger';
}

@Component({
  selector: 'app-admin-sidebar',
  standalone: true,
  imports: [CommonModule, RouterLink, RouterLinkActive],
  template: `
    <aside class="admin-sidebar" aria-label="Admin navigation">
      <button class="brand nav-tip" routerLink="/admin/overview" data-label="Riva Admin" aria-label="Riva Admin Overview">
        <img src="/Picture1.png" alt="Riva" />
      </button>

      <nav>
        <a *ngFor="let item of items"
           class="nav-tip"
           [attr.data-label]="item.label"
           [attr.aria-label]="item.label"
           [routerLink]="item.route"
           routerLinkActive="active"
           [routerLinkActiveOptions]="{ exact: item.route === '/admin/overview' }"
           [class.danger]="item.tone === 'danger'">
          <i [class]="item.icon"></i>
        </a>
      </nav>

      <button class="logout nav-tip" (click)="logout()" data-label="Logout" aria-label="Logout">
        <i class="fa-solid fa-right-from-bracket"></i>
      </button>
    </aside>
  `,
  styles: [`
    :host {
      --admin-sidebar: #0B1220;
      --admin-primary: #2563EB;
      --admin-cyan: #06B6D4;
      --admin-danger: #DC2626;
      --admin-muted: #64748B;
    }

    .admin-sidebar {
      position: fixed;
      inset: 0 auto 0 0;
      z-index: 60;
      width: 84px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 18px;
      padding: 18px 12px;
      background: var(--admin-sidebar);
      box-shadow: 18px 0 44px rgba(15, 23, 42, .18);
    }

    .brand,
    a,
    .logout {
      position: relative;
      display: grid;
      place-items: center;
      width: 52px;
      height: 52px;
      border: 0;
      border-radius: 18px;
      color: #94a3b8;
      background: transparent;
      text-decoration: none;
      cursor: pointer;
      transition: transform 160ms ease, color 160ms ease, background 160ms ease;
    }

    .brand {
      margin-bottom: 6px;
      background: #ffffff;
      box-shadow: 0 14px 26px rgba(37, 99, 235, .16);
    }

    .brand img {
      width: 38px;
      height: 38px;
      object-fit: contain;
    }

    nav {
      display: flex;
      flex: 1;
      flex-direction: column;
      align-items: center;
      gap: 8px;
      width: 100%;
      overflow-y: auto;
      overflow-x: visible;
      padding: 4px 0;
      scrollbar-width: none;
    }

    nav::-webkit-scrollbar {
      display: none;
    }

    i {
      font-size: 17px;
    }

    a::before {
      content: "";
      position: absolute;
      left: -12px;
      width: 4px;
      height: 24px;
      border-radius: 999px;
      background: var(--admin-cyan);
      opacity: 0;
      transform: translateY(8px) scaleY(.5);
      transition: opacity 160ms ease, transform 160ms ease;
    }

    a:hover,
    .logout:hover {
      transform: translateY(-2px);
      color: #ffffff;
      background: rgba(255, 255, 255, .08);
    }

    a.active {
      color: #ffffff;
      background: linear-gradient(135deg, var(--admin-primary), var(--admin-cyan));
      box-shadow: 0 14px 28px rgba(37, 99, 235, .24);
    }

    a.active::before {
      opacity: 1;
      transform: translateY(0) scaleY(1);
    }

    a.danger {
      color: #fca5a5;
    }

    a.danger.active {
      background: linear-gradient(135deg, var(--admin-danger), #fb7185);
    }

    .logout {
      flex: 0 0 52px;
      color: #fca5a5;
      background: rgba(220, 38, 38, .1);
    }

    .nav-tip::after {
      content: attr(data-label);
      position: absolute;
      left: 64px;
      top: 50%;
      transform: translate(-4px, -50%);
      pointer-events: none;
      white-space: nowrap;
      border: 1px solid rgba(226, 232, 240, .18);
      border-radius: 12px;
      padding: 8px 11px;
      background: #0f172a;
      color: #ffffff;
      font-size: 12px;
      font-weight: 800;
      opacity: 0;
      box-shadow: 0 14px 30px rgba(15, 23, 42, .24);
      transition: opacity 140ms ease, transform 140ms ease;
    }

    .nav-tip:hover::after {
      opacity: 1;
      transform: translate(0, -50%);
    }

    @media (max-width: 760px) {
      .admin-sidebar {
        inset: auto 12px 12px 12px;
        width: auto;
        height: 72px;
        flex-direction: row;
        justify-content: center;
        padding: 10px;
        border-radius: 24px;
      }

      .brand,
      .logout {
        display: none;
      }

      nav {
        flex: 0 1 auto;
        flex-direction: row;
        gap: 6px;
        overflow-x: auto;
      }

      a {
        flex: 0 0 48px;
        width: 48px;
        height: 48px;
      }

      a::before,
      .nav-tip::after {
        display: none;
      }
    }
  `]
})
export class AdminSidebarComponent {
  items: AdminNavItem[] = [
    { label: 'Overview', route: '/admin/overview', icon: 'fa-solid fa-chart-pie' },
    { label: 'Users Management', route: '/admin/users', icon: 'fa-solid fa-users-gear' },
    { label: 'Doctor Verification', route: '/admin/doctor-verification', icon: 'fa-solid fa-user-shield' },
    { label: 'Patient Monitoring', route: '/admin/patient-monitoring', icon: 'fa-solid fa-heart-pulse' },
    { label: 'Emergency Center', route: '/admin/emergencies', icon: 'fa-solid fa-triangle-exclamation', tone: 'danger' },
    { label: 'Daily Reports', route: '/admin/daily-reports', icon: 'fa-solid fa-file-medical' },
    { label: 'Appointments & Payments', route: '/admin/appointments', icon: 'fa-solid fa-calendar-check' },
    { label: 'Contact Messages', route: '/admin/contact-messages', icon: 'fa-solid fa-envelope-open-text' },
    { label: 'Notifications Center', route: '/admin/notifications', icon: 'fa-solid fa-bell' },
    { label: 'Activity Logs', route: '/admin/activity-logs', icon: 'fa-solid fa-clipboard-list' },
    { label: 'System Settings', route: '/admin/settings', icon: 'fa-solid fa-sliders' },
  ];

  constructor(private auth: AuthService, private router: Router) {}

  logout(): void {
    this.auth.logout();
    this.router.navigate(['/signin']);
  }
}
