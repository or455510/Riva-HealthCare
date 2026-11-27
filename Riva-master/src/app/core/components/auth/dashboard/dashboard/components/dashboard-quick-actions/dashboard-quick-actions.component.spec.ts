import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { DashboardQuickActionsComponent } from './dashboard-quick-actions.component';

describe('DashboardQuickActionsComponent', () => {
  let fixture: ComponentFixture<DashboardQuickActionsComponent>;
  let component: DashboardQuickActionsComponent;
  let router: Router;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [DashboardQuickActionsComponent],
      providers: [provideRouter([])],
    });
    fixture = TestBed.createComponent(DashboardQuickActionsComponent);
    component = fixture.componentInstance;
    router = TestBed.inject(Router);
    fixture.detectChanges();
  });

  it('creates', () => {
    expect(component).toBeTruthy();
  });

  it('navigates to /ai-chat for the AI Assistant action', () => {
    const navigateSpy = spyOn(router, 'navigate');
    component.openAiAssistant();
    expect(navigateSpy).toHaveBeenCalledWith(['/ai-chat']);
  });
});
