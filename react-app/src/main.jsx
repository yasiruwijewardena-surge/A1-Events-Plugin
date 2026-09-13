import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App.jsx';
import './styles/events.css';

// Mount into every instance of the shortcode's root element so the
// component can be dropped onto a page more than once. Config for each
// instance comes from its own data-* attributes — see
// wordpress-plugin/events-showcase/includes/class-shortcode.php for what
// it prints and README.md for the full attribute list.
function mountAll() {
  const roots = document.querySelectorAll('[data-events-showcase]');

  roots.forEach((el) => {
    if (el.dataset.mounted === 'true') return;
    el.dataset.mounted = 'true';

    const config = {
      // Namespace root (e.g. /wp-json/events-showcase/v1) — api.js appends
      // /events or /filters itself, so this file never hard-codes a route.
      restUrl: el.dataset.api || '/wp-json/events-showcase/v1',
      perPage: Number(el.dataset.perPage) || 12,
      initialCategory: el.dataset.category || '',
      initialSearch: el.dataset.search || '',
      // Fixed for this instance's lifetime, not a filter control — there's
      // no UI to change it, so it just needs to keep flowing into every
      // subsequent fetch the same way category/search do (see useEvents.js).
      show: el.dataset.show || 'upcoming',
      // Display-only — never sent to the REST API. App.jsx applies these
      // as modifier classes on the wrapper; events.css does the rest.
      layout: el.dataset.layout || 'grid',
      columns: el.dataset.columns || '3',
      nonce: el.dataset.nonce || '',
      // Shortcode.php always prints this attribute (see render()), so an
      // exact 'false' string is the only falsey spelling to check for —
      // anything else, including the attribute being missing entirely (a
      // build skew between an old shortcode call and a new bundle,
      // vanishingly unlikely but cheap to guard), defaults open to true.
      filters: el.dataset.filters !== 'false',
    };

    createRoot(el).render(
      <StrictMode>
        <App config={config} initialData={readInitialData(el)} />
      </StrictMode>,
    );
  });
}

/**
 * Reads the SSR payload the shortcode inlined as a sibling
 * <script type="application/json">, so the first render can hydrate
 * synchronously instead of showing a loading spinner while it fetches
 * exactly the same first page over the network.
 *
 * Falls back to null (triggering the normal fetch-on-mount path in
 * useEvents.js) if the payload is missing or fails to parse — the
 * component should degrade to "loads a little slower," never break,
 * if the markup and this script ever drift out of sync.
 */
function readInitialData(el) {
  const payloadId = el.dataset.payload;
  if (!payloadId) return null;

  const script = document.getElementById(payloadId);
  if (!script) return null;

  try {
    return JSON.parse(script.textContent);
  } catch {
    return null;
  }
}

mountAll();
