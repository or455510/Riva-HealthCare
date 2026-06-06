import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { AI_ASSISTANT_ENDPOINT } from '../constants';

export interface AiAssistantHistoryItem {
  role: 'user' | 'assistant';
  content: string;
}

export interface AiAssistantRequest {
  message: string;
  personaId: string;
  personaName: string;
  history: AiAssistantHistoryItem[];
}

export interface AiAssistantResponse {
  reply: string;
}

@Injectable({ providedIn: 'root' })
export class AiAssistantService {
  constructor(private http: HttpClient) {}

  sendMessage(payload: AiAssistantRequest): Observable<AiAssistantResponse> {
    return this.http.post<Record<string, unknown>>(AI_ASSISTANT_ENDPOINT, payload).pipe(
      map((response) => {
        const data = (response['data'] && typeof response['data'] === 'object')
          ? response['data'] as Record<string, unknown>
          : response;

        return {
          reply: String(
            data['reply']
            || data['message']
            || data['answer']
            || data['content']
            || ''
          ).trim()
        };
      })
    );
  }
}
