import { ComponentFixture, TestBed } from '@angular/core/testing';

import { AddNewMedicationComponent } from './add-new-medication.component';

describe('AddNewMedicationComponent', () => {
  let component: AddNewMedicationComponent;
  let fixture: ComponentFixture<AddNewMedicationComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [AddNewMedicationComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(AddNewMedicationComponent);
    component = fixture.componentInstance;
    await fixture.whenStable();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
