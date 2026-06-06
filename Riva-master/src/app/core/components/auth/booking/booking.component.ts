import { CommonModule } from '@angular/common';
import { Component, ElementRef, ViewChild, signal, computed, OnDestroy, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { finalize } from 'rxjs';
import { API_BASE_URL } from '../../../../constants';
import { SidebarComponent } from '../../../../components/sidebar';
import { AuthService } from '../../../../service/auth.service';

export type ConsultationType = 'online' | 'inperson' | 'daily' | 'livein';
export type BookingStep = 1 | 2 | 3;
export type PaymentMethod = 'card' | 'cash';
export type BookingMode = 'doctor' | 'caregiver';

@Component({
  selector: 'app-booking',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterModule, HttpClientModule, SidebarComponent],
  templateUrl: './booking.component.html',
  styleUrls: ['./booking.component.css'],
})
export class BookingComponent implements OnInit, OnDestroy {
  @ViewChild('fileInput') fileInputRef!: ElementRef<HTMLInputElement>;

  // ── Mode ──────────────────────────────────────────────────
  bookingMode: BookingMode = 'doctor';
  get isCaregiver(): boolean { return this.bookingMode === 'caregiver'; }
  get isDoctor():    boolean { return this.bookingMode === 'doctor';    }

  // ── Doctor ────────────────────────────────────────────────
  doctorId: number | null = null;
  doctor = signal<any>(null);
  isLoadingDoctor = false;

  get doctorName(): string {
    if (!this.doctor()) return 'Doctor';
    const d = this.doctor();
    // ✅ جرب الـ _normalizedName الأول (من localStorage)
    if (d._normalizedName) return d._normalizedName;
    const user = d.user || {};
    return `${user.first_name || d.first_name || ''} ${user.last_name || d.last_name || ''}`.trim() || d.name || 'Doctor';
  }
  get doctorSpecialty(): string { return this.doctor()?.specialty || 'Specialist'; }
  get doctorBio(): string       { return this.doctor()?.about || this.doctor()?.bio || ''; }
  get doctorFee(): number       { return Number(this.doctor()?.fee ?? 0); }
  get doctorAvatar(): string {
    const d = this.doctor();
    if (!d) return `https://ui-avatars.com/api/?name=Doctor&background=0052FF&color=fff&size=128&bold=true&rounded=true`;
    // ✅ جرب الـ _normalizedPhoto الأول (من localStorage — full URL)
    const photo = d._normalizedPhoto
      || this.resolveImageUrl(d.user?.profile_image_url)
      || this.resolveImageUrl(d.user?.profile_image)
      || this.resolveImageUrl(d.profile_image);
    return photo || `https://ui-avatars.com/api/?name=${encodeURIComponent(this.doctorName)}&background=0052FF&color=fff&size=128&bold=true&rounded=true`;
  }
  get doctorAvailableDays(): string[] {
    const days = this.doctor()?.available_days || '';
    return days ? days.split(',').map((d: string) => d.trim()).filter(Boolean) : [];
  }
  get doctorExperience(): number | null { return this.doctor()?.years_of_experience ?? null; }

  // ── DAY MAP ───────────────────────────────────────────────
  private readonly DAY_MAP: Record<string, number> = {
    'sun': 0, 'sunday': 0,
    'mon': 1, 'monday': 1,
    'tue': 2, 'tuesday': 2,
    'wed': 3, 'wednesday': 3,
    'thu': 4, 'thursday': 4,
    'fri': 5, 'friday': 5,
    'sat': 6, 'saturday': 6,
  };

  get availableDayNumbers(): number[] {
    const days = this.isCaregiver
      ? (this.caregiver()?.available_days || '')
      : (this.doctor()?.available_days || '');
    if (!days) return [0, 1, 2, 3, 4, 5, 6];
    return days.split(',')
      .map((d: string) => this.DAY_MAP[d.trim().toLowerCase()])
      .filter((n: number | undefined) => n !== undefined);
  }

