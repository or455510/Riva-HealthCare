import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { FormBuilder, FormGroup, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterModule } from '@angular/router';
import { finalize } from 'rxjs';
import { AuthService } from '../../../service/auth.service';
import { CONTACT_MESSAGE_ENDPOINT } from '../../../constants';

@Component({
  selector: 'app-contact',
  standalone: true,
  imports: [ReactiveFormsModule, RouterModule, CommonModule, FormsModule],
  templateUrl: './contact.component.html',
  styleUrl: './contact.component.css',
})
export class ContactComponent implements OnInit {
  
  userRole: string = 'patient'; // ✅ default value بدل ما تكون فاضية
  sidebarLinks: { icon: string; route: string }[] = [];
  isSending = false;
  successMessage = '';
  errorMessage = '';
  backendErrors: Record<string, string[]> = {};
  contactForm: FormGroup;

  constructor(
    private authService: AuthService,
    private fb: FormBuilder,
    private http: HttpClient
  ) {
    this.contactForm = this.fb.group({
      full_name: ['', [Validators.required, Validators.maxLength(255)]],
      email: ['', [Validators.required, Validators.email, Validators.maxLength(255)]],
      subject: ['', [Validators.required, Validators.maxLength(255)]],
      message: ['', [Validators.required, Validators.maxLength(5000)]],
    });
  }

ngOnInit(): void {
  const role = this.authService.getUserRole();
  
  // 🔍 أول حاجة: اطبع إيه اللي بيرجع من الـ service
  // لو مفيش role خالص، متعملش sidebar
  if (!role || role.trim() === '') {
    this.sidebarLinks = [];
    return;
  }
  
  this.userRole = role.trim().toLowerCase();
  this.setSidebarLinks();
}

  setSidebarLinks() {
    if (this.userRole === 'caregiver') {
      this.sidebarLinks = [
        { icon: 'fas fa-home', route: '/dashboard-caregiver' },
        { icon: 'fa-brands fa-rocketchat', route: '/chat-c' },
        { icon: 'fa-solid fa-circle-user', route: '/myprofile' },
        { icon: 'fa-solid fa-phone', route: '/contact' },
        { icon: 'fa-solid fa-bed-pulse', route: '/patient-cards' }
      ];
    } else if (this.userRole === 'doctor') {
      this.sidebarLinks = [
        { icon: 'fas fa-home', route: '/dashboard' },
        { icon: 'fa-brands fa-rocketchat', route: '/chat' },
        { icon: 'fa-solid fa-circle-user', route: '/myprofile' },
        { icon: 'fa-solid fa-phone', route: '/contact' },
        { icon: 'fa-solid fa-bed-pulse', route: '/patient-cards' }
      ];
    } else if (this.userRole === 'patient') 
      { this.sidebarLinks = [
        { icon: 'fas fa-home', route: '/dashboard-p' },
        { icon: 'fas fa-pills', route: '/add-new-medication' },
        { icon: 'fa-solid fa-user-doctor', route: '/doctor-cards' },
        { icon: 'fa-brands fa-rocketchat', route: '/chat' },
        { icon: 'fa-solid fa-circle-user', route: '/myprofile' },
        { icon: 'fa-solid fa-user-nurse', route: '/caregiver-cards' }
    ] } else {
      this.sidebarLinks = [
        
      ];
    }
  }
  showContactModal = false;
private readonly phone = '201012077945';

openContactOptions(): void {
  this.showContactModal = true;
}

closeModal(): void {
  this.showContactModal = false;
}

callDirect(): void {
  window.location.href = `tel:+${this.phone}`;
  this.closeModal();
}

openWhatsApp(): void {
  const message = encodeURIComponent('Hello, I would like to contact the Riva Platform support team..');
  window.open(`https://wa.me/${this.phone}?text=${message}`, '_blank');
  this.closeModal();
}

submitContactForm(): void {
  this.successMessage = '';
  this.errorMessage = '';
  this.backendErrors = {};

  if (this.contactForm.invalid) {
    this.contactForm.markAllAsTouched();
    return;
  }

  this.isSending = true;

  this.http.post<{ message?: string }>(CONTACT_MESSAGE_ENDPOINT, this.contactForm.getRawValue())
    .pipe(finalize(() => this.isSending = false))
    .subscribe({
      next: (response) => {
        this.successMessage = response.message || 'Your message has been sent successfully.';
        this.contactForm.reset();
      },
      error: (error) => {
        this.backendErrors = error?.error?.errors || {};
        this.errorMessage = error?.error?.message || 'Could not send your message. Please try again.';
      },
    });
}

fieldError(field: 'full_name' | 'email' | 'subject' | 'message'): string {
  const control = this.contactForm.controls[field];

  if (this.backendErrors[field]?.length) {
    return this.backendErrors[field][0];
  }

  if (!control.touched || !control.errors) {
    return '';
  }

  if (control.errors['required']) {
    return 'This field is required.';
  }

  if (control.errors['email']) {
    return 'Enter a valid email address.';
  }

  if (control.errors['maxlength']) {
    return `Maximum ${control.errors['maxlength'].requiredLength} characters allowed.`;
  }

  return 'Invalid value.';
}
}
