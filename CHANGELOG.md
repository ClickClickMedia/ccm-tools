# CCM Tools — Changelog

## v8.5.0 — Review findings, and three tests that would have caught them

A full security and correctness review, five reviewers over the whole codebase,
plus a settings audit. The authorisation layer came back clean: all 66 AJAX
actions check a nonce and a capability, there are no logged-out endpoints, and
no SQL injection is reachable from a request. What it did find was a set of
faults that take a site down or lose an administrator's work without ever
reporting an error.

### Critical: a Redis password could take the whole site down

Saving Redis settings inserted the generated block into wp-config.php with
`preg_replace`, using that block as the replacement string. A replacement
string is parsed for backreferences, so a `$1` inside a password was replaced
with capture group one, which is the "That's all, stop editing!" comment. That
comment contains an apostrophe, so the resulting `define()` was a hard parse
error and wp-config.php took the front end and wp-admin down together,
recoverable only over SFTP. The password validator rejects quotes, backslashes
and control characters, but `$` is an ordinary character in a generated
password. The insert is done by offset now and parses nothing.

### Blank pages for every visitor

Two output filters ran regular expressions over the whole page and assigned
the result straight back. PCRE returns null when it hits its backtrack limit,
and both used a lazy pattern that backtracks once per character, so a page over
about a megabyte containing an unclosed `<style>`, `<script>`, `<pre>` or
`<textarea>` produced null and the visitor got an empty page. Administrators
never saw it, because both filters are skipped for them. Every pass now falls
back to the untouched input.

### The exclude lists were destroyed on the first save

The defer, delay and preload exclusion lists are rendered into a textarea one
per line, but the save handler split on commas only and then ran `sanitize_key`
over the result. The shipped default of jquery, jquery-core and jquery-migrate
came back as the single handle `jqueryjquery-corejquery-migrate` the first time
anyone pressed Save, and a hand-added `jquery.validate` became
`jqueryvalidate`. The matcher is a case-sensitive substring test, so both fail
silently and the script you excluded gets deferred anyway.

### The save bar said "Saved" when the save failed

It inferred completion from the page's own button going disabled and back, and
every save routine re-enables its button in a `finally`, so a request that
failed looked exactly like one that succeeded. It now waits for the routine to
report the actual result, and says "Not saved" while leaving the changes marked
unsaved.

### Dark mode

The options inside a native dropdown are drawn by the operating system in a
separate popup and do not inherit the control's colour, so on a dark theme they
were dark on dark and only the highlighted row could be read. Both themes now
state the colour explicitly. An option is also no longer disabled while it is
the stored value, because a disabled option that is selected renders the whole
control blank on Windows.

### Also

- Saving WebP settings erased `exclude_sizes`, which is set by import and read
  when converting. The handler merges onto the stored settings now.
- The preferred image library was stored with no whitelist.

### Three new tests

- `tests/wp_config_write_test.php` drives the real writer against a wp-config
  fixture with eleven awkward passwords and runs `php -l` over what it produced.
  The existing test passed throughout, because it tested the function named in
  an old report rather than the operation that report was about.
- `tests/settings_roundtrip_test.php` posts every control each page offers
  through the real handlers and reads the option back. 110 settings pass.
- `tests/handler_auth_test.php` fails if any AJAX handler is missing a nonce or
  a capability check, or is registered for logged-out visitors.


## v8.4.2 — Six vitals on one line

There are always exactly six lab metrics on Site Health, but the grid was
fitting as many as would go and stranding Server Response Time alone on a
second row. The column count is set now, and every step down the widths
divides six evenly, so no screen size leaves a tile on its own.

- **Metric values are formatted here rather than taken from Lighthouse.** Its
  own displayValue is not consistent between audits: most are a bare figure
  like "1.7 s", but server-response-time returns the sentence "Root document
  took 0 ms", which read as a caption and wrapped onto a second line.
- **Findings use a dot instead of a full-height coloured rail**, the same as
  everything else in the plugin.
- **The Cloudflare Under Attack callout** was painted with a hardcoded red over
  a hardcoded pink, so it stayed pink in dark theme. It reads from the palette
  now. It was also referencing a custom property this stylesheet never defines.
- The settings group heading was sized with `--ccm-text-md`, which does not
  exist either, and had been landing on its fallback.


## v8.4.1 — Every finished database task says so

On the Database page, a task with nothing left to run said "Already done,
nothing to run" but only some of them carried the Done marker beside the name.
The marker was written into the chip that shows the row's count, and the five
index tasks have no count to show, so they had no chip to write into. One is
created for them now.

Finished rows are also muted, so the tasks you can still act on are the ones
that stand out.


## v8.4.0 — One component, no accent rails

The v8.3.0 pages were consistent in palette but not in construction, so they
read as variations on each other. Every settings list in the plugin is now the
same container, and the coloured bar down the left of each row is gone.

### The accent rail is gone everywhere

A tinted stripe down the left edge of every card is the most recognisable
generated-interface tic there is, and the switch on the right already says what
is on. An enabled row lifts its own surface instead. The same treatment was
applied to notices, toasts, the extension chips and the Database page's rows.

### One card per group

A settings group is now a single contained card, with its name, one line of
context and its live count in the header. Previously a floating heading sat
above a borderless list, which is why no two pages quite matched. The .htaccess,
Performance, WebP, Redis, WooCommerce, System Info and Database pages all use it.

### Cloudflare was a different product

Most of that page is drawn by JavaScript, which was still emitting tables with
the controls jammed against the right edge, so it never picked up the restyle.
It now emits the same rows and tiles as everything else. The Pro-plan lock is a
chip beside the setting's name rather than loose text crowding the control, and
the seven analytics tiles became six, because seven left one stranded on a row
of its own.

### Two columns where it helps

Reference material that is read rather than set now pairs up: the Redis status
and drop-in panels, its two install commands, WebP's library list beside the
test panel, and System Info's four detail panels as two rows of two. Settings
lists stay full width, because two long lists side by side is harder to scan,
not easier.

### Fixes

- **The WooCommerce payment restriction could not be turned off.** The button
  reads a `data-enabled` attribute that was never written, so it always read as
  off and always sent "turn on". Cash on Delivery and Bank Transfer stayed
  hidden from real customers after testing was finished. One attribute.
- **The .htaccess rows lost their live status** in the v8.3.0 rebuild, because
  the script was still looking for the old row class. Each row says again
  whether saving would add the directive, remove it, or leave it alone.
- **A failed error-log load was unreadable in light theme.** The viewer is a
  fixed dark surface in both themes, but the error text took the light theme's
  dark red.
- The Database page's risk groups were headed with emoji. They are named now.

### Housekeeping

Removed 243 lines of stylesheet for the row component the Database page no
longer uses. The preview build gained a markup-nesting gate, because a stray
closing tag is repaired silently by the browser, is invisible to `php -l`, and
had already broken one container this release.


## v8.3.0 — The rest of the pages, and a save bar that follows you

The v8.2.0 restyle changed the palette but left most pages' markup alone, so
they still read as the old plugin. This release rebuilds the remaining five on
the same component kit the Performance and Site Health pages use.

### Save settings is always reachable

Every settings page now carries a floating bar that stays in view no matter how
far you have scrolled. It counts what you have actually changed, offers Discard,
and warns before you leave with unsaved work. It drives the page's real Save
button rather than replacing it, so nothing about how settings are saved changed.

### Pages rebuilt

- **Redis** leads with hit rate, memory, key count and round-trip time, then
  status as a key/value panel instead of a table. Settings are grouped by
  Connection, Cache behaviour, WooCommerce and Advanced, each with a live count
  of how many values wp-config.php has locked. Compression now names the actual
  production incident it caused instead of listing options neutrally. Drop-in
  install instructions moved to a disclosure, shown only when they apply.
