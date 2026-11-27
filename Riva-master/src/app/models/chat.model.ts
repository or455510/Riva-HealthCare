export interface Message {
  role: 'user' | 'model';
  parts: any[];
  timestamp?: Date;
  id?: string;
  attachments?: Attachment[];
}

export interface ChatSession {
  id: string;
  doctorId: string;
  doctorName: string;
  doctorAvatar: string;
  doctorSpecialty: string;
  isOnline: boolean;
  lastMessage?: string;
  lastMessageTime?: Date;
  messages: Message[];
  unreadCount?: number;
  systemInstruction?: string;
}

export interface Doctor {
  id: string;
  name: string;
  avatar: string;
  specialty: string;
  isOnline: boolean;
  lastActive?: Date;
}

export interface Attachment {
  type: 'pdf' | 'image' | 'file';
  url?: string;
  name: string;
  size?: string;
  base64?: string;
  mimeType?: string;
}

export interface ChatConfig {
  showHeader: boolean;
  showVoiceButton: boolean;
  showAttachmentButton: boolean;
  showSecurityNote: boolean;
  showBanner: boolean;
  bannerTitle: string;
  bannerMessage: string;
  inputPlaceholder: string;
  securityNote: string;
}
