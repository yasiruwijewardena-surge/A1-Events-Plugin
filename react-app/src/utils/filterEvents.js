// Pure filtering logic, kept separate from the hook so it's easy to unit
// test independently of React and of the fetching/loading concerns.
export function filterEvents(events, { category, date, search }) {
  return events.filter((event) => {
    if (category && event.category !== category) return false;
    if (date && event.dateLabel !== date) return false;
    if (search && !event.title.toLowerCase().includes(search.toLowerCase())) {
      return false;
    }
    return true;
  });
}
