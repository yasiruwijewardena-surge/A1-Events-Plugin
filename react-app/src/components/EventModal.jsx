import { useEffect, useRef } from 'react';
import { useFocusTrap } from '../hooks/useFocusTrap.js';

// Full event details popup. Closable via the close button, an overlay
// click, or Escape. Traps focus while open and restores it on close.
export default function EventModal({ event, onClose }) {
  const dialogRef = useRef(null);
  const titleId = 'event-modal-title';

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

        <img
          className="event-modal__thumb"
          src={event.thumbnail}
          alt={event.thumbnailAlt || ''}
        />
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
          // Description comes from WordPress post content (already sanitized
          // server-side by wp_kses via the REST API).
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
