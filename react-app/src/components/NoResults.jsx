// Empty state shown when the current query matches nothing.
//
// Two variants, not a customisable message (there's no `empty-text`
// shortcode attribute — these two cover it): `hasActiveFilter` is only
// ever true when there both *is* a filter UI and the visitor changed it.
// A filters="false" teaser/related section, or a filters="true" section
// nobody has touched yet, has no query to "clear" — the "try a different
// category" copy would be nonsense there, since there's nothing on
// screen for it to refer to.
// The unfiltered copy tracks the `show` attribute — a section configured
// as show="past" or show="all" saying "no upcoming events" describes a
// listing the visitor isn't looking at.
const EMPTY_COPY = {
  upcoming: 'There are no upcoming events right now.',
  past: 'There are no past events to show.',
  all: 'There are no events right now.',
};

export default function NoResults({ hasActiveFilter, show = 'upcoming' }) {
  return (
    <div className="events-showcase__no-results" role="status">
      {hasActiveFilter ? (
        <>
          <p>No events match your filters.</p>
          <p>Try clearing the search or picking a different category or location.</p>
        </>
      ) : (
        <p>{EMPTY_COPY[show] || EMPTY_COPY.upcoming}</p>
      )}
    </div>
  );
}
