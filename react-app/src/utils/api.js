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
 * Fetches the available filter options (categories + locations), so the
 * filter controls are driven by real data rather than derived from
 * whatever page of events happens to be loaded.
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
