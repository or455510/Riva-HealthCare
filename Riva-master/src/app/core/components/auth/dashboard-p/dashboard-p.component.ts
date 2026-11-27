import { Component, CUSTOM_ELEMENTS_SCHEMA, OnInit, OnDestroy } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { finalize } from 'rxjs/operators';
import { Router } from '@angular/router';
import { AuthService } from '../../../../service/auth.service';
import { API_BASE_URL } from '../../../../constants';
import { MissedMedicationService } from '../../../../service/missed-medication.service';
import { NotificationService } from '../../../../service/notification.service';
import { LogoutButtonComponent } from '../../../../components/logout-button';
import { SidebarComponent } from '../../../../components/sidebar';

interface DiseaseMarker {
  key: string;
  label: string;
  description: string;
  top: string;
  left: string;
  colorClass: string;
  pingClass: string;
}

type DiseaseKey = 'diabetes' | 'hypertension' | 'heart' | 'cancer';

@Component({
  selector: 'app-dashboard-p',
  standalone: true,
  imports: [FormsModule, CommonModule, RouterModule, HttpClientModule, LogoutButtonComponent, SidebarComponent],
  templateUrl: './dashboard-p.component.html',
  styleUrls: ['./dashboard-p.component.css'],
  schemas: [CUSTOM_ELEMENTS_SCHEMA]
})
export class DashboardPComponent implements OnInit, OnDestroy {

  painLevel: number = 5;
  selectedMood: number | null = 5;
  notes: string = '';
  sleepQuality: string = 'fair';

  uploadedFile: File | null = null;
  fileName: string = '';
  filePreview: string | null = null;

  isLoading: boolean = false;
  isLoadingMed: boolean = false;
  isEmergencyLoading: boolean = false;
  errorMessage: string = '';
  successMessage: string = '';

  currentpatient = { name: '', status: 'Active', avatar: '' };
  diseaseMarkers: DiseaseMarker[] = [];
  todayMedication: any = null;
  medicationDoses: any[] = [];
  pendingDoses: any[] = [];
  completedDoses: any[] = [];
  missedDoses: any[] = [];
  weekdayStatuses: any[] = [];
  medicationSummary: any = { adherence_percentage: 0, complete: false, pending: 0, total: 0 };
  allCompletedToday = false;
  treatmentCompleted = false;
  isMissed: boolean = false;
  missedReported: boolean = false;
  loadingDoseIds = new Set<number>();

  private missedCheckInterval: any;

  moods = [
    { id: 1,  emoji: '🤩', level: 1,  value: 'great'    },
    { id: 3,  emoji: '😃', level: 3,  value: 'good'     },
    { id: 5,  emoji: '😊', level: 5,  value: 'okay'     },
    { id: 7,  emoji: '😔', level: 7,  value: 'low'      },
    { id: 10, emoji: '😠', level: 10, value: 'terrible' },
  ];

  week: any[] = [];

  constructor(
    private http: HttpClient,
    private authService: AuthService,
    private router: Router,
    private missedMedService: MissedMedicationService,
    private notifService: NotificationService   // ✅ أضف ده

  ) {}

  private get authHeaders() {
    return { Authorization: `Bearer ${this.authService.getToken()}` };
  }

  ngOnInit(): void {
    this.loadPatientProfile();
    this.loadTodayMedication();

    this.missedCheckInterval = setInterval(() => {
      this.checkIfMissed();
    }, 60000);
  }

  ngOnDestroy(): void {
    if (this.missedCheckInterval) clearInterval(this.missedCheckInterval);
  }

  openAiAssistant(): void {
    this.router.navigate(['/ai-chat']);
  }

  loadPatientProfile(): void {
    this.http.get<any>(`${API_BASE_URL}/profile`, {
      headers: this.authHeaders
    }).subscribe({
      next: (res) => {
        const user = res.user || res.data?.user || res.data || res;
        const roleProfile = res.role_profile || res.data?.role_profile || {};
        this.currentpatient.name   = `${user.first_name || ''} ${user.last_name || ''}`.trim() || 'Patient';
this.currentpatient.avatar = user.profile_image_url
  || (user.profile_image ? `http://127.0.0.1:8000/storage/${user.profile_image}` : null)
  || `https://ui-avatars.com/api/?name=${encodeURIComponent(this.currentpatient.name)}&background=E0F2FE&color=0EA5E9`;
        this.diseaseMarkers = this.buildDiseaseMarkers(roleProfile?.chronic_conditions);
      },
      error: () => {}
    });
  }

