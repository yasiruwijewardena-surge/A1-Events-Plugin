// Maps an already-normalized event from the Events Showcase REST API
// (see wordpress-plugin/.../class-events-repository.php Events_Repository::normalise())
// into the display-ready shape the components use — mostly just formatting
// the ISO 8601 dates into human-readable labels. Unlike the old version of
// this file, there's no WP REST/_embed/ACF shape to dig through here: the
// PHP repository already did that flattening, which is the whole point of
// having a single repository both the shortcode and the REST API call.
//
// Everything here formats in the *event's* timezone (the WordPress site's,
// passed down from the shortcode as data-timezone), not the visitor's.
// That distinction is the whole reason this file takes a timeZone at all:
// these are physical events, and an 18:30 meetup in Colombo is at 18:30
// for everyone reading about it. Formatting in the visitor's timezone —
// which is what a bare toLocaleString() does — made the same event show a
// different time, and sometimes a different date, to every visitor.
//
// Note that only the *timezone* is pinned. The locale is still the
// visitor's, so word order, month names and 12-vs-24-hour clock continue
// to follow their own conventions; it's the instant being described that
// is held fixed, not the language describing it.

/**
 * @param {object} raw   One event from the REST API / inline payload.
 * @param {string} [timeZone]  IANA name or UTC offset from data-timezone.
 *   Falls back to the visitor's own timezone when absent or unusable.
 */
export function normalizeEvent(raw, timeZone) {
  const fmt = formattersFor(timeZone);

  const startDate = raw.start_datetime ? new Date(raw.start_datetime) : null;
  const endDate = raw.end_datetime ? new Date(raw.end_datetime) : null;
  const categories = raw.categories || [];
  const allDay = Boolean(raw.all_day);

  return {
    id: raw.id,
    title: raw.title,
    permalink: raw.permalink,
    excerpt: raw.excerpt,
    description: raw.content,
    venue: raw.venue,
    location: raw.location,
    externalUrl: raw.external_url,
    // Cards/filters show one category label; a select dropdown still needs
    // every slug the event belongs to, in case an event has more than one.
    category: categories[0]?.name || 'General',
    categorySlugs: categories.map((c) => c.slug),
    thumbnail: raw.thumbnail?.url || '',
    thumbnailAlt: raw.thumbnail?.alt || '',
    // Carried through so <img> can set width/height and reserve its
    // aspect ratio before the file has loaded, instead of the layout
    // jumping once it does.
    thumbnailWidth: raw.thumbnail?.width || null,
    thumbnailHeight: raw.thumbnail?.height || null,
    startDate,
    endDate,
    allDay,
    // Raw ISO string kept alongside the parsed Date so the card can put a
    // machine-readable datetime on its <time> element without having to
    // re-serialise (and risk shifting) the value.
    startIso: raw.start_datetime || '',
    // Month/day split out for the card's calendar-tile date. Month is
    // whatever the visitor's locale calls it, uppercased in CSS rather
    // than here — toUpperCase() on some locales produces a different
    // string length than the display font expects, and letting CSS do it
    // keeps the original around for anything that wants it.
    dateTile: startDate
      ? {
          month: fmt.tileMonth.format(startDate),
          day: fmt.tileDay.format(startDate),
        }
      : null,
    // Falls back to 'scheduled' defensively — normalise() on the PHP
    // side already guarantees this, but a field this display-sensitive
    // (it drives a "Cancelled" notice) shouldn't trust the network on
    // top of trusting the server.
    status: raw.status || 'scheduled',
    // dateLabel is the compact form (card): dates only, never a weekday
    // or a time, even for a same-day event with both a start and end
    // time — a quick-scan card doesn't need that level of detail.
    // fullDateLabel is the full form (modal): adds the weekday and,
    // for a same-day event that isn't all-day, the start–end time range.
    // Both collapse a multi-day range the same way regardless of which
    // form is used, since neither the "same month" nor the "spans
    // months" case shows times in the first place.
    dateLabel: startDate ? formatDateRange(startDate, endDate, fmt, { allDay, full: false }) : '',
    fullDateLabel: startDate ? formatDateRange(startDate, endDate, fmt, { allDay, full: true }) : '',
  };
}

/**
 * Returns `timeZone` if this engine can actually format with it, else
 * undefined so Intl falls back to the visitor's own zone.
 *
 * IANA names are universally supported; a bare UTC offset like "+05:30"
 * — which wp_timezone_string() returns when the site is configured with
 * a manual offset rather than a city — is only accepted by newer engines
 * and throws a RangeError on older ones. Showing a slightly wrong time
 * beats throwing during render.
 */
