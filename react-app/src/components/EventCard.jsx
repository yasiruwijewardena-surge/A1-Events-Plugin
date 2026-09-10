// A single event card: thumbnail, title, date, location, category.
// Clicking (or pressing Enter/Space) opens the full details modal.
export default function EventCard({ event, onSelect }) {
  const handleKeyDown = (e) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      onSelect();
    }
  };

  return (
    <article
      className="event-card"
      role="button"
      tabIndex={0}
      onClick={onSelect}
      onKeyDown={handleKeyDown}
      aria-haspopup="dialog"
    >
      {/* alt falls back to "" (decorative) when the editor didn't set one —
          the title is already rendered as visible text right below, so an
          empty alt avoids a screen reader announcing the same thing twice. */}
      <img
        className="event-card__thumb"
        src={event.thumbnail}
        alt={event.thumbnailAlt || ''}
        loading="lazy"
      />
      <div className="event-card__body">
        <span className="event-card__category">{event.category}</span>
        <h3 className="event-card__title">{event.title}</h3>
        <p className="event-card__meta">
          <span className="event-card__date">{event.dateLabel}</span>
          <span className="event-card__location">{event.location}</span>
        </p>
      </div>
    </article>
  );
}
