// Talks to the WordPress REST API. Endpoint + params come from the
// shortcode instance's config (data-* attributes), not hard-coded here.
export async function fetchEvents({ restUrl, perPage }) {
  const url = new URL(restUrl, window.location.origin);
  url.searchParams.set('per_page', perPage);
  url.searchParams.set('_embed', '1'); // pulls in featured media + terms

  const response = await fetch(url.toString(), {
    headers: { Accept: 'application/json' },
  });

  if (!response.ok) {
    throw new Error(`Failed to load events (HTTP ${response.status})`);
  }

  return response.json();
}
