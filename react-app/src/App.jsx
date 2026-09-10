import { useEvents } from './hooks/useEvents.js';
import EventFilters from './components/EventFilters.jsx';
import EventsGrid from './components/EventsGrid.jsx';
import SearchBox from './components/SearchBox.jsx';
import NoResults from './components/NoResults.jsx';
import Loader from './components/Loader.jsx';

// Top-level component mounted per shortcode instance.
// `config` comes from the mount element's data-* attributes, and
// `initialData` from its sibling <script type="application/json"> payload,
// if present — see main.jsx.
export default function App({ config, initialData }) {
  const {
    loading,
    error,
    filteredEvents,
    filters,
    setFilters,
    search,
    setSearch,
    categories,
    locations,
  } = useEvents(config, initialData);

  return (
    <div className="events-showcase">
      <div className="events-showcase__controls">
        <SearchBox value={search} onChange={setSearch} />
        <EventFilters
          filters={filters}
          onChange={setFilters}
          categories={categories}
          locations={locations}
        />
      </div>

      {loading && <Loader />}

      {!loading && error && (
        <p className="events-showcase__error" role="alert">
          {error}
        </p>
      )}

      {!loading && !error && filteredEvents.length === 0 && <NoResults />}

      {!loading && !error && filteredEvents.length > 0 && (
        <EventsGrid events={filteredEvents} />
      )}
    </div>
  );
}