- **WebP** opens with conversion coverage and the size saved, and separates bulk
  conversion from settings from the library detail. Import, export and the
  uploads backup moved to disclosures at the bottom.
- **Error Log** now tells you what is actually wrong before showing you the log.
  Identical messages in the visible window are grouped and ranked by frequency,
  and when one plugin accounts for most of the fatals it says so by name. Added
  a text filter over the visible lines. Clear Log moved away from Refresh and
  Download, into a disclosure, as the destructive action it is.
- **Cloudflare** is ordered Cache, Security, SSL/TLS and network, then DNS, with
  development mode warned about where you can see it. Connection settings moved
  to a disclosure, since a token is set once.
- **.htaccess** rebuilt on the same kit.

### Fixes

- **Importing performance settings did nothing.** The catalogue rewrite in
  v8.2.0 left one Import button where `js/main.js` expects three elements: a
  button that opens the file picker, a label for the chosen filename, and the
  Import button itself, revealed only once a file is selected. Because the
  first was missing, the file input's change handler was never attached.
- **Four detection buttons were lost in that same rewrite.** Find scripts to
  defer, find third-party scripts to delay, and find origins to preconnect or
  DNS-prefetch all scan the site and fill the matching list for you. Their
  target fields were still there; only the buttons had gone.
- **Their result panels could not appear even when present.** They were hidden
  with a class that sets `display: none !important`, and `js/main.js` reveals
  them with an inline style, which cannot win against it.
- **Bulk turn-off came back.** Turn on everything safe had no counterpart, so
  the only way back from a page of enabled toggles was sixty clicks.

- **The error log was unreadable in light theme.** A generic `pre` rule defined
  later in the stylesheet was overriding the viewer's own background, so
  terminal-coloured syntax landed on a near-white box. Same fault in the
  .htaccess viewer.
- **Every page scrolled sideways on a phone.** The ten-item tab strip sized
  itself to its content and widened the whole document by about 100px. Wide
  reference tables now scroll inside their own card, and the two-column grids
  collapse to one column instead of holding a 480px floor.
- **ImageMagick reported itself twice**, as "ImageMagick ImageMagick 7.1.1-29
  Q16-HDRI x86_64 https://imagemagick.org", because its version string already
  contains the name. Trimmed to the version number.
- **Contrast.** Both themes now meet WCAG AA across all ten pages. Small brand
  green text has its own token, because the fill green only reaches 3.7:1 on a
  light surface; the warning, error, success and muted text colours were
  adjusted to clear 4.5:1.
- **The logo vanished in light theme.** It is drawn light for a dark bar, so it
  now sits on a dark ground there rather than being run through a filter.
- **The auto-refresh countdown read "Auto-refreshes in30seconds"** because a
  flex container discards the whitespace between its items.
- **The error log's file picker printed the whole absolute path**, which on a
  real host pushes the rest of the toolbar off the row. Shows the path from the
  WordPress root, with the full path on hover.
- **WebP quality and convert-on-demand described the wrong defaults.**
- Five form controls had no accessible name.

### Testing

`tests/render_test.php` now stubs `checked()`, `selected()` and `disabled()` the
way WordPress actually implements them: a plain string comparison. The previous
stub also matched any two truthy values, which made every option in a select
match, so the browser kept the last one and every dropdown previewed the wrong
stored value. `get_option()` returns real scalars for core options and
`size_format()` formats properly, so a preview shows what a real site shows.


## v8.2.0 — Performance page rebuilt from a catalogue

- **The page is now generated from data.** Every setting is described once in
  `inc/performance-catalogue.php` (label, description, risk, sub-fields) and one
  renderer draws them all. That replaced 1,104 lines of hand-written markup where
  each group was styled slightly differently and each risky option was warned about
  in its own words, or not at all. Adding a setting is now one array entry.
- **Every option states its risk.** Safe, test after, or can break things, with the
  specific failure named in the description rather than a vague warning.
- **Search and filter.** Sixty-odd toggles is not a list you scroll. Filter by All,
  On, Safe only or Risky, or type to find one.
- **Sub-settings live with their toggle** and appear when it is switched on, instead
  of sitting in a separate block further down the page.
- **Turn on everything safe** in one click, and a running count per group and for the
  page, updated live.
- **The master switch is in the header** with a plain warning when it is off, because
  a page full of enabled toggles that are doing nothing is misleading.
- **Prerequisites are enforced in the interface.** Deferring stylesheets stays disabled
  until critical CSS actually has content in it, and unlocks the moment it does.
- Fixed: `[hidden]` is a user-agent rule, so any class rule setting `display` beats it.
  Sub-field blocks were visible under switched-off toggles. Restated now, with a
  blanket rule so a future component cannot reintroduce it.

## v8.1.0 — Site Health rebuilt, and a real component kit

The v8.0.0 restyle swapped the palette and left every page's markup alone, so it
still read as the old plugin with a new coat of paint. This starts fixing that
properly, beginning with the page that needed it most.

### A component kit, not just tokens

New reusable pieces the pages are rebuilt *from* rather than decorated with: score
gauges, metrics with threshold bars, ranked finding rows, section heads, segmented
controls, sparklines, disclosures, flat panels, key/value lists, chips, toolbars and
empty states. All presentational and page-agnostic, so the remaining pages can be
rebuilt on the same vocabulary.

### Site Health

A full rebuild rather than a reskin.

- **Scores are rings, not table rows.** Four gauges coloured by Google's own bands,
  with the arc baked into the markup so the page is correct the instant it paints.
- **Core Web Vitals show where you actually sit.** Each metric draws the good,
  needs-improvement and poor bands to scale with a marker at the measured value.
  A number alone cannot tell you whether 2.6s was a near miss or nowhere close.
- **Findings are ranked with an impact bar** relative to the worst item, so the eye
  sorts them before the numbers are read, with the CCM Tools setting that addresses
  each one linked beside it.
- **Full history, kept.** The cap went from 20 runs to 200 per device. Sparklines per
  category on a fixed 0-100 scale, a change indicator against the previous run, and
  every recorded run in an expandable log.
- **Mobile and desktop are a segmented control**, not three separate buttons.
- **The API key moved to the bottom**, collapsed, because you set it once.
- Real-visitor data from the Chrome UX Report is shown when Google has it.

## v8.0.3 — Cloudflare Zone Features layout

- **The Polish dropdown rendered as a full-width control tiled with dozens of
  chevrons.** The new shared form styling set `background` as a shorthand, which
  resets `background-repeat` to its initial `repeat`. wp-admin then re-applied only
  its own arrow image on top, with no repeat value of its own to restore, so the
  arrow tiled across the whole control. Selects now carry their own arrow with an
  explicit `no-repeat`, so neither the shorthand nor wp-admin can reproduce it.
- **Selects no longer stretch to the full row width.** Several sit inline beside a
  label or a "Requires Pro+" note, and a stretched one pushed its neighbour onto the
  next line. They size to their content now, with a sensible minimum. A select that
  explicitly opts into `.ccm-input` still fills its container.
- **"Requires Pro+" no longer collides with the control beside it.** The note and its
  toggle or select now share a baseline with a real gap and the note does not wrap.
- Removed inline styling on the cron interval select that zeroed the right padding
  the arrow sits in.

## v8.0.2 — Dashboard reported the object cache as unavailable

