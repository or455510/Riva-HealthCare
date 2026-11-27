import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { DashboardAttentionComponent } from './dashboard-attention.component';
import { ReviewItemView } from '../dashboard-view-models';

describe('DashboardAttentionComponent', () => {
  let fixture: ComponentFixture<DashboardAttentionComponent>;
  let component: DashboardAttentionComponent;
  let router: Router;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [DashboardAttentionComponent],
      providers: [provideRouter([])],
    });
    fixture = TestBed.createComponent(DashboardAttentionComponent);
    component = fixture.componentInstance;
    router = TestBed.inject(Router);
  });

  it('creates', () => {
    fixture.detectChanges();
    expect(component).toBeTruthy();
  });

  it('labels a high_risk item "Critical" via the shared riskLabel helper (no invented vocabulary)', () => {
    fixture.detectChanges();
    expect(component.riskLabel('high_risk')).toBe('Critical');
    expect(component.riskLabel(undefined)).toBe('No data');
  });

  it('navigates to /profile-p/:id when opening a review item', () => {
    fixture.detectChanges();
    const navigateSpy = spyOn(router, 'navigate');
    component.openPatient(10);
    expect(navigateSpy).toHaveBeenCalledWith(['/profile-p', 10]);
  });

  it('does nothing when opening a review item with no patient id', () => {
    fixture.detectChanges();
    const navigateSpy = spyOn(router, 'navigate');
    component.openPatient(null);
    expect(navigateSpy).not.toHaveBeenCalled();
  });

  it('accepts real review items via @Input without transformation', () => {
    const items: ReviewItemView[] = [
      { id: 1, patient_id: 10, risk_level: 'high_risk', date: '2026-08-25', created_at: '2026-08-25T10:00:00Z', patientDisplayName: 'Alice Ahmed' },
    ];
    component.reviewItems = items;
    fixture.detectChanges();
    expect(component.reviewItems).toBe(items);
  });
});
