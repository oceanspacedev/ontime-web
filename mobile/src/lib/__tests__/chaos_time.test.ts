import { formatTime } from '../format';
import { overtimeDateError, overtimeDurationMinutes } from '../overtime';
import { followToday, shiftDate } from '../team';
import { msUntilNextDay, unclosedNotice } from '../today';

/**
 * Time chaos on the device side. The server clock (Asia/Jakarta) is the only clock that judges attendance;
 * the app must show what the server decided, whatever the device's own clock or timezone says.
 */
describe('chaos: device timezone vs server time', () => {
  const realToLocaleTimeString = Date.prototype.toLocaleTimeString;

  /** Simulates a phone whose own timezone is `deviceZone`: formatting without an explicit timeZone uses it. */
  function onDeviceIn(deviceZone: string): void {
    jest.spyOn(Date.prototype, 'toLocaleTimeString').mockImplementation(function (this: Date, locales?: Intl.LocalesArgument, options?: Intl.DateTimeFormatOptions) {
      return realToLocaleTimeString.call(this, locales, { timeZone: deviceZone, ...options });
    });
  }

  afterEach(() => {
    jest.restoreAllMocks();
  });

  // FINDING: check-in/out timestamps arrive as UTC ISO strings ("...Z") and are rendered in the DEVICE
  // timezone, while shift hours arrive as bare server-local strings ("08:00"). A worker whose phone is on
  // WITA (Bali/Makassar, UTC+8) sees "Masuk 09:05" next to "Shift 08:00–16:00" for a check-in the server
  // recorded and judged at 08:05 WIB.
  it('shows a server check-in in server (Asia/Jakarta) time even when the device is on WITA', () => {
    onDeviceIn('Asia/Makassar');
    // Server: check-in Monday 08:05:00 Asia/Jakarta, serialized by Laravel as UTC.
    expect(formatTime('2026-11-16T01:05:00.000000Z')).toBe('08:05');
  });

  it('shows a server check-in at 08:05 WIB as 08:05 on a WIB device', () => {
    onDeviceIn('Asia/Jakarta');
    expect(formatTime('2026-11-16T01:05:00.000000Z')).toBe('08:05');
  });

  it('keeps bare server times untouched whatever the device timezone', () => {
    onDeviceIn('Asia/Jayapura');
    expect(formatTime('22:00:00')).toBe('22:00');
    expect(formatTime('00:00')).toBe('00:00');
  });
});

describe('chaos: midnight, month and year boundaries on the device', () => {
  it('moves the day view across month and year ends', () => {
    expect(shiftDate('2026-10-31', 1)).toBe('2026-11-01');
    expect(shiftDate('2026-12-31', 1)).toBe('2027-01-01');
    expect(shiftDate('2027-01-01', -1)).toBe('2026-12-31');
    expect(followToday('2026-12-31', '2026-12-31', '2027-01-01')).toBe('2027-01-01');
    expect(followToday('2026-12-30', '2026-12-31', '2027-01-01')).toBe('2026-12-30');
  });

  it('plans the midnight refresh at 23:59:59 on new year eve for just after midnight', () => {
    expect(msUntilNextDay(new Date(2026, 11, 31, 23, 59, 59, 0))).toBe(2000);
  });

  it('names 31 December as yesterday on the server date 1 January', () => {
    const notice = unclosedNotice({ date: '2026-12-31', check_in: '22:00', shift: 'Malam', correction_pending: false }, '2027-01-01');
    expect(notice?.message.startsWith('Kemarin')).toBe(true);
  });

  it('computes an overtime crossing midnight at month end like the server', () => {
    expect(overtimeDurationMinutes('22:00', '02:00')).toBe(240);
    expect(overtimeDurationMinutes('23:59', '00:00')).toBe(1);
  });

  it('accepts exactly 31 days back across a month boundary and refuses 32', () => {
    const today = new Date(2026, 10, 1, 0, 0, 0);
    expect(overtimeDateError('2026-10-01', today)).toBeNull();
    expect(overtimeDateError('2026-09-30', today)).not.toBeNull();
  });
});
