import {
  Component, OnInit, OnDestroy, ViewChild, ElementRef,
  AfterViewChecked, CUSTOM_ELEMENTS_SCHEMA,
  ChangeDetectorRef, inject,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { AuthService } from '../../../../service/auth.service';
import { interval, Subscription, of } from 'rxjs';
import { switchMap, catchError, finalize } from 'rxjs/operators';
import { CommonModule } from '@angular/common';
import { SidebarComponent } from '../../../../components/sidebar';
import { RouterModule } from '@angular/router';
import { API_BASE_URL } from '../../../../constants';
import { NotificationService } from '../../../../service/notification.service';
import { VideoCallComponent } from '../../../../video-call/video-call.component';

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
  role: 'doctor' | 'caregiver' | 'patient';
  isGroup?: boolean;
}

const STORAGE_BASE = 'http://https://riva-healthcare-tm.gamer.gd/storage/';

function toAvatarUrl(user: any, fallback: string): string {
  return user.profile_image_url
    || (user.profile_image ? `${STORAGE_BASE}${user.profile_image}` : null)
    || fallback;
}

@Component({
  selector: 'app-chat',
  standalone: true,
  imports: [RouterModule, CommonModule, FormsModule, HttpClientModule, VideoCallComponent, SidebarComponent],
  templateUrl: './chat.component.html',
  styleUrls: ['./chat.component.css'],
  schemas: [CUSTOM_ELEMENTS_SCHEMA]
})
export class ChatComponent implements OnInit, OnDestroy, AfterViewChecked {

  @ViewChild('messagesContainer') messagesContainer!: ElementRef;
  private cdr = inject(ChangeDetectorRef);

  userRole: 'patient' | 'doctor' | 'caregiver' = 'patient';
  get isDoctor():    boolean { return this.userRole === 'doctor'; }
  get isCaregiver(): boolean { return this.userRole === 'caregiver'; }
  get isPatient():   boolean { return this.userRole === 'patient'; }
  get accentColor(): string  { return this.isCaregiver ? 'purple' : 'blue'; }

  get contactsLabel(): string {
    if (this.isDoctor)    return 'My Patients';
    if (this.isCaregiver) return 'My Patients';
    return 'My Contacts';
  }

  getContactPrefix(contact: ChatContact): string {
    return (this.isPatient && contact.role === 'doctor') ? 'Dr. ' : '';
  }

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

  // ── Group Chat
  showGroupModal       = false;
  groupName            = '';
  selectedGroupMembers: number[] = [];
  isCreatingGroup      = false;
  groupError           = '';

  // Video Call
  isVideoCallOpen = false;
  openVideoCall(): void  { this.isVideoCallOpen = true; }
  closeVideoCall(): void { this.isVideoCallOpen = false; }

  // Voice Call
  isVoiceCallOpen = false;
  openVoiceCall(): void  { this.isVoiceCallOpen = true; }
  closeVoiceCall(): void { this.isVoiceCallOpen = false; }

  // Image Preview
  previewImageUrl: string | null = null;
  openImage(url: string): void { this.previewImageUrl = url; }

  // Voice Recording
  private mediaRecorder!: MediaRecorder;
  private audioChunks: Blob[] = [];
  isRecording = false;

