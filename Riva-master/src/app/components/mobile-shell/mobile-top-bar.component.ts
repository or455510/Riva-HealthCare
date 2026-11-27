import { Component, Input, OnDestroy, OnInit } from '@angular/core';
import { CommonModule, Location } from '@angular/common';
import { NavigationEnd, Router, RouterLink } from '@angular/router';
import { Subscription } from 'rxjs';
import { filter } from 'rxjs/operators';

/**
 * Mobile App Shell (M1 — Mobile Redesign): the authenticated app's mobile-only
 * top bar. Rendered by SidebarComponent (the same place the desktop sidebar
 * itself is rendered), so it appears on every page that already shows the
 * sidebar and nowhere else — no per-page wiring required.
 *
 * Deliberately does NOT include a "more" (⋮) icon like the original
 * conceptual mock: MobileBottomNavComponent already has a "More" tab, and
 * having the same destination reachable from two places in the shell would
 * be duplicate navigation (explicitly disallowed for this batch).
 */
@Component({
  selector: 'app-mobile-top-bar',
  standalone: true,
  imports: [CommonModule, RouterLink],
  template: `
    <header class="mobile-top-bar md:hidden" role="banner">
      <button
        *ngIf="showBack"
        type="button"
        class="mobile-top-bar__icon-btn"
        (click)="goBack()"
        aria-label="Back"
      >
        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
      </button>
      <span *ngIf="!showBack" class="mobile-top-bar__spacer" aria-hidden="true"></span>

      <a [routerLink]="dashboardRoute" class="mobile-top-bar__title" aria-label="Riva home">Riva</a>

      <a routerLink="/notifications" class="mobile-top-bar__icon-btn" aria-label="Notifications">
        <i class="fa-regular fa-bell" aria-hidden="true"></i>
      </a>
    </header>
  `,
  styles: [`
    .mobile-top-bar {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      z-index: 40;
      height: var(--mobile-topbar-h, 56px);
      padding-top: env(safe-area-inset-top);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      padding-inline: 8px;
      background: #ffffff;
      border-bottom: 1px solid #e2e8f0;
    }
    .mobile-top-bar__icon-btn,
    .mobile-top-bar__spacer {
      width: 44px;
      height: 44px;
      display: grid;
      place-items: center;
      border-radius: 12px;
      color: #64748b;
      flex-shrink: 0;
    }
    .mobile-top-bar__icon-btn {
      text-decoration: none;
    }
    .mobile-top-bar__icon-btn:hover,
    .mobile-top-bar__icon-btn:focus-visible {
      background: #f1f5f9;
      color: #2563eb;
      outline: none;
    }
    .mobile-top-bar__title {
      font-weight: 800;
      font-size: 17px;
      color: #1e293b;
      text-decoration: none;
    }
  `],
})
export class MobileTopBarComponent implements OnInit, OnDestroy {
  /** The current role's dashboard route (e.g. '/dashboard-p') — Back is
   *  suppressed only when the current page IS that root, per the explicit
   *  "don't show Back blindly everywhere" requirement. */
  @Input() dashboardRoute = '/';

  showBack = false;
  private sub?: Subscription;

  constructor(private router: Router, private location: Location) {}

  ngOnInit(): void {
    this.updateShowBack();
    this.sub = this.router.events
      .pipe(filter((event) => event instanceof NavigationEnd))
      .subscribe(() => this.updateShowBack());
  }

  ngOnDestroy(): void {
    this.sub?.unsubscribe();
  }

  goBack(): void {
    // Angular's Location service (not window.location / history hacks) —
    // walks the SPA's own navigation history without a full page reload.
    this.location.back();
  }

  private updateShowBack(): void {
    const currentPath = this.router.url.split('?')[0].split('#')[0];
    this.showBack = currentPath !== this.dashboardRoute;
  }
}
