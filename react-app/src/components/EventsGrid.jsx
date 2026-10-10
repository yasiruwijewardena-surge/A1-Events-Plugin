import EventCard from './EventCard.jsx';

// Renders the events as a grid of cards. Which event is open in the
// details modal is owned by App.jsx, not here — the hero above the grid
// opens the same modal, so the state has to live above both.
export default function EventsGrid({ events, onSelect }) {
  return (
    <ul className="events-grid" role="list">
      {events.map((event) => (
        <li key={event.id}>
          <EventCard event={event} onSelect={() => onSelect(event)} />
        </li>
      ))}
    </ul>
  );
}
