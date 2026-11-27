import { ComponentFixture, TestBed } from '@angular/core/testing';

import { DashboardCaregiverComponent } from './dashboard-caregiver.component';

describe('DashboardCaregiverComponent', () => {
  let component: DashboardCaregiverComponent;
  let fixture: ComponentFixture<DashboardCaregiverComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [DashboardCaregiverComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(DashboardCaregiverComponent);
    component = fixture.componentInstance;
    await fixture.whenStable();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
