import { ComponentFixture, TestBed } from '@angular/core/testing';

import { DashboardPComponent } from './dashboard-p.component';

describe('DashboardPComponent', () => {
  let component: DashboardPComponent;
  let fixture: ComponentFixture<DashboardPComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [DashboardPComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(DashboardPComponent);
    component = fixture.componentInstance;
    await fixture.whenStable();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
