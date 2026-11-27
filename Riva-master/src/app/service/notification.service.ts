// src/app/service/notification.service.ts
import { Injectable } from '@angular/core';
import { Subject } from 'rxjs';

export interface AppNotification {
  id: string;
  type: 'medication' | 'daily-status' | 'message' | 'general';
  title: string;
  message: string;
  time: string;
  read: boolean;
}

@Injectable({ providedIn: 'root' })
export class NotificationService {
  private notifSubject = new Subject<AppNotification>();
  notification$ = this.notifSubject.asObservable();

  private _notifications: AppNotification[] = [];
  get all() { return this._notifications; }
  get unreadCount() { return this._notifications.filter(n => !n.read).length; }

  push(type: AppNotification['type'], title: string, message: string) {
    const notif: AppNotification = {
      id: Date.now().toString(),
      type, title, message,
      time: new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }),
      read: false
    };
    this._notifications.unshift(notif);
    this.notifSubject.next(notif);
  }

  markRead(id: string) {
    const n = this._notifications.find(x => x.id === id);
    if (n) n.read = true;
  }

  markAllRead() {
    this._notifications.forEach(n => n.read = true);
  }
}