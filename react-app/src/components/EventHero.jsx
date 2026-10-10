import { PinIcon, StarIcon, TagIcon } from './Icons.jsx';

// The featured "main event" above the grid, shown when the shortcode has
// hero="true" and an upcoming featured event exists (the server picks it —
// see Shortcode::resolve_hero()). The same event-as-one-link pattern as
// EventCard, for the same reasons: a real permalink for middle-click and
// no-JS, a plain click opens the details modal instead.
//
// The fuller date label (weekday and time) is used here rather than the
// card's compact one — the hero has the room, and "which evening is it?"
// is the first thing someone looking at a headline event wants to know.
export default function EventHero({ event, onSelect }) {
  const handleClick = (e) => {
    e.preventDefault();
    onSelect();
  };

  const isCancelled = event.status === 'cancelled';
  const isPostponed = event.status === 'postponed';
  const place = [event.venue, event.location].filter(Boolean).join(', ');

  return (
    <article className="event-hero">
      <a
        href={event.permalink}
        className="event-hero__link"
        aria-haspopup="dialog"
        onClick={handleClick}
      >
        <div className="event-hero__media">
          {event.thumbnail ? (
            // Not lazy-loaded, unlike the cards: the hero sits near the top
            // of the page and is often its largest image, so deferring it
            // would delay exactly the paint that matters most.
            <img
              className="event-hero__thumb"
              src={event.thumbnail}
              alt={event.thumbnailAlt || ''}
              width={event.thumbnailWidth || undefined}
              height={event.thumbnailHeight || undefined}
            />
          ) : (
            <div className="event-hero__thumb event-hero__thumb--placeholder" aria-hidden="true" />
          )}
        </div>

        <div className="event-hero__body">
          <p className="event-hero__badges">
            <span className="event-hero__badge">
              <StarIcon className="event-hero__icon" />
              Featured event
            </span>
            {/* Same rule as the card: a changed event says so in words,
                not only in colour. Reuses the card's pill classes so the
                two always look the same. */}
            {isCancelled && (
              <span className="event-card__status event-card__status--cancelled">Cancelled</span>
            )}
            {isPostponed && (
              <span className="event-card__status event-card__status--postponed">Postponed</span>
            )}
          </p>

          {event.fullDateLabel && (
            <time className="event-hero__date" dateTime={event.startIso || undefined}>
              {event.fullDateLabel}
            </time>
          )}

          {/* h3 like the cards, not h2: the hero is the first item of this
              listing, not a new section of the page, so it shouldn't
              re-parent the cards under itself in the document outline. */}
          <h3 className="event-hero__title">{event.title}</h3>

          {event.excerpt && <p className="event-hero__excerpt">{event.excerpt}</p>}

          <p className="event-hero__meta">
            {place && (
              <span className="event-hero__meta-item">
                <PinIcon className="event-hero__icon" />
                {place}
              </span>
            )}
            <span className="event-hero__meta-item">
              <TagIcon className="event-hero__icon" />
              {event.category}
              {event.allDay ? ' · All day' : ''}
            </span>
          </p>

          {/* Looks like a button, but it's only a visual cue — the whole
              hero is already the link, and a second focusable control here
              would be a duplicate tab stop doing the same thing. Hidden
              from assistive tech so the link's name isn't padded with it. */}
          <span className="event-hero__cta" aria-hidden="true">
            View details
            <span className="event-hero__cta-arrow">→</span>
          </span>
        </div>
      </a>
    </article>
  );
}
