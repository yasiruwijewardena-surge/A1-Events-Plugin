// Loading state shown while events are being fetched from WordPress.
export default function Loader() {
  return (
    <div className="events-showcase__loader" role="status" aria-live="polite">
      <span className="events-showcase__spinner" aria-hidden="true" />
      <span>Loading events…</span>
    </div>
  );
}