  private buildDiseaseMarkers(rawConditions: unknown): DiseaseMarker[] {
    const values = this.parseConditions(rawConditions);
    const markerMap: Record<DiseaseKey, DiseaseMarker> = {
      diabetes: {
        key: 'diabetes',
        label: 'Diabetes',
        description: 'Monitor glucose, diet, and medication adherence.',
        top: '54%',
        left: '46%',
        colorClass: 'bg-blue-500',
        pingClass: 'bg-blue-400',
      },
      hypertension: {
        key: 'hypertension',
        label: 'Hypertension',
        description: 'Maintain blood pressure below the recommended threshold.',
        top: '27%',
        left: '45%',
        colorClass: 'bg-orange-500',
        pingClass: 'bg-orange-400',
      },
      heart: {
        key: 'heart',
        label: 'Heart Disease',
        description: 'Track cardiac symptoms, activity tolerance, and vitals.',
        top: '31%',
        left: '50%',
        colorClass: 'bg-red-500',
        pingClass: 'bg-red-400',
      },
      cancer: {
        key: 'cancer',
        label: 'Cancer',
        description: 'Follow treatment notes, fatigue, pain, and warning signs.',
        top: '42%',
        left: '42%',
        colorClass: 'bg-purple-500',
        pingClass: 'bg-purple-400',
      },
    };

    const uniqueKeys = Array.from(new Set(
      values
      .map(value => this.normalizeCondition(value))
      .filter((value): value is DiseaseKey => value !== null)
    ));

    return uniqueKeys.map(value => markerMap[value]);
  }

  private parseConditions(rawConditions: unknown): string[] {
    if (Array.isArray(rawConditions)) {
      return rawConditions.map(String);
    }

    if (typeof rawConditions !== 'string') {
      return [];
    }

    const trimmed = rawConditions.trim();
    if (!trimmed) return [];

    try {
      const parsed = JSON.parse(trimmed);
      if (Array.isArray(parsed)) {
        return parsed.map(String);
      }
    } catch {
      // Stored patient profiles commonly use comma-separated condition names.
    }

    return trimmed.split(/[,|;]/).map(value => value.trim()).filter(Boolean);
  }

  private normalizeCondition(value: string): DiseaseKey | null {
    const normalized = value.toLowerCase().replace(/[_-]/g, ' ').trim();
    if (normalized.includes('diabetes')) return 'diabetes';
    if (normalized.includes('hypertension') || normalized.includes('blood pressure')) return 'hypertension';
    if (normalized.includes('heart') || normalized.includes('cardiac')) return 'heart';
    if (normalized.includes('cancer') || normalized.includes('oncology')) return 'cancer';
    return null;
  }

loadTodayMedication(): void {
  this.isLoadingMed = true;
  this.http.get<any>(`${API_BASE_URL}/patient/medications/today`, {
    headers: this.authHeaders
  }).subscribe({
    next: (res) => {
      const schedule = res.data || res || {};
      this.applyMedicationSchedule(schedule);
      this.isLoadingMed = false;
            console.log('todayMedication:', this.todayMedication); // ← ضيف ده

      this.scheduleMedicationReminder(); // ✅ أضف ده
    },
    error: () => { this.isLoadingMed = false; }
  });
}

private reminderSent = false;

scheduleMedicationReminder(): void {
  if (!this.todayMedication?.schedule_time || this.reminderSent) return;

  const now = new Date();
  const [hours, minutes] = this.todayMedication.schedule_time.split(':').map(Number);
  const scheduleMs = new Date().setHours(hours, minutes, 0, 0);
  const reminderMs = scheduleMs - 30 * 60 * 1000;
  const delay = reminderMs - now.getTime();

  const name = this.todayMedication?.drug_name
             || this.todayMedication?.medication_name
             || 'your medication';

  const sendReminder = () => {
    this.reminderSent = true;

    // ✅ Toast notification
    this.notifService.push(
      'medication',
      '💊 Medication Reminder',
      `Time to take ${name} in 30 minutes (due at ${this.todayMedication.schedule_time})`
    );

    // ✅ Email reminder
    this.http.post(`${API_BASE_URL}/medication-reminder/send`, {
      medication_name: name,
      schedule_time: this.todayMedication.schedule_time
    }, { headers: this.authHeaders }).subscribe({
      next: () => console.log('📧 Reminder email sent'),
      error: (err) => console.error('Email failed:', err)
    });
  };

  if (delay <= 0) {
    sendReminder();
  } else {
    setTimeout(() => sendReminder(), delay);
  }
}
  checkIfMissed(): void {
    this.loadTodayMedication();
  }

