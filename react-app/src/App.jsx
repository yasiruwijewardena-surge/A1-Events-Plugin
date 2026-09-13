import { useRef } from 'react';
import { useEvents } from './hooks/useEvents.js';
import EventFilters from './components/EventFilters.jsx';
import EventsGrid from './components/EventsGrid.jsx';
import SearchBox from './components/SearchBox.jsx';
import NoResults from './components/NoResults.jsx';
import Loader from './components/Loader.jsx';
import Pagination from './components/Pagination.jsx';

// Top-level component mounted per shortcode instance.
// `config` comes from the mount element's data-* attributes, and
// `initialData` from its sibling <script type="application/json"> payload,
// if present — see main.jsx.
export default function App({ config, initialData }) {
  const {
    loading,
    error,
    filteredEvents,
    filters,
    setFilters,
    search,
    setSearch,
    categories,
    locations,
    page,
    setPage,
    pages,
    resultsMessage,
  } = useEvents(config, initialData);

  // This instance's own container, not window.scrollTo(0, 0) — another
  // instance of the shortcode could be on the same page, and scrolling
  // the whole window would yank the visitor to whichever instance
  // happens to be first regardless of which one they were paging.
  const containerRef = useRef(null);

  const handlePageChange = (nextPage) => {
    setPage(nextPage);
    containerRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  // Both modifiers always applied, regardless of layout — events.css
  // scopes the columns override to grid specifically (it's a no-op
  // class otherwise), so there's no need to conditionally omit it here.
  const wrapperClassName = `events-showcase events-showcase--${config.layout} events-showcase--cols-${config.columns}`;

  // Pagination is suppressed alongside the filter controls, not behind a
  // separate attribute — a teaser/related strip showing a handful of
  // events has no use for a pager, and both are really the same "is this
  // a browse interface or a glance?" decision (see the filters attribute
  // in README.md).
  const showFilters = config.filters;

  // Drives which of NoResults' two messages shows. Filters that are
  // rendered but untouched, or not rendered at all, aren't "a filter
  // applied" — only a real category/search/location value is, and only
  // when there's a control to have set it in the first place.
  const hasActiveFilter = showFilters && Boolean(search || filters.category || filters.location);

  return (
    <div className={wrapperClassName} ref={containerRef}>
      {showFilters && (
        <div className="events-showcase__controls">
          <SearchBox value={search} onChange={setSearch} />
          <EventFilters
            filters={filters}
            onChange={setFilters}
            categories={categories}
            locations={locations}
          />
        </div>
      )}

      {/* Visually hidden; announces to screen readers only. Silent on
          first render and on every keystroke — see resultsMessage in
          useEvents.js for how that's enforced. */}
      <p className="es-visually-hidden" aria-live="polite">
        {resultsMessage}
      </p>

      {loading && <Loader />}

      {!loading && error && (
        <p className="events-showcase__error" role="alert">
          {error}
        </p>
      )}

      {!loading && !error && filteredEvents.length === 0 && (
        <NoResults hasActiveFilter={hasActiveFilter} />
      )}

      {!loading && !error && filteredEvents.length > 0 && (
        <>
          <EventsGrid events={filteredEvents} />
          {showFilters && <Pagination page={page} pages={pages} onChange={handlePageChange} />}
        </>
      )}
    </div>
  );
}
