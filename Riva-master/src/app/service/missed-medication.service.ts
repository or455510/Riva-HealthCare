import { Injectable } from '@angular/core';
import { BehaviorSubject } from 'rxjs';

export interface MissedMedNotification {
  id: number;
  medicationName: string;
  scheduleTime: string;
  reportedAt: string;
}

@Injectable({ providedIn: 'root' })
export class MissedMedicationService {
  private missedList = new BehaviorSubject<MissedMedNotification[]>([]);
  missed$ = this.missedList.asObservable();

  addMissed(med: MissedMedNotification) {
    const current = this.missedList.getValue();
    const alreadyExists = current.some(m => m.id === med.id);
    if (!alreadyExists) {
      this.missedList.next([med, ...current]);
    }
  }

  clearAll() {
    this.missedList.next([]);
  }
}