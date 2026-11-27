import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { AuthService } from '../../service/auth.service';

// RIVA audit (Section 3 — Follow-from-card bug): a 401 on a GET to one of the
// app's genuinely public, unauthenticated-friendly endpoints (the public
// doctor/caregiver directory and its sub-resources — see routes/api.php's
// "Public doctor/caregiver routes" / "Public misc routes" groups, none of
// which sit behind auth:sanctum) is not evidence that the current session is
// invalid. Before this fix, ANY 401 from ANY request force-cleared the
// session and hard-redirected to /signin, so a logged-in patient browsing
// "Find a Doctor" could be silently logged out by an unrelated background
// call (e.g. a notifications-badge poll) failing with 401, while the doctor
// list's own request then surfaced the raw "Unauthenticated." backend text
// with no session left to recover — matching the reported bug exactly.
// Scoped to GET only: a write action like a follow-request still legitimately
// means "you need to be signed in" on a real 401, so that path keeps the
// original redirect-to-signin behavior unchanged.
const PUBLIC_GET_ENDPOINT_PATTERNS: RegExp[] = [
  /\/doctors(\/|\?|$)/,
  /\/caregivers(\/|\?|$)/,
  /\/diseases(\/|\?|$)/,
  /\/hospitals(\/|\?|$)/,
  /\/pharmacies(\/|\?|$)/,
  /\/medicines(\/|\?|$)/,
  /\/clinic-invites\//,
];

function isPublicGetEndpoint(url: string): boolean {
  return PUBLIC_GET_ENDPOINT_PATTERNS.some((pattern) => pattern.test(url));
}

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
      const isSafeToIgnore =
        req.method === 'GET' && isPublicGetEndpoint(req.url);

      if (error.status === 401 && !req.url.includes('/auth/login') && !isSafeToIgnore) {
        authService.clearSession();
        router.navigate(['/signin'], { replaceUrl: true });
      }
      return throwError(() => error);
    })
  );
};
