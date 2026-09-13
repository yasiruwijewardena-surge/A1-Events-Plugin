import { useEffect, useId, useRef } from 'react';
import { createPortal } from 'react-dom';
import { useFocusTrap } from '../hooks/useFocusTrap.js';

// Full event details popup. Closable via the close button, an overlay
// click, or Escape. Traps focus while open and restores it on close.
export default function EventModal({ event, onClose }) {
  const dialogRef = useRef(null);
  // useId(), not a string literal — the shortcode supports more than one
  // instance per page, and a hardcoded id would collide (duplicate DOM
  // ids, and aria-labelledby pointing at the wrong instance's heading).
  const titleId = useId();

  useFocusTrap(dialogRef, onClose);

  useEffect(() => {
    const previouslyFocused = document.activeElement;
    dialogRef.current?.focus();
    return () => previouslyFocused?.focus?.();
  }, []);

  const handleOverlayClick = (e) => {
    if (e.target === e.currentTarget) onClose();
  };

  // Portalled straight to <body>, not rendered in place — the shortcode
  // mounts wherever a theme's page builder puts it, potentially nested
  // inside an ancestor with its own `transform`/`filter`/`contain`
  // (Divi's page wrapper does this for some layouts). Any such ancestor
  // becomes the containing block for `position: fixed` descendants, which
  // would shrink `.event-modal__overlay`'s `inset: 0` down to that
  // ancestor's box instead of the real viewport — exactly why the overlay
  // was covering everything except the theme's own fixed header. A body
  // portal sidesteps the problem entirely rather than chasing whichever
  // ancestor happens to be responsible on a given theme.
  //
  // The outer .events-showcase div is otherwise-empty on purpose: every
  // rule in events.css is scoped under that class (see the top of the
  // file for why), and a body portal lands outside the shortcode's own
  // .events-showcase wrapper — without re-declaring the class here, the
  // modal would render completely unstyled.
  return createPortal(
    <div className="events-showcase">
      <div className="event-modal__overlay" onMouseDown={handleOverlayClick}>
        <div
          className="event-modal"
          role="dialog"
          aria-modal="true"
          aria-labelledby={titleId}
          ref={dialogRef}
          tabIndex={-1}
        >
          <button
            type="button"
            className="event-modal__close"
            onClick={onClose}
            aria-label="Close dialog"
          >
            ×
          </button>

          {event.thumbnail ? (
            <img
              className="event-modal__thumb"
              src={event.thumbnail}
              alt={event.thumbnailAlt || ''}
              width={event.thumbnailWidth || undefined}
              height={event.thumbnailHeight || undefined}
            />
          ) : (
            <div
              className="event-modal__thumb event-modal__thumb--placeholder"
              aria-hidden="true"
            />
          )}

          {/* Everything below the image lives in its own padded wrapper —
              the image itself is edge-to-edge against the dialog (see
              .event-modal__thumb), so padding can't live on .event-modal
              as a whole any more. */}
          <div className="event-modal__body">
            {/* Cancelled/postponed events still appear in the grid and open
                normally — see Events_Repository::normalise() for why — so
                this notice, not an absence from the listing, is what tells
                someone checking on the event that something's changed. The
                word is in the text itself, not conveyed by colour alone. */}
            {event.status !== 'scheduled' && (
              <p
                className={`event-modal__notice event-modal__notice--${event.status}`}
                role="status"
              >
                {event.status === 'cancelled' ? 'This event has been cancelled.' : 'This event has been postponed.'}
              </p>
            )}
            <h2 id={titleId} className="event-modal__title">
              {event.title}
            </h2>
            <dl className="event-modal__meta">
              <div>
                <dt>{event.allDay ? 'Date' : 'Date & time'}</dt>
                <dd>{event.fullDateLabel}</dd>
              </div>
              <div>
                <dt>Venue</dt>
                <dd>{event.venue}</dd>
              </div>
              <div>
                <dt>Category</dt>
                <dd>{event.category}</dd>
              </div>
            </dl>
            <div
              className="event-modal__description"
              // Events_Repository::normalise() runs post_content through
              // wp_kses_post() (not the raw the_content filter chain — see
              // its own comment for why), so this is already sanitized HTML
              // by the time it reaches the browser, not arbitrary content.
              dangerouslySetInnerHTML={{ __html: event.description }}
            />

            <div className="event-modal__links">
              {event.permalink && (
                <a href={event.permalink} className="event-modal__link">
                  View full event page
                </a>
              )}
              {event.externalUrl && (
                <a
                  href={event.externalUrl}
                  className="event-modal__link"
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  External event link
                </a>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>,
    document.body
  );
}
