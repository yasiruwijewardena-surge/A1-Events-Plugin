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
    startDate,
    endDate,
    dateLabel: startDate ? formatDate(startDate) : '',
    fullDateLabel: startDate ? formatFullDate(startDate) : '',
  };
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
