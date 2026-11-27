import { Injectable } from '@angular/core';
import { BehaviorSubject } from 'rxjs';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

(window as any).Pusher = Pusher;

@Injectable({ providedIn: 'root' })
export class WebRTCService {
  private echo: any;
  private peerConnection!: RTCPeerConnection;
  private localStream!: MediaStream;
  private currentChannel: any;

  localStream$ = new BehaviorSubject<MediaStream | null>(null);
  remoteStream$ = new BehaviorSubject<MediaStream | null>(null);
  callState$ = new BehaviorSubject<'idle' | 'calling' | 'receiving' | 'in-call'>('idle');
  incomingCall$ = new BehaviorSubject<{ fromUserId: number; type: 'video' | 'voice' } | null>(null);

  private readonly ICE_SERVERS = {
    iceServers: [
      { urls: 'stun:stun.l.google.com:19302' },
      { urls: 'stun:stun1.l.google.com:19302' },
    ]
  };

  constructor() {
    this.echo = new Echo({
      broadcaster: 'pusher',
      key: 'local',
      wsHost: '127.0.0.1',
      wsPort: 6001,
      forceTLS: false,
      disableStats: true,
      cluster: 'mt1',
    });
  }

  listenOnChannel(myId: number, otherUserId: number) {
    const channelId = [myId, otherUserId].sort().join('-');
    this.currentChannel = this.echo.private(`call.${channelId}`);

    this.currentChannel.listen('.webrtc.signal', async (event: any) => {
      if (event.fromUserId === myId) return;

      switch (event.type) {
        case 'call-request':
          this.callState$.next('receiving');
          this.incomingCall$.next({ fromUserId: event.fromUserId, type: 'video' });
          break;
        case 'call-request-voice':
          this.callState$.next('receiving');
          this.incomingCall$.next({ fromUserId: event.fromUserId, type: 'voice' });
          break;
        case 'offer':
          await this.handleOffer(event.data, event.fromUserId);
          break;
        case 'answer':
          await this.peerConnection.setRemoteDescription(event.data);
          break;
        case 'ice-candidate':
          if (event.data) {
            await this.peerConnection.addIceCandidate(new RTCIceCandidate(event.data));
          }
          break;
        case 'call-rejected':
          this.endCall();
          break;
        case 'call-ended':
          this.endCall();
          break;
      }
    });
  }

  // ── Video call
  async startCall(toUserId: number) {
    this.callState$.next('calling');
    await this.sendSignal('call-request', null, toUserId);
    await this.setupLocalStream(false);
    this.createPeerConnection(toUserId);
  }

  // ── Voice-only call
  async startVoiceCall(toUserId: number) {
    this.callState$.next('calling');
    await this.sendSignal('call-request-voice', null, toUserId);
    await this.setupLocalStream(true);
    this.createPeerConnection(toUserId);
  }

  async acceptCall(fromUserId: number, voiceOnly = false) {
    this.callState$.next('in-call');
    this.incomingCall$.next(null);
    await this.setupLocalStream(voiceOnly);
    this.createPeerConnection(fromUserId);
  }

  async rejectCall(fromUserId: number) {
    await this.sendSignal('call-rejected', null, fromUserId);
    this.callState$.next('idle');
    this.incomingCall$.next(null);
  }

  private async setupLocalStream(voiceOnly: boolean) {
    this.localStream = await navigator.mediaDevices.getUserMedia({
      video: !voiceOnly,
      audio: true,
    });
    this.localStream$.next(this.localStream);
  }

  private createPeerConnection(otherUserId: number) {
    this.peerConnection = new RTCPeerConnection(this.ICE_SERVERS);

    this.localStream.getTracks().forEach(track => {
      this.peerConnection.addTrack(track, this.localStream);
    });

    const remoteStream = new MediaStream();
    this.peerConnection.ontrack = (event) => {
      event.streams[0].getTracks().forEach(track => remoteStream.addTrack(track));
      this.remoteStream$.next(remoteStream);
    };

    this.peerConnection.onicecandidate = async (event) => {
      await this.sendSignal('ice-candidate', event.candidate, otherUserId);
    };

    if (this.callState$.value === 'calling') {
      this.createAndSendOffer(otherUserId);
    }
  }

  private async createAndSendOffer(toUserId: number) {
    const offer = await this.peerConnection.createOffer();
    await this.peerConnection.setLocalDescription(offer);
    await this.sendSignal('offer', offer, toUserId);
  }

  private async handleOffer(offer: RTCSessionDescriptionInit, fromUserId: number) {
    await this.peerConnection.setRemoteDescription(new RTCSessionDescription(offer));
    const answer = await this.peerConnection.createAnswer();
    await this.peerConnection.setLocalDescription(answer);
    await this.sendSignal('answer', answer, fromUserId);
    this.callState$.next('in-call');
  }

  async endCall(otherUserId?: number) {
    if (otherUserId) {
      await this.sendSignal('call-ended', null, otherUserId);
    }
    this.peerConnection?.close();
    this.localStream?.getTracks().forEach(t => t.stop());
    this.localStream$.next(null);
    this.remoteStream$.next(null);
    this.callState$.next('idle');
  }

  toggleMic(mute: boolean) {
    this.localStream?.getAudioTracks().forEach(t => t.enabled = !mute);
  }

  toggleCamera(off: boolean) {
    this.localStream?.getVideoTracks().forEach(t => t.enabled = !off);
  }

  private async sendSignal(type: string, data: any, toUserId: number) {
    const token = localStorage.getItem('auth_token');
    await fetch('/api/call/signal', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`,
      },
      body: JSON.stringify({ type, data, to_user_id: toUserId }),
    });
  }
}