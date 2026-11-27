import { CommonModule } from '@angular/common';
import { Component } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { finalize } from 'rxjs';
import { AuthService } from '../../../../service/auth.service';

@Component({
  selector: 'app-reset-code',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  templateUrl: './reset-code.component.html',
  styleUrl: './reset-code.component.css',
})
export class ResetCodeComponent {
  form;
  email = typeof sessionStorage !== 'undefined' ? sessionStorage.getItem('reset_email') || '' : '';
  loading = false;
  message = '';

  constructor(
    private fb: FormBuilder,
    private authService: AuthService,
    private router: Router
  ) {
    this.form = this.fb.group({
      code: ['', [Validators.required, Validators.pattern(/^\d{6}$/)]],
    });
  }

  onSubmit(): void {
    if (this.form.invalid || !this.email) {
      this.form.markAllAsTouched();
      return;
    }

    this.loading = true;
    const code = this.form.value.code || '';

    this.authService.verifyResetCode(this.email, code)
      .pipe(finalize(() => this.loading = false))
      .subscribe({
        next: () => {
          sessionStorage.setItem('reset_code', code);
          this.router.navigate(['/new-password']);
        },
        error: error => this.message = error.error?.message || 'Invalid verification code',
      });
  }
}
