// Filter controls for the events grid. Currently filters by category and
// date; wired up to whatever dimensions useEvents() derives from the data.
export default function EventFilters({ filters, onChange, categories, dates }) {
  const update = (key) => (e) => {
    onChange({ ...filters, [key]: e.target.value });
  };

  return (
    <div className="event-filters" role="group" aria-label="Filter events">
      <label className="event-filters__field">
        <span>Category</span>
        <select value={filters.category} onChange={update('category')}>
          <option value="">All categories</option>
          {categories.map((cat) => (
            <option key={cat} value={cat}>
              {cat}
            </option>
          ))}
        </select>
      </label>

      <label className="event-filters__field">
        <span>Date</span>
        <select value={filters.date} onChange={update('date')}>
          <option value="">All dates</option>
          {dates.map((date) => (
            <option key={date} value={date}>
              {date}
            </option>
          ))}
        </select>
      </label>
    </div>
  );
}
