import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { AuthService } from '../../service/auth.service';

export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const router = inject(Router);
  const authService = inject(AuthService);
  const token =
    typeof window !== 'undefined'
      ? localStorage.getItem('token')
      : null;

  if (!token) return next(req);

  // ✅ لو FormData متضيفيش Content-Type خالص
  const isFormData = req.body instanceof FormData;

  return next(
    req.clone({
      setHeaders: isFormData
        ? { Authorization: `Bearer ${token}`, Accept: 'application/json' }
        : { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' },
    })
  ).pipe(
    catchError((error) => {
      if (error.status === 401 && !req.url.includes('/auth/login')) {
        authService.clearSession();
        router.navigate(['/signin'], { replaceUrl: true });
      }
      return throwError(() => error);
    })
  );
};
