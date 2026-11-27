import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { RouterModule, Router } from '@angular/router';
import { finalize } from 'rxjs/operators';
import { AuthService } from '../../../../service/auth.service';
import { API_BASE_URL } from '../../../../constants';
import { SidebarComponent } from '../../../../components/sidebar';

interface Notification {
  id: number | string;
  type: 'medication' | 'test' | 'doctor' | 'general';
  title: string;
  body: string;
  category: string;
  time: string;
  iconBg: string;
  read_at: string | null;
}

@Component({
  selector: 'app-notifications',
  standalone: true,
  imports: [CommonModule, FormsModule, HttpClientModule, RouterModule, SidebarComponent],
  templateUrl: './notifications.component.html'
})
export class NotificationsComponent implements OnInit, OnDestroy {
  notifications: Notification[] = [];
  isLoading = true;
  overallEnabled = true;

  categoryFilters = {
    Medication: true,
    Tests: true,
    Messages: true,
  };

  get filteredNotifications(): Notification[] {
    return this.notifications.filter(n => {
      if (!this.overallEnabled) return false;
      if (n.type === 'medication' && !this.categoryFilters.Medication) return false;
      if (n.type === 'test' && !this.categoryFilters.Tests) return false;
      if (n.type === 'general' && !this.categoryFilters.Messages) return false;
      return true;
    });
  }

  constructor(
    private http: HttpClient,
    private authService: AuthService,
    private router: Router
  ) {}

  private get authHeaders() {
    return { Authorization: `Bearer ${this.authService.getToken()}` };
  }

  ngOnInit(): void {
    this.loadNotifications();
  }

  ngOnDestroy(): void {}

  loadNotifications(): void {
    this.isLoading = true;
    this.http.get<any>(`${API_BASE_URL}/notifications`, { headers: this.authHeaders })
      .pipe(finalize(() => this.isLoading = false))
      .subscribe({
        next: (res) => {
          const list = res.data?.items || res.data || res.notifications || (Array.isArray(res) ? res : []);
          this.notifications = list.map((n: any) => {
            const payload = n.data || {};
            const type = n.type || payload.type || '';
            return {
              id: n.id,
              type: this.resolveType(type),
              title: n.title || payload.title || 'Notification',
              body: n.body || n.message || payload.body || payload.message || '',
              category: n.category || payload.category || type || 'general',
              time: n.created_at
                ? new Date(n.created_at).toLocaleString('en-GB', {
                    hour: '2-digit',
                    minute: '2-digit',
                    day: 'numeric',
                    month: 'short'
                  })
                : '',
              iconBg: this.resolveIconBg(`${type} ${n.category || ''}`),
              read_at: n.read_at,
            };
          });
        },
        error: (err) => {
          console.error('Notifications error:', err);
          if (err.status === 401) this.router.navigate(['/signin']);
        }
      });
  }

  markAllAsRead(): void {
    this.http.post(`${API_BASE_URL}/notifications/read-all`, {}, { headers: this.authHeaders })
      .subscribe({
        next: () => {
          const now = new Date().toISOString();
          this.notifications = this.notifications.map(n => ({ ...n, read_at: now }));
        },
        error: (err) => console.error(err)
      });
  }

  markAsRead(notification: Notification): void {
    if (notification.read_at) {
      this.navigateTo(notification);
      return;
    }

    this.http.patch(`${API_BASE_URL}/notifications/${notification.id}/read`, {}, { headers: this.authHeaders })
      .subscribe({
        next: () => {
          notification.read_at = new Date().toISOString();
          this.navigateTo(notification);
        },
        error: (err) => console.error(err)
      });
  }

  private navigateTo(notification: Notification): void {
    this.router.navigate(['/notifications', notification.id]);
  }

  private resolveType(type: string): Notification['type'] {
    const value = type.toLowerCase();
    if (value.includes('medication')) return 'medication';
    if (value.includes('test') || value.includes('scan') || value.includes('report')) return 'test';
    if (value.includes('doctor') || value.includes('prescription')) return 'doctor';
    return 'general';
  }

  private resolveIconBg(type: string): string {
    const value = type.toLowerCase();
    if (value.includes('medication')) return 'bg-blue-600';
    if (value.includes('test') || value.includes('scan') || value.includes('report')) return 'bg-purple-600';
    if (value.includes('doctor') || value.includes('prescription')) return 'bg-blue-500';
    return 'bg-slate-500';
  }

  get unreadCount(): number {
    return this.notifications.filter(n => !n.read_at).length;
  }
}
