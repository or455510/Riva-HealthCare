import { CommonModule, isPlatformBrowser } from '@angular/common';
import { Component, CUSTOM_ELEMENTS_SCHEMA, Inject, NgZone, OnInit, PLATFORM_ID } from '@angular/core';
import { FormsModule, ReactiveFormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { HttpClient } from '@angular/common/http';
import { finalize } from 'rxjs';
import { API_BASE_URL } from '../../../../constants';
import { AuthService } from '../../../../service/auth.service';

@Component({
  selector: 'app-caregiver-follow-request',
  standalone: true,
  imports: [CommonModule, FormsModule, ReactiveFormsModule],
  templateUrl: './caregiver-follow-request.component.html',
  styleUrl: './caregiver-follow-request.component.css',
  schemas: [CUSTOM_ELEMENTS_SCHEMA],
})
export class CaregiverFollowRequestComponent implements OnInit {
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
  successMessage = '';
  errorMessage = '';

  caregiverId = 0;
  caregiverName = '';
  caregiverSpecialty = '';
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
    this.loadCaregiverData();
    this.loadBookingData();
  }

  private loadCaregiverData(): void {
    try {
      const raw = localStorage.getItem('selectedCaregiver');
      if (!raw) return;
      const cg = JSON.parse(raw);
      this.caregiverId = Number(cg.id || 0);

      // ✅ جرب كل الأسماء الممكنة
      this.caregiverName =
        cg._normalizedName ||
        cg.name ||
        `${cg.user?.first_name || cg.first_name || ''} ${cg.user?.last_name || cg.last_name || ''}`.trim() ||
        'Caregiver';

      this.caregiverSpecialty = cg.specialty || 'General Care';
    } catch (e) {
      console.error('[CaregiverFollowRequest] failed to parse selectedCaregiver', e);
    }
  }

  private loadBookingData(): void {
    try {
      const raw = localStorage.getItem('bookingData');
      if (!raw) return;
      const booking = JSON.parse(raw);

      if (booking.mode && booking.mode !== 'caregiver') {
        this.router.navigate(['/doctors-follow-request']);
        return;
      }

      this.summaryDate        = booking.summaryDate     || '';
      this.summaryTime        = booking.summaryTime     || '';
      this.summaryType        = booking.summaryType     || '';
      this.summaryPrice       = booking.summaryPrice    || '';

      // ✅ الاسم من bookingData أو من selectedCaregiver
      this.caregiverName      = booking.personName      || this.caregiverName;
      this.caregiverSpecialty = booking.personSpecialty || this.caregiverSpecialty;

      if (!this.caregiverId && booking.caregiverId) {
        this.caregiverId = Number(booking.caregiverId);
      }
    } catch (e) {
      console.error('[CaregiverFollowRequest] failed to parse bookingData', e);
    }
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
    if (!this.caregiverId) {
      this.errorMessage = 'Caregiver not found. Please choose a caregiver again.';
      return;
    }

    this.isLoading = true;
    this.errorMessage = '';

    const bookingData = this.readBookingData();

    this.http.post<any>(
      `${API_BASE_URL}/caregivers/${this.caregiverId}/follow-request`,
      {
        payment_method:   'card',
        payment_mode:     'demo',
        billing_email:    this.email,
        billing_address:  this.address,
        appointment_date: bookingData?.date
          ? new Date(bookingData.date).toISOString().slice(0, 10)
          : null,
        appointment_time: bookingData?.slot
          ? bookingData.slot.trim().substring(0, 5)
          : null,
        type:   bookingData?.consultType || null,
        amount: Number((this.summaryPrice || '').replace(/[^\d.]/g, '')) || null,
        notes:  bookingData?.notes || null,
      },
      { headers: this.authHeaders }
    )
    .pipe(finalize(() => { this.isLoading = false; }))
    .subscribe({
      next: (res) => {
        const paymentId = res?.data?.payment_id || res?.payment_id || null;

        if (!paymentId) {
          this.zone.run(() => {
            this.successMessage = `Booking request sent to ${this.caregiverName} successfully.`;
            localStorage.removeItem('bookingData');
            localStorage.removeItem('selectedCaregiver');
            setTimeout(() => this.router.navigate(['/dashboard-p']), 1200);
          });
          return;
        }

        this.http.post<any>(
          `${API_BASE_URL}/payments/${paymentId}/pay`,
          {
            payment_method:  'card',
            payment_mode:    'demo',
            billing_email:   this.email,
            billing_address: this.address,
            card_holder:     this.cardHolder,
            card_number:     this.cardNumber.replace(/\s/g, ''),
            expiry:          this.expiry,
            cvc:             this.cvc,
          },
          { headers: this.authHeaders }
        )
        .subscribe({
          next: () => {
            this.zone.run(() => {
              this.successMessage = `Payment confirmed for ${this.caregiverName}!`;
              localStorage.removeItem('bookingData');
              localStorage.removeItem('selectedCaregiver');
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
          this.errorMessage = error?.error?.message || 'Request failed. Please try again.';
        });
      },
    });
  }

  private readBookingData(): any {
    try {
      const raw = localStorage.getItem('bookingData');
      return raw ? JSON.parse(raw) : null;
    } catch { return null; }
  }
}