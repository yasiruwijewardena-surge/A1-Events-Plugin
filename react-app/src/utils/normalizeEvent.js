// Maps an already-normalized event from the Events Showcase REST API
// (see wordpress-plugin/.../class-events-repository.php Events_Repository::normalise())
// into the display-ready shape the components use — mostly just formatting
// the ISO 8601 dates into human-readable labels. Unlike the old version of
// this file, there's no WP REST/_embed/ACF shape to dig through here: the
// PHP repository already did that flattening, which is the whole point of
// having a single repository both the shortcode and the REST API call.
export function normalizeEvent(raw) {
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
    dateLabel: startDate ? formatDateRange(startDate, endDate, { allDay, full: false }) : '',
    fullDateLabel: startDate ? formatDateRange(startDate, endDate, { allDay, full: true }) : '',
  };
}

function isSameDay(a, b) {
  return (
    a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate()
  );
}

function formatDate(date) {
  return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

function formatFullDate(date) {
  return date.toLocaleString(undefined, {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

// Same as formatFullDate but without a time component, for an all-day
// event with no end date.
function formatFullDateOnly(date) {
  return date.toLocaleDateString(undefined, {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  });
}

// Pre-built Intl.DateTimeFormat instances, one per range "shape" — used
// via .formatRange() below rather than hand-rolled collapsing logic.
// formatRange() already handles same-day (it collapses two instants on
// the same calendar day down to a single formatted result, even with a
// date-only formatter — verified: a date-only formatter given 9am and
// 5pm on the same day returns one date, not a same-day-to-itself range),
// same-month, cross-month, and cross-year cases correctly for whatever
// locale is active, which a hand-rolled version can only approximate
// (see git history for the version this replaced).
const RANGE_DATE_FORMATTER = new Intl.DateTimeFormat(undefined, {
  day: 'numeric',
  month: 'short',
  year: 'numeric',
});
const RANGE_WEEKDAY_FORMATTER = new Intl.DateTimeFormat(undefined, {
  weekday: 'short',
  day: 'numeric',
  month: 'short',
  year: 'numeric',
});
const RANGE_TIME_FORMATTER = new Intl.DateTimeFormat(undefined, {
  weekday: 'short',
  day: 'numeric',
  month: 'short',
  year: 'numeric',
  hour: 'numeric',
  minute: '2-digit',
});

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
 * formatting with an `undefined` locale rather than hardcoding one, per
 * the rest of this file — the exact word order and punctuation follows
 * the visitor's own locale rather than matching one fixed example
 * character-for-character.
 *
 * @param {Date} startDate
 * @param {Date|null} endDate
 * @param {{allDay?: boolean, full?: boolean}} options
 * @returns {string}
 */
function formatDateRange(startDate, endDate, { allDay = false, full = false } = {}) {
  // No end date, or end before start (a data-entry mistake — formatRange
  // is spec'd to throw a RangeError for this, though not every engine
  // enforces it, so this guard also protects against a silently garbled
  // "16 – 14 Oct 2026" rather than relying on a catch). Either way, the
  // sensible fallback is the same: just the start date.
  if (!endDate || endDate < startDate) {
    if (!full) return formatDate(startDate);
    return allDay ? formatFullDateOnly(startDate) : formatFullDate(startDate);
  }

  if (!full) {
    return RANGE_DATE_FORMATTER.formatRange(startDate, endDate);
  }

  // Full (modal): same-day gets a weekday, plus — unless all-day — a
  // time range. Multi-day never shows a time or a weekday, since a
  // per-day time range isn't meaningful for a spanning event, regardless
  // of whether it's all-day.
  if (isSameDay(startDate, endDate)) {
    return allDay
      ? RANGE_WEEKDAY_FORMATTER.formatRange(startDate, endDate)
      : RANGE_TIME_FORMATTER.formatRange(startDate, endDate);
  }

  return RANGE_DATE_FORMATTER.formatRange(startDate, endDate);
}