- **The dashboard said "Not Available" while Redis was connected and serving.**
  Its object-cache tile asked whether the third-party *Redis Object Cache* plugin
  by Till Krüss was installed and active. CCM Tools ships its own drop-in and exists
  specifically so that plugin is not needed, so the answer was always no and the
  dashboard contradicted the Redis page sitting one tab away.
  The tile now reads the drop-in actually installed at `wp-content/object-cache.php`,
  the same source the Redis page uses, and distinguishes five real states: our
  drop-in running, another plugin's drop-in, an unrecognised one, Redis running with
  no drop-in, and no Redis at all. Each says which it is rather than just "not
  available".
- Removed `ccm_tools_check_redis_plugin()`, which existed only for that check, and a
  pair of status variables it fed that were computed on every dashboard load and
  never displayed.

## v8.0.1 — Dashboard fatal

- **Fixed a fatal on the CCM Tools dashboard.** `ccm_tools_convert_php_size_to_bytes()`
  was declared *inside* `create_dashboard_page()`, roughly a hundred lines below the
  new at-a-glance tiles that call it, so opening the dashboard died with "call to
  undefined function". The helper now lives at file scope, which is where it always
  should have been.
- **Fixed an undefined index warning on the same page.** `ccm_tools_cf_detect()`
  returned a cached array without checking it carried the key its callers read, so a
  stale or malformed transient produced a PHP warning on every dashboard view.
- **Added `tests/render_test.php`.** It stubs WordPress, loads every module and then
  actually executes all eleven admin page callbacks. Neither bug above was visible to
  `php -l` or to importing the files; only running the page finds them.

## v8.0.0 — Premium removed, AI optimiser removed, new UI

Everything that used to be paid is now standard, the AI auto-optimiser is gone, and the whole admin interface has been rebuilt. This is a major version because the Premium page, the AI Performance Hub and their settings no longer exist.

### Every feature is now available to every site

The premium tier is retired. There is no subscription, no API key to the CCM hub, no upgrade prompt and no locked card. Everything the plugin can do, it does on every install:

- **Redis Advanced Settings** — serializer, compression, async flush (UNLINK), ACL authentication, TLS connections, connection and read timeouts, and the drop-in runtime diagnostics.
- **Redis WooCommerce optimisation** — product query caching, term count caching and the per-type TTLs.
- **Cloudflare Security** — security level and Under Attack mode.
- **Cloudflare SSL/TLS and Network** — encryption mode, HTTP/2, HTTP/3, 0-RTT, always use HTTPS, automatic HTTPS rewrites, email obfuscation, hotlink protection, opportunistic encryption, early hints, Brotli and pseudo-IPv4.
- **Cloudflare Zone Analytics** and the read-only **DNS Records** viewer.
- **The postmeta composite index** on the Database page, which is the single biggest database win on an ACF or WooCommerce site.

A one-time cleanup runs on the first admin page load after updating and removes the orphaned subscription and AI options and transients. Nothing else in the database is touched.

### The AI Performance Optimiser is gone

Removed in full: hub PageSpeed testing, AI analysis, the one-click optimise loop, visual regression screenshots, the console check, the AI troubleshooter chat, settings snapshots and rollback, and the cross-site "known bad" learning store.

It was removed because it did not work. It applied its own guesses to live production sites, its rollback only ever undid the most recent iteration, infrastructure changes it made to `.htaccess` and the Redis drop-in were never reverted at all, and the visual check meant to catch "fast but broken" could be skipped by a missing screenshot. On top of that its opportunity data had been silently empty for months: Lighthouse 13 removed the audit IDs it was keyed to.

Nothing is applied automatically by this plugin any more. A human ticks every box.

### New: Site Health

The measurement half was worth keeping, so it has been rebuilt without the hub and without the auto-apply:

- Talks **straight to the Google PageSpeed Insights API** with your own API key. No CCM middleman.
- Mobile and desktop scores, Core Web Vitals, and real-visitor field data from the Chrome UX Report where Google has it.
- Findings are **ranked by how much time each one costs**, and where CCM Tools has a setting that addresses one, there is a link to it. It never changes the setting for you.
- Reads audits generically rather than by fixed ID, so a future Lighthouse release cannot silently empty the report the way it did to the old integration.
- Score history, so you can see whether a change actually helped.
- The key can live in the database or, better, as `CCM_TOOLS_PSI_KEY` in `wp-config.php`. Restrict it to the PageSpeed Insights API in the Google Cloud console.

### Security

Ten fixes, several of them reachable without logging in.

- **Stored XSS in the error log viewer.** The AJAX path returned the raw log and the browser rendered it as HTML, so anything that wrote attacker-controlled text into `debug.log` executed in the admin's browser on the 30-second auto-refresh. A previous release recorded this as fixed; only the initial page render had been escaped, not the refresh. Now escaped on every path, and the client no longer has a raw fallback to fall back to.
- **Any visitor could switch the plugin off for one request.** The REST detection matched `/wp-json/` anywhere in the request URI, query string included, and bailed out before loading any module. `/checkout/?x=/wp-json/` therefore disabled the admin-only Cash on Delivery and Bank Transfer restriction and allowed an unpaid order. The WooCommerce Store API bypassed it with no trick at all. The short-circuit has been removed entirely.
- **Path traversal in the WebP converter, reachable anonymously.** Image paths were derived from URLs by string replacement with no containment check, and the frontend pass scans every image tag on the page, so a crafted `src` in any post or comment gave a file read and write outside the uploads directory. On shared hosting that crossed customer accounts. Every URL-to-path conversion now resolves with `realpath()` and is confined to the uploads tree, with an extension allowlist.
- **`.htaccess` could be wiped to a single newline.** The regex that replaces the managed block was unguarded against a null return, which a PCRE backtrack-limit failure on a large `.htaccess` produces. That wrote an empty file: permalinks, other plugins' rules and the wp-config protection all gone, sitewide 500, no backup. Now guarded, backed up before every write, written atomically, and refused outright if the result would be empty or implausibly short.
- **`Disable WP Cron` erased the cron array.** It returned an empty array to every reader of the cron option, not just the runner, so the next plugin to schedule an event wrote back only its own and wiped every other scheduled job on the site.
- **wp-config.php writes are now atomic everywhere.** The debug toggles, the memory limit handler and both Redis writers used a plain write with no temp file. A worker killed mid-write or a full disk left a truncated wp-config.php, which is a white screen with no way into wp-admin to fix it. All of them now write to a temp file in the same directory, verify the byte count and rename into place, after taking a backup.
- **wp-config backups are encrypted at rest.** They hold database credentials and auth salts, and the `.htaccess` that was protecting them does nothing on nginx. Now AES-256-CBC with an HMAC, keyed from the site's own salts.
- **`?force-check=1` needed no permission.** Any logged-in user, including a subscriber or a customer, could hit it on any admin screen and force an unauthenticated GitHub API call, exhausting the 60-per-hour budget shared by every site behind the same IP. Now requires `update_plugins` and a nonce.
- **A Cloudflare Zone ID is now checked against the site's own domain.** With an all-zones API token and a mistyped or stale Zone ID, one site's admin panel silently drove another customer's zone.
- **The Cloudflare API token is encrypted at rest** and the update package is verified against a published SHA-256 when the release provides one.

### Correctness

