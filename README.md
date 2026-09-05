# Events Showcase — React + Vite on WordPress

Interactive events grid (filters, search, details modal) built as a React
component and bundled with Vite, mounted into WordPress via the
`[events_showcase]` shortcode from a small custom plugin.

> **Status:** project scaffold in progress. This README will be filled in
> further as each piece (data layer, styling, Cloudways deployment) lands.

## Repository layout

```
react-app/                         React + Vite source (the component itself)
  src/
    components/                    EventCard, EventsGrid, EventFilters, SearchBox, EventModal, ...
    hooks/                         useEvents (data + filtering), useFocusTrap (modal a11y)
    utils/                         api.js (REST fetch), normalizeEvent.js, filterEvents.js
    styles/                        events.css
  vite.config.js                   Builds straight into the plugin's assets/build folder
  index.html                       Standalone dev sandbox (mimics the WP mount element)

wordpress-plugin/
  events-showcase/                 The custom WordPress plugin (deliverable)
    events-showcase.php            Plugin bootstrap
    includes/
      class-post-type.php          Registers the "event" CPT + "event_category" taxonomy (REST-enabled)
      class-acf-fields.php         Registers ACF fields (start/end date, venue, location) + exposes them in REST
      class-shortcode.php          Registers [events_showcase], renders the mount <div>
      class-assets.php             Reads Vite's manifest.json, enqueues hashed JS/CSS, scoped to pages using the shortcode
    assets/build/                  Vite build output (generated, not committed — see .gitignore)

docs/                              Supporting notes / screenshots for submission
```

## How data flows: WordPress → React

1. Events are stored as a custom post type (`event`) with:
   - Title, content (description) — standard WP fields.
   - Featured image — used as the card/modal thumbnail.
   - Taxonomy `event_category` — used for category filtering.
   - ACF fields `start_date`, `end_date`, `venue`, `location` — used for
     date filtering and the modal's full details.
2. All of the above is REST-enabled (`show_in_rest`), so the data is
   available at `/wp-json/wp/v2/events` with `?_embed=1` (pulls in the
   featured image and taxonomy terms) and ACF fields exposed under an
   `acf` key via `register_rest_field()`.
3. The shortcode (`class-shortcode.php`) renders only a mount element,
   with the REST endpoint and any shortcode attributes passed through as
   `data-*` attributes — no event content is ever hard-coded in PHP or JS.
4. `react-app/src/main.jsx` finds every `[data-events-showcase]` element
   on the page (supporting more than one shortcode instance per page),
   reads its `data-*` config, and mounts one independent `<App>` per
   element.
5. `useEvents()` fetches from the REST URL in that config, normalizes the
   response (`normalizeEvent.js`) into the shape the UI needs, and derives
   the available filter options from the actual data returned.

## Shortcode usage

```
[events_showcase per_page="9" category="workshops"]
```

| Attribute  | Default | Description                                   |
|------------|---------|------------------------------------------------|
| `per_page` | `12`    | Number of events fetched from the REST API.    |
| `category` | *(none)*| Restrict to one `event_category` slug.         |

## Setup

### React app

```bash
cd react-app
npm install
npm run dev      # local dev server (standalone sandbox, see index.html)
npm run build     # production build → wordpress-plugin/events-showcase/assets/build/
```

### WordPress plugin

1. Make sure Advanced Custom Fields is active (used for event details).
2. Copy (or symlink) `wordpress-plugin/events-showcase/` into
   `wp-content/plugins/` on the WordPress site.
3. Activate **Events Showcase** in wp-admin → Plugins.
4. Add some `Event` posts (Events → Add New): title, description, featured
   image, category, and the ACF fields.
5. Drop `[events_showcase]` into any page or post.

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
