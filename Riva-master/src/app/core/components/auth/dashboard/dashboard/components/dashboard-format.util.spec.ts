import { formatCompactCurrency, riskLabel, riskRank } from './dashboard-format.util';

describe('dashboard-format.util', () => {
  describe('formatCompactCurrency', () => {
    it('formats sub-thousand values as plain numbers', () => {
      expect(formatCompactCurrency(350)).toBe('350');
    });
    it('formats thousands with a K suffix', () => {
      expect(formatCompactCurrency(1500)).toBe('1.5K');
    });
    it('formats millions with an M suffix', () => {
      expect(formatCompactCurrency(2_500_000)).toBe('2.5M');
    });
    it('treats non-finite/undefined-ish input as 0', () => {
      expect(formatCompactCurrency(NaN)).toBe('0');
    });
  });

  describe('riskLabel', () => {
    it('maps high_risk to Critical', () => expect(riskLabel('high_risk')).toBe('Critical'));
    it('maps moderate_risk and attention_needed to Attention', () => {
      expect(riskLabel('moderate_risk')).toBe('Attention');
      expect(riskLabel('attention_needed')).toBe('Attention');
    });
    it('maps stable to Stable', () => expect(riskLabel('stable')).toBe('Stable'));
    it('never guesses Stable for an unresolved risk level', () => expect(riskLabel(undefined)).toBe('No data'));
  });

  describe('riskRank', () => {
    it('ranks high_risk most urgent (lowest number)', () => {
      expect(riskRank('high_risk')).toBeLessThan(riskRank('moderate_risk'));
      expect(riskRank('moderate_risk')).toBeLessThan(riskRank('stable'));
      expect(riskRank('stable')).toBeLessThan(riskRank(undefined));
    });
  });
});
