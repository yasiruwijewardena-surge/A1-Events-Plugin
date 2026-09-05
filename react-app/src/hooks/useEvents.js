import { useEffect, useMemo, useState } from 'react';
import { fetchEvents } from '../utils/api.js';
import { normalizeEvent } from '../utils/normalizeEvent.js';
import { filterEvents } from '../utils/filterEvents.js';

// Fetches events for this shortcode instance from the WordPress REST API,
// normalizes them, and derives filter options + the filtered list.
export function useEvents(config) {
  const [events, setEvents] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [filters, setFilters] = useState({ category: '', date: '' });
  const [search, setSearch] = useState('');

  useEffect(() => {
    let cancelled = false;

    setLoading(true);
    setError(null);

    fetchEvents(config)
      .then((posts) => {
        if (cancelled) return;
        setEvents(posts.map(normalizeEvent));
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
  }, [config.restUrl, config.perPage]);

  const categories = useMemo(
    () => [...new Set(events.map((e) => e.category))].sort(),
    [events],
  );

  const dates = useMemo(
    () => [...new Set(events.map((e) => e.dateLabel).filter(Boolean))].sort(),
    [events],
  );

  const filteredEvents = useMemo(
    () => filterEvents(events, { ...filters, search }),
    [events, filters, search],
  );

  return {
    loading,
    error,
    events,
    filteredEvents,
    filters,
    setFilters,
    search,
    setSearch,
    categories,
    dates,
  };
}
