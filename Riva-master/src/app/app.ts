// import { Component, signal, OnInit } from '@angular/core';
// import { RouterOutlet, Router, NavigationEnd } from '@angular/router';
// import { CommonModule } from '@angular/common';
// import { filter } from 'rxjs/operators';
// import { AuthService } from './service/auth.service';

// import { SigninComponent } from './core/components/auth/signin/signin.component';
// import { ForgetPasswordComponent } from './core/components/auth/forget-password/forget-password.component';
// import { NewPasswordComponent } from './core/components/auth/new-password/new-password.component';
// import { SignupComponent } from './core/components/auth/signup/signup.component';
// import { ProfileAccountComponent } from './core/components/auth/profile-account/profile-account.component';
// import { NavbarComponent } from './core/components/layout/navbar/navbar.component';
// import { FooterComponent } from './core/components/layout/footer/footer.component';

// @Component({
//   selector: 'app-root',
//   standalone: true,
//   imports: [
//     RouterOutlet,
//     CommonModule,
//     SigninComponent,
//     ForgetPasswordComponent,
//     NewPasswordComponent,
//     SignupComponent,
//     ProfileAccountComponent,
//     NavbarComponent,
//     FooterComponent,

//   ],
//   templateUrl: './app.html',
//   styleUrls: ['./app.css']
// })
// export class App implements OnInit {

//   protected readonly title = signal('project-riva');

//   constructor(
//     public router: Router,
//     private auth: AuthService
//   ) {}

//   ngOnInit(): void {
//     // Scroll to top on route change
//     this.router.events
//       .pipe(filter(event => event instanceof NavigationEnd))
//       .subscribe(() => {
//         window.scrollTo(0, 0);
//       });

//     // AUTO LOGIN
//     const token = this.auth.getToken();
//     // if (token) {
//     //   this.router.navigate(['/dashboard-p']);
//     // } else {
//     //   this.router.navigate(['/home']);
//     // }
//   }

//   // Hide navbar/footer on auth pages
//   isAuthPage(): boolean {
//     const authRoutes = [
//       'signin',
//       'signup',
//       'forget-password',
//       'new-password',
//       'profile-account',
//       'dashboard-p',
//       'welcome1',
//       'add-new-medication',
//       'doctor-cards',
//       'chat',
//       'Dashboard'

//     ];
//     return authRoutes.some(route => this.router.url.includes(route));
//   }
// }
import { Component, signal, OnInit, PLATFORM_ID, Inject } from '@angular/core';
import { RouterOutlet, Router, NavigationEnd } from '@angular/router';
import { CommonModule, isPlatformBrowser } from '@angular/common';
import { filter } from 'rxjs/operators';
import { AuthService } from './service/auth.service';
import { NavbarComponent } from './core/components/layout/navbar/navbar.component';
import { FooterComponent } from './core/components/layout/footer/footer.component';
import { LoadingComponent } from "./loading/loading.component";

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
    const authRoutes = [
      'signin',
      'signup',
      'complete-profile/patient',
      'complete-profile/doctor',
      'complete-profile/caregiver',
      '/appointments/book',
      'forget-password',
      'new-password',
      'profile-account',
      'dashboard-p',
      'welcome1',
      'add-new-medication',
      'doctor-cards',
      'chat',
      'dashboard',
      'contact',
      'myprofile',
      'signup2',
      'booking',
      'patient-cards',
      'profile-d',
      'doctors-follow-request',
      'caregiver-cards',
      'profile-c',
      'caregiver-follow-request',
      'dashboard-admin',
      'dashboard-caregiver',
      'chat-c' ,
      'report',
      'profile-p',
      'notifications',
      'dashboard-admin',
      'activity-logs',
      'users',
      'loading',
      'auth/google/callback',
    
    ];
    return authRoutes.some(route => this.router.url.includes(route));
  }
}