- **The uploads backup failed on every batch after the first.** It used `ZipArchive::RDWR`, which is not a real constant, and threw an `Error` that the surrounding `catch` could not catch.
- **Redis cache keys now always carry a salt.** The field only ever showed the hostname as placeholder text, which is never submitted, so the constant was usually never written. Two WordPress installs sharing one Redis produced identical keys and could read each other's options, sessions and cart data.
- **Settings import no longer destroys preload URLs.** They were run through `sanitize_key`, which turns `https://site/font.woff2` into `httpsxsitefontwoff2`, and every page then emitted a broken preload tag. Script exclusion lists had the same problem and could never match again after a round trip.
- **The WebP reset no longer deletes hand-uploaded WebP files.** It inferred targets from filenames, so a `hero.webp` uploaded alongside `hero.png` was destroyed. It now only deletes files this plugin recorded converting.
- **`Preload CSS` cannot be enabled without critical CSS**, which was a guaranteed flash of unstyled content, and it no longer silently undoes small-stylesheet inlining.
- **Inlining a small script no longer discards its inline companion**, so configuration blobs and translations attached to a script survive, and deferred scripts are left alone.
- **Block theme guards** on the Gutenberg and block CSS toggles, which were dequeuing `global-styles` and rendering block themes unstyled.
- **WooCommerce asset trimming** now checks for product blocks and shortcodes, so a homepage with a products block keeps its add-to-cart.
- **Cache-Control** is emitted after the query, never alongside a `Set-Cookie`, and carries `Vary: Cookie`.
- **The LCP image flag** is no longer claimed by the site logo, which left the real hero image lazy-loaded.
- **Table conversion and database optimisation report truthfully.** Failed `ALTER` and `OPTIMIZE` statements were silently reported as successes.
- **The WooCommerce payment gateway check** no longer runs third-party gateways against a null cart in wp-admin, and its "available but disabled" states are now reachable.
- **`memory_limit = -1`** reads as unlimited instead of being flagged red.
- The admin Pages list keeps its own ordering instead of being forced to date descending.
- The plugin no longer flushes the entire object cache on every dashboard view, which on a shared Redis emptied the cache for every site on the box.
- The updater loads the admin plugin API before using it, so a cron run started by ordinary traffic cannot fatal and take every other scheduled job down with it.
- `HSTS` is no longer on by default. It is a one-year commitment and it now sits with the other options you choose deliberately.

### Interface

The admin interface has been rebuilt in the house design language, carried over from frikwork but vendored into the plugin's own stylesheet. Nothing is fetched from an external CDN, so a strict Content Security Policy or HSTS configuration on a client site cannot half-load it.

- **Light and dark themes**, with a toggle in the header. It follows the operating system until you choose, remembers your choice per browser, and is stamped before the page paints so there is no flash of the wrong palette.
- **Frosted glass cards** over a brand wash, gradient buttons, and a consistent set of badges, switches, tables and form controls.
- **The CCM brand spinner** replaces every loading indicator in the plugin, vendored from the shared `ccm-spinner` component so it matches ServerWatch, WebWatch and the tools site.
- The muted text colour is darker than the shared token, which measured 3.75:1 on a card and failed accessibility contrast.
- The stylesheet lost 1,942 lines of dead premium and AI rules.

### Housekeeping

- Removed roughly 2,460 lines of PHP across the two deleted modules, plus their orphaned test and documentation.
- Retired a second, weaker wp-config writer that hardcoded `127.0.0.1:6379`, never wrote its backup to disk and left an unterminated comment that the remover could not match.
- Deleted a number of AJAX handlers and functions with no caller anywhere, including one that returned the database host, name and user.
- De-duplicated the table name and collation validators, which existed twice byte for byte.
- Both escapers now escape quotes. Three call sites put their output inside an HTML attribute, where a quote could break out.
- Fixed a double-binding bug that started a second concurrent optimisation run on the second click of the run button.

## v7.45.0 — Security & AI safety hardening

- **Security:** fixed an authenticated PHP-injection/RCE in the Redis object-cache config writer (Redis password/username are now var_export-safe and quote/control-char-rejected at input); moved secret-bearing wp-config backups out of the web root into uploads/ccm-private/ with a deny .htaccess.
- **AI safety:** the AI apply path now validates every recommendation against a typed allow-list with preconditions — preload_css/critical_css require non-empty critical CSS (enforced before AND after sanitization), block-theme-incompatible settings are skipped on block themes, WooCommerce-only settings gated, and value type-mismatches are rejected (with benign int/bool normalization) instead of coerced.
- **AI one-click optimize:** interim safety guardrails — infrastructure changes (.htaccess/Redis/WebP/Cloudflare) are no longer auto-applied during optimize (they are never rolled back); the visual-regression gate now fails closed (rolls back when it cannot confirm the page is intact on both mobile and desktop); and an uncaught error always rolls back to the pre-optimization snapshot.

## v7.44.0
- **WebP is now actually served on sites that use `<picture>` elements**
  - On sites whose theme hand-codes `<picture>` markup (responsive `<source media="…" srcset="…">` children with an `<img>` fallback), the browser selects a matching `<source>` and serves *that* — it only falls back to the `<img>` when no source matches. The converter previously only rewrote the `<img>` `src` to WebP and never touched `<source>` elements, so the browser kept serving the original PNG/JPG from the source. The frontend WebP pass now rewrites `src` **and** `srcset` on both `<img>` **and** `<source>` tags, so the URL the browser actually picks is the WebP one. Each candidate is verified against the on-disk WebP (respecting the *Convert On-Demand* setting) and the original URL is kept whenever no WebP is available — no broken images. The pass is idempotent (already-`.webp` and non-upload URLs are skipped) and only runs for WebP-capable browsers (with `Vary: Accept` already set). An explicit `<source type="image/png|jpeg|gif">` hint is updated to `image/webp` when its URL is swapped.
- **Removed the "Use `<picture>` Tags" option**
  - The setting made the plugin wrap `<img>` tags in generated `<picture style="display:contents">` elements. Despite the `display:contents` trick it remained fragile across themes and page builders and could not be guaranteed safe on every site — the exact volatility it was meant to avoid. It's now gone: the toggle, the generation code, and its content/widget/WooCommerce filters are removed. WebP is served purely by rewriting existing markup (above) plus WordPress's native `srcset` filter, which is safe on all sites. Existing installs that had the toggle on lose no functionality — the stored flag simply becomes inert and images are served WebP via the new path.
- **Also:** swapped the CSS-drawn loading spinners for the branded CCM `img/spinner.svg`.

## v7.43.0
- **Raise the default Redis max-TTL from 1 hour to 7 days (`604800`)**
  - The managed `WP_REDIS_MAXTTL` default was `3600`, which capped *every* cache entry — including the many objects WordPress stores with no expiry (`expire = 0`) — at one hour. With `allkeys-lru` doing the real memory management (and 0 evictions / huge headroom on our boxes), a 1-hour cap just forced needless cache misses and DB churn. The new `604800` default keeps a sane safety ceiling while letting long-lived objects actually live. Applied consistently across the one-step Save flow, the legacy "Add to wp-config" path, the auto-generated config in System Info, and the settings-screen placeholder. Existing installs with an explicit Max TTL set are untouched.
- **Default the Redis serializer to igbinary when the extension is present**
  - igbinary produces smaller payloads and faster encode/decode than PHP's native serializer. The default serializer is now `igbinary` whenever `extension_loaded('igbinary')`, falling back to `php` otherwise. The constant is still only written to `wp-config.php` when non-php *and* the extension is loaded, and the drop-in's existing serializer-drift detection auto-flushes its own keys once on the switch, so existing installs migrate safely. (Compression is deliberately left at `none` — LZ4 + igbinary previously caused production OOMs; see v7.41.4.) The settings-screen labels now reflect which serializer is the active default.