  reportMissed(): void {
    this.http.post(`${API_BASE_URL}/medication-logs/detect-missed`, {}, { headers: this.authHeaders })
      .subscribe({ next: () => this.loadTodayMedication(), error: () => {} });
  }

  get adherence(): number {
    return Math.round(this.medicationSummary?.adherence_percentage ?? 0);
  }

  get patientInitials(): string {
    if (!this.currentpatient?.name) return '';
    return this.currentpatient.name
      .split(' ').map((w: string) => w[0]).join('').toUpperCase().slice(0, 2);
  }

  private getMoodValue(): string {
    return this.moods.find(m => m.id === this.selectedMood)?.value ?? 'okay';
  }

  onFileChange(event: any) {
    const file = event.target.files[0];
    if (!file) return;
    this.uploadedFile = file;
    this.fileName = file.name;
    if (file.type.startsWith('image/')) {
      const reader = new FileReader();
      reader.onload = (e: any) => (this.filePreview = e.target.result);
      reader.readAsDataURL(file);
    } else {
      this.filePreview = null;
    }
  }

  removeFile() {
    this.uploadedFile = null;
    this.fileName = '';
    this.filePreview = null;
  }

  selectMood(mood: any) {
    this.selectedMood = mood.id;
    this.painLevel = mood.level;
  }

  updateMoodFromSlider() {
    const closest = this.moods.reduce((prev, curr) =>
      Math.abs(curr.level - this.painLevel) < Math.abs(prev.level - this.painLevel) ? curr : prev
    );
    this.selectedMood = closest.id;
  }

  onTakeMedication(dose = this.todayMedication) {
    if (!dose?.id || this.loadingDoseIds.has(dose.id)) return;
    this.loadingDoseIds.add(dose.id);
    this.http.post(`${API_BASE_URL}/patient/medications/doses/${dose.id}/take`, {},
      { headers: this.authHeaders }
    ).pipe(finalize(() => this.loadingDoseIds.delete(dose.id))).subscribe({
      next: (res: any) => {
        this.isMissed = false;
        this.missedReported = true;
        this.applyMedicationSchedule(res?.data?.schedule);
      },
      error: (err) => this.errorMessage = err?.error?.message || 'Unable to confirm this dose.'
    });
  }

  remindLater(dose = this.todayMedication) {
    if (!dose?.id || this.loadingDoseIds.has(dose.id)) return;
    this.loadingDoseIds.add(dose.id);
    this.http.post(`${API_BASE_URL}/patient/medications/doses/${dose.id}/snooze`, { minutes: 30 },
      { headers: this.authHeaders }
    ).pipe(finalize(() => this.loadingDoseIds.delete(dose.id))).subscribe({
      next: (res: any) => {
        this.applyMedicationSchedule(res?.data?.schedule);
        this.successMessage = 'Reminder snoozed for 30 minutes.';
      },
      error: (err) => this.errorMessage = err?.error?.message || 'Unable to snooze this dose.'
    });
  }

  toggleDay(day: any) {}

