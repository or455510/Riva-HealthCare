import { CommonModule } from '@angular/common';
import { Component, CUSTOM_ELEMENTS_SCHEMA } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { finalize } from 'rxjs';
import { AuthService } from '../../../../service/auth.service';

@Component({
  selector: 'app-new-password',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  templateUrl: './new-password.component.html',
  styleUrl: './new-password.component.css',
  schemas: [CUSTOM_ELEMENTS_SCHEMA]
})
export class NewPasswordComponent {
  email = typeof sessionStorage !== 'undefined' ? sessionStorage.getItem('reset_email') || '' : '';
  code = typeof sessionStorage !== 'undefined' ? sessionStorage.getItem('reset_code') || '' : '';
  loading = false;
  message = '';
  form;

  constructor(
    private fb: FormBuilder,
    private authService: AuthService,
    private router: Router
  ) {
    this.form = this.fb.group({
      password: ['', [Validators.required, Validators.minLength(8)]],
      password_confirmation: ['', [Validators.required, Validators.minLength(8)]],
    });
  }

  onSubmit(): void {
    const { password, password_confirmation } = this.form.value;

    if (this.form.invalid || !this.email || !this.code || password !== password_confirmation) {
      this.form.markAllAsTouched();
      this.message = password !== password_confirmation ? 'Passwords do not match' : this.message;
      return;
    }

    this.loading = true;
    this.authService.resetPassword(this.email, this.code, password || '', password_confirmation || '')
      .pipe(finalize(() => this.loading = false))
      .subscribe({
        next: () => {
          sessionStorage.removeItem('reset_email');
          sessionStorage.removeItem('reset_code');
          this.router.navigate(['/signin']);
        },
        error: error => this.message = error.error?.message || 'Unable to reset password',
      });
  }

}