  // ── Caregiver ─────────────────────────────────────────────
  caregiverId: number | null = null;
  caregiver = signal<any>(null);
  isLoadingCaregiver = false;

  get caregiverName(): string {
    if (!this.caregiver()) return 'Caregiver';
    const c = this.caregiver();
    // ✅ جرب الـ _normalizedName الأول (من localStorage)
    if (c._normalizedName) return c._normalizedName;
    const user = c.user || {};
    return `${user.first_name || c.first_name || ''} ${user.last_name || c.last_name || ''}`.trim() || c.name || 'Caregiver';
  }
  get caregiverSpecialty(): string  { return this.caregiver()?.specialty || 'General Care'; }
  get caregiverBio(): string        { return this.caregiver()?.about || this.caregiver()?.bio || ''; }
  get caregiverSalary(): number     { return Number(this.caregiver()?.salary ?? 0); }
  get caregiverExperience(): number | null { return this.caregiver()?.experience_years ?? null; }
  get caregiverAvatar(): string {
    const c = this.caregiver();
    if (!c) return `https://ui-avatars.com/api/?name=Caregiver&background=6D28D9&color=fff&size=128&bold=true&rounded=true`;
    // ✅ جرب الـ _normalizedPhoto الأول (من localStorage — full URL)
    const photo = c._normalizedPhoto
      || this.resolveImageUrl(c.user?.profile_image_url)
      || this.resolveImageUrl(c.user?.profile_image)
      || this.resolveImageUrl(c.profile_image);
    return photo || `https://ui-avatars.com/api/?name=${encodeURIComponent(this.caregiverName)}&background=6D28D9&color=fff&size=128&bold=true&rounded=true`;
  }

  // ── helper: يضمن full URL للصورة ─────────────────────────
  private resolveImageUrl(path: string | null | undefined): string | null {
    if (!path) return null;
    if (path.startsWith('http://') || path.startsWith('https://')) return path;
    return `http://127.0.0.1:8000/storage/${path}`;
  }

  // ── Unified getters ───────────────────────────────────────
  get personName():       string      { return this.isCaregiver ? this.caregiverName      : this.doctorName;      }
  get personSpecialty():  string      { return this.isCaregiver ? this.caregiverSpecialty : this.doctorSpecialty; }
  get personBio():        string      { return this.isCaregiver ? this.caregiverBio        : this.doctorBio;       }
  get personAvatar():     string      { return this.isCaregiver ? this.caregiverAvatar     : this.doctorAvatar;    }
  get personExperience(): number|null { return this.isCaregiver ? this.caregiverExperience : this.doctorExperience;}

  // ── Booking signals ───────────────────────────────────────
  currentStep         = signal<BookingStep>(1);
  selectedConsultType = signal<ConsultationType>('online');
  selectedSlot        = signal<string | null>(null);
  notes               = signal<string>('');
  attachedFile        = signal<File | null>(null);
  isDragOver          = signal<boolean>(false);
  paymentMethod       = signal<PaymentMethod>('cash');
  cardNumber          = signal<string>('');
  cardExpiry          = signal<string>('');
  cardCvv             = signal<string>('');
  cardName            = signal<string>('');
  bookingReference    = signal<string>('');
  activeAppointmentId  = signal<number | null>(null);
  errorMessage        = signal<string>('');
  successMessage      = signal<string>('');
  isSubmittingBooking = signal<boolean>(false);
  isProcessingPayment  = signal<boolean>(false);

  today        = new Date();
  viewYear     = signal<number>(this.today.getFullYear());
  viewMonth    = signal<number>(this.today.getMonth());
  selectedDate = signal<Date | null>(new Date());

