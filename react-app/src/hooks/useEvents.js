import { useEffect, useMemo, useRef, useState } from 'react';
import { fetchEvents, fetchFilters } from '../utils/api.js';
import { normalizeEvent } from '../utils/normalizeEvent.js';

/**
 * Owns events data + filter state for one shortcode instance.
 *
 * `category` filtering is server-side: it's sent to the REST API as a
 * query param and triggers a refetch, same as the shortcode's own
 * server-rendered payload. `location` is a lighter, client-side second
 * filter dimension applied over whatever page of events is already
 * loaded — there's no REST query param for it (only category/search/
 * page/per_page exist on Events_Repository::get_events()), so its
 * options are derived from the currently-loaded events rather than the
 * global list, to avoid offering a choice that can't actually narrow
 * anything. This is a known trade-off, not an oversight: paging changes
 * which events are "currently loaded," so the location dropdown's
 * options and its filtering both silently re-scope to whatever page
 * you're on. A location that only appears on page 2 isn't selectable
 * while viewing page 1.
 *
 * @param {{restUrl: string, perPage: number, initialCategory: string, initialSearch: string, show: string}} config
 * @param {{events: object[], total: number, pages: number}|null} initialData
 *   Server-rendered payload from the shortcode's inline <script>, if any.
 *   When present, the first render skips its own fetch and hydrates from
 *   this instead — see main.jsx.
 */
export function useEvents(config, initialData) {
  const [events, setEvents] = useState(() =>
    (initialData?.events || []).map(normalizeEvent),
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

  // Category options come from the REST API's global list, independent of
  // what's currently loaded — selecting any of them is always valid since
  // it triggers a fresh server-side query.
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
        if (!cancelled) setCategories(result.categories || []);
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
  // new category/search doesn't stay stuck on a page number that may no
  // longer exist.
  useEffect(() => {
    setPage(1);
  }, [filters.category, debouncedSearch]);

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
      search: debouncedSearch,
      perPage: config.perPage,
      page,
      show: config.show,
    })
      .then((result) => {
        if (cancelled) return;
        setEvents((result.events || []).map(normalizeEvent));
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
  }, [config.restUrl, config.perPage, config.show, filters.category, debouncedSearch, page]);

  const locations = useMemo(() => {
    const fromEvents = events.map((e) => e.location).filter(Boolean);

    // The selected location is kept in the list even when no event on the
    // current page has it. Options are derived from the loaded page, so
    // paging to a page with no matches would otherwise drop the selected
    // value out of the <select> entirely — leaving a control whose value
    // matches no option, which renders blank while still filtering. The
    // visitor could see the effect of a filter with no way to read or
    // clear it.
    if (filters.location) fromEvents.push(filters.location);

    return [...new Set(fromEvents)].sort();
  }, [events, filters.location]);

  const filteredEvents = useMemo(
    () => events.filter((e) => !filters.location || e.location === filters.location),
    [events, filters.location],
  );

  // Announces the result count once the visitor has changed something —
  // silent on the initial render (nothing has changed yet) and silent
  // mid-fetch, where the count still describes the previous results.
  //
  // Derived from what is actually on screen rather than set inside the
  // fetch callback, where this used to live. The location filter runs
  // client-side and triggers no fetch at all, so a fetch-time
  // announcement both missed location changes entirely and read out the
  // server's unfiltered `total` next to a grid that filter had already
  // narrowed.
  const hasAnnounced = useRef(false);
  useEffect(() => {
    if (loading) return;

    if (!hasAnnounced.current) {
      hasAnnounced.current = true;
      return;
    }

    // `total` counts every page and is the right figure while only the
    // server-side filters (category, search) are applied. Once the
    // client-side location filter narrows the loaded page, it no longer
    // describes anything the visitor can actually reach, so the visible
    // count becomes the only honest number.
    const count = filters.location ? filteredEvents.length : total;

    setResultsMessage(count === 1 ? '1 event matches' : `${count} events match`);
  }, [filteredEvents, filters.location, total, loading]);

  return {
    loading,
    error,
    filteredEvents,
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
