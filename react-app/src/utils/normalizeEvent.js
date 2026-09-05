// Maps a raw WP REST API "event" post (title/content + ACF fields +
// _embedded featured media/terms) into the flat shape components expect.
// Keeping this in one place means the components never touch WP's
// response shape directly — only this function needs to change if the
// custom post type / field names change.
export function normalizeEvent(post) {
  const acf = post.acf || {};
  const media = post._embedded?.['wp:featuredmedia']?.[0];
  const terms = post._embedded?.['wp:term']?.flat() || [];
  const category = terms.find((t) => t.taxonomy === 'event_category')?.name || 'General';

  const startDate = acf.start_date ? new Date(acf.start_date) : null;

  return {
    id: post.id,
    title: post.title?.rendered || '(untitled)',
    description: post.content?.rendered || '',
    category,
    location: acf.location || '',
    venue: acf.venue || acf.location || '',
    thumbnail: media?.source_url || acf.thumbnail || '',
    startDate,
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
