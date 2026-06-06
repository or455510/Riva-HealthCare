import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { ActivatedRouteSnapshot } from '@angular/router';
import { of } from 'rxjs';

import { HomeComponent } from './features/components/home/home.component';
import { AuthGuard } from './guards/auth-guard';
import { SidebarComponent } from './components/sidebar';
import { BookingComponent } from './core/components/auth/booking/booking.component';
import { NotificationDetailComponent } from './core/components/auth/notifications/notification-detail.component';
import { AuthService } from './service/auth.service';
import { routes } from './app.routes';

class MockAuthService {
  role = 'patient';
  authenticated = true;
  completed = true;
  token = 'test-token';

  isAuthenticated(): boolean { return this.authenticated; }
  getUserRole(): string { return this.role; }
  isProfileCompleted(): boolean { return this.completed; }
  completionRouteForRole(role: string): string { return `/complete-profile/${role}`; }
  dashboardRouteForRole(role = this.role): string {
    return role === 'doctor' ? '/dashboard'
      : role === 'caregiver' ? '/dashboard-caregiver'
      : role === 'admin' ? '/admin/dashboard-admin'
      : '/dashboard-p';
  }
  logout(): void { this.authenticated = false; }
  getToken(): string { return this.token; }
}

describe('Riva final QA frontend smoke suite', () => {
  let auth: MockAuthService;

  beforeEach(() => {
    auth = new MockAuthService();
    localStorage.clear();
  });

  it('HomeComponent routes disease cards to the disease detail pages', () => {
    const router = jasmine.createSpyObj<Router>('Router', ['navigate']);
    const zone = { runOutsideAngular: (fn: Function) => fn() } as any;
    const component = new HomeComponent(zone, router);

    component.goToDisease('diabetes');

    expect(router.navigate).toHaveBeenCalledWith(['/diseases', 'diabetes']);
    expect(routes.find(route => route.path === 'diseases/diabetes')?.data?.['diseaseSlug']).toBe('diabetes');
    expect(routes.find(route => route.path === 'diseases/heart')?.data?.['diseaseSlug']).toBe('heart');
    expect(routes.find(route => route.path === 'diseases/hypertension')?.data?.['diseaseSlug']).toBe('hypertension');
    expect(routes.find(route => route.path === 'diseases/cancer')?.data?.['diseaseSlug']).toBe('cancer');
  });

  it('AuthGuard redirects forbidden roles to /unauthorized', () => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: 'signin', children: [] }, { path: 'unauthorized', children: [] }]),
        { provide: AuthService, useValue: auth },
        AuthGuard,
      ],
    });

    auth.role = 'patient';
    const guard = TestBed.inject(AuthGuard);
    const deniedRoute = { data: { roles: ['doctor'] } } as unknown as ActivatedRouteSnapshot;

    expect(guard.canActivate(deniedRoute).toString()).toBe('/unauthorized');
  });

  it('Sidebar renders the role-aware patient menu and logout control', async () => {
    await TestBed.configureTestingModule({
      imports: [SidebarComponent],
      providers: [
        provideRouter([]),
        { provide: AuthService, useValue: auth },
      ],
    }).compileComponents();

    const fixture = TestBed.createComponent(SidebarComponent);
    fixture.detectChanges();
    const text = fixture.nativeElement.textContent || '';
    const buttons = fixture.nativeElement.querySelectorAll('button');

    expect(fixture.componentInstance.menuItems.map(item => item.title)).toContain('Medications');
    expect(fixture.componentInstance.menuItems.map(item => item.title)).toContain('Doctors');
    expect(fixture.componentInstance.menuItems.map(item => item.title)).not.toContain('Users');
    expect(buttons.length).toBeGreaterThan(1);
    expect(text).not.toContain('Admin');
  });

  it('BookingComponent restores a pending booking to payment step', async () => {
    localStorage.setItem('bookingData', JSON.stringify({
      appointmentId: 55,
      requiresPayment: true,
      slot: '10:30',
      consultType: 'online',
      notes: 'Follow up',
    }));

    await TestBed.configureTestingModule({
      imports: [BookingComponent],
      providers: [
        provideRouter([]),
        provideHttpClient(),
        { provide: AuthService, useValue: auth },
      ],
    }).compileComponents();

    const fixture = TestBed.createComponent(BookingComponent);
    fixture.detectChanges();

    expect(fixture.componentInstance.currentStep()).toBe(2);
    expect(fixture.componentInstance.activeAppointmentId()).toBe(55);
    expect(fixture.componentInstance.selectedSlot()).toBe('10:30');
  });

  it('Notification detail route is protected and available', () => {
    const detailRoute = routes.find(route => route.path === 'notifications/:id');

    expect(detailRoute).toBeTruthy();
    expect(detailRoute?.data?.['roles']).toEqual(['patient', 'doctor', 'caregiver', 'admin']);
  });

  it('NotificationDetailComponent maps clinical action URLs before generic category routes', async () => {
    await TestBed.configureTestingModule({
      imports: [NotificationDetailComponent],
      providers: [
        provideRouter([]),
        provideHttpClient(),
        { provide: AuthService, useValue: auth },
      ],
    }).compileComponents();

    const fixture = TestBed.createComponent(NotificationDetailComponent);
    fixture.componentInstance.notification = {
      type: 'emergency_alert',
      category: 'emergency',
      action_url: '/report/7',
      data: { patient_name: 'Omar', severity: 'high' },
    };

    expect(fixture.componentInstance.actionRoute).toBe('/report/7');
    expect(fixture.componentInstance.clinicalDetails.length).toBeGreaterThan(0);
  });
});
