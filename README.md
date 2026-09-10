# Events Showcase — React + Vite on WordPress

Interactive events grid (filters, search, details modal) built as a React
component and bundled with Vite, mounted into WordPress via the
`[events_showcase]` shortcode from a small custom plugin.

## Repository layout

```
react-app/                         React + Vite source (the component itself)
  src/
    components/                    EventCard, EventsGrid, EventFilters, SearchBox, EventModal, ...
    hooks/                         useEvents (data + filtering), useFocusTrap (modal a11y)
    utils/                         api.js (REST fetch), normalizeEvent.js
    styles/                        events.css
  vite.config.js                   Builds straight into the plugin's assets/build folder
  index.html                       Standalone dev sandbox (mimics the shortcode's markup)

wordpress-plugin/
  events-showcase/                 The custom WordPress plugin (deliverable)
    events-showcase.php            Plugin bootstrap
    uninstall.php                  Deletes all plugin data when the plugin is removed
    includes/
      class-post-type.php          Registers the `es_event` CPT + `es_event_category` taxonomy (REST-enabled) and the event fields' post meta schema
      class-acf-fields.php         Registers an ACF field group for the event fields, if ACF is active
      class-meta-box.php           Built-in fallback admin UI for the same fields, if ACF is not active — no plugin dependency required
      class-events-repository.php  Single source of truth for querying + normalising events — used by both the REST controller and the shortcode's SSR payload
      class-rest-controller.php    events-showcase/v1/events and /filters REST routes
      class-shortcode.php          Registers [events_showcase]: mount element, inline JSON payload, no-JS fallback
      class-assets.php             Reads Vite's manifest.json, enqueues hashed JS/CSS, scoped to pages using the shortcode
    assets/build/                  Vite build output (generated, not committed — see .gitignore)

docs/                              Supporting notes / screenshots for submission
```

## How data flows: WordPress → React

1. Events are stored as a custom post type (`es_event`, public — a
   single event's own permalink is the no-JS fallback) with:
   - Title, content (description), excerpt — standard WP fields.
   - Featured image — used as the card/modal thumbnail.
   - Taxonomy `es_event_category` — used for category filtering.
   - Event fields `es_start_datetime`, `es_end_datetime`, `es_venue_name`,
     `es_location`, `es_external_url` — registered as typed post meta via
     `register_post_meta()`, independent of whichever UI wrote them.
     Editable either through ACF (if installed — `class-acf-fields.php`)
     or the plugin's own built-in meta box (`class-meta-box.php`), which
     is the default and requires no other plugin. Exactly one of the two
     UIs shows up on the edit screen, whichever applies.
2. `Events_Repository` (`class-events-repository.php`) is the *only*
   place that queries and shapes event data — both the REST controller
   and the shortcode call `get_events()` on it, so the two can never
   drift into different response shapes. It returns a flat, predictable
   array per event: `id, title, permalink, excerpt, content,
   start_datetime, end_datetime, venue, location, categories, thumbnail,
   external_url` — dates as ISO 8601, never the raw MySQL format.
3. Two consumers read that data:
   - **REST API** — `GET /wp-json/events-showcase/v1/events` (filters:
     `category`, `search`, `page`, `per_page`) and `.../v1/filters`
     (available categories + locations, for data-driven filter controls).
   - **The shortcode** (`class-shortcode.php`) calls the same
     `get_events()` directly and inlines the first page's result as JSON
     in a `<script type="application/json">` tag next to the mount
     element — so the initial render has zero network round-trips.
4. `react-app/src/main.jsx` finds every `[data-events-showcase]` element
   on the page (supporting more than one shortcode instance per page),
   reads its `data-*` config (REST namespace root, per-page, initial
   category/search), parses its sibling inline-payload `<script>` if
   present, and mounts one independent `<App>` per element.
5. `useEvents()` hydrates from that inline payload on first render (no
   fetch, no loading spinner), then calls the REST API for every
   subsequent filter/search change. Category filtering is server-side
   (a real REST query param); location is a lighter client-side second
   filter over whatever page is currently loaded, since the REST API
   doesn't take a location param — see the comment at the top of
   `useEvents.js` for the reasoning.

## Shortcode usage

```
[events_showcase per-page="9" category="workshops" search=""]
```

| Attribute  | Default  | Description                                              |
|------------|----------|-----------------------------------------------------------|
| `per-page` | `12`     | Events per page, clamped 1–48.                             |
| `category` | *(none)* | Restrict to one `es_event_category` slug. An unknown slug falls back to no filter (never a silent empty grid). |
| `search`   | *(none)* | Initial search string, also seeds the search box's value.  |

## Setup

### React app

```bash
cd react-app
npm install
npm run dev      # local dev server (standalone sandbox, see index.html)
npm run build     # production build → wordpress-plugin/events-showcase/assets/build/
```

### WordPress plugin

1. Run the React build first (`npm run build` in `react-app/`) — the
   plugin looks for `assets/build/.vite/manifest.json` and shows an
   admin notice (to `manage_options` users only) if it's missing.
2. No other plugin is required. Event fields (start/end date, venue,
   location, external URL) have a built-in admin UI out of the box. If
   **Advanced Custom Fields** happens to be active on the site already,
   the plugin uses ACF's field group instead and hides its own — either
   way the data ends up in the same post meta keys, so
   `Events_Repository` can't tell which UI wrote them.
3. Upload `wordpress-plugin/events-showcase/` into `wp-content/plugins/`
   on the WordPress site, then activate **Events Showcase** in wp-admin →
   Plugins.
4. Add some **Events** posts (Events → Add New in the admin sidebar):
   title, description, featured image, a category, and the Event Details
   fields (start date is required; the rest are optional).
5. Drop `[events_showcase]` into any page or post and publish.
6. If permalinks are set to "Plain," switch to "Post name" (Settings →
   Permalinks) — the CPT's rewrite rules need a non-plain structure to
   produce `/event/your-event-slug/` URLs for the no-JS fallback links.

### Build output & asset scoping

`class-assets.php` only enqueues the bundle on pages whose content
contains the shortcode (`has_shortcode()`), and reads the hashed
filenames from Vite's `manifest.json` rather than hard-coding them, so a
fresh `npm run build` never requires touching PHP.

## Known limitations

**Asset loading can miss the shortcode outside plain post content.**
`class-assets.php` decides whether to load the app's JS/CSS by running
`has_shortcode()` against `$post->post_content` — which only sees the
literal `[events_showcase]` text stored on that field. It won't see the
shortcode if it's placed via a widget, a reusable block or synced
pattern, a page builder that stores its own content outside
`post_content`, or a template calling `do_shortcode( '[events_showcase]' )`
directly. On a page like that, the shortcode still renders (PHP doesn't
care where `do_shortcode()` is called from), but the mount element has
nothing to hydrate into.

The fix is the `events_showcase_enqueue_assets` filter — force assets on
for a specific case from a theme's `functions.php` (or a small
site-specific plugin):

```php
add_filter(
    'events_showcase_enqueue_assets',
    function ( $should, $post ) {
        if ( $post && 42 === $post->ID ) {
            return true;
        }
        return $should;
    },
    10,
    2
);
```

Swap `42` for the ID of the page in question.

## Deployment

The working demo is hosted on a Cloudways WordPress dev server. *(Link to
be added once deployed.)*
