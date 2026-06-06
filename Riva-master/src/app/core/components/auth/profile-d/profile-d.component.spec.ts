import { ComponentFixture, TestBed } from '@angular/core/testing';

import { ProfileDComponent } from './profile-d.component';

describe('ProfileDComponent', () => {
  let component: ProfileDComponent;
  let fixture: ComponentFixture<ProfileDComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ProfileDComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(ProfileDComponent);
    component = fixture.componentInstance;
    await fixture.whenStable();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
