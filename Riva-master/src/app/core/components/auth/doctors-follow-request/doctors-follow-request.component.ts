import { CommonModule, isPlatformBrowser } from '@angular/common';
import { Component, CUSTOM_ELEMENTS_SCHEMA, Inject, NgZone, OnInit, PLATFORM_ID } from '@angular/core';
import { FormsModule, ReactiveFormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { HttpClient } from '@angular/common/http';
import { finalize } from 'rxjs';
import { API_BASE_URL } from '../../../../constants';
import { AuthService } from '../../../../service/auth.service';

@Component({
  selector: 'app-doctors-follow-request',
  standalone: true,
  imports: [CommonModule, FormsModule, ReactiveFormsModule],
  templateUrl: './doctors-follow-request.component.html',
  styleUrl: './doctors-follow-request.component.css',
  schemas: [CUSTOM_ELEMENTS_SCHEMA],
})
export class DoctorsFollowRequestComponent implements OnInit {
  cardHolder = '';
  cardNumber = '';
  expiry = '';
  cvc = '';
  email = '';
  address = '';
  country = 'Egypt';
  saveInfo = true;

  countries = ['United States', 'Egypt', 'United Kingdom', 'Germany', 'Saudi Arabia', 'UAE'];

  isLoading = false;
  isLoadingDoctor = false;
  successMessage = '';
  errorMessage = '';

  doctorId = 0;
  doctorName = '';
  doctorSpecialty = '';
  doctorFee = '';
  summaryDate = '';
  summaryTime = '';
  summaryType = '';
  summaryPrice = '';

  constructor(
    private authService: AuthService,
    private http: HttpClient,
    private router: Router,
    private zone: NgZone,
    @Inject(PLATFORM_ID) private platformId: Object
  ) {}

  private get authHeaders() {
    return { Authorization: `Bearer ${this.authService.getToken()}` };
  }

  ngOnInit(): void {
    if (!isPlatformBrowser(this.platformId)) return;
    if (!this.authService.isAuthenticated()) {
      this.router.navigate(['/signin']);
      return;
    }

    // ✅ جيب الـ doctor data من localStorage أول
    this.loadDoctorFromStorage();

    // ✅ جيب الـ booking data من localStorage
    this.loadBookingFromStorage();

    if (!this.doctorId) {
      this.errorMessage = 'Doctor not found. Please choose a doctor again.';
      return;
    }

    // ✅ جيب بيانات الدكتور من API للـ update
    this.loadDoctorFromApi();
  }

  private loadDoctorFromStorage(): void {
    try {
      const raw = localStorage.getItem('selectedDoctor');
      if (!raw) return;
      const doctor = JSON.parse(raw);
      this.doctorId = Number(doctor.id || 0);

      // ✅ جرب كل الأسماء الممكنة
      this.doctorName =
        doctor._normalizedName ||
        doctor.name ||
        `${doctor.user?.first_name || doctor.first_name || ''} ${doctor.user?.last_name || doctor.last_name || ''}`.trim() ||
        'Doctor';

      this.doctorSpecialty = doctor.specialty || '';
      this.doctorFee       = doctor.fee       || '';
    } catch (e) {
      console.error('failed to parse selectedDoctor', e);
    }
  }

  private loadBookingFromStorage(): void {
    try {
      const raw = localStorage.getItem('bookingData');
      if (!raw) return;
      const booking = JSON.parse(raw);

      this.summaryDate  = booking.summaryDate  || '';
      this.summaryTime  = booking.summaryTime  || '';
      this.summaryType  = booking.summaryType  || '';
      this.summaryPrice = booking.summaryPrice || '';

      // ✅ الاسم من bookingData لو موجود
      if (booking.personName) this.doctorName = booking.personName;
      if (booking.personSpecialty) this.doctorSpecialty = booking.personSpecialty;
      if (!this.doctorId && booking.doctorId) this.doctorId = Number(booking.doctorId);
    } catch (e) {
      console.error('failed to parse bookingData', e);
    }
  }

  private loadDoctorFromApi(): void {
    this.isLoadingDoctor = true;
    this.http.get<any>(`${API_BASE_URL}/doctors/${this.doctorId}`, {
      headers: this.authHeaders
    }).pipe(finalize(() => this.isLoadingDoctor = false))
    .subscribe({
      next: (res) => {
        const doctor = res.data || res;
        const user   = doctor.user || {};

        // ✅ جرب كل الأسماء الممكنة من الـ API
        const apiName = `${user.first_name || ''} ${user.last_name || ''}`.trim() || doctor.name || '';
        if (apiName) this.doctorName = apiName;

        if (doctor.specialty) this.doctorSpecialty = doctor.specialty;
        if (doctor.fee) {
          this.doctorFee    = doctor.fee;
          this.summaryPrice = this.summaryPrice || `${doctor.fee} EGP`;
        }
      },
      error: (err) => {
        console.error('Failed to load doctor from API', err);
        // استخدم الـ localStorage data اللي حملناه فوق
      }
    });
  }

  formatCardNumber(event: any): void {
    const value = event.target.value.replace(/\D/g, '').substring(0, 16);
    this.cardNumber = value.match(/.{1,4}/g)?.join(' ') || value;
    event.target.value = this.cardNumber;
  }

  formatExpiry(event: any): void {
    let value = event.target.value.replace(/\D/g, '').substring(0, 4);
    if (value.length > 2) value = `${value.slice(0, 2)}/${value.slice(2)}`;
    this.expiry = value;
    event.target.value = value;
  }

  onSubmit(): void {
    if (!this.doctorId) {
      this.errorMessage = 'Doctor not found. Please choose a doctor again.';
      return;
    }

    this.isLoading = true;
    this.errorMessage = '';

    const bookingRaw = localStorage.getItem('bookingData');
    const booking    = bookingRaw ? JSON.parse(bookingRaw) : null;

    this.http.post<any>(`${API_BASE_URL}/doctors/${this.doctorId}/follow-request`, {
      payment_method:   'card',
      payment_mode:     'demo',
      billing_email:    this.email,
      billing_address:  this.address,
      appointment_date: booking?.date
        ? new Date(booking.date).toISOString().slice(0, 10)
        : null,
      appointment_time: booking?.slot
        ? booking.slot.trim().substring(0, 5)
        : null,
      type:   booking?.consultType || null,
      amount: Number((this.summaryPrice || '').replace(/[^\d.]/g, '')) || null,
      notes:  booking?.notes || null,
    }, { headers: this.authHeaders })
    .subscribe({
      next: (res) => {
        const paymentId = res?.data?.payment_id || res?.payment_id || null;

        if (!paymentId) {
          this.zone.run(() => {
            this.isLoading = false;
            this.successMessage = `Follow request sent to Dr. ${this.doctorName} successfully.`;
            localStorage.removeItem('bookingData');
            localStorage.removeItem('selectedDoctor');
            setTimeout(() => this.router.navigate(['/dashboard-p']), 1200);
          });
          return;
        }

        this.http.post<any>(`${API_BASE_URL}/payments/${paymentId}/pay`, {
          payment_method:  'card',
          payment_mode:    'demo',
          billing_email:   this.email,
          billing_address: this.address,
          card_holder:     this.cardHolder,
          card_number:     this.cardNumber.replace(/\s/g, ''),
          expiry:          this.expiry,
          cvc:             this.cvc,
        }, { headers: this.authHeaders })
        .pipe(finalize(() => this.isLoading = false))
        .subscribe({
          next: () => {
            this.zone.run(() => {
              this.successMessage = `Payment confirmed for Dr. ${this.doctorName}!`;
              localStorage.removeItem('bookingData');
              localStorage.removeItem('selectedDoctor');
              setTimeout(() => this.router.navigate(['/dashboard-p']), 1200);
            });
          },
          error: (err) => {
            this.zone.run(() => {
              this.errorMessage = err?.error?.message || 'Payment failed. Please try again.';
            });
          }
        });
      },
      error: (error) => {
        this.zone.run(() => {
          this.isLoading = false;
          this.errorMessage = error?.error?.message || 'Request failed. Please try again.';
        });
      },
    });
  }
}