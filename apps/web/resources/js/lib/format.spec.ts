import { describe, expect, it } from 'vitest';
import {
    formatDuration,
    formatFileSize,
    formatLastSeen,
    formatMessageDay,
    formatPresence,
    messageDayKey,
} from './format';

const NOW = new Date(2026, 9, 31, 12, 0, 0);
const NOW_MS = NOW.getTime();

function localIso(
    year: number,
    month: number,
    day: number,
    hour = 12,
    minute = 0,
): string {
    return new Date(year, month - 1, day, hour, minute).toISOString();
}

function minutesAgo(minutes: number): string {
    return new Date(NOW_MS - minutes * 60_000).toISOString();
}

describe('formatPresence', () => {
    it('labels a member with a live connection as online', () => {
        expect(
            formatPresence(
                { online: true, last_seen_at: minutesAgo(240) },
                NOW_MS,
            ),
        ).toBe('Online');
    });

    it('falls back to the relative last seen label when offline', () => {
        expect(
            formatPresence(
                { online: false, last_seen_at: minutesAgo(5) },
                NOW_MS,
            ),
        ).toBe('Last seen 5m ago');
    });

    it('reports offline when the member never connected', () => {
        expect(
            formatPresence({ online: false, last_seen_at: null }, NOW_MS),
        ).toBe('Offline');
    });

    it('treats a missing participant as no presence at all', () => {
        expect(formatPresence(null, NOW_MS)).toBeNull();
    });
});

describe('formatLastSeen', () => {
    it('describes recent activity in relative units', () => {
        expect(formatLastSeen(minutesAgo(0), NOW_MS)).toBe(
            'Last seen just now',
        );
        expect(formatLastSeen(minutesAgo(5), NOW_MS)).toBe('Last seen 5m ago');
        expect(formatLastSeen(minutesAgo(3 * 60), NOW_MS)).toBe(
            'Last seen 3h ago',
        );
        expect(formatLastSeen(minutesAgo(2 * 24 * 60), NOW_MS)).toBe(
            'Last seen 2d ago',
        );
    });

    it('falls back to a calendar date after a week', () => {
        const label = formatLastSeen(localIso(2026, 1, 2, 9), NOW_MS);

        expect(label).toContain('Last seen');
        expect(label).toContain('2026');
    });

    it('reports offline for missing or invalid timestamps', () => {
        expect(formatLastSeen(null, NOW_MS)).toBe('Offline');
        expect(formatLastSeen('not-a-date', NOW_MS)).toBe('Offline');
    });
});

describe('messageDayKey', () => {
    it('shares a key for messages on the same local day', () => {
        expect(messageDayKey(localIso(2026, 10, 31, 0, 5))).toBe(
            messageDayKey(localIso(2026, 10, 31, 23, 55)),
        );
    });

    it('separates keys across days, months and years', () => {
        expect(messageDayKey(localIso(2026, 10, 31))).not.toBe(
            messageDayKey(localIso(2026, 11, 1)),
        );
        expect(messageDayKey(localIso(2026, 10, 31))).not.toBe(
            messageDayKey(localIso(2025, 10, 31)),
        );
    });

    it('passes unparseable timestamps through so rows stay unique', () => {
        expect(messageDayKey('not-a-date')).toBe('not-a-date');
    });
});

describe('formatMessageDay', () => {
    it('labels the current and previous day by name', () => {
        expect(formatMessageDay(localIso(2026, 10, 31, 0, 30), NOW_MS)).toBe(
            'Today',
        );
        expect(formatMessageDay(localIso(2026, 10, 30, 23, 30), NOW_MS)).toBe(
            'Yesterday',
        );
    });

    it('uses the weekday for earlier days in the current year', () => {
        const label = formatMessageDay(localIso(2026, 1, 5), NOW_MS);

        expect(label).toContain('Monday');
        expect(label).toContain('Jan');
        expect(label).not.toContain('2026');
    });

    it('spells out the year for messages from another year', () => {
        const label = formatMessageDay(localIso(2025, 12, 24), NOW_MS);

        expect(label).toContain('Dec');
        expect(label).toContain('2025');
    });

    it('returns an empty label for unparseable timestamps', () => {
        expect(formatMessageDay('not-a-date', NOW_MS)).toBe('');
    });
});

describe('formatDuration', () => {
    it('formats a clip as a media clock', () => {
        expect(formatDuration(7_000)).toBe('0:07');
        expect(formatDuration(271_000)).toBe('4:31');
    });

    it('adds an hour segment for long clips', () => {
        expect(formatDuration(3_729_000)).toBe('1:02:09');
    });

    it('renders unknown durations as an empty label', () => {
        expect(formatDuration(null)).toBe('');
        expect(formatDuration(0)).toBe('');
        expect(formatDuration(Number.NaN)).toBe('');
    });
});

describe('formatFileSize', () => {
    it('switches units as the size grows', () => {
        expect(formatFileSize(512)).toBe('512 B');
        expect(formatFileSize(2_048)).toBe('2 KB');
        expect(formatFileSize(26_214_400)).toBe('25 MB');
    });

    it('renders invalid sizes as an empty label', () => {
        expect(formatFileSize(-1)).toBe('');
        expect(formatFileSize(Number.POSITIVE_INFINITY)).toBe('');
    });
});
