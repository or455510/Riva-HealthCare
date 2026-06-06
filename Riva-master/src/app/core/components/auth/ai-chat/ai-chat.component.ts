import {
  AfterViewChecked,
  Component,
  CUSTOM_ELEMENTS_SCHEMA,
  ElementRef,
  OnDestroy,
  ViewChild
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterModule } from '@angular/router';
import { Subscription, finalize } from 'rxjs';
import {
  AiAssistantHistoryItem,
  AiAssistantService
} from '../../../../service/ai-assistant.service';
import { SidebarComponent } from '../../../../components/sidebar';

interface AiPersona {
  id: string;
  name: string;
  specialty: string;
  avatar: string;
  welcome: string;
}

interface AiChatMessage {
  role: 'user' | 'assistant';
  text: string;
  time: Date;
}

type ConversationMap = Record<string, AiChatMessage[]>;

@Component({
  selector: 'app-ai-chat',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterModule, SidebarComponent],
  templateUrl: './ai-chat.component.html',
  styleUrls: ['./ai-chat.component.css'],
  schemas: [CUSTOM_ELEMENTS_SCHEMA]
})
export class AiChatComponent implements AfterViewChecked, OnDestroy {
  @ViewChild('messagesPanel') messagesPanel?: ElementRef<HTMLDivElement>;

  personas: AiPersona[] = [
    {
      id: 'primary-care',
      name: 'Primary Care Robot',
      specialty: 'General Health',
      avatar: '/primary_robot.png',
      welcome: 'Ask about symptoms, daily care routines, medication habits, or when to contact your care team.'
    },
    {
      id: 'cardiology',
      name: 'Cardio Robot',
      specialty: 'Heart Support',
      avatar: '/cardio_robot.png',
      welcome: 'I can help explain heart-health tracking, warning signs, blood pressure habits, and follow-up questions.'
    },
    {
      id: 'dermatology',
      name: 'Skin Care Robot',
      specialty: 'Skin Guidance',
      avatar: '/derm_robot.png',
      welcome: 'Ask about skin-care monitoring, rash notes, photo tracking, and questions to prepare for your doctor.'
    },
    {
      id: 'pediatrics',
      name: 'Pediatric Robot',
      specialty: 'Child Health',
      avatar: '/pediatric_robot.png',
      welcome: 'I can help families organize child symptoms, routines, and non-emergency questions for clinicians.'
    }
  ];

  selectedPersona = this.personas[0];
  draft = '';
  isSending = false;
  shouldScroll = true;
  errorMessage = '';

  conversations: ConversationMap = this.personas.reduce((acc, persona) => {
    acc[persona.id] = [this.createGreeting(persona)];
    return acc;
  }, {} as ConversationMap);

  private sendSubscription?: Subscription;

  constructor(private aiAssistantService: AiAssistantService) {}

  get messages(): AiChatMessage[] {
    return this.conversations[this.selectedPersona.id] || [];
  }

  ngAfterViewChecked(): void {
    if (!this.shouldScroll || !this.messagesPanel?.nativeElement) return;
    const panel = this.messagesPanel.nativeElement;
    panel.scrollTop = panel.scrollHeight;
    this.shouldScroll = false;
  }

  selectPersona(persona: AiPersona): void {
    if (persona.id === this.selectedPersona.id) return;
    this.selectedPersona = persona;
    this.errorMessage = '';
    this.ensureConversation(persona);
    this.shouldScroll = true;
  }

  sendMessage(): void {
    const message = this.draft.trim();
    if (!message || this.isSending) return;
    const activePersona = this.selectedPersona;
    const activePersonaId = activePersona.id;

    this.setCurrentMessages([
      ...this.messages,
      { role: 'user', text: message, time: new Date() }
    ]);
    this.draft = '';
    this.isSending = true;
    this.errorMessage = '';
    this.shouldScroll = true;

    const history: AiAssistantHistoryItem[] = this.messages
      .slice(-10)
      .map(item => ({ role: item.role, content: item.text }));

    this.sendSubscription?.unsubscribe();
    this.sendSubscription = this.aiAssistantService.sendMessage({
      message,
      personaId: activePersona.id,
      personaName: activePersona.name,
      history
    })
      .pipe(finalize(() => {
        this.isSending = false;
        this.shouldScroll = true;
      }))
      .subscribe({
        next: (response) => {
          this.setMessagesForPersona(activePersonaId, [
            ...this.conversations[activePersonaId],
            {
              role: 'assistant',
              text: response.reply || 'I received your message, but the assistant returned an empty response.',
              time: new Date()
            }
          ]);
        },
        error: () => {
          this.errorMessage = 'I could not reach the Riva AI agent. Please check the Laravel API and Gemini key, then try again.';
          this.setMessagesForPersona(activePersonaId, [
            ...this.conversations[activePersonaId],
            {
              role: 'assistant',
              text: this.errorMessage,
              time: new Date()
            }
          ]);
        }
      });
  }

  clearChat(): void {
    this.conversations[this.selectedPersona.id] = [this.createGreeting(this.selectedPersona)];
    this.errorMessage = '';
    this.shouldScroll = true;
  }

  onComposerKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      this.sendMessage();
    }
  }

  trackPersona(_: number, persona: AiPersona): string {
    return persona.id;
  }

  trackMessage(index: number): number {
    return index;
  }

  ngOnDestroy(): void {
    this.sendSubscription?.unsubscribe();
  }

  private ensureConversation(persona: AiPersona): void {
    if (!this.conversations[persona.id]) {
      this.conversations[persona.id] = [this.createGreeting(persona)];
    }
  }

  private setCurrentMessages(messages: AiChatMessage[]): void {
    this.conversations[this.selectedPersona.id] = messages;
  }

  private setMessagesForPersona(personaId: string, messages: AiChatMessage[]): void {
    this.conversations[personaId] = messages;
  }

  private createGreeting(persona: AiPersona): AiChatMessage {
    return {
      role: 'assistant',
      text: `Hello, I am ${persona.name}. ${persona.welcome} Riva AI Assistant provides general guidance and platform support only.`,
      time: new Date()
    };
  }
}
