// Talks to the Events Showcase REST API. `restUrl` is the namespace root
// (e.g. https://example.com/wp-json/events-showcase/v1) that the shortcode
// prints as data-api — this file is the only place that appends /events or
// /filters to it, so the shortcode's markup never has to know the route
// names.

/**
 * Fetches one page of events.
 *
 * @param {string} restUrl Namespace root from the mount element's data-api.
 * @param {{category?: string, page?: number, perPage?: number, search?: string}} params
 * @returns {Promise<{events: object[], total: number, pages: number}>}
 */
export async function fetchEvents(restUrl, params = {}) {
  const url = new URL(`${restUrl}/events`, window.location.origin);

  if (params.category) url.searchParams.set('category', params.category);
  if (params.search) url.searchParams.set('search', params.search);
  url.searchParams.set('per_page', params.perPage || 12);
  url.searchParams.set('page', params.page || 1);

  const response = await fetch(url.toString(), {
    headers: { Accept: 'application/json' },
  });

  if (!response.ok) {
    throw new Error(`Failed to load events (HTTP ${response.status})`);
  }

  return response.json();
}

/**
 * Fetches the available filter options. Only the `categories` half is
 * currently used (by useEvents.js, for the category dropdown, since
 * category filtering is a real server-side query and any category is
 * always a valid choice). `locations` is intentionally ignored — location
 * filtering is client-side over whatever page is already loaded, so
 * useEvents.js derives its options from those loaded events instead;
 * offering a server-wide location that can't narrow the current page
 * would be a dead option. The route still returns both — it's public and
 * harmless, and a future server-side location filter could start using it.
 *
 * @param {string} restUrl Namespace root.
 * @returns {Promise<{categories: {slug: string, name: string}[], locations: string[]}>}
 */
export async function fetchFilters(restUrl) {
  const url = new URL(`${restUrl}/filters`, window.location.origin);

  const response = await fetch(url.toString(), {
    headers: { Accept: 'application/json' },
  });

  if (!response.ok) {
    throw new Error(`Failed to load filters (HTTP ${response.status})`);
  }

  return response.json();
}
