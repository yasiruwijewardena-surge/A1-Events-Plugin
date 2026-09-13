// Empty state shown when the current query matches nothing.
//
// Two variants, not a customisable message (there's no `empty-text`
// shortcode attribute — these two cover it): `hasActiveFilter` is only
// ever true when there both *is* a filter UI and the visitor changed it.
// A filters="false" teaser/related section, or a filters="true" section
// nobody has touched yet, has no query to "clear" — the "try a different
// category" copy would be nonsense there, since there's nothing on
// screen for it to refer to.
export default function NoResults({ hasActiveFilter }) {
  return (
    <div className="events-showcase__no-results" role="status">
      {hasActiveFilter ? (
        <>
          <p>No events match your filters.</p>
          <p>Try clearing the search or picking a different category or location.</p>
        </>
      ) : (
        <p>There are no upcoming events right now.</p>
      )}
    </div>
  );
}