  private applyMedicationSchedule(schedule: any): void {
    if (!schedule) {
      this.loadTodayMedication();
      return;
    }

    this.pendingDoses = schedule.pending_doses || [];
    this.completedDoses = schedule.completed_doses || [];
    this.missedDoses = schedule.missed_doses || [];
    this.medicationDoses = schedule.doses || [...this.pendingDoses, ...this.completedDoses, ...this.missedDoses];
    this.medicationSummary = schedule.summary || this.medicationSummary;
    this.weekdayStatuses = schedule.weekday_statuses || [];
    this.week = this.weekdayStatuses.map((d: any) => ({
      day: d.label || d.day,
      status: d.status,
      taken: d.status === 'completed',
      isFuture: d.is_future,
    }));
    this.allCompletedToday = !!schedule.all_completed_today;
    this.treatmentCompleted = !!schedule.treatment_completed;
    this.isMissed = this.missedDoses.length > 0;
    this.missedReported = this.isMissed;
    this.todayMedication = schedule.current_due || this.pendingDoses[0] || null;
  }

  sendEmergencyAlert() {
    if (this.isEmergencyLoading) return;
    if (!confirm('Are you sure you want to send an emergency alert?')) return;

    this.errorMessage = '';
    this.successMessage = '';
    this.isEmergencyLoading = true;

    this.http.post(`${API_BASE_URL}/emergency-alert`, {
      message: this.notes?.trim() || 'Emergency alert: Patient needs immediate care.',
      pain_level: this.painLevel,
      symptoms: this.notes?.trim() || null,
    }, { headers: this.authHeaders })
    .pipe(finalize(() => (this.isEmergencyLoading = false)))
    .subscribe({
      next: (res: any) => {
        this.successMessage = res?.message || 'Emergency alert sent to your care team.';
      },
      error: (err) => {
        this.errorMessage = err.error?.message || 'Failed to send emergency alert.';
      },
    });
  }

  getMoodEmoji(): string {
    return this.moods.find(m => m.id === this.selectedMood)?.emoji ?? '';
  }

  // ✅ FIXED: submitCheckIn now sends FormData to support file uploads
  // and uses 1/0 for medication_taken to avoid boolean validation error
  submitCheckIn() {
    if (this.isLoading) return;

    this.errorMessage  = '';
    this.successMessage = '';

    const medTaken = this.week.find(d => d.day === 'M')?.taken ?? false;

    const formData = new FormData();
    formData.append('mood',             this.getMoodValue());
    formData.append('pain_level',       String(this.painLevel));
    formData.append('sleep_quality',    this.sleepQuality);
    formData.append('symptoms',         this.notes);
    formData.append('notes',            this.notes);
    // ✅ FIX 1: send 1/0 instead of "true"/"false" — Laravel boolean validation accepts 1/0
    formData.append('medication_taken', medTaken ? '1' : '0');

    // ✅ FIX 2: attach the actual file so it reaches the doctor
    if (this.uploadedFile) {
      formData.append('attachment', this.uploadedFile, this.uploadedFile.name);
    }

    this.isLoading = true;

    // ✅ FIX 3: do NOT set Content-Type manually — browser sets it with the correct boundary
    this.http.post(`${API_BASE_URL}/daily-status`, formData, {
      headers: { Authorization: `Bearer ${this.authService.getToken()}` }
    })
    .pipe(finalize(() => (this.isLoading = false)))
    .subscribe({
     // في الـ next بتاع submitCheckIn
next: () => {
  this.successMessage = 'Daily report submitted successfully! ✅';

  // ✅ أضف ده
  this.notifService.push(
    'daily-status',
    '📋 Daily Status Sent',
    `${this.currentpatient.name}'s daily health report has been submitted successfully.`
  );

  this.resetForm();
},
      error: (err) => {
        if (err.status === 422) {
          const errors = err.error?.errors;
          this.errorMessage = errors
            ? Object.values(errors).flat().join(' | ')
            : 'Validation error.';
        } else {
          this.errorMessage = err.error?.message || 'Something went wrong.';
        }
      }
    });
  }

  resetForm() {
    this.notes = '';
    this.sleepQuality = 'fair';
    this.painLevel = 5;
    this.selectedMood = 5;
    this.removeFile();
  }
}
