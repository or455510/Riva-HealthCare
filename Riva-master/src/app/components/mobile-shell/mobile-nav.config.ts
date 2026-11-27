import { Role } from '../sidebar';

/**
 * Mobile App Shell (M1 — Mobile Redesign): curated "top destinations" per
 * role for the mobile bottom tab bar. Route strings only — icon/title are
 * always read from the same `menuItems` the desktop sidebar already renders
 * (see SidebarComponent.menuItems), so the mobile tabs can never drift out
 * of sync with the desktop sidebar or with app.routes.ts. This is the single
 * source of truth for the curation and is shared by MobileBottomNavComponent
 * and MobileShellComponent — do not duplicate this list elsewhere.
 *
 * "Home" itself is not listed here: it is always derived from the role's own
 * dashboard route (see AuthService.dashboardRouteForRole()).
 *
 * Admin is intentionally empty: admin pages never render <app-sidebar> (they
 * use AdminLayoutComponent/AdminSidebarComponent instead, which already has
 * its own mobile navigation), so this map is never consulted for admin.
 */
export const MOBILE_PRIMARY_ROUTES: Record<Role, string[]> = {
  patient: ['/add-new-medication', '/chat'],
  doctor: ['/patient-cards', '/chat'],
  caregiver: ['/patient-cards', '/chat'],
  admin: [],
};