## v7.42.1
- **Fix "plugin deactivated itself" after a manual zip install (duplicate folder)**
  - Release zips are flat (files at the archive root). When a developer downloads `ccm-tools-<ver>.zip` and installs it via **Plugins → Add New → Upload**, WordPress names the destination folder after the *zip filename* — creating `wp-content/plugins/ccm-tools-<ver>/` alongside the canonical `ccm-tools/`. With two active copies, the old duplicate-detection guard made the plugin deactivate **itself** (often the good copy, whichever loaded first) — the "plugin deactivated itself after update" symptom several sites hit. (The in-dashboard auto-updater was unaffected because its `fix_source_dir` renames the folder.)
  - **Prevent (root cause):** release zips now ship with a top-level `ccm-tools/` wrapper folder, so a manual upload always installs to `/wp-content/plugins/ccm-tools/` regardless of the zip filename. The 7.42.0 and 7.41.4 release assets were rebuilt with the wrapper too.
  - **Heal (existing sites):** the duplicate guard no longer deactivates itself. From the canonical `/ccm-tools/` install it now detects version-suffixed `ccm-tools-*` duplicate folders, silently deactivates them (no teardown hooks fire — wp-config and the drop-in are never touched), deletes the stale folders via the filesystem API (no uninstall hooks, so shared options survive), and shows a notice listing what was removed. A copy running from a version-suffixed folder while the canonical exists stands down quietly and hands back to `/ccm-tools/`; a lone version-suffixed install keeps running and just warns to reinstall.
- **Atomic object-cache drop-in replacement**
  - The v7.42.0 auto-refresh wrote the drop-in with `copy()` straight over the live `wp-content/object-cache.php`, which truncates-then-writes — a concurrent request could read a half-written file and fatal. It now writes to a temp file and `rename()`s it into place (atomic on the same filesystem), so readers always see the old or new file whole.

## v7.42.0
- **Redis drop-in lifecycle automation + one-step Save**
  - **Auto-replace the drop-in on plugin update.** Added `upgrader_process_complete` and an `admin_init` self-heal that bring the deployed `wp-content/object-cache.php` into line with the bundled version automatically. Previously a version bump (e.g. the v7.41.4 OOM fix) only raised an admin notice the user had to click — so fixes never reached sites until someone manually reinstalled. The refresh is connection-less, never overwrites another plugin's drop-in, only copies when the bundled `@version` is newer, and is guarded against AJAX/cron churn.
  - **Auto-(re)install on activation.** `register_activation_hook` reinstalls/refreshes the drop-in when Redis was previously enabled — covering the WP-Cron update deactivate→reactivate dance that could leave a stale or missing drop-in.
  - **Clean teardown on disable/deactivation.** `register_deactivation_hook` (and the Disable button) now remove the drop-in **and** strip the managed Redis block from `wp-config.php`. Genuine deactivations only — WordPress deactivates silently during updates, so caching is never torn down mid-update. Saved settings are retained, so re-enabling restores everything.
  - **One Save does the lot.** Saving the Redis settings now also rewrites `wp-config.php` and refreshes the drop-in **when Redis is enabled** — eliminating the easily-missed second "Add to wp-config.php" step. Enabling Redis writes `wp-config.php` in the same action too. The wp-config write is skipped when nothing changed (no needless backups), and both `wp-config-backup-*` and `object-cache-backup-*` files are pruned to the most recent 5.
  - Internal: extracted shared `ccm_tools_redis_build_config_array()` and `ccm_tools_redis_managed_constants()` so the Save and "Add to wp-config" paths can never drift; added `ccm_tools_redis_refresh_dropin()`, `ccm_tools_redis_remove_config()`, and `ccm_tools_redis_prune_backups()`.

## v7.41.4
- **Harden Redis object cache against `alloptions` corruption causing 4&nbsp;GB OOM crashes**
  - thesportingbase.com (Paladine headless front-end, WP backend at `/tsb/`) suffered two outages — **2026-05-28** and **2026-06-03** — where 3,000+ `Allowed memory size … exhausted (tried to allocate 4,295,229,440 bytes) in wp-includes/theme.php` fatals took `/tsb/wp-admin` and `/tsb/wp-json` fully down for ~1 hour each. The 4&nbsp;GB allocation is the classic signature of `unserialize()` reading a corrupted length prefix. With `WP_REDIS_SERIALIZER='igbinary'` + `WP_REDIS_COMPRESSION='lz4'`, php-redis occasionally fails to round-trip the `alloptions` blob (LZ4 decompress → igbinary deserialize), and because that blob is read on nearly every request via `wp_load_alloptions()`, every fresh FPM worker that hit the corrupt Redis key OOM'd identically until the cache was flushed and FPM restarted.
  - The **v7.39.10** fix (commit `67a4193`) addressed one *trigger* — `apply_filters('active_plugins', …)` firing theme resolution at file-include time — but not the underlying cache fragility. The 2026-06-03 incident fired from a different entry point (`theme.php:325`, `apply_filters('template', get_option('template'))`) with v7.39.10 confirmed in place, proving the cache itself needed hardening.
  - **Fix (P1 — the real fix):** the object-cache drop-in no longer persists the `options` / `site-options` groups to Redis. The `alloptions` blob is already memoised per request by WP core, so skipping Redis costs at most one indexed `wp_options` SELECT per worker while removing the sitewide failure surface entirely. Opt back in (not recommended) with `define('WP_REDIS_PERSIST_OPTIONS', true);`.
  - **Fix (P2 — belt-and-braces):** `get()` now type-guards `options:alloptions` and `options:notoptions` — any non-array return is treated as a cache miss and rebuilt from the database, protecting sites that re-enable options persistence.
  - **Fix (P3 — encoding-drift auto-flush):** on connect, the drop-in stamps the active serializer+compression in a sentinel key (read/written via `rawCommand` to bypass the encoders) and selectively flushes its own keys if the encoding changed out from under existing data — covering manual `wp-config.php` edits and server-level extension changes, which the v7.39.6 UI-only auto-flush never caught.
  - **Fix (P4 — guidance):** the Redis settings screen now warns that LZ4 + igbinary has caused production OOMs (recommending igbinary with no compression) and documents the skipped options/site-options groups plus the override constant.
  - Drop-in `@version` bumped 7.19.0 → 7.41.4; sites running the old drop-in will see the "drop-in outdated" admin notice prompting a reinstall.

## v7.41.3
- **Fix silent "an error occurred" deactivation after auto-update**
  - During a WP-Cron auto-update, WordPress's `Plugin_Upgrader` silently deactivates the plugin via `active_before`, replaces the files, then calls `activate_plugin()` to re-enable it. Both WP's `active_after` and our own `after_install` invoke `activate_plugin()` — and because the plugin is no longer in `active_plugins` at that point, WP runs the full activation path including `plugin_sandbox_scrape()`, which `include`s `ccm.php` a second time within the same request.
  - The OLD `ccm.php` was already loaded at request boot, so the second include re-executed the unguarded global function definitions (`ccm_tools_hide_all_notices`, `ccm_initialize_plugin`, class `CCMSettings`, …) and PHP fatal'd with "Cannot redeclare function". WordPress's fatal-error handler caught it, paused the plugin, and surfaced the generic "an error occurred" notice — but with no entry in `debug.log` unless `WP_DEBUG_LOG` was enabled.
  - Fix: added a `CCM_TOOLS_FILE_LOADED` sentinel at the very top of `ccm.php` that cleanly returns on the second include, so activation completes without re-declaring symbols.

## v7.41.2
- **Fix `wp` / `jQuery` is not defined console errors caused by defer/delay**
  - `defer_js` and `delay_js` now skip any script that has a registered `wp_add_inline_script(handle, ..., 'before'|'after')` companion. The inline `_after` runs at parse time and references symbols (`wp.i18n.setLocaleData`, `jQuery(...)`) that the parent hasn't defined yet — three of the four console errors on wendyshome.com.au were this exact pattern.
  - Added `wp-a11y` and `wp-polyfill` to the always-exclude list for both defer and delay (previously only `delay_js` had a list, and it was missing these two).
  - `defer_js` previously had **no** always-exclude list at all — it was happily deferring `wp-i18n`/`wp-a11y`/`wp-hooks`. Now mirrors `delay_js`.
