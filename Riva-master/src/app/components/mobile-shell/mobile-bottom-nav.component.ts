import {
  Component,
  ElementRef,
  EventEmitter,
  Input,
  OnChanges,
  OnDestroy,
  OnInit,
  Output,
  SimpleChanges,
  ViewChild,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { NavigationEnd, Router, RouterLink } from '@angular/router';
import { Subscription } from 'rxjs';
import { filter } from 'rxjs/operators';
import { Role, SidebarItem } from '../sidebar';
import { MOBILE_PRIMARY_ROUTES } from './mobile-nav.config';

interface Tab {
  icon: string;
  route: string;
  title: string;
}

/**
 * Mobile App Shell (M1 — Mobile Redesign): the authenticated app's mobile-only
 * bottom tab bar. Tabs are Home + a role-curated top 2 (see
 * mobile-nav.config.ts) + More — never the same 4 tabs for every role, per
 * the explicit requirement not to blindly reuse one tab set across roles.
 *
 * Active-state detection is done manually against `router.url` rather than
 * `routerLinkActive`'s containment algorithm: `/chat` and `/chat/:id` are two
 * separate sibling routes in app.routes.ts (not a parent/child pair), so
 * `routerLinkActive` would not mark the Chat tab active while viewing
 * `/chat/5`. This does the same job without touching route definitions.
 */
@Component({
  selector: 'app-mobile-bottom-nav',
  standalone: true,
  imports: [CommonModule, RouterLink],
  template: `
    <nav class="mobile-bottom-nav md:hidden" role="navigation" aria-label="Primary">
      <a
        *ngFor="let tab of tabs"
        [routerLink]="tab.route"
        class="mobile-bottom-nav__item"
        [class.is-active]="isActive(tab.route)"
        [attr.aria-label]="tab.title"
        [attr.aria-current]="isActive(tab.route) ? 'page' : null"
      >
        <i [class]="tab.icon" aria-hidden="true"></i>
        <span>{{ tab.title }}</span>
      </a>
      <button
        #moreBtn
        type="button"
        class="mobile-bottom-nav__item"
        [class.is-active]="moreOpen"
        (click)="more.emit()"
        aria-label="More"
        aria-haspopup="dialog"
        [attr.aria-expanded]="moreOpen"
      >
        <i class="fa-solid fa-ellipsis" aria-hidden="true"></i>
        <span>More</span>
      </button>
    </nav>
  `,
  styles: [`
    .mobile-bottom-nav {
      position: fixed;
      left: 0;
      right: 0;
      bottom: 0;
      z-index: 40;
      height: calc(var(--mobile-bottomnav-h, 60px) + env(safe-area-inset-bottom));
      padding-bottom: env(safe-area-inset-bottom);
      display: flex;
      background: #ffffff;
      border-top: 1px solid #e2e8f0;
    }
    .mobile-bottom-nav__item {
      flex: 1 1 0;
      min-height: 48px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 2px;
      font-size: 11px;
      font-weight: 600;
      color: #94a3b8;
      background: transparent;
      border: none;
      text-decoration: none;
    }
    .mobile-bottom-nav__item i {
      font-size: 18px;
    }
    .mobile-bottom-nav__item.is-active {
      color: #2563eb;
    }
    .mobile-bottom-nav__item:focus-visible {
      outline: 2px solid #2563eb;
      outline-offset: -2px;
      border-radius: 8px;
    }
  `],
})
export class MobileBottomNavComponent implements OnInit, OnChanges, OnDestroy {
  @Input() role: Role = 'patient';
  @Input() menuItems: SidebarItem[] = [];
  @Input() dashboardRoute = '/';
  @Input() moreOpen = false;
  @Output() more = new EventEmitter<void>();

  @ViewChild('moreBtn') moreBtn?: ElementRef<HTMLButtonElement>;

  currentPath = '';
  private sub?: Subscription;

  constructor(private router: Router) {}

  ngOnInit(): void {
    this.updateCurrentPath();
    this.sub = this.router.events
      .pipe(filter((event) => event instanceof NavigationEnd))
      .subscribe(() => this.updateCurrentPath());
  }

  ngOnChanges(changes: SimpleChanges): void {
    // Return focus to the More trigger once the sheet it opens closes, so
    // keyboard/screen-reader users land back where they started rather than
    // losing focus into the document body.
    const change = changes['moreOpen'];
    if (change && change.previousValue === true && change.currentValue === false) {
      this.moreBtn?.nativeElement.focus();
    }
  }

  ngOnDestroy(): void {
    this.sub?.unsubscribe();
  }

  get tabs(): Tab[] {
    const home = this.menuItems.find((item) => item.route === this.dashboardRoute);
    const homeTab: Tab[] = home ? [{ icon: home.icon, route: home.route, title: 'Home' }] : [];

    const primaryRoutes = MOBILE_PRIMARY_ROUTES[this.role] ?? [];
    const curated = primaryRoutes
      .map((route) => this.menuItems.find((item) => item.route === route))
      .filter((item): item is SidebarItem => !!item)
      .map((item) => ({ icon: item.icon, route: item.route, title: item.title }));

    return [...homeTab, ...curated];
  }

  isActive(route: string): boolean {
    return this.currentPath === route || this.currentPath.startsWith(`${route}/`);
  }

  private updateCurrentPath(): void {
    this.currentPath = this.router.url.split('?')[0].split('#')[0];
  }
}
