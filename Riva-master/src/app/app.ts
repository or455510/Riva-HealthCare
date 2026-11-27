import { Component, signal, OnInit, PLATFORM_ID, Inject } from '@angular/core';
import { RouterOutlet, Router, NavigationEnd } from '@angular/router';
import { CommonModule, isPlatformBrowser } from '@angular/common';
import { filter } from 'rxjs/operators';
import { AuthService } from './service/auth.service';
import { NavbarComponent } from './core/components/layout/navbar/navbar.component';
import { FooterComponent } from './core/components/layout/footer/footer.component';
import { LoadingComponent } from "./loading/loading.component";
import { AuthGuard } from './guards/auth-guard';
import { GuestGuard } from './guards/guest-guard';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [
    RouterOutlet,
    CommonModule,
    NavbarComponent,
    FooterComponent,
    LoadingComponent
],
  templateUrl: './app.html',
  styleUrls: ['./app.css']
})
export class App implements OnInit {

  protected readonly title = signal('project-riva');

  constructor(
    public router: Router,
    private auth: AuthService,
    @Inject(PLATFORM_ID) private platformId: object
  ) {}

  ngOnInit(): void {
    // Scroll to top on route change - فقط في البراوزر
    if (isPlatformBrowser(this.platformId)) {
      this.router.events
        .pipe(filter(event => event instanceof NavigationEnd))
        .subscribe(() => {
          window.scrollTo(0, 0);
        });
    }
  }

  // Hide navbar/footer on auth pages
  isAuthPage(): boolean {
    if (this.router.url.startsWith('/admin')) {
      return true;
    }

    // RIVA-124: previously a hand-maintained list of URL substrings that had
    // to be kept in sync with app.routes.ts by hand — which is exactly how
    // it went stale. clinic-staff/clinic-queue/clinic-patients/
    // clinic-services (and, checking every route, also verify-reset-code,
    // ai-chat, video-call, my-reports, and the appointment/notification
    // detail routes) were all added to app.routes.ts but never added here,
    // so the public marketing navbar/footer kept rendering on top of their
    // real dashboard layout. This now reads the guard actually applied to
    // the matched route instead: AuthGuard (a signed-in app/dashboard page)
    // or GuestGuard (the standalone sign-in/sign-up/password-reset forms)
    // both mean "this is not a marketing page" — so every current and
    // future guarded route is covered automatically, with nothing left to
    // remember to update by hand.
    let route = this.router.routerState.snapshot.root;
    while (route.firstChild) {
      route = route.firstChild;
    }
    const guards = route.routeConfig?.canActivate ?? [];
    if (guards.includes(AuthGuard) || guards.includes(GuestGuard)) {
      return true;
    }

    // A handful of app-shell pages intentionally carry no route guard at
    // all (the loading screen, the OAuth callback redirect target, and the
    // contact page) but still shouldn't show the marketing chrome — there's
    // no route-config signal to derive that from automatically, so these
    // stay an explicit, short list.
    const noGuardChromeHiddenPages = ['loading', 'auth/google/callback', 'contact'];
    return noGuardChromeHiddenPages.some(path => this.router.url.includes(path));
  }
}