function resolveTimeZone(timeZone) {
  if (!timeZone) return undefined;

  try {
    new Intl.DateTimeFormat(undefined, { timeZone });
    return timeZone;
  } catch {
    return undefined;
  }
}

// Intl.DateTimeFormat construction is comparatively expensive and these
// are rebuilt for every event in a listing otherwise. Keyed by timezone
// rather than module-level constants because the timezone now varies —
// two shortcode instances on one page could in principle be handed
// different ones.
const FORMATTER_CACHE = new Map();

function formattersFor(timeZone) {
  const key = timeZone || '';
  const cached = FORMATTER_CACHE.get(key);
  if (cached) return cached;

  const tz = resolveTimeZone(timeZone);
  const built = {
    // One formatter serves both the single compact date and the compact
    // range, via .format() and .formatRange() — the option set is
    // identical, so there's no reason to build it twice.
    compactDate: new Intl.DateTimeFormat(undefined, {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
      timeZone: tz,
    }),
    fullDateTime: new Intl.DateTimeFormat(undefined, {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      timeZone: tz,
    }),
    fullDateOnly: new Intl.DateTimeFormat(undefined, {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric',
      timeZone: tz,
    }),
    rangeWeekday: new Intl.DateTimeFormat(undefined, {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      year: 'numeric',
      timeZone: tz,
    }),
    rangeTime: new Intl.DateTimeFormat(undefined, {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      timeZone: tz,
    }),
    tileMonth: new Intl.DateTimeFormat(undefined, { month: 'short', timeZone: tz }),
    tileDay: new Intl.DateTimeFormat(undefined, { day: 'numeric', timeZone: tz }),
    // Used only to decide whether two instants land on the same calendar
    // day — see isSameDay(). Fixed to en-CA for a stable, sortable
    // YYYY-MM-DD shape; this string is compared, never displayed, so the
    // visitor's locale is irrelevant here and would only add variance.
    dayKey: new Intl.DateTimeFormat('en-CA', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      timeZone: tz,
    }),
  };

  FORMATTER_CACHE.set(key, built);
  return built;
}

/**
 * Whether two instants fall on the same calendar day *in the event's
 * timezone*.
 *
 * Deliberately not getFullYear()/getMonth()/getDate(), which read the
 * visitor's timezone: an event running 23:00–01:00 is one day in one
 * timezone and two in another, and this decides whether the modal shows
 * a time range or a date range. Comparing formatted day keys keeps that
 * decision in the same timezone as the formatting it controls.
 */
function isSameDay(a, b, fmt) {
  return fmt.dayKey.format(a) === fmt.dayKey.format(b);
}

/**
 * Formats a start/end date pair, collapsing sensibly:
 *  - no end date (or a reversed one — see below): unchanged single-date
 *    behavior, compact or full.
 *  - same calendar day: one date, plus a start–end time range in the
 *    full form (unless all-day, which never shows a time at all).
 *  - different days: a range via Intl's own formatRange(), which
 *    collapses "14 Oct – 16 Oct 2026" to "14–16 Oct 2026" and similarly
 *    for cross-month/cross-year spans, on its own.
 *
 * Note on locale: this deliberately keeps using the platform's Intl
 * formatting with an `undefined` locale rather than hardcoding one — the
 * exact word order and punctuation follows the visitor's own locale. Only
 * the timezone is pinned to the event's.
 *
 * @param {Date} startDate
 * @param {Date|null} endDate
 * @param {object} fmt Formatter set from formattersFor().
 * @param {{allDay?: boolean, full?: boolean}} options
 * @returns {string}
 */
function formatDateRange(startDate, endDate, fmt, { allDay = false, full = false } = {}) {
  // No end date, or end before start (a data-entry mistake — formatRange
  // is spec'd to throw a RangeError for this, though not every engine
  // enforces it, so this guard also protects against a silently garbled
  // "16 – 14 Oct 2026" rather than relying on a catch). Either way, the
  // sensible fallback is the same: just the start date.
  if (!endDate || endDate < startDate) {
    if (!full) return fmt.compactDate.format(startDate);
    return allDay
      ? fmt.fullDateOnly.format(startDate)
      : fmt.fullDateTime.format(startDate);
  }

  if (!full) {
    return fmt.compactDate.formatRange(startDate, endDate);
  }

  // Full (modal): same-day gets a weekday, plus — unless all-day — a
  // time range. Multi-day never shows a time or a weekday, since a
  // per-day time range isn't meaningful for a spanning event, regardless
  // of whether it's all-day.
  if (isSameDay(startDate, endDate, fmt)) {
    return allDay
      ? fmt.rangeWeekday.formatRange(startDate, endDate)
      : fmt.rangeTime.formatRange(startDate, endDate);
  }

  return fmt.compactDate.formatRange(startDate, endDate);
}
