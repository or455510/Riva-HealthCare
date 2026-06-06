import { ComponentFixture, TestBed } from '@angular/core/testing';

import { ProfilePComponent } from './profile-p.component';

describe('ProfilePComponent', () => {
  let component: ProfilePComponent;
  let fixture: ComponentFixture<ProfilePComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ProfilePComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(ProfilePComponent);
    component = fixture.componentInstance;
    await fixture.whenStable();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
