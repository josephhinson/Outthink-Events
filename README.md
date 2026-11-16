# Out:Think Events — how the plugin works

WordPress plugin (v1.1.1) that manages a list of upcoming events and displays them with a shortcode and a widget. Use this guide when designing the front-end look: it describes the data, the exact HTML the plugin produces, and the CSS hooks available.

Files:

- `ot_events.php` — all PHP: post type, editor fields, shortcode, widget
- `assets/css/ot-events.css` — default styles for the shortcode table
- `assets/js/ot-events-admin.js` — admin-only live date preview (not front end)

---

## 1. Data model

Each event is a WordPress post of type **`event`** (admin menu: **Events**).

| Field | Where it comes from | Example | Notes |
|---|---|---|---|
| Title | Post title | `Local Event` | Shown in the **Event** column |
| Details | Post content (block editor) | `Hangin with my homies.` | Rendered as full HTML (paragraphs, links, etc.) |
| Excerpt | Post excerpt | | Stored, not shown anywhere yet |
| Location | Meta `ot_e_location` | `St Louis, MO` | Plain text |
| Date | Meta `ot_e_date` | `March 1, 2027` | Required to appear in lists. Stored as a Unix timestamp (midnight UTC). Editors type freeform text ("March 1", "3/1/27", "next Friday"); a year-less date that has already passed rolls to next year |
| Time | Meta `ot_e_time` | `12pm ET`, `9–5pm` | Freeform plain text, not parsed |
| Link | Meta `ot_e_link` | `https://…` | Optional "More Info" URL |
| Category | Taxonomy `event-category` | | Hierarchical. Exists in admin, **not displayed or filterable on the front end yet** |

Rules:

- Only **published** events with a date **today or later** (site timezone) are shown. Past events drop off automatically.
- Sorted by date ascending, then title.
- Events without a date never appear in lists.

All fields are also available through the REST API at `/wp-json/wp/v2/event` (meta under `meta`).

---

## 2. The `[events]` shortcode

Usage: place `[events]` in any page or post. **No parameters** — it always lists every upcoming event.

### Output structure

A single `<table>`, one header row, then for each month a month heading row followed by that month's event rows.

```html
<table class="ot_event_list">
  <tr>
    <th>Date</th>
    <th>Location</th>
    <th>Event</th>
    <th class="e_details">Details</th>
  </tr>

  <!-- Month heading: appears when the month (and year) changes -->
  <tr class="month month-march">
    <td colspan="4">March</td>
  </tr>

  <!-- One row per event; first, third, fifth… rows also get class "even" -->
  <tr class="ot_e_data even">
    <td class="e_date">1</td>
    <td class="e_location">St Louis, MO</td>
    <td class="e_venue">Local Event</td>
    <td class="e_details">
      <p>Hangin with my homies.</p>          <!-- post content, as HTML -->

      <!-- Only if time and/or link exist; " - " separates them when both exist -->
      <span class="event_details">
        <span class="event_time">12pm ET</span> - <span class="event_link"><a href="https://…" target="_blank" rel="noopener">More Info →</a></span>
      </span>

      <!-- Only for logged-in users who can edit the event -->
      <span class="edit_link"><a href="…/wp-admin/post.php?post=123&action=edit">Edit This Event</a></span>
    </td>
  </tr>
</table>
```

### Details that matter for design

- **Date column shows only the day number** (e.g. `1`). The month comes from the month heading row; **the year is not shown anywhere**, even though grouping is by month + year (March 2027 and March 2028 get separate headings, both labelled "March").
- Month row class is `month month-{lowercase month name}` (`month-january` … `month-december`), so individual months can be styled.
- `even` is applied to the 1st, 3rd, 5th… event rows across the whole table (counting continues across month headings). It is used for zebra striping.
- `.e_details` contains arbitrary editor HTML (usually `<p>` tags, could include lists, images, links).
- Any piece can be empty: location, time and link are optional. The `.event_details` span is omitted entirely when there is no time and no link.
- `.edit_link` appears only for logged-in editors; regular visitors never see it.
- **Empty state:** if there are no upcoming events, the table renders with **only the header row** and no message.
- The table has no `<thead>`/`<tbody>` and no `<caption>`; the header row is the first `<tr>`.
- Text labels ("Date", "Location", "Event", "Details", "More Info →", "Edit This Event") are translatable strings in PHP.

### Default styles (`assets/css/ot-events.css`)

Loaded only on pages that contain the shortcode (style handle `ot-events`).

- Table: full width, collapsed borders, **horizontal rules only** (`1px solid #434343` under header, month, and event rows). Vertical/top borders from themes are explicitly removed.
- Header cells: 14px, normal weight, left aligned.
- Body cells: 13px / 17px line height, 10px vertical padding, top aligned.
- Column widths: date 10%, location 15%, event 20%, details 40%.
- Date cell: 24px day number.
- Month row: 24px text, 20px top / 14px bottom padding.
- Zebra rows: `tr.even td { background: #f5f5f5; }`
- `.event_details` is `display: block` with 7px top margin; "More Info" link underlined.
- No responsive/mobile rules yet — it is a plain 4-column table at every width.

The theme's own table styles also apply (fonts, colors, link color). To replace the defaults entirely, a theme can call `wp_dequeue_style( 'ot-events' )` and supply its own CSS, or the CSS file can simply be rewritten. Markup changes require editing `Outthink_Events::shortcode()` in `ot_events.php`.

---

## 3. Upcoming Events widget

Classic widget **"Upcoming Events Widget"** (also usable in the block widget editor via the Legacy Widget block). Settings: **Title** (default "Upcoming Events") and **Number of Events** (default 3). It has **no CSS of its own** — it inherits the theme's widget styles.

```html
<!-- before_widget / title markup comes from the theme's sidebar registration -->
<section class="widget ot_events_widget">
  <h2 class="widget-title">Upcoming Events</h2>

  <div class="ot-event-post">
    <h4>Local Event</h4>
    <p>
      St Louis, MO<br>                                   <!-- only if location -->
      <span class="date">Monday, March 1st, 2027</span><br>
      <span class="time">12pm ET</span>                   <!-- always present, may be empty -->
      <br><strong><a href="https://…">Learn More »</a></strong>  <!-- only if link -->
    </p>
  </div>
  <!-- …repeats per event -->
</section>
```

Empty state: `<p>No upcoming events.</p>`

Note the widget shows the full date with year, while the shortcode shows only the day number.

---

## 4. Other pages

- **Single event pages** exist at `/event/{slug}/` but use the theme's generic single-post template. The plugin adds nothing there, so **location, date, time and link are not shown** on single event pages unless a template is added.
- **No events archive** (`/event/` is a 404). The shortcode page is the listing.
- **Category archives** exist at `/event-category/{slug}/`, again using the theme's generic archive template with no event fields.

---

## 5. Admin editing experience (for reference)

Events use the block editor. Below the content is an **Event Details** box with:

- **Event Location** — text
- **Event Date** — freeform text with a live preview underneath ("Monday, March 1, 2027"), flagged "(in the past)" in red when applicable, and an error if it can't be read (the saved date is kept)
- **Event Link** — URL
- **Event Time** — freeform text

---

## 6. Ideas not yet built (open for design)

- Shortcode parameters: `limit`, `category`, past events
- Showing the year in the shortcode
- Empty-state message for the shortcode
- Mobile layout (stacked cards instead of a table)
- Single event template showing date/location/time/link
- Displaying categories