  readonly DAY_NAMES   = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  readonly MONTH_NAMES = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  readonly timeSlots   = [
    { time: '09:00', disabled: false },
    { time: '10:00', disabled: false },
    { time: '11:00', disabled: false },
    { time: '14:00', disabled: false },
    { time: '15:00', disabled: false },
    { time: '16:00', disabled: true  },
    { time: '17:00', disabled: false },
  ];

  // ── Consult options ───────────────────────────────────────
  get consultOptions() {
    if (this.isCaregiver) {
      const salary = this.caregiverSalary || 3000;
      return [
        { id: 'daily'  as ConsultationType, label: 'Daily home visit', duration: '4 hours',  price: Math.round(salary / 22) },
        { id: 'livein' as ConsultationType, label: 'Live-in care',     duration: 'Full time', price: salary },
      ];
    }
    const fee = this.doctorFee || 300;
    return [
      { id: 'online'   as ConsultationType, label: 'Online consultation', duration: '30 minutes', price: fee },
      { id: 'inperson' as ConsultationType, label: 'In-person visit',     duration: '45 minutes', price: Math.round(fee * 1.2) },
    ];
  }

  // ── Computed ──────────────────────────────────────────────
  selectedConsultOption = computed(() => this.consultOptions.find(o => o.id === this.selectedConsultType()) ?? this.consultOptions[0]);
  calMonthTitle         = computed(() => `${this.MONTH_NAMES[this.viewMonth()]} ${this.viewYear()}`);
  attachedFileName      = computed(() => this.attachedFile()?.name ?? null);
  showCardFields        = computed(() => this.paymentMethod() === 'card');
  summaryDate           = computed(() => this.selectedDate()?.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) ?? '—');
  selectedDateLabel     = computed(() => this.selectedDate()?.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' }) ?? 'Select a date');
  summaryTime           = computed(() => this.selectedSlot() ?? '—');
  summaryType           = computed(() => this.selectedConsultOption()?.label ?? '—');
  summaryPrice          = computed(() => `${this.selectedConsultOption()?.price ?? 0} EGP`);
  confirmTimeLabel      = computed(() => this.selectedSlot() ? `${this.selectedSlot()} — ${this.selectedConsultOption()?.duration} session` : '—');

  // ✅ Calendar مع الأيام المتاحة من الـ API
  calendarCells = computed(() => {
    const year        = this.viewYear();
    const month       = this.viewMonth();
    const firstDay    = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const cells: any[] = [];

    const doctorData    = this.doctor();
    const caregiverData = this.caregiver();
    const days = this.isCaregiver
      ? (caregiverData?.available_days || '')
      : (doctorData?.available_days || '');
    const availableNums = !days
      ? [0, 1, 2, 3, 4, 5, 6]
      : days.split(',')
          .map((d: string) => this.DAY_MAP[d.trim().toLowerCase()])
          .filter((n: number | undefined) => n !== undefined);

    for (let i = 0; i < firstDay; i++) cells.push({ day: null, state: 'empty' });

    for (let day = 1; day <= daysInMonth; day++) {
      const date        = new Date(year, month, day);
      const isPast      = date < new Date(this.today.getFullYear(), this.today.getMonth(), this.today.getDate());
      const isSelected  = this.selectedDate()?.toDateString() === date.toDateString();
      const isToday     = date.toDateString() === this.today.toDateString();
      const isAvailable = availableNums.includes(date.getDay());
      const isDisabled  = isPast || !isAvailable;

      cells.push({
        day,
        state: isSelected ? 'selected'
             : isDisabled ? 'disabled'
             : isToday    ? 'today'
             : 'available'
      });
    }
    return cells;
  });

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private http: HttpClient,
    private authService: AuthService,
  ) {}

  ngOnInit(): void {
    const type = this.route.snapshot.queryParamMap.get('type') || 'doctor';
    this.bookingMode = type === 'caregiver' ? 'caregiver' : 'doctor';
    this.restorePendingBooking();

    if (this.isCaregiver) {
      this.caregiverId = Number(this.route.snapshot.paramMap.get('id') || 0) || null;
      if (this.caregiverId) {
        // ✅ جرب الـ localStorage الأول عشان نعرض البيانات فوراً
        this.loadCaregiverFromStorage();
        // ثم جيب من الـ API عشان تتحدث
        this.isLoadingCaregiver = true;
        this.http.get<any>(`${API_BASE_URL}/caregivers/${this.caregiverId}`)
          .pipe(finalize(() => { this.isLoadingCaregiver = false; }))
          .subscribe({
            next: (res) => {
              const data = res?.data || res;
              // ✅ دمج الـ _normalizedName و _normalizedPhoto من الـ localStorage مع الـ API data
              const stored = this.caregiver();
              if (stored?._normalizedName) data._normalizedName = stored._normalizedName;
              if (stored?._normalizedPhoto) data._normalizedPhoto = stored._normalizedPhoto;
              this.caregiver.set(data);
              this.selectedConsultType.set('daily');
            },
            error: () => { /* استخدم الـ localStorage اللي حملناه فوق */ },
          });
      } else {
        this.loadCaregiverFromStorage();
      }
    } else {
      this.doctorId = Number(this.route.snapshot.paramMap.get('doctorId') || this.route.snapshot.paramMap.get('id') || 0) || null;
      if (this.doctorId) {
        // ✅ جرب الـ localStorage الأول عشان نعرض البيانات فوراً
        this.loadFromLocalStorage();
        // ثم جيب من الـ API
        this.isLoadingDoctor = true;
        this.http.get<any>(`${API_BASE_URL}/doctors/${this.doctorId}`)
          .pipe(finalize(() => { this.isLoadingDoctor = false; }))
          .subscribe({
            next: (res) => {
              const data = res?.data || res;
              const stored = this.doctor();
              if (stored?._normalizedName) data._normalizedName = stored._normalizedName;
              if (stored?._normalizedPhoto) data._normalizedPhoto = stored._normalizedPhoto;
              this.doctor.set(data);
            },
            error: () => { /* استخدم الـ localStorage اللي حملناه فوق */ },
          });
      } else {
        this.loadFromLocalStorage();
      }
    }
  }

  ngOnDestroy(): void {}

  private loadFromLocalStorage(): void {
    try {
      const stored = localStorage.getItem('selectedDoctor');
      this.doctor.set(stored ? JSON.parse(stored) : null);
    } catch { this.doctor.set(null); }
  }

  private loadCaregiverFromStorage(): void {
    try {
      const stored = localStorage.getItem('selectedCaregiver');
      this.caregiver.set(stored ? JSON.parse(stored) : null);
      if (this.caregiver()) this.selectedConsultType.set('daily');
    } catch { this.caregiver.set(null); }
  }

  private restorePendingBooking(): void {
    try {
      const stored = localStorage.getItem('bookingData');
      if (!stored) return;

      const data = JSON.parse(stored);
      if (data?.appointmentId && data?.requiresPayment) {
        this.activeAppointmentId.set(Number(data.appointmentId));
        this.bookingReference.set(String(data.appointmentId));
        this.currentStep.set(2);
        if (data.slot) this.selectedSlot.set(data.slot);
        if (data.consultType) this.selectedConsultType.set(data.consultType);
        if (data.notes) this.notes.set(data.notes);
        if (data.date) this.selectedDate.set(new Date(data.date));
      }
    } catch {
      localStorage.removeItem('bookingData');
    }
  }

  // ✅ nextStep مع validation
  nextStep(): void {
    if (this.currentStep() === 1) {
      if (!this.selectedSlot()) {
        alert('Please select a time slot before continuing.');
        return;
      }

      const bookingData = {
        mode:            this.bookingMode,
        doctorId:        this.doctorId,
        caregiverId:     this.caregiverId,
        doctor:          this.doctor(),
        caregiver:       this.caregiver(),
        date:            this.selectedDate()?.toISOString(),
        slot:            this.selectedSlot()!.substring(0, 5),
        consultType:     this.selectedConsultType(),
        notes:           this.notes(),
        summaryDate:     this.summaryDate(),
        summaryTime:     this.selectedSlot()!.substring(0, 5),
        summaryType:     this.summaryType(),
        summaryPrice:    this.summaryPrice(),
        personName:      this.personName,
        personSpecialty: this.personSpecialty,
      };
      localStorage.setItem('bookingData', JSON.stringify(bookingData));

      if (this.isCaregiver) {
        this.router.navigate(['/caregiver-follow-request']);
      } else {
        this.createDoctorAppointment(bookingData);
      }
    }
    window.scrollTo(0, 0);
  }

  private createDoctorAppointment(bookingData: any): void {
    if (!this.doctorId || !bookingData.date || !bookingData.slot) {
      this.errorMessage.set('Please choose a doctor, date, and time.');
      return;
    }

    const token = this.authService.getToken();
    const role = this.authService.getUserRole();
    if (!token) {
      this.errorMessage.set('Your session expired. Please sign in again.');
      setTimeout(() => this.router.navigate(['/signin'], { replaceUrl: true }), 800);
      return;
    }

    if (role !== 'patient') {
      this.errorMessage.set('Only patients can book consultations.');
      return;
    }

    this.isSubmittingBooking.set(true);
    this.errorMessage.set('');
    this.http.post<any>(`${API_BASE_URL}/appointments`, {
      doctor_id: this.doctorId,
      appointment_date: new Date(bookingData.date).toISOString().slice(0, 10),
      appointment_time: bookingData.slot,
      type: bookingData.consultType === 'inperson' ? 'inperson' : 'online',
      amount: this.selectedConsultOption()?.price ?? 0,
      notes: bookingData.notes || null,
    }, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    }).pipe(finalize(() => this.isSubmittingBooking.set(false))).subscribe({
      next: (res) => {
        const appointmentId = Number(res?.data?.id || res?.id || 0);
        this.activeAppointmentId.set(appointmentId || null);
        this.bookingReference.set(appointmentId ? String(appointmentId) : '');
        localStorage.setItem('bookingData', JSON.stringify({
          ...bookingData,
          appointmentId,
          requiresPayment: true,
        }));
        this.successMessage.set('Appointment reserved. Please complete payment to confirm it.');
        this.currentStep.set(2);
      },
      error: (err) => {
        if (err?.status === 401) {
          this.authService.clearSession();
          this.errorMessage.set('Your session expired. Please sign in again.');
          setTimeout(() => this.router.navigate(['/signin'], { replaceUrl: true }), 800);
          return;
        }

        if (err?.status === 403) {
          this.errorMessage.set('You are not allowed to book this doctor. Please make sure your follow request is accepted.');
          return;
        }

        if (err?.status === 422 && err?.error?.errors) {
          const messages = Object.values(err.error.errors).flat().join(' ');
          this.errorMessage.set(messages || 'Please check the booking details and try again.');
          return;
        }

        this.errorMessage.set(err?.error?.message || 'Unable to book this consultation. Please choose another date or time.');
      }
    });
  }

  completePayment(): void {
    const appointmentId = this.activeAppointmentId();
    const token = this.authService.getToken();
    if (!appointmentId || !token) {
      this.errorMessage.set('No pending appointment payment was found. Please choose a time again.');
      this.currentStep.set(1);
      return;
    }

    if (this.paymentMethod() === 'card' && (!this.cardNumber().trim() || !this.cardExpiry().trim() || !this.cardCvv().trim() || !this.cardName().trim())) {
      this.errorMessage.set('Please complete the card payment details.');
      return;
    }

    this.isProcessingPayment.set(true);
    this.errorMessage.set('');
    this.http.post<any>(`${API_BASE_URL}/payments/${appointmentId}/pay`, {}, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    }).pipe(finalize(() => this.isProcessingPayment.set(false))).subscribe({
      next: () => {
        localStorage.removeItem('bookingData');
        this.successMessage.set('Payment successful. Your consultation is confirmed.');
        this.currentStep.set(3);
        setTimeout(() => this.router.navigate(['/dashboard-p']), 1800);
      },
      error: (err) => {
        if (err?.status === 401) {
          this.authService.clearSession();
          this.errorMessage.set('Your session expired. Please sign in again.');
          setTimeout(() => this.router.navigate(['/signin'], { replaceUrl: true }), 800);
          return;
        }
        if (err?.status === 403) {
          this.errorMessage.set('You are not allowed to pay for this appointment.');
          return;
        }
        if (err?.status === 409) {
          this.errorMessage.set('This appointment has already been paid.');
          return;
        }
        this.errorMessage.set(err?.error?.message || 'Payment could not be processed. Please try again.');
      }
    });
  }

  markPaymentFailed(): void {
    const appointmentId = this.activeAppointmentId();
    const token = this.authService.getToken();
    if (!appointmentId || !token) return;

    this.isProcessingPayment.set(true);
    this.http.post<any>(`${API_BASE_URL}/payments/${appointmentId}/fail`, {}, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    }).pipe(finalize(() => this.isProcessingPayment.set(false))).subscribe({
      next: () => this.errorMessage.set('Payment failed. Your slot is still reserved while you try again.'),
      error: (err) => this.errorMessage.set(err?.error?.message || 'Unable to mark payment as failed.'),
    });
  }

  prevStep(): void {
    if (this.currentStep() > 1) this.currentStep.set((this.currentStep() - 1) as BookingStep);
  }

  selectDay(day: number | null, state: string): void {
    if (day && state !== 'disabled' && state !== 'empty') {
      this.selectedDate.set(new Date(this.viewYear(), this.viewMonth(), day));
      this.selectedSlot.set(null);
    }
  }

  prevMonth(): void {
    if (this.viewMonth() === 0) { this.viewMonth.set(11); this.viewYear.update(y => y - 1); }
    else this.viewMonth.update(m => m - 1);
  }

  nextMonth(): void {
    if (this.viewMonth() === 11) { this.viewMonth.set(0); this.viewYear.update(y => y + 1); }
    else this.viewMonth.update(m => m + 1);
  }

  goToToday(): void {
    this.viewMonth.set(this.today.getMonth());
    this.viewYear.set(this.today.getFullYear());
    this.selectedDate.set(new Date(this.today));
  }

  selectSlot(slot: any): void              { if (!slot.disabled) this.selectedSlot.set(slot.time); }
  setPaymentMethod(m: PaymentMethod): void { this.paymentMethod.set(m); }
  triggerFileInput(): void                 { this.fileInputRef?.nativeElement?.click(); }
  onFileSelected(event: any): void         { const f = event.target.files[0]; if (f) this.attachedFile.set(f); }
  removeFile(): void                       { this.attachedFile.set(null); }

  formatCardNumber(event: any): void {
    const value = event.target.value.replace(/\D/g, '').substring(0, 16);
    this.cardNumber.set(value.match(/.{1,4}/g)?.join(' ') ?? value);
  }

  formatExpiry(event: any): void {
    const value = event.target.value.replace(/\D/g, '').substring(0, 4);
    this.cardExpiry.set(value.length >= 2 ? `${value.substring(0, 2)} / ${value.substring(2)}` : value);
  }

  isStepActive(step: number): boolean { return this.currentStep() === step; }
  isStepDone(step: number):   boolean { return this.currentStep() > step;   }
  reschedule():        void { this.currentStep.set(1); }
  cancelAppointment(): void { if (confirm('Cancel?')) this.currentStep.set(1); }
  addToCalendar():     void { alert('Added to calendar.'); }
  contactSupport():    void { alert('Support contacted.'); }
}
