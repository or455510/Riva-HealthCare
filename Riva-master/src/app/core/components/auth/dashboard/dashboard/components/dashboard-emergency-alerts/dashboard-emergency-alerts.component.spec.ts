import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { DashboardEmergencyAlertsComponent } from './dashboard-emergency-alerts.component';

describe('DashboardEmergencyAlertsComponent', () => {
  let fixture: ComponentFixture<DashboardEmergencyAlertsComponent>;
  let component: DashboardEmergencyAlertsComponent;
  let router: Router;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [DashboardEmergencyAlertsComponent],
      providers: [provideRouter([])],
    });
    fixture = TestBed.createComponent(DashboardEmergencyAlertsComponent);
    component = fixture.componentInstance;
    router = TestBed.inject(Router);
  });

  it('creates', () => {
    fixture.detectChanges();
    expect(component).toBeTruthy();
  });

  it('navigates to /profile-p/:id when opening an alert', () => {
    fixture.detectChanges();
    const navigateSpy = spyOn(router, 'navigate');
    component.openPatient(10);
    expect(navigateSpy).toHaveBeenCalledWith(['/profile-p', 10]);
  });

  it('does not render the banner element when count is 0 (real empty state, no placeholder)', () => {
    component.count = 0;
    fixture.detectChanges();
    const banner = fixture.nativeElement.querySelector('[data-testid="emergency-alert-banner"]');
    expect(banner).toBeNull();
  });

  it('renders the banner when count is greater than 0', () => {
    component.count = 2;
    component.alerts = [
      { id: 1, patient_id: 10, patient_name: 'Alice', severity: 'high', message: 'Chest pain', pain_level: 8, symptoms: null, created_at: '2026-08-26T09:00:00Z' },
    ];
    fixture.detectChanges();
    const banner = fixture.nativeElement.querySelector('[data-testid="emergency-alert-banner"]');
    expect(banner).not.toBeNull();
  });
});
