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

// Weekday + date, abbreviated — the "Sat 14 Oct 2026" half of the
// same-day range format.
function formatWeekdayDate(date) {
  return date.toLocaleDateString(undefined, {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  });
}

function formatTime(date) {
  return date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

// Day + short month, with or without the year — the building block for
// the two multi-day range formats ("14–16 Oct 2026", "28 Oct – 2 Nov 2026").
function formatMonthDay(date, withYear) {
  return date.toLocaleDateString(
    undefined,
    withYear
      ? { day: 'numeric', month: 'short', year: 'numeric' }
      : { day: 'numeric', month: 'short' },
  );
}

/**
 * Formats a start/end date pair, collapsing sensibly:
 *  - no end date: unchanged single-date behavior (compact or full).
 *  - same calendar day: one date, plus a start–end time range in the
 *    full form (unless all-day, which never shows a time at all).
 *  - same month: "14–16 Oct 2026".
 *  - spanning months or years: "28 Oct – 2 Nov 2026" (year shown once,
 *    unless the range crosses a year boundary, in which case both dates
 *    get their own year).
 *
 * Note on locale: this deliberately keeps using toLocaleDateString/
 * toLocaleString with an `undefined` locale rather than hardcoding one,
 * per the rest of this file — the exact word order and punctuation in
 * the multi-day forms therefore follows the visitor's own locale (e.g.
 * "Oct 28 – Nov 2, 2026" in en-US) rather than matching a single fixed
 * example character-for-character.
 *
 * @param {Date} startDate
 * @param {Date|null} endDate
 * @param {{allDay?: boolean, full?: boolean}} options
 * @returns {string}
 */
function formatDateRange(startDate, endDate, { allDay = false, full = false } = {}) {
  if (!endDate) {
    if (!full) return formatDate(startDate);
    return allDay ? formatFullDateOnly(startDate) : formatFullDate(startDate);
  }

  const sameDay = isSameDay(startDate, endDate);

  if (sameDay) {
    if (!full) return formatDate(startDate);
    if (allDay) return formatWeekdayDate(startDate);
    return `${formatWeekdayDate(startDate)}, ${formatTime(startDate)} – ${formatTime(endDate)}`;
  }

  // Multi-day: never shows times, in either the compact or full form — a
  // per-day time range isn't meaningful for a spanning event, and the
  // spec's own examples agree (neither multi-day case includes one).
  const sameYear = startDate.getFullYear() === endDate.getFullYear();
  const sameMonth = sameYear && startDate.getMonth() === endDate.getMonth();

  if (sameMonth) {
    return `${startDate.getDate()}–${formatMonthDay(endDate, true)}`;
  }

  return `${formatMonthDay(startDate, !sameYear)} – ${formatMonthDay(endDate, true)}`;
}
