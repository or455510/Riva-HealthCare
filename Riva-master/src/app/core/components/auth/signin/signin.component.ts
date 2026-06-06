import { Component, CUSTOM_ELEMENTS_SCHEMA, OnInit } from '@angular/core';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { CommonModule } from '@angular/common';
import { AuthService, LoginResponse } from '../../../../service/auth.service';
import { Router } from '@angular/router';
import { finalize } from 'rxjs';
import { API_BASE_URL } from '../../../../constants';

@Component({
  selector: 'app-signin',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  templateUrl: './signin.component.html',
  schemas: [CUSTOM_ELEMENTS_SCHEMA]
})
export class SigninComponent implements OnInit {
  form!: FormGroup;
  robotMessage = '';
  errorMessage = '';
  isLoading = false;
  showPassword = false;

  constructor(
    private fb: FormBuilder,
    private authService: AuthService,
    private router: Router
  ) {}

  ngOnInit(): void {
    this.form = this.fb.group({
      email: ['', [Validators.required, Validators.email]],
      password: ['', [Validators.required]]
    });
  }

  speak(message: string): void {
    if (
      typeof window === 'undefined' ||
      typeof window.speechSynthesis === 'undefined' ||
      typeof SpeechSynthesisUtterance === 'undefined'
    ) {
      return;
    }

    const speech = new SpeechSynthesisUtterance(message);
    speech.lang = 'en-US';
    speech.rate = 1;
    speech.pitch = 1;
    window.speechSynthesis.speak(speech);
  }

  onSubmit(): void {
    this.errorMessage = '';
    this.robotMessage = '';

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.errorMessage = 'Please enter a valid email and password';
      return;
    }

    const { email, password } = this.form.getRawValue();

    this.isLoading = true;

    this.authService.login(email.trim(), password)
      .pipe(
        finalize(() => {
          this.isLoading = false;
        })
      )
      .subscribe({
        next: (res: LoginResponse) => {
          console.log('LOGIN SUCCESS:', res);

          if (!res?.token || !res?.user) {
            this.errorMessage = 'Login response is missing token or user data.';
            return;
          }

          this.authService.saveToken(res);

          this.router.navigate([this.authService.dashboardRouteForRole(res.user.role)]);
          
        },
        error: (error) => {
          console.error('LOGIN ERROR:', error);

          if (error.status === 0) {
            this.errorMessage = 'Cannot connect to server. Make sure Laravel is running on http://https://riva-healthcare-tm.gamer.gd';
          } else if (error.status === 401) {
            this.errorMessage = 'Email or password is invalid.';
          } else if (error.status === 422) {
            const validationErrors = error.error?.errors
              ? Object.values(error.error.errors).flat().join(' ')
              : '';

            this.errorMessage =
              validationErrors ||
              error.error?.message ||
              'Please enter valid login data.';
          } else {
            this.errorMessage =
              error.error?.message ||
              `Login failed. Status: ${error.status}`;
          }

          this.speak(this.errorMessage);
        }
      });
  }

  loginWithGoogle(): void {
    this.errorMessage = '';
    if (typeof window === 'undefined') return;

    window.location.href = `${API_BASE_URL}/auth/google/redirect`;
  }
}
