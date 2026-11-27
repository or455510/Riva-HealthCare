import { ComponentFixture, TestBed } from '@angular/core/testing';

import { CaregiverFollowRequestComponent } from './caregiver-follow-request.component';

describe('CaregiverFollowRequestComponent', () => {
  let component: CaregiverFollowRequestComponent;
  let fixture: ComponentFixture<CaregiverFollowRequestComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [CaregiverFollowRequestComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(CaregiverFollowRequestComponent);
    component = fixture.componentInstance;
    await fixture.whenStable();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
