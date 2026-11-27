// import { Component, OnInit, CUSTOM_ELEMENTS_SCHEMA } from '@angular/core';
// import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
// import { CommonModule } from '@angular/common';
// import { AuthService, RegisterPayload, LoginResponse } from '../../../../service/auth.service';
// import { Router } from '@angular/router';

// @Component({
//   selector: 'app-signup',
//   standalone: true,
//   imports: [CommonModule, ReactiveFormsModule],
//   templateUrl: './signup.component.html',
//   styleUrls: ['./signup.component.css'],
//   schemas: [CUSTOM_ELEMENTS_SCHEMA]
// })
// export class SignupComponent implements OnInit {
//   form!: FormGroup;
//   errorMessage = '';
//   successMessage = '';

//   constructor(
//     private fb: FormBuilder,
//     private authService: AuthService,
//     private router: Router
//   ) {}

//   ngOnInit(): void {
//     this.form = this.fb.group({
//       first_name:            ['', [Validators.required, Validators.minLength(2)]],
//       last_name:             ['', [Validators.required, Validators.minLength(2)]],
//       phone:                 ['', [Validators.required]],
//       email:                 ['', [Validators.required, Validators.email]],
//       password:              ['', [Validators.required, Validators.minLength(8)]],
//       password_confirmation: ['', [Validators.required]],
//       role:                  ['patient', [Validators.required]],
//     });
//   }

//   onSubmit(): void {
//     if (this.form.invalid) {
//       this.form.markAllAsTouched();
//       this.errorMessage = 'Please fill all fields correctly';
//       return;
//     }

//     const payload: RegisterPayload = this.form.getRawValue();

//     this.authService.register(payload).subscribe({
//       next: (res: LoginResponse) => {
//         this.errorMessage = '';
//         this.successMessage = 'Registration successful! Redirecting...';
//         this.authService.saveToken(res); // ✅ حفظ الـ token عشان signup2 تقدر تستخدمه
//         this.router.navigate(['/signup2']);
//       },
//       error: (err) => {
//         console.error('Register Error:', err);
//         if (err.status === 422) {
//           this.errorMessage = err?.error?.message || 'Validation failed';
//         } else if (err.status === 0) {
//           this.errorMessage = 'Cannot connect to server';
//         } else {
//           this.errorMessage = err?.error?.message || 'Registration failed';
//         }
//       }
//     });
//   }
// }
import { Component, OnInit, CUSTOM_ELEMENTS_SCHEMA } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { CommonModule } from '@angular/common';
import { AuthService, RegisterPayload, LoginResponse } from '../../../../service/auth.service';
import { Router } from '@angular/router';
import { API_BASE_URL } from '../../../../constants';

@Component({
  selector: 'app-signup',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  templateUrl: './signup.component.html',
  styleUrls: ['./signup.component.css'],
  schemas: [CUSTOM_ELEMENTS_SCHEMA]
})
export class SignupComponent implements OnInit {
  form!: FormGroup;
  errorMessage = '';
  successMessage = '';

  constructor(
    private fb: FormBuilder,
    private authService: AuthService,
    private router: Router
  ) {}

  ngOnInit(): void {
    this.form = this.fb.group({
      first_name:            ['', [Validators.required, Validators.minLength(2)]],
      last_name:             ['', [Validators.required, Validators.minLength(2)]],
      phone:                 ['', [Validators.required]],
      email:                 ['', [Validators.required, Validators.email]],
      password:              ['', [Validators.required, Validators.minLength(8)]],
      password_confirmation: ['', [Validators.required]],
      role:                  ['patient', [Validators.required]],
    }, { validators: this.passwordMatchValidator });
  }

  // ✅ Validator لتطابق الباسورد
  passwordMatchValidator(form: FormGroup) {
    const password = form.get('password')?.value;
    const confirm = form.get('password_confirmation')?.value;

    return password === confirm ? null : { mismatch: true };
  }

  onSubmit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.errorMessage = 'Please fill all fields correctly';
      return;
    }

    const payload: RegisterPayload = this.form.getRawValue();

    this.authService.register(payload).subscribe({
      next: (res: LoginResponse) => {
        this.errorMessage = '';
        this.successMessage = 'Registration successful! Redirecting...';
        this.authService.saveToken(res);
        this.router.navigate([this.authService.completionRouteForRole(res.user.role)]);
      },
      error: (err) => {
        console.error('Register Error:', err);
        if (err.status === 422) {
          this.errorMessage = err?.error?.message || 'Validation failed';
        } else if (err.status === 0) {
          this.errorMessage = 'Cannot connect to server';
        } else {
          this.errorMessage = err?.error?.message || 'Registration failed';
        }
      }
    });
  }
    loginWithGoogle(): void {
      this.errorMessage = '';
      if (typeof window === 'undefined') return;
  
      window.location.href = `${API_BASE_URL}/auth/google/redirect`;
    }
  

}
