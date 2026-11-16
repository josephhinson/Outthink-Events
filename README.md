# Out:Think Events — how the plugin works

WordPress plugin (v1.4.0) for managing upcoming events. It shows them through a shortcode, a block and a widget, and adds a details box to single event pages. Lists stay correct even when pages are served from a cache: past events disappear in the browser, and fresh data is loaded from a REST endpoint.

Use this guide when designing the front end. It describes the data each template receives, the exact HTML produced today, the markers that must stay for roll-off to work, and the CSS hooks.

## Files

| File | Purpose |
|---|---|
| `ot_events.php` | Plugin header and loader |
| `includes/class-outthink-events.php` | Post type, taxonomy, meta, editor box, date parsing, queries, `prepare_event()` |
| `includes/class-outthink-events-display.php` | Rendering, shortcodes, block registration, single-page details, REST endpoint |
| `includes/class-outthink-events-privacy.php` | Events without a page: private and "No link" (see §2a) |
| `includes/class-ot-events-widget.php` | Classic widget |
| `templates/list.php` | **Event list markup** (shortcode + block) |
| `templates/widget.php` | Widget body markup |
| `templates/single.php` | Details box on single event pages |
| `assets/css/ot-events.css` | Default styles |
| `assets/js/ot-events-front.js` | Roll-off + refresh on the front end |
| `assets/js/ot-events-admin.js` | Live date preview in the editor (admin only) |
| `blocks/events/` | "Upcoming Events" block (`block.json`, editor script; no build step) |

**Redesigning = editing the three templates and the CSS.** All the data is prepared in PHP before the templates run, so a template is plain HTML with variables. A theme can also override any template by copying it to `yourtheme/outthink-events/{list|widget|single}.php`.

---

## 1. Data model

Each event is a post of type **`event`** (admin menu: **Events**), edited in the block editor with an **Event Details** box.

