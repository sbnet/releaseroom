const MINUTE = 60;
const HOUR = MINUTE * 60;
const DAY = HOUR * 24;
const WEEK = DAY * 7;
const MONTH = DAY * 30;
const YEAR = DAY * 365;

const THRESHOLDS: Array<[Intl.RelativeTimeFormatUnit, number]> = [
    ['year', YEAR],
    ['month', MONTH],
    ['week', WEEK],
    ['day', DAY],
    ['hour', HOUR],
    ['minute', MINUTE],
];

/**
 * Render an ISO timestamp as "3 minutes ago", in the viewer's locale.
 *
 * Used for the last verification of a repository connection, where the
 * distance matters far more than the exact instant.
 */
export function toRelativeTime(iso: string): string {
    const seconds = (Date.now() - new Date(iso).getTime()) / 1000;
    const formatter = new Intl.RelativeTimeFormat(undefined, {
        numeric: 'auto',
    });

    for (const [unit, threshold] of THRESHOLDS) {
        if (Math.abs(seconds) >= threshold) {
            return formatter.format(-Math.round(seconds / threshold), unit);
        }
    }

    return formatter.format(-Math.round(seconds), 'second');
}

/**
 * Render an ISO timestamp as an absolute date, for the values a viewer may
 * want to compare or write down.
 */
export function toLongDate(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
}
