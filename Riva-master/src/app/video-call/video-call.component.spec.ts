import { ComponentFixture, TestBed } from '@angular/core/testing';

import { VideoCallComponent } from './video-call.component';

describe('VideoCallComponent', () => {
  let component: VideoCallComponent;
  let fixture: ComponentFixture<VideoCallComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [VideoCallComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(VideoCallComponent);
    component = fixture.componentInstance;
    await fixture.whenStable();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
