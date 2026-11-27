import { ComponentFixture, TestBed } from '@angular/core/testing';

import { DoctorCardsComponent } from './doctor-cards.component';

describe('DoctorCardsComponent', () => {
  let component: DoctorCardsComponent;
  let fixture: ComponentFixture<DoctorCardsComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [DoctorCardsComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(DoctorCardsComponent);
    component = fixture.componentInstance;
    await fixture.whenStable();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