- **Visual regression check no longer dismisses real carousel breakage**
  - Previous filter treated any AI report mentioning "carousel" or "slider" as expected dynamic content. The AI was correctly flagging "carousel JavaScript is not initializing properly, all testimonials stacked vertically" — that's a regression, not a slide change. New filter looks for breakage words (`not initializing`, `stacked vertically`, `broken`, `regression`, `unstyled`, `missing`, `falling back`, plus mentions of plugin setting names) and overrides the dynamic-content classification when present.

## v7.41.1
- **AI Optimiser — guard against orphan parent toggles**
  - The AI sometimes recommends a feature toggle (`critical_css: true`, `preconnect: true`, `dns_prefetch: true`, `lcp_preload: true`, `preload_key_requests: true`, `delay_third_party: true`) without the companion data key in the same response. The toggle would flip on but do nothing — confusing in the UI and PSI variance could blame it for unrelated score drops.
  - Server-side: `ccm_tools_ai_hub_apply_recommendations` now post-validates the result and forces the parent toggle back to false if the required data key is empty.
  - Client-side: the apply pre-filter detects orphan parent toggles before they reach a test cycle and logs `Blocked N orphan toggle(s) — feature enabled without required data`. Saves a 30-second mobile retest per orphan.

## v7.41.0
- **AI Performance Optimiser — reliability fixes**
  - **Visual check on tall pages no longer fails silently**: Hub now scales screenshots wider/taller than 7800px to fit Claude Vision's 8000px hard limit; plugin detects the legacy oversize error, logs a clear "skipped — page too tall" message, and stops uselessly retrying. New `image_clamped` flag surfaces when auto-scaling occurred.
  - **Stops re-suggesting already-applied settings**: Before the apply loop runs, the plugin now compares each AI recommendation against current settings and drops anything that would be a no-op. Skipped keys are sent back to the AI on the next iteration so it picks fresh levers instead of wasting a slot.
  - **Persistent per-URL learning**: Settings that cause a ≥10pt mobile drop on a URL are now remembered across runs (60-day TTL, 50-key cap, LRU evicted). On the next One-Click Optimise for the same URL, those settings are filtered out before apply and the AI is told they're proven incompatible. New AJAX handlers: `ccm_tools_ai_record_known_bad`, `ccm_tools_ai_get_known_bad`, `ccm_tools_ai_clear_known_bad`.

