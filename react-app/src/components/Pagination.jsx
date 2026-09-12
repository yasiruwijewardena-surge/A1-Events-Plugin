// Previous/Next paging over the server-side result set. Hidden entirely
// when there's only one page — a disabled-both-ends control for a single
// page is just noise.
export default function Pagination({ page, pages, onChange }) {
  if (pages <= 1) return null;

  return (
    <nav className="events-pagination" aria-label="Events pagination">
      <button
        type="button"
        className="events-pagination__button"
        onClick={() => onChange(page - 1)}
        disabled={page <= 1}
      >
        Previous
      </button>
      <span className="events-pagination__status">
        Page {page} of {pages}
      </span>
      <button
        type="button"
        className="events-pagination__button"
        onClick={() => onChange(page + 1)}
        disabled={page >= pages}
      >
        Next
      </button>
    </nav>
  );
}
