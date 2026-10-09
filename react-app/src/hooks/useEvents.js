import { useEffect, useRef, useState } from 'react';
import { fetchEvents, fetchFilters } from '../utils/api.js';
import { normalizeEvent } from '../utils/normalizeEvent.js';

/**
 * Owns events data + filter state for one shortcode instance.
 *
 * Every filter — category, location, search — is server-side: each is
 * sent to the REST API as a query param and triggers a refetch, the same
 * query the shortcode's server-rendered payload ran. That keeps the
 * result count and the pager describing the whole result set, not just
 * whichever page happens to be loaded, and lets the dropdowns offer every
 * option in use rather than only those on the current page.
 *
 * @param {{restUrl: string, perPage: number, initialCategory: string, initialSearch: string, show: string}} config
 * @param {{events: object[], total: number, pages: number}|null} initialData
 *   Server-rendered payload from the shortcode's inline <script>, if any.
 *   When present, the first render skips its own fetch and hydrates from
 *   this instead — see main.jsx.
 */
export function useEvents(config, initialData) {
  // Arrow function, not a bare `.map(normalizeEvent)`: map passes
  // (item, index, array), so the index would arrive as normalizeEvent's
  // second parameter — which is now the timezone. That silently produced
  // a wrong timezone for every event after the first.
  const [events, setEvents] = useState(() =>
    (initialData?.events || []).map((event) => normalizeEvent(event, config.timeZone)),
  );
  const [total, setTotal] = useState(initialData?.total ?? 0);
  const [pages, setPages] = useState(initialData?.pages ?? 0);
  const [page, setPage] = useState(1);
  const [filters, setFilters] = useState({
    category: config.initialCategory || '',
    location: '',
  });
  const [search, setSearch] = useState(config.initialSearch || '');
  const [loading, setLoading] = useState(!initialData);
  const [error, setError] = useState(null);
  const [categories, setCategories] = useState([]);
  const [locations, setLocations] = useState([]);

  // The SSR payload already reflects config.initialCategory/initialSearch
  // (the shortcode queried with the same values), so the first run of the
  // fetch effect below should be a no-op rather than an immediate refetch.
  const skipNextFetch = useRef(Boolean(initialData));

  const [resultsMessage, setResultsMessage] = useState('');

  // Debounced so typing in the search box doesn't hit the REST API on
  // every keystroke.
  const [debouncedSearch, setDebouncedSearch] = useState(search);
  useEffect(() => {
    const timeout = setTimeout(() => setDebouncedSearch(search), 300);
    return () => clearTimeout(timeout);
  }, [search]);

  // Category and location options come from the REST API's global lists,
  // independent of what's currently loaded — selecting any of them is
  // always valid since it triggers a fresh server-side query.
  //
  // Skipped entirely when config.filters is false: App.jsx doesn't render
  // EventFilters in that mode, so there's nothing to populate — firing
  // this request anyway would just be a wasted round-trip on every page a
  // filters="false" instance appears on (a homepage teaser, a related-
  // events strip).
  useEffect(() => {
    if (!config.filters) return undefined;

    let cancelled = false;

    fetchFilters(config.restUrl)
      .then((result) => {
        if (cancelled) return;
        setCategories(result.categories || []);
        setLocations(result.locations || []);
      })
      .catch(() => {
        // Filter options are a progressive enhancement; losing them
        // shouldn't take down the events grid itself.
      });

    return () => {
      cancelled = true;
    };
  }, [config.restUrl, config.filters]);

  // Resets to page 1 whenever a server-side query parameter changes, so a
  // new category/location/search doesn't stay stuck on a page number that may no
  // longer exist.
  useEffect(() => {
    setPage(1);
  }, [filters.category, filters.location, debouncedSearch]);

  useEffect(() => {
    if (skipNextFetch.current) {
      skipNextFetch.current = false;
      return;
    }

    let cancelled = false;
    setLoading(true);
    setError(null);

    fetchEvents(config.restUrl, {
      category: filters.category,
      location: filters.location,
      search: debouncedSearch,
      perPage: config.perPage,
      page,
      show: config.show,
    })
      .then((result) => {
        if (cancelled) return;
        setEvents((result.events || []).map((event) => normalizeEvent(event, config.timeZone)));
        setTotal(result.total || 0);
        setPages(result.pages || 0);
      })
      .catch((err) => {
        if (cancelled) return;
        setError(err.message || 'Something went wrong loading events.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [
    config.restUrl,
    config.perPage,
    config.show,
    filters.category,
    filters.location,
    debouncedSearch,
    page,
  ]);

  // Announces the result count once the visitor has changed something —
  // silent on the initial render (nothing has changed yet) and silent
  // mid-fetch, where the count still describes the previous results.
  const hasAnnounced = useRef(false);
  useEffect(() => {
    if (loading) return;

    if (!hasAnnounced.current) {
      hasAnnounced.current = true;
      return;
    }

    setResultsMessage(total === 1 ? '1 event matches' : `${total} events match`);
  }, [events, total, loading]);

  return {
    loading,
    error,
    events,
    filters,
    setFilters,
    search,
    setSearch,
    categories,
    locations,
    total,
    pages,
    page,
    setPage,
    resultsMessage,
  };
}
