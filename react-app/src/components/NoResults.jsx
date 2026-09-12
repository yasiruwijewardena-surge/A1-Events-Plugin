// Bonus: clear empty state shown when filters/search match nothing.
export default function NoResults() {
  return (
    <div className="events-showcase__no-results" role="status">
      <p>No events match your filters.</p>
      <p>Try clearing the search or picking a different category or location.</p>
    </div>
  );
}
