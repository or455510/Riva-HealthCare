import { ComponentFixture, TestBed } from '@angular/core/testing';

import { CaregiverCardsComponent } from './caregiver-cards.component';

describe('CaregiverCardsComponent', () => {
  let component: CaregiverCardsComponent;
  let fixture: ComponentFixture<CaregiverCardsComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [CaregiverCardsComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(CaregiverCardsComponent);
    component = fixture.componentInstance;
    await fixture.whenStable();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
