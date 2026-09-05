import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App.jsx';
import './styles/events.css';

// Mount into every instance of the shortcode's root element so the
// component can be dropped onto a page more than once. Config for each
// instance comes from its own data-* attributes (see README for the list).
function mountAll() {
  const roots = document.querySelectorAll('[data-events-showcase]');

  roots.forEach((el) => {
    if (el.dataset.mounted === 'true') return;
    el.dataset.mounted = 'true';

    const config = {
      restUrl: el.dataset.restUrl || '/wp-json/wp/v2/events',
      perPage: Number(el.dataset.perPage) || 12,
    };

    createRoot(el).render(
      <StrictMode>
        <App config={config} />
      </StrictMode>,
    );
  });
}

mountAll();
