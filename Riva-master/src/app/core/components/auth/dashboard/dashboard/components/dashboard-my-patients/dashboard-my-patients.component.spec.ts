import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { DashboardMyPatientsComponent } from './dashboard-my-patients.component';

describe('DashboardMyPatientsComponent', () => {
  let fixture: ComponentFixture<DashboardMyPatientsComponent>;
  let component: DashboardMyPatientsComponent;
  let router: Router;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [DashboardMyPatientsComponent],
      providers: [provideRouter([])],
    });
    fixture = TestBed.createComponent(DashboardMyPatientsComponent);
    component = fixture.componentInstance;
    router = TestBed.inject(Router);
    component.pieOptions = { series: [1, 0, 0], chart: { type: 'donut', height: 280 }, labels: [], colors: [], responsive: [] };
    component.lineOptions = { series: [{ name: 'Patients', data: [] }], chart: { type: 'area', height: 280 }, xaxis: {}, stroke: {}, colors: [] };
    fixture.detectChanges();
  });

  it('creates', () => {
    expect(component).toBeTruthy();
  });

  it('navigates to /chat/:userId when a patient row is clicked (unchanged Phase 5 behavior, not /profile-p)', () => {
    const navigateSpy = spyOn(router, 'navigate');
    component.openChat(7);
    expect(navigateSpy).toHaveBeenCalledWith(['/chat', 7]);
  });

  it('navigates to /ai-chat when the AI Assistant tile is activated', () => {
    const navigateSpy = spyOn(router, 'navigate');
    component.openAiAssistant();
    expect(navigateSpy).toHaveBeenCalledWith(['/ai-chat']);
  });
});
