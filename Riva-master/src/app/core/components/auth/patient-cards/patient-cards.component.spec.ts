import { ComponentFixture, TestBed } from '@angular/core/testing';

import { PatientCardsComponent } from './patient-cards.component';

describe('PatientCardsComponent', () => {
  let component: PatientCardsComponent;
  let fixture: ComponentFixture<PatientCardsComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [PatientCardsComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(PatientCardsComponent);
    component = fixture.componentInstance;
    await fixture.whenStable();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
