import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router, RouterLink, RouterLinkActive } from '@angular/router';
import { AuthService } from '../service/auth.service';

type Role = 'patient' | 'doctor' | 'caregiver' | 'admin';

interface SidebarItem {
  icon: string;
  route: string;
  title: string;
}

const MENU: Record<Role, SidebarItem[]> = {
  patient: [
    { icon: 'fas fa-home', route: '/dashboard-p', title: 'Dashboard' },
    { icon: 'fas fa-pills', route: '/add-new-medication', title: 'Medications' },
    { icon: 'fa-solid fa-user-doctor', route: '/doctor-cards', title: 'Doctors' },
    { icon: 'fa-solid fa-user-nurse', route: '/caregiver-cards', title: 'Caregivers' },
    { icon: 'fa-brands fa-rocketchat', route: '/chat', title: 'Chat' },
    { icon: 'fa-solid fa-robot', route: '/ai-chat', title: 'AI Assistant' },
    { icon: 'fa-regular fa-bell', route: '/notifications', title: 'Notifications' },
    { icon: 'fa-solid fa-file-medical', route: '/my-reports', title: 'Reports' },
    { icon: 'fa-solid fa-circle-user', route: '/myprofile', title: 'Profile' },
  ],
  doctor: [
    { icon: 'fas fa-home', route: '/dashboard', title: 'Dashboard' },
    { icon: 'fa-solid fa-bed-pulse', route: '/patient-cards', title: 'Patients' },
    { icon: 'fa-brands fa-rocketchat', route: '/chat', title: 'Chat' },
    { icon: 'fa-solid fa-robot', route: '/ai-chat', title: 'AI Assistant' },
    { icon: 'fa-regular fa-bell', route: '/notifications', title: 'Notifications' },
    { icon: 'fa-solid fa-file-medical', route: '/my-reports', title: 'Reports' },
    { icon: 'fa-solid fa-circle-user', route: '/myprofile', title: 'Profile' },
  ],
  caregiver: [
    { icon: 'fas fa-home', route: '/dashboard-caregiver', title: 'Dashboard' },
    { icon: 'fa-solid fa-bed-pulse', route: '/patient-cards', title: 'Patients' },
    { icon: 'fa-brands fa-rocketchat', route: '/chat', title: 'Chat' },
    { icon: 'fa-solid fa-robot', route: '/ai-chat', title: 'AI Assistant' },
    { icon: 'fa-regular fa-bell', route: '/notifications', title: 'Notifications' },
    { icon: 'fa-solid fa-circle-user', route: '/myprofile', title: 'Profile' },
  ],
  admin: [
    { icon: 'fas fa-home', route: '/admin/dashboard-admin', title: 'Dashboard' },
    { icon: 'fa-solid fa-users', route: '/admin/users', title: 'Users' },
    { icon: 'fa-solid fa-user-doctor', route: '/admin/users', title: 'Doctors' },
    { icon: 'fa-solid fa-user-nurse', route: '/admin/users', title: 'Caregivers' },
    { icon: 'fa-solid fa-bed-pulse', route: '/admin/users', title: 'Patients' },
    { icon: 'fa-solid fa-robot', route: '/ai-chat', title: 'AI Assistant' },
    { icon: 'fa-regular fa-bell', route: '/notifications', title: 'Notifications' },
  ],
};

@Component({
  selector: 'app-sidebar',
  standalone: true,
  imports: [CommonModule, RouterLink, RouterLinkActive],
  template: `
   <aside class="fixed left-0 top-0 h-screen w-20 bg-white border-r border-gray-200 hidden md:flex flex-col items-center py-6 gap-8 shadow-sm z-50">
        <button class="text-blue-600 font-bold text-xl" [routerLink]="dashboardRoute" title="Riva dashboard">
            <img src="/Picture1.png" alt="RIVA Logo" class="w-12">
        </button>
        <nav class="flex flex-col gap-4 text-gray-400">
            <button
              *ngFor="let item of menuItems"
              class="grid h-10 w-10 place-items-center rounded-xl transition-colors hover:bg-blue-50 hover:text-blue-600"
              routerLinkActive="bg-blue-50 text-blue-600"
              [routerLinkActiveOptions]="{ exact: true }"
              [routerLink]="item.route"
              [title]="item.title"
              [attr.aria-label]="item.title">
                <i [class]="item.icon"></i>
            </button>
        </nav>
        <div class="mt-auto">
            <button
              class="grid h-10 w-10 place-items-center rounded-xl text-red-400 transition-colors hover:bg-red-50 hover:text-red-600"
              title="Logout"
              aria-label="Logout"
              (click)="logout()">
              <i class="fa-solid fa-right-from-bracket"></i>
            </button>
        </div>
    </aside>
  `,
  styles: [`
    .sidebar {
      position: fixed;
      left: 0;
      top: 0;
      bottom: 0;
      width: 75px;
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 20px 0;
      background: #ffffff;
      border-right: 1px solid #f0f0f0;
      z-index: 1000;
    }
    
    .logo { margin-bottom: 40px; cursor: pointer; }
    .logo-img { width: 32px; height: auto; }
    
    .nav-icons {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 20px;
    }
    
    .icon-wrapper {
      width: 45px;
      height: 45px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%; /* دائرية تماماً مثل الصورة */
      color: #B0B7C3;
      font-size: 18px;
      cursor: pointer;
      transition: all 0.3s ease;
    }
    
    .icon-wrapper.active {
      background: #5D5FEF;
      color: #fff;
      box-shadow: 0 4px 15px rgba(93, 95, 239, 0.3);
    }

    .settings-container { margin-top: auto; }
    .settings-icon { color: #B0B7C3; }
  `]
})
export class SidebarComponent {
  constructor(private authService: AuthService, private router: Router) {}

  get role(): Role {
    const role = this.authService.getUserRole();
    return (['patient', 'doctor', 'caregiver', 'admin'].includes(role) ? role : 'patient') as Role;
  }

  get menuItems(): SidebarItem[] {
    return MENU[this.role];
  }

  get dashboardRoute(): string {
    return this.authService.dashboardRouteForRole(this.role);
  }

  logout(): void {
    this.authService.logout();
    this.router.navigate(['/signin']);
  }
}
