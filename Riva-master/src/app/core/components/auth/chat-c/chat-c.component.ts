import {
  Component, OnInit, OnDestroy, ViewChild, ElementRef,
  AfterViewChecked, CUSTOM_ELEMENTS_SCHEMA,
  ChangeDetectorRef, inject
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { AuthService } from '../../../../service/auth.service';
import { interval, Subscription, of } from 'rxjs';
import { switchMap, catchError, finalize } from 'rxjs/operators';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { API_BASE_URL } from '../../../../constants';
import { VideoCallComponent } from '../../../../video-call/video-call.component';
import { SidebarComponent } from '../../../../components/sidebar';
import { NotificationService } from '../../../../service/notification.service';

export interface Message {
  id?: number;
  sender_id?: number;
  receiver_id?: number;
  body: string;
  created_at?: string;
  file_url?: string;
  file_type?: 'image' | 'audio' | 'file';
  file_name?: string;
  audio_duration?: string;
}

export interface ChatContact {
  id: number;
  name: string;
  avatar: string;
  lastMessage?: string;
  status: 'online' | 'offline';
}

const STORAGE_BASE = 'http://https://riva-healthcare-tm.gamer.gd/storage/';

function toAvatarUrl(user: any, fallback: string): string {
  return user.profile_image_url
    || (user.profile_image ? `${STORAGE_BASE}${user.profile_image}` : null)
    || fallback;
}

@Component({
  selector: 'app-chat-c',
  standalone: true,
  imports: [RouterModule, CommonModule, FormsModule, HttpClientModule, VideoCallComponent, SidebarComponent],
  templateUrl: './chat-c.component.html',
  styleUrls: ['./chat-c.component.css'],
  schemas: [CUSTOM_ELEMENTS_SCHEMA]
})
export class ChatCComponent implements OnInit, OnDestroy, AfterViewChecked {

  @ViewChild('messagesContainer') messagesContainer!: ElementRef;
  private cdr = inject(ChangeDetectorRef);

  userRole: 'patient' | 'caregiver' | 'doctor' = 'patient';
  get isCaregiver(): boolean { return this.userRole === 'caregiver'; }
  get isPatient():   boolean { return this.userRole === 'patient'; }
  get isDoctor():    boolean { return this.userRole === 'doctor'; }
  get contactsLabel(): string { return this.isCaregiver ? 'My Patients' : 'My Caregivers'; }

  sidebarLinks: { icon: string; route: string }[] = [];
  myUserId: number | null = null;
  contacts: ChatContact[] = [];
  selectedContact: ChatContact | null = null;
  isLoadingContacts = true;
  messages: Message[] = [];
  newMessage = '';
  isLoading = false;
  isSending = false;
  showTip = true;

  // ── Audio Player
  playingMsgId: number | null = null;
  audioProgress = 0;
  currentTimeLabel = '0:00';
  private currentAudio: HTMLAudioElement | null = null;

  // ── Video Call
  isVideoCallOpen = false;
  openVideoCall(): void  { this.isVideoCallOpen = true; }
  closeVideoCall(): void { this.isVideoCallOpen = false; }

  // ── Voice Call
  isVoiceCallOpen = false;
  openVoiceCall(): void  { this.isVoiceCallOpen = true; }
  closeVoiceCall(): void { this.isVoiceCallOpen = false; }

  // ── Image Preview
  previewImageUrl: string | null = null;
  openImage(url: string): void { this.previewImageUrl = url; }

  // ── Voice Recording
  private mediaRecorder!: MediaRecorder;
  private audioChunks: Blob[] = [];
  isRecording = false;

  private pollingSubscription!: Subscription;
  private shouldScrollToBottom = false;
  private imageBlobCache = new Map<string, string>();

  constructor(
    private http: HttpClient,
    private authService: AuthService,
    private notifService: NotificationService,
  ) {}

  ngOnInit(): void { this.resolveRole(); this.loadCurrentUser(); }

  ngAfterViewChecked(): void {
    if (this.shouldScrollToBottom) {
      try {
        const el = this.messagesContainer?.nativeElement;
        if (el) el.scrollTop = el.scrollHeight;
      } catch {}
      this.shouldScrollToBottom = false;
      this.cdr.detectChanges();
    }
  }

  private resolveRole(): void {
    const role = this.authService.getUserRole().toLowerCase();
    this.userRole = role === 'caregiver' ? 'caregiver' : role === 'doctor' ? 'doctor' : 'patient';
    if (this.isCaregiver) {
      this.sidebarLinks = [
        { icon: 'fas fa-home',             route: '/dashboard-caregiver' },
        { icon: 'fa-brands fa-rocketchat', route: '/chat-c' },
        { icon: 'fa-solid fa-circle-user', route: '/myprofile' },
        { icon: 'fa-solid fa-phone',       route: '/contact' },
        { icon: 'fa-solid fa-bed-pulse',   route: '/patient-cards' },
      ];
    } else if (this.isDoctor) {
      this.sidebarLinks = [
        { icon: 'fas fa-home',             route: '/dashboard' },
        { icon: 'fa-brands fa-rocketchat', route: '/chat-c' },
        { icon: 'fa-solid fa-circle-user', route: '/myprofile' },
        { icon: 'fa-solid fa-phone',       route: '/contact' },
        { icon: 'fa-solid fa-bed-pulse',   route: '/patient-cards' },
      ];
    } else {
      this.sidebarLinks = [
        { icon: 'fas fa-home',                     route: '/dashboard-p' },
        { icon: 'fas fa-pills',                    route: '/add-new-medication' },
        { icon: 'fa-solid fa-user-doctor',         route: '/doctor-cards' },
        { icon: 'fa-solid fa-hands-holding-heart', route: '/caregiver-cards' },
        { icon: 'fa-brands fa-rocketchat',         route: '/chat' },
        { icon: 'fa-solid fa-circle-user',         route: '/myprofile' },
      ];
    }
  }

  private loadCurrentUser(): void {
    try {
      const stored = localStorage.getItem('user');
      if (stored) { const obj = JSON.parse(stored); this.myUserId = obj?.id || null; }
    } catch {}
    this.loadContacts();
    this.authService.me().subscribe({
      next: (res) => { const r = res as any; this.myUserId = r?.user?.id ?? r?.id ?? this.myUserId; },
      error: () => { this.myUserId = this.authService.getUser()?.id || this.myUserId; }
    });
  }

  loadContacts(): void {
    this.isLoadingContacts = true;
    const headers = { Authorization: `Bearer ${this.authService.getToken()}` };
    if (this.isCaregiver) {
      this.http.get<any>(`${API_BASE_URL}/dashboard/caregiver`, { headers }).subscribe({
        next: (res) => {
          const list = res.data?.assigned_patients || [];
          this.contacts = list.map((r: any) => {
            const user = r.patient?.user || {};
            const name = `${user.first_name || ''} ${user.last_name || ''}`.trim() || 'Patient';
            return {
              id: user.id || r.patient_id,
              name,
              avatar: toAvatarUrl(user, `https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=F5F0FF&color=7C3AED`),
              status: 'online' as const
            };
          });
          this.isLoadingContacts = false;
          if (this.contacts.length > 0) this.selectContact(this.contacts[0]);
        },
        error: () => { this.isLoadingContacts = false; }
      });
    } else {
      this.http.get<any>(`${API_BASE_URL}/dashboard/patient`, { headers }).subscribe({
        next: (res) => {
          const contacts: any[] = [];
          const ac = res.data?.active_caregiver?.caregiver;
          if (ac) {
            const user = ac.user || {};
            const name = `${user.first_name || ''} ${user.last_name || ''}`.trim() || 'Caregiver';
            contacts.push({
              id: user.id || ac.user_id,
              name,
              avatar: toAvatarUrl(user, `https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=F5F0FF&color=7C3AED`),
              status: 'online' as const
            });
          }
          this.contacts = contacts;
          this.isLoadingContacts = false;
          if (this.contacts.length > 0) this.selectContact(this.contacts[0]);
        },
        error: () => { this.isLoadingContacts = false; }
      });
    }
  }

  selectContact(contact: ChatContact): void {
    this.selectedContact = contact;
    this.messages = [];
    this.isLoading = true;
    this.imageBlobCache.clear();
    this.pollingSubscription?.unsubscribe();
    this.loadMessages(contact);
    this.startPolling(contact);
  }

  private normalizeMessages(msgs: Message[]): Message[] {
    return msgs.map(msg => {
      if (msg.file_url) {
        msg.file_url = msg.file_url.replace(/([^:])\/\/+/g, '$1/');
      }
      if (msg.file_url && !msg.file_type) {
        const url = msg.file_url.toLowerCase();
        if (/\.(jpg|jpeg|png|gif|webp|svg)(\?|$)/.test(url))
          msg.file_type = 'image';
        else if (/\.(mp3|ogg|wav|webm|m4a|aac)(\?|$)/.test(url))
          msg.file_type = 'audio';
        else
          msg.file_type = 'file';
      }
      return msg;
    });
  }

  loadMessages(contact: ChatContact): void {
    const token = this.authService.getToken();
    this.http.get<any>(`${API_BASE_URL}/messages/${contact.id}`, {
      headers: { Authorization: `Bearer ${token}` }
    })
      .pipe(finalize(() => { this.isLoading = false; }))
      .subscribe({
        next: (res) => {
          const msgs = Array.isArray(res) ? res
            : (Array.isArray(res?.data) ? res.data
            : (Array.isArray(res?.data?.messages) ? res.data.messages : []));
          this.messages = this.normalizeMessages(msgs);
          this.shouldScrollToBottom = true;
        },
        error: (err) => {
          console.error('[ChatC] loadMessages failed:', err.status, err.error);
          this.messages = [];
        }
      });
  }

  private startPolling(contact: ChatContact): void {
    const token = this.authService.getToken();
    this.pollingSubscription = interval(5000).pipe(
      switchMap(() =>
        this.http.get<any>(`${API_BASE_URL}/messages/${contact.id}`, {
          headers: { Authorization: `Bearer ${token}` }
        }).pipe(catchError(() => of(null)))
      )
    ).subscribe((res) => {
      if (!res) return;
      const msgs: Message[] = Array.isArray(res) ? res
        : (Array.isArray(res?.data) ? res.data
        : (Array.isArray(res?.data?.messages) ? res.data.messages : []));
      if (msgs.length === 0) return;

      const normalized = this.normalizeMessages(msgs);
      const lastNew = normalized[normalized.length - 1];
      const lastOld = this.messages[this.messages.length - 1];

      if (lastNew?.id !== lastOld?.id) {
        if (lastNew?.sender_id !== this.myUserId) {
          const senderName = this.selectedContact?.name || 'Someone';
          this.notifService.push(
            'message',
            `💬 New message from ${senderName}`,
            lastNew?.body?.length > 60 ? lastNew.body.substring(0, 60) + '...' : lastNew?.body || 'Sent you a message'
          );
        }
        this.messages = normalized;
        this.shouldScrollToBottom = true;
      } else if (normalized.length !== this.messages.length) {
        this.messages = normalized;
      }
    });
  }

  sendMessage(): void {
    const content = this.newMessage.trim();
    if (!content || !this.selectedContact) return;
    if (!Array.isArray(this.messages)) this.messages = [];

    const optimistic: Message = {
      sender_id: this.myUserId ?? undefined,
      receiver_id: this.selectedContact.id,
      body: content,
      created_at: new Date().toISOString()
    };

    this.messages = [...this.messages, optimistic];
    this.newMessage = '';
    this.isSending = true;
    this.shouldScrollToBottom = true;

    const token = this.authService.getToken();
    this.http.post<any>(
      `${API_BASE_URL}/messages`,
      { receiver_id: this.selectedContact.id, body: content },
      { headers: { Authorization: `Bearer ${token}` } }
    ).pipe(finalize(() => { this.isSending = false; }))
      .subscribe({
        next: (res) => {
          const saved = res?.data || res;
          const idx = this.messages.indexOf(optimistic);
          if (idx !== -1 && saved?.id) {
            const updated = [...this.messages];
            updated[idx] = saved;
            this.messages = updated;
          }
          this.shouldScrollToBottom = true;
        },
        error: (err) => {
          console.error('[ChatC] sendMessage failed:', err.status, err.error);
          this.messages = this.messages.filter(m => m !== optimistic);
        }
      });
  }

  onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    if (!input.files?.length || !this.selectedContact) return;

    const file = input.files[0];

    const getFileType = (f: File): 'image' | 'audio' | 'file' => {
      if (f.type.startsWith('image/')) return 'image';
      if (f.type.startsWith('audio/')) return 'audio';
      return 'file';
    };

    const localType = getFileType(file);
    const localUrl = URL.createObjectURL(file);
    const optimistic: Message = {
      sender_id: this.myUserId ?? undefined,
      receiver_id: this.selectedContact.id,
      body: '',
      created_at: new Date().toISOString(),
      file_url: localUrl,
      file_type: localType,
      file_name: file.name,
    };
    this.messages = [...this.messages, optimistic];
    this.shouldScrollToBottom = true;

    const formData = new FormData();
    formData.append('file', file);
    formData.append('receiver_id', String(this.selectedContact.id));

    const token = this.authService.getToken();
    this.http.post<any>(`${API_BASE_URL}/messages/upload`, formData, {
      headers: { Authorization: `Bearer ${token}` }
    }).subscribe({
      next: (res) => {
        const saved = res?.data || res;
        if (saved) {
          if (!saved.file_type) saved.file_type = localType;
          if (!saved.file_url)  saved.file_url  = localUrl;
          if (!saved.file_name) saved.file_name = file.name;

          const idx = this.messages.indexOf(optimistic);
          if (idx !== -1) {
            const updated = [...this.messages];
            updated[idx] = saved;
            this.messages = updated;
          } else {
            this.messages = [...this.messages, saved];
          }
        }
        this.shouldScrollToBottom = true;
      },
      error: (err) => {
        console.error('[ChatC] Upload failed:', err);
        this.messages = this.messages.filter(m => m !== optimistic);
        URL.revokeObjectURL(localUrl);
      }
    });

    input.value = '';
  }

  toggleAudio(msg: Message): void {
    if (!msg.file_url) return;

    if (this.playingMsgId === msg.id) {
      this.currentAudio?.pause();
      this.playingMsgId = null;
      this.audioProgress = 0;
      this.currentTimeLabel = '0:00';
      this.currentAudio = null;
      this.cdr.detectChanges();
      return;
    }

    this.currentAudio?.pause();
    this.currentAudio = null;
    this.audioProgress = 0;
    this.currentTimeLabel = '0:00';

    const cleanUrl = msg.file_url.replace(/([^:])\/\/+/g, '$1/');
    const audio = new Audio(cleanUrl);
    this.currentAudio = audio;
    this.playingMsgId = msg.id ?? null;

    audio.ontimeupdate = () => {
      if (audio.duration && !isNaN(audio.duration)) {
        this.audioProgress = (audio.currentTime / audio.duration) * 100;
        const m = Math.floor(audio.currentTime / 60);
        const s = Math.floor(audio.currentTime % 60);
        this.currentTimeLabel = `${m}:${s.toString().padStart(2, '0')}`;
        this.cdr.detectChanges();
      }
    };

    audio.onended = () => {
      this.playingMsgId = null;
      this.audioProgress = 0;
      this.currentTimeLabel = '0:00';
      this.currentAudio = null;
      this.cdr.detectChanges();
    };

    audio.onerror = () => {
      console.error('[ChatC] Audio error for URL:', cleanUrl);
      this.playingMsgId = null;
      this.currentAudio = null;
      this.cdr.detectChanges();
    };

    audio.play().catch(err => {
      console.error('[ChatC] Audio play failed:', err);
      this.playingMsgId = null;
      this.currentAudio = null;
    });
  }

  seekAudio(msg: Message, event: MouseEvent): void {
    if (this.playingMsgId !== msg.id || !this.currentAudio) return;
    const bar = event.currentTarget as HTMLElement;
    const rect = bar.getBoundingClientRect();
    const ratio = (event.clientX - rect.left) / rect.width;
    this.currentAudio.currentTime = ratio * this.currentAudio.duration;
  }

  async startRecording(): Promise<void> {
    if (this.isRecording) return;
    this.isRecording = true;

    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      this.mediaRecorder = new MediaRecorder(stream);
      this.audioChunks = [];
      let sent = false;

      this.mediaRecorder.ondataavailable = (e) => {
        if (e.data.size > 0) this.audioChunks.push(e.data);
      };

      this.mediaRecorder.onstop = () => {
        stream.getTracks().forEach(t => t.stop());
        if (sent || this.audioChunks.length === 0) return;
        sent = true;
        const audioBlob = new Blob(this.audioChunks, { type: 'audio/webm' });
        this.sendVoiceMessage(audioBlob);
        this.audioChunks = [];
      };

      this.mediaRecorder.start(100);

      const stopFn = () => {
        this.stopRecording();
        document.removeEventListener('mouseup', stopFn);
        document.removeEventListener('touchend', stopFn);
      };
      document.addEventListener('mouseup', stopFn);
      document.addEventListener('touchend', stopFn);

    } catch (err: any) {
      console.warn('[ChatC] Microphone permission denied:', err);
      this.isRecording = false;
    }
  }

  stopRecording(): void {
    if (!this.isRecording) return;
    this.isRecording = false;
    if (this.mediaRecorder && this.mediaRecorder.state === 'recording') {
      this.mediaRecorder.stop();
    }
  }

  private sendVoiceMessage(blob: Blob): void {
    if (!this.selectedContact) return;

    const localUrl = URL.createObjectURL(blob);
    const optimistic: Message = {
      sender_id: this.myUserId ?? undefined,
      receiver_id: this.selectedContact.id,
      body: '',
      created_at: new Date().toISOString(),
      file_url: localUrl,
      file_type: 'audio',
      file_name: 'voice_message.webm',
    };
    this.messages = [...this.messages, optimistic];
    this.shouldScrollToBottom = true;

    const formData = new FormData();
    formData.append('file', blob, 'voice_message.webm');
    formData.append('receiver_id', String(this.selectedContact.id));

    const token = this.authService.getToken();
    this.http.post<any>(`${API_BASE_URL}/messages/upload`, formData, {
      headers: { Authorization: `Bearer ${token}` }
    }).subscribe({
      next: (res) => {
        const saved = res?.data || res;
        if (saved) {
          if (!saved.file_type) saved.file_type = 'audio';
          if (saved.file_url) saved.file_url = saved.file_url.replace(/([^:])\/\/+/g, '$1/');

          const idx = this.messages.indexOf(optimistic);
          if (idx !== -1) {
            const updated = [...this.messages];
            updated[idx] = saved;
            this.messages = updated;
          }
        }
        this.shouldScrollToBottom = true;
      },
      error: (err) => {
        console.error('[ChatC] Voice upload failed:', err);
        this.messages = this.messages.filter(m => m !== optimistic);
        URL.revokeObjectURL(localUrl);
      }
    });
  }

  getImageUrl(fileUrl: string): string {
    if (!fileUrl) return '';
    if (fileUrl.startsWith('blob:') || fileUrl.startsWith('data:')) return fileUrl;

    fileUrl = fileUrl.replace(/([^:])\/\/+/g, '$1/');

    const cached = this.imageBlobCache.get(fileUrl);
    if (cached && cached !== 'loading') return cached;

    if (cached !== 'loading') {
      const rawPath = fileUrl
        .replace('http://https://riva-healthcare-tm.gamer.gd/storage/', '')
        .replace('http://https://riva-healthcare-tm.gamer.gd/storage/', '');
      const apiUrl = `${API_BASE_URL}/files/${rawPath}`;
      const token = this.authService.getToken();

      this.imageBlobCache.set(fileUrl, 'loading');

      this.http.get(apiUrl, {
        headers: { Authorization: `Bearer ${token}` },
        responseType: 'blob'
      }).subscribe({
        next: (blob) => {
          const objectUrl = URL.createObjectURL(blob);
          this.imageBlobCache.set(fileUrl, objectUrl);
          this.cdr.detectChanges();
        },
        error: () => {
          this.imageBlobCache.delete(fileUrl);
        }
      });
    }

    return '';
  }

  onKeyDown(e: KeyboardEvent): void {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); this.sendMessage(); }
  }

  formatTime(ts: string): string {
    try { return new Date(ts).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }); }
    catch { return ''; }
  }

  ngOnDestroy(): void { this.pollingSubscription?.unsubscribe(); }
}
