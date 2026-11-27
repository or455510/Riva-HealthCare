import { Component, OnInit, OnDestroy, ViewChild, ElementRef, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { WebRTCService } from '../service/webrtc.service';
import { Subscription } from 'rxjs';

@Component({
  selector: 'app-video-call',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './video-call.component.html',
  styleUrl: './video-call.component.scss'
})
export class VideoCallComponent implements OnInit, OnDestroy {
  @Input() myUserId!: number;
  @Input() otherUserId!: number;
  @Input() callType: 'video' | 'voice' = 'video';

  @ViewChild('localVideo')  localVideo!: ElementRef<HTMLVideoElement>;
  @ViewChild('remoteVideo') remoteVideo!: ElementRef<HTMLVideoElement>;

  callState = 'idle';
  incomingCall: any = null;
  isMuted = false;
  isCameraOff = false;

  private subs: Subscription[] = [];

  constructor(public webrtc: WebRTCService) {}

  ngOnInit() {
    this.webrtc.listenOnChannel(this.myUserId, this.otherUserId);

    this.subs.push(
      this.webrtc.callState$.subscribe(s => this.callState = s),
      this.webrtc.incomingCall$.subscribe(c => this.incomingCall = c),

      this.webrtc.localStream$.subscribe(stream => {
        setTimeout(() => {
          if (this.localVideo?.nativeElement && stream)
            this.localVideo.nativeElement.srcObject = stream;
        });
      }),

      this.webrtc.remoteStream$.subscribe(stream => {
        setTimeout(() => {
          if (this.remoteVideo?.nativeElement && stream)
            this.remoteVideo.nativeElement.srcObject = stream;
        });
      })
    );
  }

  startCall() {
    if (this.callType === 'voice') {
      this.webrtc.startVoiceCall(this.otherUserId);
    } else {
      this.webrtc.startCall(this.otherUserId);
    }
  }

  acceptCall() {
    const voiceOnly = this.incomingCall?.type === 'voice';
    this.webrtc.acceptCall(this.incomingCall.fromUserId, voiceOnly);
  }

  rejectCall() { this.webrtc.rejectCall(this.incomingCall.fromUserId); }
  endCall()    { this.webrtc.endCall(this.otherUserId); }
  toggleMic()  { this.isMuted = !this.isMuted; this.webrtc.toggleMic(this.isMuted); }
  toggleCam()  { this.isCameraOff = !this.isCameraOff; this.webrtc.toggleCamera(this.isCameraOff); }

  ngOnDestroy() { this.subs.forEach(s => s.unsubscribe()); }
}