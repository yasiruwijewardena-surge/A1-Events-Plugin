// A single event card: thumbnail, title, date, location, category.
export default function EventCard({ event, onSelect }) {
  // The href is a real permalink (the CPT is public) so middle-click,
  // cmd/ctrl-click, "open in new tab", and the status-bar preview all work
  // normally; a plain click intercepts to open the modal instead of
  // navigating. No-JS visitors fall through to the real page.
  const handleTitleClick = (e) => {
    e.preventDefault();
    onSelect();
  };

  const isCancelled = event.status === 'cancelled';
  const isPostponed = event.status === 'postponed';

  return (
    <article className="event-card">
      {event.thumbnail ? (
        <img
          className="event-card__thumb"
          src={event.thumbnail}
          // alt falls back to "" (decorative) when the editor didn't set
          // one — the title is already rendered as visible text right
          // below, so an empty alt avoids a screen reader announcing the
          // same thing twice.
          alt={event.thumbnailAlt || ''}
          width={event.thumbnailWidth || undefined}
          height={event.thumbnailHeight || undefined}
          loading="lazy"
        />
      ) : (
        // A placeholder box, not an <img> with no src — a bare <img
        // src=""> renders as a broken-image icon, which looks like a
        // loading failure rather than "no image was set."
        <div className="event-card__thumb event-card__thumb--placeholder" aria-hidden="true" />
      )}
      <div className="event-card__body">
        <p className="event-card__badges">
          <span className="event-card__category">{event.category}</span>
          {/* Cancelled/postponed events still appear here — see
              Events_Repository::normalise() for why — so the badge is
              the only signal that something's changed. Text says the
              word, not just a colour, since colour alone isn't
              perceivable by everyone. */}
          {isCancelled && (
            <span className="event-card__status event-card__status--cancelled">Cancelled</span>
          )}
          {isPostponed && (
            <span className="event-card__status event-card__status--postponed">Postponed</span>
          )}
        </p>
        <h3 className="event-card__title">
          {/* The only focusable, keyboard-activatable element on the
              card — role="button" on the whole <article> would have made
              a screen reader announce the entire card's text as one
              run-on button name. aria-haspopup signals the click opens a
              dialog rather than navigating. */}
          <a
            href={event.permalink}
            className="event-card__title-link"
            aria-haspopup="dialog"
            onClick={handleTitleClick}
          >
            {event.title}
          </a>
        </h3>
        <p className="event-card__meta">
          <span className="event-card__date">
            {event.dateLabel}
            {event.allDay ? ' · All day' : ''}
          </span>
          <span className="event-card__location">{event.location}</span>
        </p>
      </div>
    </article>
  );
}
