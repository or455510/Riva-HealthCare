import { RiskLevel } from '../../../../../../service/doctor-dashboard.service';

/**
 * Phase 6 (dashboard component split): small, pure, dependency-free helpers
 * shared by more than one dashboard sub-component. Kept as plain functions
 * (not a service — nothing here has state) so every split-out component
 * that needs currency formatting or risk labeling reads from exactly one
 * place, instead of re-implementing the same K/M rounding or risk
 * vocabulary and drifting apart over time.
 */

/** Shared by dashboard-today and dashboard-business (both render real
 * currency figures returned by GET /dashboard/doctor). */
export function formatCompactCurrency(value: number): string {
  const num = Number(value) || 0;
  const abs = Math.abs(num);
  if (abs >= 1_000_000) return (num / 1_000_000).toFixed(1) + 'M';
  if (abs >= 1_000) return (num / 1_000).toFixed(1) + 'K';
  return num.toLocaleString('en-US', { maximumFractionDigits: 0 });
}

/**
 * Shared by dashboard.component.ts (My Patients sorting) and
 * dashboard-attention (review-required badges). Matches the real
 * `daily_statuses.risk_level` DB enum exactly — see doctor-dashboard.service.ts.
 * A patient/status with no resolvable risk level is labeled "No data",
 * never guessed as "Stable" (Phase 5 anti-fabrication rule, carried over
 * unchanged into Phase 6).
 */
export function riskLabel(riskLevel: RiskLevel | undefined): string {
  switch (riskLevel) {
    case 'high_risk': return 'Critical';
    case 'moderate_risk': return 'Attention';
    case 'attention_needed': return 'Attention';
    case 'stable': return 'Stable';
    default: return 'No data';
  }
}

/** Sort weight for riskLabel() — lower sorts first (most urgent first). */
export function riskRank(riskLevel: RiskLevel | undefined): number {
  switch (riskLevel) {
    case 'high_risk': return 0;
    case 'moderate_risk': return 1;
    case 'attention_needed': return 1;
    case 'stable': return 2;
    default: return 3;
  }
}
