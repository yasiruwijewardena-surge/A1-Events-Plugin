import { useId } from 'react';

// Bonus: free-text search box, filters events by title as the user types.
export default function SearchBox({ value, onChange }) {
  // useId(), not a string literal — a hardcoded id breaks label
  // association (and produces duplicate DOM ids) once the shortcode
  // appears more than once on a page.
  const inputId = useId();

  return (
    <div className="search-box">
      <label htmlFor={inputId} className="search-box__label">
        Search events
      </label>
      <input
        id={inputId}
        type="search"
        className="search-box__input"
        placeholder="Search by title…"
        value={value}
        onChange={(e) => onChange(e.target.value)}
      />
    </div>
  );
}
