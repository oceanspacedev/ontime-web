import { initialCorrectionDate } from '../correction';

describe('initialCorrectionDate', () => {
  const today = new Date(2026, 10, 17, 9, 0);

  it('prefills the day the home card passes', () => {
    expect(initialCorrectionDate('2026-11-16', today)).toBe('2026-11-16');
  });

  it('accepts today', () => {
    expect(initialCorrectionDate('2026-11-17', today)).toBe('2026-11-17');
  });

  it('takes the first value of a repeated param', () => {
    expect(initialCorrectionDate(['2026-11-15', '2026-11-16'], today)).toBe('2026-11-15');
  });

  it('ignores a missing, empty or malformed param', () => {
    expect(initialCorrectionDate(undefined, today)).toBeNull();
    expect(initialCorrectionDate('', today)).toBeNull();
    expect(initialCorrectionDate('16/11/2026', today)).toBeNull();
  });

  it('ignores a date after today', () => {
    expect(initialCorrectionDate('2026-11-18', today)).toBeNull();
  });
});
