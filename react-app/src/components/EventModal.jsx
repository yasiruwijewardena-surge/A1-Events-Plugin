import { useEffect, useId, useRef } from 'react';
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

  return (
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
        <h2 id={titleId} className="event-modal__title">
          {event.title}
        </h2>
        <dl className="event-modal__meta">
          <div>
            <dt>Date &amp; time</dt>
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
  );
}
