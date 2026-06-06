import { ComponentFixture, TestBed } from '@angular/core/testing';

import { Welcome2Component } from './welcome2.component';

describe('Welcome2Component', () => {
  let component: Welcome2Component;
  let fixture: ComponentFixture<Welcome2Component>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [Welcome2Component]
    })
    .compileComponents();

    fixture = TestBed.createComponent(Welcome2Component);
    component = fixture.componentInstance;
    await fixture.whenStable();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
