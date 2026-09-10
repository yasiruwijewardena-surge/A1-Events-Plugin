// Filter controls: category (server-side, options from the REST /filters
// endpoint) and location (client-side, options derived from whatever
// events are currently loaded — see useEvents.js for why the two work
// differently).
export default function EventFilters({ filters, onChange, categories, locations }) {
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
            <option key={cat.slug} value={cat.slug}>
              {cat.name}
            </option>
          ))}
        </select>
      </label>

      <label className="event-filters__field">
        <span>Location</span>
        <select value={filters.location} onChange={update('location')}>
          <option value="">All locations</option>
          {locations.map((location) => (
            <option key={location} value={location}>
              {location}
            </option>
          ))}
        </select>
      </label>
    </div>
  );
}