| Field | Storage | Notes |
|---|---|---|
| Title | Post title | |
| Description | Post content | Full editor HTML |
| Excerpt | Post excerpt | Optional |
| Venue | Meta `ot_e_venue` | Plain text name of the place, e.g. `The Fox Theatre` |
| Location | Meta `ot_e_location` | Plain text city/area, e.g. `St Louis, MO` |
| Date | Meta `ot_e_date` | Unix timestamp, midnight UTC of the event day. Typed as freeform text ("March 1", "3/1/27", "next Friday") with a live preview; a year-less date that has passed rolls to next year |
| Time | Meta `ot_e_time` | Freeform text, e.g. `12pm ET`, `9–5pm` (not parsed) |
| Venue link | Meta `ot_e_link` | Optional external URL |
| Details link to | Meta `ot_e_link_to` | `local` (the event's own page), `venue` (the venue link) or `none` (no link, and **no event page**, see §2a). Defaults to `venue` when a venue link exists; `venue` falls back to `local` without one |
| Categories | Taxonomy `event-category` | Hierarchical; used for filtering lists. The `private` category makes an event private (§2a) |

**Which events appear in lists:** published, with a date of today or later (site timezone), soonest first (ties by title). Undated events never appear in lists.

---

## 2. Where events appear

| Output | How to place it | Options |
|---|---|---|
| Event list | Shortcode `[events]` | `limit="5"` (0 or omitted = all), `category="speaking,workshops"` (slugs; any match) |
| Event list | Block **Upcoming Events** (`outthink/events`) | Sidebar: Number of events (0 = all), category checkboxes. Supports wide/full alignment, margin, padding |
| Event list (compact) | Widget **Upcoming Events Widget** | Title, number of events, category slugs |
| Event details box | Automatically above the content on single event pages | Or place it manually with `[event_details]` (current event) or `[event_details id="123"]`. Manual placement turns off the automatic box on that page |

**Beaver Builder:** use the shortcode in a Shortcode/HTML module. On Beaver Themer single-event layouts, the details box appears in the Post Content module automatically. You can also put `[event_details]` anywhere in the layout.

Single event pages live at `/event/{slug}/`, categories at `/event-category/{slug}/`. Both use the theme's normal templates. There is no `/event/` archive; the list shortcode/block is the listing.

---

## 2a. Events without a page: private and "No link"

Two kinds of event have no page of their own:

- **No link:** "Details link to" set to **No link**. The event shows in lists in full (title, venue, city, time), just without a details link.
- **Private:** in the **`private`** event category. Lists show only the date and city.

Both still appear in upcoming lists. Nothing else on the site reveals them to visitors (anyone who can't edit posts):

- Their `/event/{slug}/` page returns a 404.
- They're left out of search, feeds, category archives, other front-end post queries and WordPress core sitemaps.
- They're left out of the Yoast SEO sitemap.
- Previous/next links on other event pages skip them.
- The public `/wp/v2/event` REST API hides them.
- In templates, `$event['has_page']` is false and `details_url` is empty for both kinds.
- For private events specifically, `$event['is_private']` is true and `details_url` is empty. The plugin's list and widget templates show "Private Event" with the date and location only (row/card class `is-private`). The theme's list override does the same.

Editors still see and can preview these events everywhere. To use different category slugs:

```php
add_filter( 'ot_events_private_categories', fn() => array( 'private', 'closed-door' ) );
```

---

## 3. Template data

`templates/list.php` and `templates/widget.php` receive:

- `$events` — array of prepared events (below), already filtered and sorted
- `$args` — `['limit' => int, 'category' => string[], 'view' => 'list'|'widget']`

`templates/single.php` receives `$event`, one prepared event.

Each prepared event is an array (escape on output; `content` is already-rendered HTML):

| Key | Example | Notes |
|---|---|---|
| `id` | `123` | |
| `title` | `Local Event` | |
| `content` | `<p>Hangin with my homies.</p>` | Rendered post content. **List view only**; empty in widget and single |
| `excerpt` | `''` | Manual excerpt or empty |
| `venue` | `The Fox Theatre` | May be empty |
| `location` | `St Louis, MO` | May be empty |
| `time` | `12pm ET` | May be empty |
| `timestamp` | `1803945600` | Midnight UTC of the event day |
| `iso_date` | `2027-03-01` | For `<time datetime>` |
| `day` | `1` | Day of month, no leading zero |
| `weekday` | `Monday` | |
| `month` | `March` | |
| `month_short` | `Mar` | |
| `month_slug` | `march` | For CSS classes |
| `month_key` | `2027-03` | For grouping by month |
| `year` | `2027` | |
| `date_label` | `Monday, March 1st, 2027` | |
| `is_past` | `false` | Only ever true on single pages |
| `expires` | `1803967200` | Unix time the event's day ends (site timezone) |
| `permalink` | `https://site/event/local-event/` | Event's own page |
| `venue_url` | `https://theplace.com` | May be empty |
| `link_to` | `venue` | `local`, `venue` or `none` (resolved, see data model) |
| `details_url` | `https://theplace.com` | **Use this for "More Info" links**: the venue link or the event page, per the editor's choice. Empty for "No link" and private events, so print no link |
| `details_external` | `true` | True when `details_url` is the venue link (open in new tab) |
| `is_private` | `false` | True for events in a private category. Show only the date and location; `details_url` is empty |
| `has_page` | `true` | False for private and "No link" events: their `/event/{slug}/` page 404s for visitors, so never link to `permalink` |
| `categories` | `[['name'=>'Speaking','slug'=>'speaking']]` | |
| `edit_url` | `''` | Admin edit link; only set for logged-in users who can edit |
| `attributes` | ` data-ot-expires="1803967200"` | Must be printed on the element for each event (see §4) |

---

## 4. Roll-off markers (required in list and widget templates)

`assets/js/ot-events-front.js` keeps lists current on cached pages:

1. **Roll-off:** on load and every minute, it removes any element whose `data-ot-expires` time has passed. It also removes now-empty groups, re-applies the `even` class to the remaining events (1st, 3rd, 5th…), and swaps in the empty state when nothing is left.
2. **Refresh:** it fetches the list again from `GET /wp-json/outthink-events/v1/events?view=list&limit=5&category=a,b` (sent with no-cache headers plus an hourly cache-busting parameter) and replaces the wrapper's contents. That picks up events added or edited since the page was cached.

The plugin outputs the wrapper itself. Templates render only what goes inside it:

```html
<div class="ot-events ot-events--list" data-ot-events data-view="list" data-limit="5" data-category="speaking">
  <!-- template output -->
</div>
```

(The block adds `wp-block-outthink-events` plus alignment/spacing classes to the same wrapper.)

A template **must keep** these markers or roll-off breaks:

| Marker | Put it on | Effect |
|---|---|---|
| `<?php echo $event['attributes']; ?>` | The element for each event | Removed when the event's day ends |
| `data-ot-group` | Any element grouping events (e.g. a month) | Removed when it no longer contains events |
| `data-ot-empty` | The "no events" message (render it always; add `hidden` when there are events) | Shown when no events remain |
| `data-ot-hide-when-empty` | Anything to hide when empty (e.g. the table and its header) | Hidden when no events remain |

The script fires a bubbling `ot-events:updated` event on the wrapper after each change, so design JS can re-run if needed.

---

## 5. Current markup

### List (`templates/list.php`)

```html
<p class="ot-events__empty" data-ot-empty hidden>No upcoming events.</p>
<table class="ot_event_list" data-ot-hide-when-empty>
  <thead>
    <tr><th>Date</th><th>Location</th><th>Event</th><th class="e_details">Details</th></tr>
  </thead>

  <tbody class="ot-month" data-ot-group>                  <!-- one per month -->
    <tr class="month month-march"><td colspan="4">March</td></tr>

    <tr class="ot_e_data even" data-ot-expires="1803967200">  <!-- one per event -->
      <td class="e_date">1</td>
      <td class="e_location">
        <span class="event_venue">The Fox Theatre</span>   <!-- only if venue -->
        <span class="event_location">St Louis, MO</span>   <!-- only if location -->
      </td>
      <td class="e_event">Local Event</td>
      <td class="e_details">
        <p>Hangin with my homies.</p>
        <span class="event_details">                       <!-- only if time and/or link -->
          <span class="event_time">12pm ET</span> -
          <span class="event_link"><a href="…" target="_blank" rel="noopener">More Info →</a></span>
        </span>
        <span class="edit_link"><a href="…">Edit This Event</a></span>  <!-- editors only -->
      </td>
    </tr>
  </tbody>
</table>
```

- The date column shows only the day number; the month heading shows the month name. **The year isn't shown** (data is available as `year`).
- `target="_blank"` is only added when the link goes to the venue.
- When there are no events, only the `<p>` renders (visible).
- No responsive rules yet: it's a 4-column table at every width.

### Widget (`templates/widget.php`)

```html
<p class="ot-events__empty" data-ot-empty hidden>No upcoming events.</p>
<div class="ot-event-post" data-ot-expires="1803967200">
  <h4>Local Event</h4>
  <p>
    <span class="venue">The Fox Theatre</span><br>      <!-- only if venue -->
    <span class="location">St Louis, MO</span><br>      <!-- only if location -->
    <span class="date">Monday, March 1st, 2027</span><br>
    <span class="time">12pm ET</span>
    <br><strong><a href="…">Learn More »</a></strong>
  </p>
</div>
```

The theme supplies the widget wrapper and title. The widget has no CSS of its own.

### Single event details (`templates/single.php`)

```html
<div class="ot-event-details">                <!-- adds "is-past" class after the date -->
  <p class="ot-event-details__notice">This event has already taken place.</p>  <!-- past only -->
  <dl>
    <dt>Date</dt>     <dd class="ot-event-details__date"><time datetime="2027-03-01">Monday, March 1st, 2027</time></dd>
    <dt>Time</dt>     <dd class="ot-event-details__time">12pm ET</dd>
    <dt>Venue</dt>    <dd class="ot-event-details__venue">The Fox Theatre</dd>
    <dt>Location</dt> <dd class="ot-event-details__location">St Louis, MO</dd>
  </dl>
  <p class="ot-event-details__link"><a href="…" target="_blank" rel="noopener">Event Website →</a></p>
</div>
```

Rows without data are omitted, and the box isn't printed at all if the event has no date, time, venue, location or link. It appears above the post content; the theme still prints the title and content.

---

## 6. Default CSS (`assets/css/ot-events.css`)

Style handle `ot-events`. It's loaded wherever a list or the details box appears (not for the widget).

- **List table:** full width, horizontal rules only (`1px solid #434343`), theme vertical borders removed. 13px body, 14px headers, 24px day number and month headings, zebra rows `tr.even td { background: #f5f5f5 }`. Column widths: date 10%, location 15%, event 20%, details 40%. In the location column, venue and city sit on separate lines with the venue in semibold.
- **Empty state:** italic paragraph.
- **Details box:** light grey panel with a dark left border, label/value grid.
- `.ot-events [hidden] { display: none !important }` so themes can't un-hide rolled-off parts.

The theme's own fonts, colors and link styles apply on top. To fully replace the defaults, rewrite this file, or dequeue it with `wp_dequeue_style( 'ot-events' )` and style from the theme.

---

## 7. Developer hooks

| Hook | Type | Use |
|---|---|---|
| `ot_events_query_args` | filter | Change the `get_posts()` query (`$query, $args`) |
| `ot_events_prepare_event` | filter | Add/alter template data (`$event, $post`) |
| `ot_events_template` | filter | Point a template name at another file (`$file, $name`) |
| `ot_events_auto_single_details` | filter | Return `false` to stop the automatic details box (`$show, $post`) |
| `ot_events_private_categories` | filter | Category slugs that make an event private (default `['private']`) |

`Outthink_Events::get_upcoming_events( ['limit' => 5, 'category' => ['speaking']] )` and `Outthink_Events::prepare_event( $post )` can be used directly in custom code.

---

## 8. Open for design

- Mobile layout (e.g. stacked cards instead of a table)
- Showing the year, weekday or categories in the list
- Linking the event title to `details_url`
- Styling for the widget
- Single event page beyond the details box (hero, map, etc.)