## v7.30.0
- **INP & Interaction Optimizations — Passive Event Listeners + DOM Size Warning**
  - **Passive Event Listeners** (#27): Inline `wp_head` script (priority 1) overrides `EventTarget.prototype.addEventListener` globally — forces `{passive: true}` for scroll/wheel/touchstart/touchmove; fixes PageSpeed "Does not use passive listeners" audit; estimated 50–150ms TBT/INP improvement
  - **DOM Size Warning** (#28): Informational toggle; instructs AI Performance Optimizer to flag pages with DOM node counts >1,500 and recommend structural simplifications
  - New "INP & Interaction Optimizations" UI card on Performance Optimizer page

## v7.29.0
- **WP Cleanup — Disable WP Cron, Disable Author Archives**
  - **Disable WP Cron** (#22): Unhooks `wp_cron` from `init`, eliminating the per-request cron HTTP sub-request (~50–200ms); requires server-side cron as replacement
  - **Disable Author Archive Pages** (#25): 301 redirects `/author/username/` to homepage — prevents thin-content penalty and user enumeration via author slugs

## v7.28.0
- **Block Editor & WooCommerce Asset Control + Browser Cache Headers**
  - **Disable Gutenberg Frontend Assets**: Dequeues `wp-block-library`, `wp-block-library-theme`, `global-styles`, `classic-theme-styles` (~35–50 KB saved)
  - **WooCommerce Assets on Shop Pages Only**: Dequeues WC scripts/styles on non-commerce pages (~100–200 KB saved); only shown when WooCommerce active
  - **Cache-Control Header**: Sends `Cache-Control: public, max-age=3600` for logged-out users; works on Nginx/LiteSpeed
  - **Stale-While-Revalidate**: Sub-toggle appends `stale-while-revalidate=86400` to Cache-Control header

## v7.27.0
- **Resource Hints & Third-party Delay**
  - **Preload LCP CSS Background Image**: Emits `<link rel="preload" as="image" fetchpriority="high">` for CSS background LCP element; 200–1000ms LCP improvement
  - **Priority Hints (Above-fold Images)**: Output buffer adds `fetchpriority="high"`, removes `loading="lazy"` on above-fold images
  - **Delay Third-party Scripts**: Rewrites matching `<script src>` to `type="text/plain" data-ccm-delay-src`; restores on first user interaction or 5s fallback; can reduce TBT 200–2000ms

## v7.26.0
- **HTML & Font Optimisations**
  - **Minify HTML Output**: Strips HTML comments and inter-tag whitespace; preserves `<pre>`, `<textarea>`, `<script>`, `<style>` blocks; saves 5–25 KB
  - **Preload Key Requests**: `<link rel="preload">` tags in `wp_head`; auto-detects `as` from file extension
  - **Remove wp-embed.min.js**: Deregisters wp-embed script and removes oembed host JS (~3.5 KB)
  - **Self-host Google Fonts**: Downloads CSS + WOFF2 to `uploads/ccm-fonts/`; MD5 cache key; 30-day freshness; eliminates external DNS lookup

## v7.25.0
- **Script & Style Inlining**: Inline scripts/styles under configurable threshold (default 2 KB, range 1–50 KB); eliminates per-asset HTTP requests
- **Inject Image Dimensions**: Adds `width`/`height` to `<img>` tags missing them; eliminates CLS
- **Inject Responsive srcset**: Adds `srcset`/`sizes` to local images missing them via WordPress srcset API

## v7.24.0
- **Head Cleanup additions**: Remove Generator Tag, Disable Admin Bar (Frontend), Remove Adjacent Post Links
- **Bug Fix**: `lazy_load_images`, `image_decoding_async`, `prefetch_on_hover` not saving (were missing from `savePerfSettings()` JS data object)

## v7.23.0
- **Image Optimizations**: Lazy Load Images, Async Image Decoding, Prefetch on Hover — all with LCP exclusion logic

## v7.22.4
- **Fix Duplicate Redis Constants**: `ccm_tools_redis_add_config()` now strips existing CCM block before writing fresh one — idempotent, prevents "Cannot redeclare constant" PHP fatal

## v7.22.3
- **Redis Active Config Table Live Update**: Save handler returns `active_config` array; JS rebuilds table without page reload
- Added `id="redis-active-config-table"` for DOM targeting; removed `location.reload()`

## v7.22.2
- **WWW/Non-WWW URL Normalization**: Both plugin and hub now strip `www.` before URL comparison; same API key works for both variants

## v7.22.1
- **AI Troubleshooter Site-Specific**: Chat endpoint fetches live page HTML/CSS on first message; AI can generate real Critical CSS and identify actual script/domain lists

## v7.22.0
- **Per-Setting Incremental Apply**: Each AI recommendation tested individually (apply → 3s wait → mobile PSI → keep or revert); `SINGLE_SETTING_TOLERANCE = 5` pts; related settings grouped (parent + data keys)
- **Net result**: If AI recommends 5 settings and 1 is bad, the other 4 now survive

## v7.21.0
- **Smart Iteration Strategy**: `sessionFailedBatches` tracks rolled-back settings within session; `buildSessionFailedContext()` sends "banned keys" to AI on retries
- **Hub prompts**: Max 5 recommendations per batch; sort by risk (no-risk → medium → high; max 1 high per batch)
- **Max iterations pulled from hub** (was hardcoded `AI_MAX_ITERATIONS = 10`)
- **Visual check timeout**: No longer forces rollback when scores improved; only rolls back when visual check failed AND scores dropped

## v7.20.8
- `WP_REDIS_SCHEME`, `WP_REDIS_TIMEOUT`, `WP_REDIS_READ_TIMEOUT` always written to wp-config.php (not skipped when at defaults)

## v7.20.7
- Redis Key Prefix/Salt "Generate" button using `crypto.getRandomValues()` for cryptographically secure random bytes

## v7.20.6
- **Dynamic Stripe Price on Premium Page**: `ccm_tools_premium_get_pricing()` fetches from hub; 12h transient cache
- **Fixed "Free" status after saving API key**: Save handler now calls `ccm_tools_premium_clear_cache()`
- **Hide comparison cards when Premium active**; refactored hub premium check to use shared `ccm_tools_ai_hub_request()`

## v7.20.5
- Fixed auto-update failing when plugin directory already named `ccm-tools` (flat zip edge case)

## v7.20.4
- Fixed API key not saving on Premium page (`initAiHubHandlers()` only called from Performance page)

## v7.20.3
- Fixed GitHub release zip installs to wrong directory (`upgrader_source_selection` filter renames extracted folder)

## v7.20.2
- Removed Premium tab from top nav; moved Premium submenu item to bottom of sidebar (after Error Log)

## v7.20.1
- Removed Premium dashboard card; updated Get Premium URL; added "Lost your API key?" login link

## v7.20.0
- **Dedicated Premium Admin Page** (`ccm-tools-premium`): Hub Connection + Subscription Status cards
- **Premium Subscription System**: 3-tier check (wp-config constant → 12h transient → hub API); render-level + AJAX-level gating; upsell cards; `ccm_tools_is_premium()`; `CCM_TOOLS_PREMIUM_URL` constant

## v7.19.7
- **Auto-Flush Redis on Serializer/Compression Change**: Prevents deserialization crash when switching between serializers

## v7.19.6
- **"Add to wp-config.php" writes all Redis settings** including serializer, compression, async_flush, username, scheme, path, timeouts, disable_comment

## v7.19.5
- **Drop-In Runtime Diagnostics Panel**: Shows live `$wp_object_cache->info()` values — actual serializer/compression in use, hit/miss stats, Redis call count and timing

## v7.19.4
- Fixed false error when saving unchanged Redis settings (`update_option()` returns false on no-change)

## v7.19.3
- Redis settings page reloads 800ms after save so Active Configuration table reflects new values

## v7.19.2
- Fixed Redis password field browser autofill (`autocomplete="new-password"`)

## v7.19.1
- Redis settings form visible when disconnected — don't lock users out after saving bad config

## v7.19.0
- **Redis Object Cache — Complete Rewrite** (`CCM_Redis_Object_Cache` class): SCAN-based flush, pipelined bulk ops, serializer support (php/igbinary/msgpack), compression (none/lzf/lz4/zstd), async flush (UNLINK), ACL auth, retry/reconnect, HTML footnote, `wp_cache_has()`, `wp_cache_remember()`, `wp_cache_supports()`, Site Health integration, drop-in version checking + update button

## v7.18.12
- Disk card explains quota fallback clearly when `quota` command unavailable

## v7.18.11
- Disk info prioritizes cPanel account quota over server filesystem totals

## v7.18.10
- Standardized collation to `utf8mb4_unicode_520_ci` (WordPress core default); "Update Table Collations" checkbox auto-unchecks when 0 tables need it

## v7.18.9
- Progressive per-table DB optimization (one AJAX call per table, prevents timeout on 100+ tables)
- Redis stats: replaced `KEYS` with `SCAN` to prevent blocking on large instances

## v7.18.8
- Visual regression: `layout_ok: false` now triggers rollback regardless of severity; failed check now fails-safe to rollback

## v7.18.7
- Robust screenshot capture: 3-attempt retry pipeline (Puppeteer × 2, Chromium CLI fallback); 120s global JS timeout; `diagnoseScreenshotCapability()` for debugging

## v7.18.6
- Step indicators fit on one line: `flex-wrap: nowrap`, removed `min-width`, reduced sizes

## v7.18.5
- Puppeteer screenshot capture: `waitUntil: networkidle0`, programmatic scroll, `fullPage: true`; falls back to Chromium CLI if Node.js unavailable

## v7.18.4
- Fixed `--virtual-time-budget` flag breaking screenshot capture (incompatible with `--screenshot` in headless=new mode)

## v7.18.3
- Visual Check retries on failure (was silently skipping); screenshot capture added virtual time budget for lazy-loaded content

## v7.18.2
- Parallelized baseline screenshots + console check with PageSpeed tests (saves 30–90s)

## v7.18.1
- AI token optimization ~40%: CSS minification before sending, reduced caps, URL truncation, page resource caching in optimize sessions, removed duplicate learnings from plugin side

## v7.18.0
- **Cross-Site AI Learning**: Optimization runs stored in hub `optimization_runs` table; `buildHubLearnings()` aggregates cross-site win/loss data; Hub admin Optimizations page

## v7.17.13
- Live UI update when pre-flight enables Performance Optimizer

## v7.17.12
- Fixed plugin update loop (header Version stuck at 7.17.9); fixed hub iteration screenshots timing window

## v7.17.11
- Searchable page picker for URL to test (type-ahead, keyboard nav, post type badges)

## v7.17.10
- Visual regression fail-safe: JSON parse fallback defaults to `layout_ok: false, severity: critical`

## v7.17.9
- Fixed side-by-side screenshot height mismatch (`max-height: 520px; object-fit: cover`)

## v7.17.8
- Auto-scroll to screenshots during optimization (`scrollIntoView` on baseline and after captures)

## v7.17.7
- **AI Visual Regression Detection**: Before/after screenshots sent to Claude Vision; automatic rollback on critical layout regression; three severity levels; new "Visual Check" step

## v7.17.6
- Per-iteration screenshot capture during One-Click Optimize; "After (Iter N)" labeling

## v7.17.5
- Screenshot capture timeout fix (`set_time_limit(120)`); backward compatible with both `url` and `data_uri` response formats

## v7.17.4
- **Screenshot storage rewrite**: File-based JPEGs saved on hub with UUIDs; hub returns URLs not base64; `screenshots` DB table; Hub admin Screenshots page

## v7.17.3
- Interactive screenshot lightbox: Before shows immediately after baseline; After slides in when ready; Esc/click-outside to close

## v7.17.2
- Screenshots moved into Before/After Comparison block (same results section as score tables)

## v7.17.1
- **Visual Screenshot Comparison**: Headless Chromium captures desktop (1920×1080) + mobile (375×812); PNG→JPEG via GD; hub endpoint `POST /api/v1/screenshot/capture`

## v7.17.0
- **Console Error Checking**: Headless Chromium captures JS errors before and after optimization; automatic rollback if new errors introduced; hub endpoint `POST /api/v1/console/check`

## v7.16.2
- Multiple screenshot uploads in AI Chat (up to 5); error log context injected into AI system prompt; `max_tokens` increased to 8,192

## v7.16.1
- AI Chat screenshot upload via Claude Vision API (PNG/JPEG/GIF/WebP, max 5 MB)

## v7.16.0
- **Video Optimization**: Video Lazy Load facade (click to play), Video Preload: None; Dashboard PageSpeed UI redesign with hero circle

## v7.15.1
- Fixed "key.replace is not a function" in Recent Results History (enriched change objects vs string keys)

## v7.15.0
- **PageSpeed Scores on Dashboard**: Async-loaded latest Mobile/Desktop scores; `ccm_tools_ai_hub_get_latest_scores` AJAX handler

## v7.14.2
- Reset step indicators to pending state on rollback iteration

## v7.14.1
- Fixed step indicator icon rendering artifacts (`font-size: 0` on element, explicit `14px` on `::after`)

## v7.14.0
- **AI Troubleshooter Chat**: Floating chat widget; conversational AI with all 30+ setting descriptions; markdown rendering; hub endpoint `api/v1/ai/chat`

## v7.13.1
- **AI Learning Memory**: `ccm_tools_build_learnings_context()` reads past runs; categorizes win/rollback settings with score deltas; enriched run data stores from/to values

## v7.13.0
- **Smart Rollback Algorithm**: Net-positive evaluation (keeps if both within PSI noise ±3pts OR net positive AND neither dropped >15pts); snapshot-based comparison
- **Pre-flight Tool Check**: Auto-enables .htaccess, WebP, Redis, Performance Optimizer before optimization

## v7.12.9
- Updated hub models: Sonnet 4.6, Opus 4.6, Haiku 4.5

## v7.12.8
- Max optimization iterations increased from 3 to 10

## v7.12.7
- Redesigned Recent Results History (before→after scores, color-coded, outcome badges, settings tags)
- Optimization Run Persistence (`ccm_tools_ai_save_run` AJAX handler; stores up to 20 runs in `wp_options`)

## v7.12.6
- Live Activity Log (terminal-style, Catppuccin Mocha theme); Accordion PageSpeed results; Google-standard color-coded scores; Remaining Recommendations panel

## v7.12.5
- Live settings update after AI apply; fixed `enabled` key → `#perf-master-enable` DOM mapping

## v7.12.4
- Fully automated One-Click Optimize (removed manual review step)

## v7.12.3
- **Iterative AI Optimization with Rollback**: Hub `ai-optimize.php` deep analysis rewrite; score-drop aware retest; plugin snapshot/rollback AJAX handlers; JS iterative loop with up to 3 retries

## v7.12.2
- Button uniformity fixes; `.ccm-ai-connection-row` flex layout; mobile responsive rules

## v7.12.1
- **Deep AI Analysis**: Hub fetches live page HTML; Critical CSS generation; JS defer/delay script analysis; preconnect/DNS prefetch domain identification; LCP image preload detection

## v7.12.0
- **Combined AI + Performance pages**; One-Click Optimize with dual strategy (Mobile + Desktop); 8-step progress indicator; Before/After comparison; Dual strategy result tabs

## v7.11.2
- Fixed "Hub vunknown" (flat hub response format); fixed `result_id` TypeError; defensive JS null checks

## v7.11.1
- Fixed AI page buttons not working (wrong wrapper class `ccm-wrap` vs `ccm-tools`)

## v7.11.0
- **AI Performance Hub**: Hub application + API v1 endpoints (health, pagespeed/test, pagespeed/results, ai/analyze, ai/optimize); plugin-side `inc/ai-hub.php`

## v7.10.15
- 6 Performance Optimizer bug fixes: CSS exclude list missing from import, YouTube facades on non-singular pages, Heartbeat only on frontend, admin test mode `?ccm_test_perf=1`, static settings cache, removed dead emoji code

## v7.10.14
- Added AVIF MIME type support to .htaccess rules

## v7.10.13
- Hardened Async CSS against double-processing (checks single + double quote variants); fixed ImageMagick `/tmp/` path error (sets `MAGICK_TMPDIR` to uploads)

## v7.10.12
- 4 Performance Optimizer bug fixes: removed dead font-display override CSS, fixed `?ver=` query string stripping, fixed LCP fetchpriority on `wp_get_attachment_image()` calls, cleaned up dead LCP code

## v7.10.11
- Cleaned up remaining libvips references

## v7.10.10
- **Fixed Async CSS Loading**: Reimplemented with print media trick (`media="print"` + `onload="this.media='all'"`); added exclude list; added noscript fallback

## v7.10.9
- Fixed Detect Scripts giving identical results for Defer and Delay (now accepts `target` parameter)

## v7.10.8
- Removed libvips support (causing 500 errors); WebP now uses ImageMagick or GD only

## v7.9.5
- Fixed WebP conversion after reset (failed transients not cleared; URL matching fallback)

## v7.9.4
- Fixed WebP picture tag URL matching with `/wp-content/uploads/` fallback

## v7.9.3
- Fixed WebP for page builder/theme images (output buffering replaces filter-only approach)

## v7.9.2
- Font Display: Swap for self-hosted fonts via output buffer injection into `@font-face` rules

## v7.9.1
- Import/Export Performance Settings (JSON with metadata, validation, auto-refresh on import)

## v7.9.0
- New performance settings: Font Display Swap, Speculation Rules API, Critical CSS, Disable Block Library CSS, Disable jQuery Migrate, Disable WooCommerce Cart Fragments, Reduce Heartbeat, Head Cleanup (XML-RPC, RSD, Shortlink, REST API, oEmbed)

## v7.8.6
- WooCommerce Redis Optimization (cache cart fragments, persistent cart, session caching, Product/Session TTL)

## v7.8.5
- Redis stats: fixed memory calc with `MEMORY USAGE` command; added Cache Groups and Avg. TTL stats

## v7.8.4
- Site-specific Redis statistics (filtered by key prefix, not server-wide)

## v7.8.3
- Redis cache statistics auto-refresh after flush

## v7.8.2
- Redesigned Redis configuration UI with CSS Grid; responsive breakpoints

## v7.8.1
- Security improvements: Redis extension checks on AJAX handlers, `realpath()` path validation, comprehensive input validation for all Redis settings

## v7.8.0
- **Redis Object Cache**: Custom drop-in replacing third-party plugins; tcp/tls/unix support; `wp-config.php` constants; one-click install/uninstall; selective + full flush; multisite support

## v7.7.0
- Reorganized menu order: System Info, Database, .htaccess, WebP, Performance, WooCommerce, Error Log; WooCommerce item conditional on plugin active

## v7.6.9 – v7.6.7
- Fixed picture tag layout breaking (3 iterations): final fix uses `display:block;width:100%;height:100%` on `<picture>` + `style="width:100%;height:100%"` on inner `<img>`

## v7.6.1
- Fixed WebP background image conversion for theme templates (switched to output buffering)

## v7.6.0
- WebP background image conversion (CSS inline styles and `<style>` blocks)

## v7.5.5 – v7.5.9
- Fixed srcset stripping with WebP; fixed blurry full-width images; consistent navigation menu; picture tag double-wrapping fix

## v7.3.0 – v7.4.0
- **WebP Image Converter** stable release (GD/ImageMagick, bulk convert, on-demand, picture tags, WooCommerce)
- **Performance Optimizer** initial release (defer/delay JS, async CSS, critical CSS, resource hints, query strings, emoji, dashicons, iframe lazy, YouTube facades)

## v7.2.13 – v7.2.19
- WebP improvements: on-demand conversion, WooCommerce hooks, picture tag conversion
- Error Log: fixed Show Errors Only filter

## v7.0.3 (Security Release)
- **CRITICAL**: Removed hardcoded GitHub API token; **HIGH**: Fixed SQL injection in table name handling; added `ccm_tools_validate_table_name()` whitelist validation

## v7.0.0 – v7.0.2
- Complete UI rewrite: pure CSS/vanilla JS, removed jQuery/Bootstrap/FontAwesome
- Deferred TTFB loading; toast notification system
