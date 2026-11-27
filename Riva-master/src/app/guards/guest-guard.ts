import { Injectable } from '@angular/core';
import { CanActivate, Router, UrlTree } from '@angular/router';
import { AuthService } from '../service/auth.service';

@Injectable({ providedIn: 'root' })
export class GuestGuard implements CanActivate {
  constructor(private auth: AuthService, private router: Router) {}

  canActivate(): boolean | UrlTree {
    if (!this.auth.isAuthenticated()) {
      return true;
    }

    const role = this.auth.getUserRole().toLowerCase();

    return this.router.createUrlTree([
      role === 'admin' || this.auth.isProfileCompleted()
        ? this.auth.dashboardRouteForRole()
        : this.auth.completionRouteForRole(role)
    ]);
  }
}
