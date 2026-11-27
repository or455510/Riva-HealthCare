import { ComponentFixture, TestBed } from '@angular/core/testing';

import { ProfileCComponent } from './profile-c.component';

describe('ProfileCComponent', () => {
  let component: ProfileCComponent;
  let fixture: ComponentFixture<ProfileCComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ProfileCComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(ProfileCComponent);
    component = fixture.componentInstance;
    await fixture.whenStable();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
