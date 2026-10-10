import { useRef, useState } from 'react';
import { useEvents } from './hooks/useEvents.js';
import EventFilters from './components/EventFilters.jsx';
import EventsGrid from './components/EventsGrid.jsx';
import EventHero from './components/EventHero.jsx';
import EventModal from './components/EventModal.jsx';
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
    events,
    hero,
    showHero,
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

  // The event open in the details modal, if any. Held here rather than in
  // EventsGrid because the hero opens the same modal.
  const [activeEvent, setActiveEvent] = useState(null);

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
  //
  // config.className comes last so a theme's own class sits at the end of
  // the attribute. That has no effect on the cascade — source order in
  // the stylesheet decides, not order in the class attribute — but it
  // keeps the component's own classes readable as a group when someone
  // is reading the DOM in devtools.
  const wrapperClassName = [
    'events-showcase',
    `events-showcase--${config.layout}`,
    `events-showcase--cols-${config.columns}`,
    config.className,
  ]
    .filter(Boolean)
    .join(' ');

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

  // Only a load with nothing already on screen gets the spinner. A
  // refetch triggered by a filter, a search keystroke, or a page change
  // keeps the previous results mounted and dims them instead (see
  // aria-busy below) — swapping a populated grid for a one-line spinner
  // collapses the page height and then re-expands it on every keystroke,
  // which reads as flicker rather than as progress.
  const isInitialLoad = loading && events.length === 0;
  const isRefreshing = loading && events.length > 0;

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

      {isInitialLoad && <Loader />}

      {!loading && error && (
        <p className="events-showcase__error" role="alert">
          {error}
        </p>
      )}

      {/* Results outlive a failed refetch. Keeping the stale-but-valid
          grid beneath the error banner beats replacing it with nothing —
          the visitor keeps whatever they were already reading, and the
          banner explains that the update didn't land. Only a failure
          with nothing to fall back on (the very first load) leaves the
          error standing on its own. */}
      {!isInitialLoad && (!error || events.length > 0) && (
        // Pagination sits outside the empty/non-empty branch so a visitor
        // is never left on a blank page with no control to get off it.
        <div className="events-showcase__results" aria-busy={isRefreshing}>
          {showHero && <EventHero event={hero} onSelect={() => setActiveEvent(hero)} />}
          {/* An empty grid under a hero isn't "no events" — the hero is
              one. That's the case where the featured event is the only
              upcoming one, and saying "no upcoming events" right beneath
              it would contradict the page. */}
          {events.length === 0 ? (
            !showHero && <NoResults hasActiveFilter={hasActiveFilter} show={config.show} />
          ) : (
            <EventsGrid events={events} onSelect={setActiveEvent} />
          )}
          {showFilters && <Pagination page={page} pages={pages} onChange={handlePageChange} />}
        </div>
      )}

      {activeEvent && (
        <EventModal
          event={activeEvent}
          onClose={() => setActiveEvent(null)}
          // Forwarded so the body-portalled dialog can carry the same
          // theme class as the grid — see EventModal.jsx.
          className={config.className}
        />
      )}
    </div>
  );
}
