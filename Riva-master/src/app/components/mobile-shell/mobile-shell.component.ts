import { Component, EventEmitter, Input, Output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Role, SidebarItem } from '../sidebar';
import { MOBILE_PRIMARY_ROUTES } from './mobile-nav.config';
import { MobileTopBarComponent } from './mobile-top-bar.component';
import { MobileBottomNavComponent } from './mobile-bottom-nav.component';
import { MobileMoreSheetComponent } from './mobile-more-sheet.component';

/**
 * Mobile App Shell (M1 — Mobile Redesign): composes the mobile-only top bar,
 * bottom tab bar, and "More" sheet. Rendered directly inside SidebarComponent
 * (see sidebar.ts), immediately after the desktop <aside>, so it is present
 * on exactly the same set of pages as the desktop sidebar and nowhere else —
 * no per-page wiring, no app.routes.ts changes, no app.html changes.
 *
 * All three children are `md:hidden` internally, so at 768px+ this entire
 * shell renders nothing visible; the desktop <aside> is untouched and is the
 * only navigation shown there.
 *
 * Role/menu data is not recomputed here: it is passed down from
 * SidebarComponent's own already-existing `role`/`menuItems`/`dashboardRoute`
 * getters, which are themselves backed by AuthService — the single existing
 * source of truth for the current user's role. This avoids a second,
 * independent role-checking code path.
 */
@Component({
  selector: 'app-mobile-shell',
  standalone: true,
  imports: [CommonModule, MobileTopBarComponent, MobileBottomNavComponent, MobileMoreSheetComponent],
  template: `
    <app-mobile-top-bar [dashboardRoute]="dashboardRoute"></app-mobile-top-bar>

    <app-mobile-bottom-nav
      [role]="role"
      [menuItems]="menuItems"
      [dashboardRoute]="dashboardRoute"
      [moreOpen]="moreOpen"
      (more)="moreOpen = !moreOpen"
    ></app-mobile-bottom-nav>

    <app-mobile-more-sheet
      [open]="moreOpen"
      [items]="moreItems"
      (close)="moreOpen = false"
      (logout)="logout.emit()"
    ></app-mobile-more-sheet>
  `,
})
export class MobileShellComponent {
  @Input() role: Role = 'patient';
  @Input() menuItems: SidebarItem[] = [];
  @Input() dashboardRoute = '/';
  @Output() logout = new EventEmitter<void>();

  moreOpen = false;

  /**
   * Everything in the desktop sidebar's menu that isn't already reachable
   * from Home or one of the bottom bar's curated tabs — built from the same
   * MOBILE_PRIMARY_ROUTES map the bottom nav uses (imported, not
   * re-declared), so the two navigation surfaces can never disagree about
   * which routes are "primary" vs. "more".
   */
  get moreItems(): SidebarItem[] {
    const primaryRoutes = new Set(MOBILE_PRIMARY_ROUTES[this.role] ?? []);
    return this.menuItems.filter(
      (item) => item.route !== this.dashboardRoute && !primaryRoutes.has(item.route)
    );
  }
}
