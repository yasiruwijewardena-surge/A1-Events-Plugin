// Bonus: free-text search box, filters events by title as the user types.
export default function SearchBox({ value, onChange }) {
  return (
    <div className="search-box">
      <label htmlFor="events-search" className="search-box__label">
        Search events
      </label>
      <input
        id="events-search"
        type="search"
        className="search-box__input"
        placeholder="Search by title…"
        value={value}
        onChange={(e) => onChange(e.target.value)}
      />
    </div>
  );
}
