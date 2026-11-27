import { ComponentFixture, TestBed } from '@angular/core/testing';

import { DiseasesComponent } from './diseases.component';

describe('DiseasesComponent', () => {
  let component: DiseasesComponent;
  let fixture: ComponentFixture<DiseasesComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [DiseasesComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(DiseasesComponent);
    component = fixture.componentInstance;
    await fixture.whenStable();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
