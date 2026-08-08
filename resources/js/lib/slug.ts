/**
 * Maximum slug length, mirroring the `projects.slug` column and the server
 * side validation rule.
 */
export const SLUG_MAX_LENGTH = 60;

/** Combining diacritical marks, left over once a string is NFD-normalized. */
const DIACRITICS = /[\u0300-\u036f]/g;

/**
 * Derive a URL-safe slug from a free-form name.
 *
 * This only suggests: the server remains the authority on what a valid,
 * available slug is.
 */
export function toSlug(value: string): string {
    return value
        .normalize('NFD')
        .replace(DIACRITICS, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .slice(0, SLUG_MAX_LENGTH)
        .replace(/^-+|-+$/g, '');
}
