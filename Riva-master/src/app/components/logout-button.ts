import { CommonModule } from '@angular/common';
import { Component, Input } from '@angular/core';
import { Router } from '@angular/router';
import { AuthService } from '../service/auth.service';

@Component({
  selector: 'app-logout-button',
  standalone: true,
  imports: [CommonModule],
  template: `
    <button
      type="button"
      (click)="logout()"
      [class]="buttonClass"
      title="Logout"
      aria-label="Logout"
    >
      <i class="fas fa-right-from-bracket"></i>
      <span *ngIf="showLabel">Logout</span>
    </button>
  `,
})
export class LogoutButtonComponent {
  @Input() showLabel = false;
  @Input() buttonClass = 'p-2 text-gray-400 hover:text-red-600 transition-colors';

  constructor(private authService: AuthService, private router: Router) {}

  logout(): void {
    this.authService.logout();
    this.router.navigate(['/signin'], { replaceUrl: true });
  }
}
