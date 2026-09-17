import { useState } from 'react';
import EventCard from './EventCard.jsx';
import EventModal from './EventModal.jsx';

// Renders the filtered events as a grid of cards and owns which
// event (if any) is currently open in the details modal.
export default function EventsGrid({ events, className = '' }) {
  const [activeEvent, setActiveEvent] = useState(null);

  return (
    <>
      <ul className="events-grid" role="list">
        {events.map((event) => (
          <li key={event.id}>
            <EventCard event={event} onSelect={() => setActiveEvent(event)} />
          </li>
        ))}
      </ul>

      {activeEvent && (
        <EventModal
          event={activeEvent}
          onClose={() => setActiveEvent(null)}
          // Forwarded so the body-portalled dialog can carry the same
          // theme class as the grid — see EventModal.jsx.
          className={className}
        />
      )}
    </>
  );
}
