// A single event card: inset thumbnail, calendar date tile, then the
// location / title / excerpt / category stack beside it.
//
// Icons are inlined SVG rather than an icon font or a package — two
// glyphs don't justify a dependency, and inlining means they inherit
// currentColor and can't arrive after the text they belong to. Both are
// aria-hidden: each sits next to a text label that already says the same
// thing.
function PinIcon() {
  return (
    <svg className="event-card__icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
      <path
        d="M8 1.5a4.5 4.5 0 0 0-4.5 4.5c0 3.4 4.5 8.5 4.5 8.5s4.5-5.1 4.5-8.5A4.5 4.5 0 0 0 8 1.5Z"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.3"
      />
      <circle cx="8" cy="6" r="1.7" fill="none" stroke="currentColor" strokeWidth="1.3" />
    </svg>
  );
}

function TagIcon() {
  return (
    <svg className="event-card__icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
      <path
        d="M2.5 7.3V2.5h4.8l6.2 6.2-4.8 4.8L2.5 7.3Z"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.3"
        strokeLinejoin="round"
      />
      <circle cx="5.4" cy="5.4" r="1" fill="currentColor" />
    </svg>
  );
}

export default function EventCard({ event, onSelect }) {
  // The whole card is one link, not just the title — a card is a single
  // "go here" affordance, and users expect to be able to click anywhere
  // on it, not hunt for the one line of text that happens to be
  // interactive. It's a real permalink (the CPT is public), so
  // middle-click, cmd/ctrl-click, "open in new tab", and the status-bar
  // preview all work normally; a plain click intercepts to open the
  // modal instead of navigating, and a no-JS visitor falls through to
  // the real page. Wrapping everything in one <a> — rather than, say,
  // adding a second click handler on the <article> — keeps exactly one
  // focusable, keyboard-activatable element on the card: a screen
  // reader announces its whole text as a single link, which is the
  // standard, well-supported "card as link" pattern, not two competing
  // interactive targets doing the same thing.
  const handleClick = (e) => {
    e.preventDefault();
    onSelect();
  };

  const isCancelled = event.status === 'cancelled';
  const isPostponed = event.status === 'postponed';
  // Only non-scheduled events get anything over the image now that the
  // category has moved down to the card footer. That makes the overlay
  // meaningful — a badge on the photo means something has changed —
  // rather than something every card carries regardless.
  const hasStatusBadge = isCancelled || isPostponed;

  return (
    <article className="event-card">
      <a
        href={event.permalink}
        className="event-card__link"
        aria-haspopup="dialog"
        onClick={handleClick}
      >
        {/* The modifier lets the compact layout — which hides the photo —
            tell apart a media block that still has something to show (a
            status pill) from one that would collapse to an empty rounded
            box. Done with a class rather than :has() so it doesn't depend
            on selector support. */}
        <div
          className={`event-card__media${hasStatusBadge ? ' event-card__media--status' : ''}`}
        >
          {event.thumbnail ? (
            <img
              className="event-card__thumb"
              src={event.thumbnail}
              // alt falls back to "" (decorative) when the editor didn't
              // set one — the title is already rendered as visible text
              // right below, so an empty alt avoids a screen reader
              // announcing the same thing twice.
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
          {hasStatusBadge && (
            <p className="event-card__badges">
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
          )}
        </div>

        <div className="event-card__body">
          {event.dateTile && (
            // The tile shows an abbreviated month over a large day number,
            // which is a glance-level graphic rather than a readable date.
            // The visually-hidden span carries the full, unambiguous date
            // for screen readers (and it comes first, so it is what gets
            // announced); the two visible spans are hidden from them so
            // the date isn't read out twice, once as fragments.
            <time className="event-card__date-tile" dateTime={event.startIso || undefined}>
              <span className="es-visually-hidden">{event.dateLabel}</span>
              <span className="event-card__date-month" aria-hidden="true">
                {event.dateTile.month}
              </span>
              <span className="event-card__date-day" aria-hidden="true">
                {event.dateTile.day}
              </span>
            </time>
          )}

          <div className="event-card__content">
            {event.location && (
              <p className="event-card__location">
                <PinIcon />
                {event.location}
              </p>
            )}
            <h3 className="event-card__title">{event.title}</h3>
            {/* Plain text by the time it reaches here — the repository
                runs the excerpt through wp_strip_all_tags(), so this is a
                text node, not markup. Clamped to two lines in CSS. */}
            {event.excerpt && <p className="event-card__excerpt">{event.excerpt}</p>}
            <p className="event-card__category">
              <TagIcon />
              {event.category}
              {event.allDay ? ' · All day' : ''}
            </p>
          </div>
        </div>
      </a>
    </article>
  );
}
