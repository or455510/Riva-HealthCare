import { TestBed } from '@angular/core/testing';

import { MissedMedicationService } from './missed-medication.service';

describe('MissedMedicationService', () => {
  let service: MissedMedicationService;

  beforeEach(() => {
    TestBed.configureTestingModule({});
    service = TestBed.inject(MissedMedicationService);
  });

  it('should be created', () => {
    expect(service).toBeTruthy();
  });
});
