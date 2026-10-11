/**
 * @fileoverview Day-count parsing for the first-purchase lookback window
 */

/**
 * Parses a day count written with the digits 0-9 only, 1 or more.
 * @param raw - The text in the field
 * @returns The value to save (a number, or a digit string when too large for a number), or
 * null when the text is not such a count
 */
export function parseWholeDays(raw: string): number | string | null {
  const digits = raw.trim();
  if (!/^[0-9]+$/.test(digits)) {
    return null;
  }
  const normalized = digits.replace(/^0+/, '');
  if (normalized === '') {
    return null;
  }
  // Past this length a JS number loses digits; the server caps the string itself.
  return normalized.length <= 15 ? Number(normalized) : normalized;
}
