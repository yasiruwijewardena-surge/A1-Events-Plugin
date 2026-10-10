// Talks to the Events Showcase REST API. `restUrl` is the namespace root
// (e.g. https://example.com/wp-json/events-showcase/v1) that the shortcode
// prints as data-api — this file is the only place that appends /events or
// /filters to it, so the shortcode's markup never has to know the route
// names.

/**
 * Fetches one page of events.
 *
 * @param {string} restUrl Namespace root from the mount element's data-api.
 * @param {{category?: string, location?: string, featured?: string, exclude?: number, page?: number, perPage?: number, search?: string, show?: string}} params
 * @returns {Promise<{events: object[], total: number, pages: number}>}
 */
export async function fetchEvents(restUrl, params = {}) {
  const url = new URL(`${restUrl}/events`, window.location.origin);

  if (params.category) url.searchParams.set('category', params.category);
  if (params.search) url.searchParams.set('search', params.search);
  if (params.location) url.searchParams.set('location', params.location);
  if (params.featured) url.searchParams.set('featured', params.featured);
  if (params.exclude) url.searchParams.set('exclude', params.exclude);
  // Always sent, not `if (params.show)`: 'upcoming' is a real, meaningful
  // choice here, not an empty/absent value like category or search — the
  // REST default already matches it, but relying on that silently would
  // make config.show a no-op the moment the REST default ever changes.
  url.searchParams.set('show', params.show || 'upcoming');
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
 * Fetches the available filter options — every category and location in
 * use across all events, not just the loaded page. Both filters are
 * server-side REST params, so any option listed here is a real query.
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