  private pollingSubscription!: Subscription;
  private shouldScrollToBottom = false;

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
    const roleFromService = this.authService.getUserRole?.()?.toLowerCase() || '';
    if (roleFromService) { this.userRole = this.mapRole(roleFromService); this.buildSidebar(); return; }
    try {
      for (const key of ['user', 'userData', 'authUser', 'currentUser']) {
        const str = localStorage.getItem(key);
        if (str) {
          const obj = JSON.parse(str);
          const r = (obj?.role || obj?.type || '').toLowerCase();
          if (r) { this.userRole = this.mapRole(r); this.buildSidebar(); return; }
        }
      }
    } catch {}
    this.userRole = 'patient';
    this.buildSidebar();
  }

  private mapRole(r: string): 'patient' | 'doctor' | 'caregiver' {
    if (r.includes('doctor'))    return 'doctor';
    if (r.includes('caregiver')) return 'caregiver';
    return 'patient';
  }

  private buildSidebar(): void {
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
        { icon: 'fa-brands fa-rocketchat', route: '/chat' },
        { icon: 'fa-solid fa-circle-user', route: '/myprofile' },
        { icon: 'fa-solid fa-phone',       route: '/contact' },
        { icon: 'fa-solid fa-bed-pulse',   route: '/patient-cards' },
      ];
    } else {
      this.sidebarLinks = [
        { icon: 'fas fa-home',              route: '/dashboard-p' },
        { icon: 'fas fa-pills',             route: '/add-new-medication' },
        { icon: 'fa-solid fa-user-doctor',  route: '/doctor-cards' },
        { icon: 'fa-brands fa-rocketchat',  route: '/chat' },
        { icon: 'fa-solid fa-circle-user',  route: '/myprofile' },
        { icon: 'fa-solid fa-user-nurse',   route: '/caregiver-cards' },
        { icon: 'fa-solid fa-file-medical', route: '/my-reports' },
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
    if (this.contacts.length > 0) { this.isLoadingContacts = false; return; }
    this.isLoadingContacts = true;
    const token = this.authService.getToken();
    const headers = { Authorization: `Bearer ${token}` };
    if (this.isDoctor)         this.loadDoctorPatients(headers);
    else if (this.isCaregiver) this.loadCaregiverPatients(headers);
    else                       this.loadPatientContacts(headers);
  }

  private loadDoctorPatients(headers: any): void {
    this.http.get<any>(`${API_BASE_URL}/doctor/patients`, { headers }).subscribe({
      next: (res) => {
        const arr = Array.isArray(res) ? res : (res?.data || res?.patients || []);
        const unique = arr.filter((r: any, i: number, self: any[]) =>
          self.findIndex((x: any) => x.patient_id === r.patient_id) === i);
        const patients: ChatContact[] = unique.map((r: any) => {
          const user = r.patient?.user || {};
          const name = `${user.first_name || ''} ${user.last_name || ''}`.trim() || `Patient #${r.patient_id}`;
          return {
            id: user.id || r.patient_id,
            name,
            avatar: toAvatarUrl(user, `https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=F0F4FF&color=2D5BFF`),
            status: 'online' as const,
            lastMessage: '',
            role: 'patient' as const,
            isGroup: false
          };
        });
        this.loadGroups(headers, patients);
      },
      error: (err) => { console.error('[Chat] doctor/patients failed:', err.status, err.error); this.loadGroups(headers, []); }
    });
  }

  private loadGroups(headers: any, existingContacts: ChatContact[]): void {
    this.http.get<any>(`${API_BASE_URL}/groups`, { headers }).subscribe({
      next: (res) => {
        const list = res?.data?.groups || res?.data?.data || res?.data || res?.groups || (Array.isArray(res) ? res : []);
        const groups: ChatContact[] = list.map((g: any) => ({
          id: g.id,
          name: g.name || 'Group',
          avatar: `https://ui-avatars.com/api/?name=${encodeURIComponent(g.name || 'G')}&background=EEF2FF&color=4F46E5&bold=true`,
          status: 'online' as const,
          lastMessage: g.last_message?.body || '',
          role: 'patient' as const,
          isGroup: true,
        }));
        this.contacts = [...existingContacts, ...groups];
        this.isLoadingContacts = false;
        if (this.contacts.length > 0) this.selectContact(this.contacts[0]);
      },
      error: () => {
        this.contacts = existingContacts;
        this.isLoadingContacts = false;
        if (this.contacts.length > 0) this.selectContact(this.contacts[0]);
      }
    });
  }

  private loadCaregiverPatients(headers: any): void {
    this.http.get<any>(`${API_BASE_URL}/dashboard/caregiver`, { headers })
      .pipe(finalize(() => { this.isLoadingContacts = false; }))
      .subscribe({
        next: (res) => {
          const list = res?.data?.assigned_patients || res?.data?.patients || res?.patients || (Array.isArray(res) ? res : []);
          this.contacts = list.map((r: any) => {
            const patient = r.patient || r;
            const user = patient.user || r.user || {};
            const name = `${user.first_name || ''} ${user.last_name || ''}`.trim() || 'Patient';
            return {
              id: user.id || r.patient_id || patient.id,
              name,
              avatar: toAvatarUrl(user, `https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=F5F0FF&color=7C3AED`),
              status: 'online' as const,
              lastMessage: '',
              role: 'patient' as const,
              isGroup: false
            };
          });
          if (this.contacts.length > 0) this.selectContact(this.contacts[0]);
        },
        error: (err) => { console.error('[Chat] caregiver patients failed:', err.status); }
      });
  }

  private loadPatientContacts(headers: any): void {
    let doctors: ChatContact[] = [];
    let caregivers: ChatContact[] = [];
    let doctorsDone = false;
    let caregiversDone = false;

    const tryMerge = () => {
      if (!doctorsDone || !caregiversDone) return;
      const h = { Authorization: `Bearer ${this.authService.getToken()}` };
      this.loadGroups(h, [...doctors, ...caregivers]);
    };

    this.http.get<any>(`${API_BASE_URL}/patient/my-doctors`, { headers }).subscribe({
      next: (res) => {
        const arr = Array.isArray(res) ? res : (res?.data || []);
        doctors = arr.map((d: any) => {
          const user = d.doctor?.user || {};
          const name = `${user.first_name || ''} ${user.last_name || ''}`.trim() || 'Doctor';
          return {
            id: user.id || d.doctor_id,
            name,
            avatar: toAvatarUrl(user, `https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=E6F0FF&color=2D5BFF`),
            status: 'online' as const,
            lastMessage: '',
            role: 'doctor' as const,
            isGroup: false
          };
        });
        doctorsDone = true; tryMerge();
      },
      error: (err) => { console.error('[Chat] my-doctors failed:', err.status); doctorsDone = true; tryMerge(); }
    });

    this.http.get<any>(`${API_BASE_URL}/dashboard/patient`, { headers }).subscribe({
      next: (res) => {
        const data = res?.data || res;
        const ac = data?.active_caregiver?.caregiver;
        if (ac) {
          const user = ac.user || {};
          const name = `${user.first_name || ''} ${user.last_name || ''}`.trim() || 'Caregiver';
          caregivers.push({
            id: user.id || ac.user_id,
            name,
            avatar: toAvatarUrl(user, `https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=F5F0FF&color=7C3AED`),
            status: 'online' as const,
            lastMessage: '',
            role: 'caregiver' as const,
            isGroup: false
          });
        }
        caregiversDone = true; tryMerge();
      },
      error: (err) => { console.error('[Chat] dashboard/patient failed:', err.status); caregiversDone = true; tryMerge(); }
    });
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
      // ✅ تنظيف الـ URL من الـ double slashes
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
    const url = contact.isGroup
      ? `${API_BASE_URL}/groups/${contact.id}/messages`
      : `${API_BASE_URL}/messages/${contact.id}`;
    this.http.get<any>(url, { headers: { Authorization: `Bearer ${token}` } })
      .pipe(finalize(() => { this.isLoading = false; }))
      .subscribe({
        next: (res) => {
          const msgs = Array.isArray(res) ? res
            : (Array.isArray(res?.data) ? res.data
            : (Array.isArray(res?.data?.messages) ? res.data.messages : []));
          this.messages = this.normalizeMessages(msgs);
          this.shouldScrollToBottom = true;
        },
        error: (err) => { console.error('[Chat] loadMessages failed:', err.status, err.error); this.messages = []; }
      });
  }

  private startPolling(contact: ChatContact): void {
    const token = this.authService.getToken();
    const url = contact.isGroup
      ? `${API_BASE_URL}/groups/${contact.id}/messages`
      : `${API_BASE_URL}/messages/${contact.id}`;

    this.pollingSubscription = interval(5000).pipe(
      switchMap(() => this.http.get<any>(url, { headers: { Authorization: `Bearer ${token}` } })
        .pipe(catchError(() => of(null))))
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
    const url = this.selectedContact.isGroup
      ? `${API_BASE_URL}/groups/${this.selectedContact.id}/messages`
      : `${API_BASE_URL}/messages`;
    const body = this.selectedContact.isGroup
      ? { body: content }
      : { receiver_id: this.selectedContact.id, body: content };

    this.http.post<any>(url, body, { headers: { Authorization: `Bearer ${token}` } })
      .pipe(finalize(() => { this.isSending = false; }))
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
          console.error('[Chat] sendMessage failed:', err.status, err.error);
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
        console.error('Upload failed:', err);
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

    // ✅ تنظيف الـ URL من double slashes
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
      console.error('Audio error for URL:', cleanUrl);
      this.playingMsgId = null;
      this.currentAudio = null;
      this.cdr.detectChanges();
    };

    audio.play().catch(err => {
      console.error('Audio play failed:', err);
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
      console.warn('Microphone permission denied:', err);
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

  // ✅ optimistic preview فوري
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

        // ✅ استبدل الـ optimistic بالرسالة الحقيقية
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
      console.error('Voice upload failed:', err);
      this.messages = this.messages.filter(m => m !== optimistic);
      URL.revokeObjectURL(localUrl);
    }
  });
}

  toggleGroupMember(id: number): void {
    const idx = this.selectedGroupMembers.indexOf(id);
    if (idx === -1) this.selectedGroupMembers = [...this.selectedGroupMembers, id];
    else this.selectedGroupMembers = this.selectedGroupMembers.filter(m => m !== id);
  }

  createGroup(): void {
    this.groupError = '';
    if (!this.groupName.trim()) { this.groupError = 'Please enter a group name.'; return; }
    if (this.selectedGroupMembers.length < 1) { this.groupError = 'Please select at least one patient.'; return; }
    this.isCreatingGroup = true;
    const token = this.authService.getToken();
    this.http.post<any>(
      `${API_BASE_URL}/groups`,
      { name: this.groupName.trim(), patient_ids: this.selectedGroupMembers },
      { headers: { Authorization: `Bearer ${token}` } }
    ).pipe(finalize(() => { this.isCreatingGroup = false; }))
      .subscribe({
        next: (res) => {
          this.showGroupModal = false;
          this.groupName = '';
          this.selectedGroupMembers = [];
          this.groupError = '';
          const group = res?.data?.group || res?.data || res;
          if (group?.id) {
            const groupContact: ChatContact = {
              id: group.id,
              name: group.name || 'Group',
              avatar: `https://ui-avatars.com/api/?name=${encodeURIComponent(group.name || 'G')}&background=EEF2FF&color=4F46E5&bold=true`,
              status: 'online',
              lastMessage: '',
              role: 'patient',
              isGroup: true
            };
            this.contacts = [groupContact, ...this.contacts];
            this.selectContact(groupContact);
          }
        },
        error: (err) => {
          console.error('[Chat] createGroup failed:', err);
          this.groupError = err?.error?.message || 'Failed to create group. Please try again.';
        }
      });
  }

  onKeyDown(e: KeyboardEvent): void {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); this.sendMessage(); }
  }

  formatTime(ts: string): string {
    try { return new Date(ts).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }); }
    catch { return ''; }
  }

  ngOnDestroy(): void { this.pollingSubscription?.unsubscribe(); }

  private imageBlobCache = new Map<string, string>();

  getImageUrl(fileUrl: string): string {
    if (!fileUrl) return '';
    if (fileUrl.startsWith('blob:') || fileUrl.startsWith('data:')) return fileUrl;

    // ✅ تنظيف double slashes
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
}
