import { Component, CUSTOM_ELEMENTS_SCHEMA, PLATFORM_ID, Inject } from '@angular/core';
import { Router } from '@angular/router';
import { isPlatformBrowser } from '@angular/common';

@Component({
  selector: 'app-welcome1',
  standalone: true,
  imports: [],
  templateUrl: './welcome1.component.html',
  styleUrls: ['./welcome1.component.css'],
  schemas: [CUSTOM_ELEMENTS_SCHEMA]
})
export class Welcome1Component {
  constructor(
    private router: Router,
    @Inject(PLATFORM_ID) private platformId: object
  ) {}

  goToNextPage() {
    if (!isPlatformBrowser(this.platformId)) return;

    const role = localStorage.getItem('role')?.toLowerCase();

    if (role === 'doctor') {
      this.router.navigate(['/dashboard']);
    } else if (role === 'caregiver') {
      this.router.navigate(['/dashboard-caregiver']);
    } else if (role === 'admin') {
  this.router.navigate(['/admin/dashboard-admin']); // ← غيري دي
     

    } else if (role === 'patient') {
      this.router.navigate(['/dashboard-p']);
    } else {
      this.router.navigate(['/signin']);
    }
  }
}
 
