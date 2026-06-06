import { Injectable } from "@angular/core";
import { ActivatedRouteSnapshot, CanActivate, Router, UrlTree } from '@angular/router';
import { AuthService } from "../service/auth.service";

@Injectable({ providedIn: 'root' })
export class AuthGuard implements CanActivate {

  constructor(private auth: AuthService, private router: Router) {}

  canActivate(route: ActivatedRouteSnapshot): boolean | UrlTree {
    if (!this.auth.isAuthenticated()) {
      return this.router.createUrlTree(['/signin']);
    }

    const allowedRoles = route.data?.['roles'] as string[] | undefined;
    const role = this.auth.getUserRole().toLowerCase();

    if (allowedRoles?.length && !allowedRoles.map(item => item.toLowerCase()).includes(role)) {
      return this.router.createUrlTree(['/unauthorized']);
    }

    const allowIncompleteProfile = route.data?.['allowIncompleteProfile'] === true;

    // ✅ لو مفيش role خالص (Google user لسه مختارش) اسمحله يعدّي
    if (!role) return true;

    if (!allowIncompleteProfile && role !== 'admin' && !this.auth.isProfileCompleted()) {
      // ✅ لو هو أصلاً في صفحة complete-profile متبعتوش تاني في loop
      const currentUrl = route.pathFromRoot.map(r => r.url.map(s => s.path).join('/')).join('/');
      if (currentUrl.includes('complete-profile')) return true;

      return this.router.createUrlTree([this.auth.completionRouteForRole(role)]);
    }

    return true;
  }
}