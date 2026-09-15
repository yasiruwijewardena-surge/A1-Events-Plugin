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
      class-post-type.php          Registers the `es_event` CPT + `es_event_category` taxonomy (REST-enabled), the event fields' post meta schema, and backfills a missing start date on save
      class-settings.php           Settings page (submenu under Events) + Settings::get() — the site-wide defaults every other class's precedence chain resolves through
      class-acf-fields.php         Registers an ACF field group for the event fields, if ACF is active
      class-meta-box.php           Built-in fallback admin UI for the same fields, if ACF is not active — no plugin dependency required
      class-events-repository.php  Single source of truth for querying + normalising events — used by the REST controller, the shortcode's SSR payload, and the Schema.org output
      class-rest-controller.php    events-showcase/v1/events and /filters REST routes
      class-shortcode.php          Registers [events_showcase]: mount element, inline JSON payload, no-JS fallback
      class-schema.php             Outputs Schema.org Event JSON-LD in wp_head on singular event pages
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
     `es_location`, `es_external_url`, `es_all_day`, `es_status` —
     registered as typed post meta via `register_post_meta()`,
     independent of whichever UI wrote them. Editable either through ACF
     (if installed — `class-acf-fields.php`) or the plugin's own
     built-in meta box (`class-meta-box.php`), which is the default and
     requires no other plugin. Exactly one of the two UIs shows up on
     the edit screen, whichever applies. `es_start_datetime` is required
     by both editing UIs, and `Post_Type::backfill_start_datetime()`
     guarantees it's never actually empty regardless — a post saved
     without one (e.g. via a direct REST API create request, which
     doesn't enforce the "required" attribute either UI form uses) gets
     it backfilled from the post's own date on `save_post`. This is what
     makes `Events_Repository::get_events()`'s ordering clause safe to
     rely on unconditionally, rather than needing a fallback query path
     for events with no date.
2. `Events_Repository` (`class-events-repository.php`) is the *only*
   place that queries and shapes event data — both the REST controller
   and the shortcode call `get_events()` on it, so the two can never
   drift into different response shapes. It returns a flat, predictable
   array per event: `id, title, permalink, excerpt, content,
   start_datetime, end_datetime, venue, location, categories, thumbnail,
   external_url, all_day, status` — dates as ISO 8601, never the raw
   MySQL format.
3. Two consumers read that data:
   - **REST API** — `GET /wp-json/events-showcase/v1/events` (filters:
     `category`, `search`, `show`, `page`, `per_page`) and
     `.../v1/filters` (available categories + locations, for
     data-driven filter controls).
   - **The shortcode** (`class-shortcode.php`) calls the same
     `get_events()` directly and inlines the first page's result as JSON
     in a `<script type="application/json">` tag next to the mount
     element — so the initial render has zero network round-trips.
   - **`class-schema.php`** is a third consumer: it calls
     `Events_Repository::normalise()` directly (never a fresh query) to
     print Schema.org `Event` JSON-LD in `wp_head` on each event's own
     page, so structured data can't describe an event any differently
     than the page itself does.
4. `react-app/src/main.jsx` finds every `[data-events-showcase]` element
   on the page (supporting more than one shortcode instance per page),
   reads its `data-*` config (REST namespace root, per-page, initial
   category/search/show), parses its sibling inline-payload `<script>`
   if present, and mounts one independent `<App>` per element.
5. `useEvents()` hydrates from that inline payload on first render (no
   fetch, no loading spinner), then calls the REST API for every
   subsequent filter/search change. Category filtering is server-side
   (a real REST query param); location is a lighter client-side second
   filter over whatever page is currently loaded, since the REST API
   doesn't take a location param — see the comment at the top of
   `useEvents.js` for the reasoning.

> **Behaviour note:** the default listing (`show=upcoming`) now hides
> past events — previously every event ever created was returned,
> oldest first. Pass `show="all"` (shortcode) or `?show=all` (REST) for
> the old "everything" behaviour, or `show="past"` for a most-recent-
> first archive view.

## Shortcode usage

```
[events_showcase per-page="9" category="workshops" search="" show="upcoming" layout="grid" columns="3"]
```

Every default below (`12`, `upcoming`, `grid`, `3`) is only the *hardcoded*
fallback — see [Settings](#settings) for the actual precedence rule.

| Attribute  | Hardcoded default | Description                                              |
|------------|--------------------|-----------------------------------------------------------|
| `per-page` | `12`               | Events per page, clamped 1–48.                             |
| `category` | *(none)*           | Restrict to one `es_event_category` slug. An unknown slug falls back to no filter (never a silent empty grid). Still applied when `filters="false"` — it just can't be changed by the visitor. |
| `search`   | *(none)*           | Initial search string, also seeds the search box's value. Still applied when `filters="false"`, same as `category` above. |
| `show`     | `upcoming`         | `upcoming` (soonest first), `past` (most recent first), or `all` (everything, soonest first). |
| `layout`   | `grid`             | `grid` (cards in a responsive grid), `list` (full-width rows, thumbnail left), or `compact` (no thumbnail, denser type). |
| `columns`  | `3`                | `2`, `3`, or `4` — the grid's column count at the widest breakpoint only. Only meaningful when `layout="grid"`. |
| `filters`  | `true`             | Whether the search box, category/location dropdowns, and pagination render at all — one flag for all three, since they're really one "is this a browse interface or a glance?" decision. `false`/`0`/`no` all count as off. Defaults to `false` instead when `related="true"` (see below), but an explicit `filters="true"` still wins. Not a site-wide Settings option on purpose — the same site legitimately wants filters on its events page and off on a homepage teaser. |
| `related`  | `false`            | Shows events related to the event currently being viewed, instead of a plain listing. See [Related events](#related-events) below. |

## Related events

`[events_showcase related="true"]`, placed on an **event's own singular
template**, replaces the plain listing with events sharing that event's
categories — useful as a "you might also like" strip at the bottom of an
event page. Two fallbacks keep it from ever looking broken:

- **No source event.** `related="true"` needs a current event to relate
  to, resolved via `is_singular()` + `get_queried_object_id()` in
  `Shortcode::render()`. Used anywhere else — a page, a blog post, an
  archive — there's no such event, so it silently falls back to a plain
  listing rather than erroring or rendering nothing.
- **No shared categories.** If the source event has no categories, or no
  other event shares any of them, it falls back to a plain upcoming
  listing (still excluding the source event) rather than showing an empty
  "Related events" block — three unrelated upcoming events read as
  intentional; an empty section reads as broken.

Related events are ordered by start date, the same as every other
listing — not by how many categories they share with the source event,
since ranking by tax-match count needs raw SQL that WP_Query doesn't
support and isn't worth the complexity here.

Related mode is **server-rendered only**: with `filters` and pagination
both off, there's nothing for the React app to refetch, so `related` was
deliberately never added as a REST parameter — a REST request has no page
context, so supporting it there would mean accepting a caller-supplied
post ID with no real consumer for it yet.

## Three realistic configurations

```
[events_showcase]
```
The full events page: filters, search, and pagination all on, showing
whatever `show`/`layout`/`columns` Settings resolves to.

```
[events_showcase filters="false" per-page="3" show="upcoming"]
```
A homepage teaser: three upcoming events, no search box or pager — just a
glance, not a browse interface.

```
[events_showcase related="true" per-page="3"]
```
A related-events block dropped into the single-event template. `filters`
defaults to `false` here automatically (a related strip with a search box
would be incoherent) — no need to pass it explicitly.

## Settings

**Events → Settings** in wp-admin (a submenu under the Events post type,
not under the site's Settings menu — these are defaults for this one
shortcode, not site-wide options). One option row
(`events_showcase_settings`), built entirely on the WordPress Settings
API. The page's own **How to Use** tab has the same shortcode/attribute
reference as this README, for whoever's editing content and doesn't have
a checkout of the repo open.

**Precedence, strictly: shortcode attribute → saved option → hardcoded
default.** An explicit `[events_showcase show="past"]` always wins; an
omitted attribute falls through to whatever's saved on the settings
page; a fresh install with nothing saved falls through to the hardcoded
default below. `Shortcode::parse_atts()` and
`REST_Controller::events_args()` each implement this by sourcing their
own `shortcode_atts()`/args-schema defaults from `Settings::get()` — the
ordering falls out of how those two functions already work, rather than
being hand-coded as an if/else chain.

| Setting             | Default    | Applies to |
|---------------------|------------|------------|
| `default_show`      | `upcoming` | The shortcode's `show` attribute and the REST API's `show` param. |
| `default_per_page`  | `12`       | The shortcode's `per-page` attribute and the REST API's `per_page` param. |
| `default_layout`    | `grid`     | The shortcode's `layout` attribute. |
| `default_columns`   | `3`        | The shortcode's `columns` attribute. Only meaningful for `layout="grid"`. |
| `default_duration`  | `120` (minutes) | Pre-fills the end time in the event editor when a start time is set and no end time is given yet — a suggested value in the field, not something saved until the event itself is. |
| `date_format`       | `site`     | The no-JS fallback list's date display. `site` matches WordPress's own Settings → General date format; `short`/`long` are fixed formats independent of it. |

`Events_Repository` — the query layer — deliberately never reads these
options itself. It takes explicit, already-resolved arguments and stays
a pure data layer; resolving the precedence chain above is each caller's
(Shortcode's, REST_Controller's) job, not the repository's.

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

## Design decisions

Deliberate scope boundaries on the settings page, not gaps that were
missed:

**No custom CSS textarea.** WordPress already ships one — Appearance →
Customize → Additional CSS — and it already does the one thing a
plugin-specific textarea would: apply arbitrary CSS to the front end.
Adding a second one here would mean storing and echoing user-supplied
CSS from a second location, which is a sanitization surface (CSS can
carry `url()` calls, `expression()` in older IE, `@import`) with no
capability the built-in one doesn't already cover. There's nothing this
plugin's version would do that the Customizer's doesn't, for the cost of
one more thing to sanitize correctly.

**No shortcode-instance discovery.** A settings UI listing "every place
`[events_showcase]` is used on this site" sounds useful until it's
wrong: `has_shortcode()` — the only mechanism available — can only see
the shortcode when it's literally present in a post's `post_content`
field (see Known limitations, above). It can't see one inside a widget,
a reusable block or synced pattern, or most page-builder fields. A list
built from that check would look authoritative — a clean table with
checkmarks — while silently missing real instances, which is worse than
having no such list at all: an admin trusting an incomplete list is
worse off than one who knows to check manually.

## Deployment

**Live demo:** <https://wordpress-1404196-5221843.cloudwaysapps.com/events/>

Hosted on a Cloudways WordPress dev server. The `/events/` page is an
ordinary WordPress page whose content is a single `[events_showcase]`
shortcode — nothing about the page itself is special, which is the point.

To ship an update: run `npm run build` in `react-app/`, then upload the
refreshed `wordpress-plugin/events-showcase/` folder. No PHP edit is
needed for a new bundle — `class-assets.php` reads the new hashed
filenames out of `assets/build/.vite/manifest.json` on the next request.
