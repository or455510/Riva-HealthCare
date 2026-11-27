import { ComponentFixture, TestBed } from '@angular/core/testing';

import { DoctorsFollowRequestComponent } from './doctors-follow-request.component';

describe('DoctorsFollowRequestComponent', () => {
  let component: DoctorsFollowRequestComponent;
  let fixture: ComponentFixture<DoctorsFollowRequestComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [DoctorsFollowRequestComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(DoctorsFollowRequestComponent);
    component = fixture.componentInstance;
    await fixture.whenStable();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
