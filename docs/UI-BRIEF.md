# CCM Tools UI brief

How a page in this plugin is built, as of v8.3.0. Follow it exactly so the
eight screens read as one product rather than eight people's work.

Two pages are already rebuilt and are the reference. Read them before you
write anything:

- `inc/site-health.php` — hero, gauges, metrics, findings, trends, disclosure.
- `inc/performance-catalogue.php` + `ccm_tools_render_perf_page()` in
  `inc/performance-optimizer.php` — data-driven settings page.

## The rules

1. **Never a bare `<div class="ccm-card">` wrapping a `<table class="ccm-table">`.**
   That pattern is what the rebuild exists to remove. If you find yourself
   writing it, reach for `.ccm-panel` + `.ccm-kv`, or `.ccm-stat-grid`, or
   `.ccm-opts`.
2. **Every page opens with a `.ccm-hero`**: an `<h1>`, one line of real context
   in `.ccm-hero__meta` (actual numbers, not a slogan), and the page's primary
   action on the right.
3. **Group with `.ccm-section`, not with more cards.** A section head carries an
   eyebrow (usually a live count), a title and one sentence. Cards inside
   sections inside cards is three levels of chrome around one fact.
4. **Lead with the summary.** If a page has numbers worth knowing at a glance,
   they go in a `.ccm-stat-grid` of `.ccm-stat-tile` directly under the hero.
5. **State needs a shape, not just a colour.** Use `.ccm-chip`, `.ccm-dot` or a
   severity class alongside the word. Never colour alone.
6. **Destructive and risky things say what they break**, specifically, in the
   description. "May cause issues" is not a warning.
7. **Write for someone who knows WordPress but not this plugin.** Plain words.
   No exclamation marks. Australian spelling.
8. **Both themes, always.** Only ever use `--ccm-*` tokens. A literal hex in a
   component is a bug.
9. **Hiding cuts both ways, so match how the code will reveal it.**
   `[hidden]` loses to any class that sets `display`, so if your component sets
   `display`, restate `display: none` for `[hidden]` or toggle `.ccm-hide`.
   The reverse is the one that bites: `.ccm-hide` is `display: none !important`,
   and `js/main.js` reveals things with an inline `style.display`, which cannot
   beat it. Anything main.js shows must start hidden with an inline
   `style="display: none;"`, not with the class. Grep main.js for the id before
   you choose.
10. **Do not touch the save logic in `js/main.js`.** Keep the element ids it
    reads. Page behaviour that is purely presentational goes in its own file.

## The kit

Defined at the end of `css/style.css` under "UI KIT". Use these, do not invent
near-duplicates.

| Component | Use for |
|---|---|
| `.ccm-hero`, `.ccm-hero__meta`, `.ccm-hero__actions` | Page header |
| `.ccm-section`, `.ccm-section__eyebrow` | Group heading with a live count |
| `.ccm-stat-grid` + `.ccm-stat-tile` | At-a-glance numbers |
| `.ccm-gauge` | A 0-100 score as a ring |
| `.ccm-metric` | A value with good/poor bands and a marker |
| `.ccm-panel`, `.ccm-panel__head`, `.ccm-kv` | Key/value detail, replaces a two-column table |
| `.ccm-opts` + `.ccm-opt` | A list of toggles |
| `.ccm-findings` + `.ccm-finding` | Ranked recommendations |
| `.ccm-toolbar`, `.ccm-toolbar--sticky` | Search, filters, actions |
| `.ccm-seg` | Segmented control (radios, not buttons) |
| `.ccm-disclose` | Setup and detail you need once |
| `.ccm-empty` | Nothing to show yet |
| `.ccm-alert`, `--warn`, `--bad`, `--good` | One important sentence |
| `.ccm-chip`, `--good`, `--warn`, `--bad`, `--info` | Inline state |
| `.ccm-meter` | Progress |
| `.ccm-trend` + `.ccm-spark` | A value over time |
| `.ccm-grid-2`, `.ccm-grid-3`, `.ccm-stack`, `.ccm-row` | Layout |

Existing classes that still work and should be kept: `.ccm-button` and its
variants, `.ccm-toggle`, `.ccm-badge`, `.ccm-input`, `.ccm-table` (for genuine
tabular data only), `.ccm-text-muted`, `.ccm-success` / `.ccm-warning` /
`.ccm-error`.

## The save bar

Any page with settings ends with this, immediately before the closing
`</div>` of `.ccm-content`:

```php
<div class="ccm-savebar" data-ccm-savebar data-savebar-target="#your-real-save-button-id">
    <span class="ccm-savebar__dot" aria-hidden="true"></span>
    <span class="ccm-savebar__msg"><?php _e('No unsaved changes', 'ccm-tools'); ?></span>
    <button type="button" class="ccm-button ccm-button-secondary ccm-button-small" data-savebar-discard>
        <?php _e('Discard', 'ccm-tools'); ?>
    </button>
    <button type="button" class="ccm-button ccm-button-primary" data-savebar-save>
        <?php _e('Save settings', 'ccm-tools'); ?>
    </button>
</div>
```

`js/ui.js` does the rest: it watches every control in `.ccm-content`, counts
what changed, proxies its button to the real one, and warns on leaving with
unsaved work. The real save button stays on the page (usually in the hero) and
keeps its id, because `js/main.js` binds to it.

## Verifying

`php tests/render_test.php` renders every admin page under stubbed WordPress.
It must stay green. It catches the things `php -l` cannot: a function called
before it is declared, a missing helper, a page that throws halfway.

Then **look at it**. Build a preview page (see the pattern used for the two
reference pages: render the callback to HTML, inline `css/style.css` and the
page's JS, open it in a browser) and check both themes at a desktop width and
at about 400px. Layout bugs of this class are invisible to source review and
to every server-side test.
