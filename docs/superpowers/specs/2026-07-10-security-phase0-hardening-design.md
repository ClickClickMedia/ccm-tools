# Design Spec — Security + Phase 0 Hardening Pass

> Date: 2026-07-10
> Scope: `ccm-tools` (WordPress plugin) + `ccm-api-hub` (PHP hub, deployed on Ignea at `/home/ccmtoolsapi/public_html`, shared with ~186 customer sites).
> Companion review: [`AI-PERFORMANCE-REDESIGN-PLAN.md`](../../../AI-PERFORMANCE-REDESIGN-PLAN.md).
> This is unit **A+B** ("harden first"). Unit **C** (the AI Auto Performance rebuild) gets its own spec cycle after this ships.

---

## 1. Goals / non-goals

**Goals**
1. Close the live security exposures: hub SSRF → customer secret disclosure, plugin Redis-config RCE, deploy-secret/`.git` exposure, admin CSRF/authz gaps.
2. Make the AI recommendations track the actual PageSpeed report again (LH13 insight ingestion), on a current model, with a validated output contract.
3. Add the minimum interim guardrails so the existing one-click flow stops leaving sites broken in the window before unit C replaces it.

**Non-goals (deferred to unit C)**
- Rebuilding the client-side optimize loop into server-authoritative (plugin-driven, Action Scheduler) orchestration.
- Full-state atomic snapshot/rollback covering infrastructure changes.
- Per-change single-setting apply with median-of-N verification.
- The safe/assisted catalog tiers.

We deliberately do **not** deep-fix the JS optimize loop here — C replaces it. Phase 0 invests only in (a) hub-side changes that carry into C unchanged, and (b) small interim guardrails.

## 2. Settled decisions (from brainstorming)

- **Sequencing:** harden (A+B) first and ship, then design + build C.
- **Deploy workflow:** feature branch `harden/security-phase0` in both repos; user reviews; merge to `main` triggers hub auto-deploy; plugin ships via tag + GitHub Release. No direct-to-prod.
- **Model:** Claude **Opus 4.8** (`claude-opus-4-8`) for analyze/optimize.
- **C architecture (recorded for later):** plugin-driven server-side loop (WP-Cron/Action Scheduler), hub stays a stateless advisor; auto-apply all tiers but one change at a time with atomic rollback gated on score + console + visual.

## 3. Branching & release model

| Repo | Branch | Ship trigger |
|------|--------|--------------|
| `ccm-api-hub` | `harden/security-phase0` | merge to `main` → webhook `git pull` on Ignea |
| `ccm-tools` | `harden/security-phase0` | merge to `main`, then tag + `gh release create` with built zips |

Claude does all code + local commits on the branches and documents the manual steps (§8). Claude does **not** rotate live credentials or merge to main unattended.

---

## 4. Hub security changes (`ccm-api-hub`)

### 4.1 SSRF guard (Critical)
New helper in `includes/` (e.g. `includes/url-guard.php`):

```
assertSafeExternalUrl(string $url, ?string $mustMatchSite = null): string
  - parse_url; require scheme ∈ {http, https}
  - reject if host is empty / is an IP in private/reserved/link-local/loopback ranges
    (127.0.0.0/8, 10/8, 172.16/12, 192.168/16, 169.254/16, 0.0.0.0, ::1, fc00::/7, fe80::/10)
  - resolve host (gethostbynamel / dns_get_record A+AAAA); reject if ANY resolved IP is in the above ranges (DNS-rebinding safe)
  - if $mustMatchSite !== null: require urlMatchesSite($url, $mustMatchSite)
  - return the normalized URL; throw/return error on any failure
```

cURL callers additionally set `CURLOPT_PROTOCOLS`/`CURLOPT_REDIR_PROTOCOLS = CURLPROTO_HTTP | CURLPROTO_HTTPS` and either disable redirects or re-run `assertSafeExternalUrl` on the effective URL after each redirect.

Apply at:
- `api/v1/ai-chat.php:32,47-48` — validate `site_url` (bind to authenticated site).
- `includes/page-analyzer.php:33-42,302-311` — page fetch + CSS href fetch.
- `api/v1/screenshot-capture.php:38-44` — validate before Chromium; **never** allow `file://`.
- `api/v1/console-check.php:32-39` — validate before Chromium.
- `api/v1/ai-visual-compare.php:41-49,109-118` — validate `before_*_url`/`after_*_url`.

### 4.2 Deploy secret (High)
- `deploy.php:24` — replace hardcoded `DEPLOY_SECRET` with `Env::string('DEPLOY_SECRET')`; add key to `.env` (value set by user during rotation, §8).

### 4.3 `.git` exposure (High)
- `.htaccess` — add `RedirectMatch 404 /\.git` (and verify `config/`, `includes/`, `.env` denies still hold).

### 4.4 Admin CSRF/authz (Medium)
- `admin/subscriptions.php:13,22-61` — add `requireCsrf()` + `requireManager()` to the `grant_premium`/`revoke_premium` POST handler; add `csrf_token` hidden field to its forms.

### 4.5 test-screenshot.php (Medium)
- Remove from production (recommended). If kept: wrap in `requireAdmin()`, remove forced `display_errors`, apply §4.1 guard to `$_GET['url']`.

### 4.6 Lower-severity batch
- `includes/api-auth.php` feature check: fix `screenshot-capture.php:31` / `screenshot-history.php:23` `checkFeatureAccess($site,'ai_enabled')` → `'ai'`.
- `premium-register-site.php:29` — `hash_equals()` instead of `!==`.
- `admin/sites.php:263` — stop serializing `api_key_hash` into the `editSite()` DOM payload (whitelist fields).

---

## 5. Plugin security changes (`ccm-tools`)

### 5.1 Redis-config RCE (High)
- `inc/redis-object-cache.php:1300` — build config lines with `var_export($value, true)` instead of raw single-quote interpolation:
  `$config_lines[] = "define('{$constant}', " . var_export($value, true) . ");";`
- `inc/ajax-handlers.php:3267,3303` — validate/reject single quotes, backslashes and non-printable chars on `password` and `username` at input (mirror existing `host`/`path`/`key_salt` validation).

### 5.2 Backups out of web root (Low)
- `inc/redis-object-cache.php:1348-1351,836-839` — write `wp-config`/`object-cache` backups to `wp-content/uploads/ccm-private/` (create with `.htaccess deny` + `index.php`), not ABSPATH/web root.

---

## 6. Hub AI correctness (`ccm-api-hub`) — carries into unit C

### 6.1 LH13 insight-audit ingestion
Central mapping (new `includes/psi-insights.php` or extend `page-analyzer.php`). For each supported insight audit, extract `id`, `title`, and savings from `metricSavings` (fallback to legacy `details.overallSavingsMs` when present), plus its detail items. Insight → CCM-setting hints:

| Insight audit | Addresses / CCM settings |
|---------------|--------------------------|
| `render-blocking-insight` | `preload_css`+`critical_css`, `defer_js`, `inline_small_styles` |
| `lcp-discovery-insight` | `lcp_preload`, `preload_css_bg_image`, `lcp_fetchpriority` |
| `lcp-phases-insight` | `lcp_fetchpriority`, lazy-load exclusions |
| `image-delivery-insight` | WebP, `inject_srcset`, responsive images |
| `third-parties-insight` | `delay_third_party` |
| `legacy-javascript-insight` | (informational; no safe auto-fix) |
| `duplicated-javascript-insight` | (informational) |
| `dom-size-insight` | `warn_dom_size` (informational) |
| `font-display-insight` | `font_display_swap`, `self_host_google_fonts` |
| `network-dependency-tree-insight` | `preconnect`, `dns_prefetch`, `preload_key_requests` |
| `modern-http-insight` | Cloudflare HTTP/3 |
| `use-cache-insight` | `.htaccess` caching, `cache_control_meta` |

- Fixes apply to `ai-optimize.php:490-498,586-661`, `ai-analyze.php:58-132`, `pagespeed-test.php:158-174`.
- Keep legacy extraction as a fallback and **log which schema was seen** (so we can confirm on live data).
- Fix `ai-optimize.php:440-445` `category` param (append `&category=` repeated, matching the fix already in `pagespeed-test.php:93-97`); store `diagnostics` on the optimize path (`ai-optimize.php:501-517`).

### 6.2 Model + request I/O
- `claude_model` default → `claude-opus-4-8` (config default + a DB settings update — see §8 manual step for the live value); add `claude-opus-4-8` (and `claude-sonnet-5`) to the cost table `page-analyzer.php:437-450`.
- Remove `temperature` (Opus 4.8 ignores/rejects it).
- Add `stop_reason` handling: if `max_tokens`, surface a real error (don't return empty success).
- **Structured Outputs**: use `output_config.format` with a JSON schema; `setting_key` constrained to an `enum` of real plugin keys; `recommended_value` typed. Removes the brittle regex parse and guarantees valid keys.
- Add `cache_control` breakpoints on the system prompt and the page-resource block.
- `ai-chat.php:235` — use `max(..., 16384)` like the other endpoints (not `min(...)`).

### 6.3 Prompt catalog fixes (`includes/prompts.php`)
- Rename to real plugin keys: `remove_wp_embed`→`disable_wp_embed`, `inject_responsive_srcset`→`inject_srcset`, `disable_admin_bar_frontend`→`disable_admin_bar`, `remove_adjacent_posts`→`remove_adjacent_post_links`.
- Fix type docs: `preload_key_urls` and `delay_third_party_domains` are **arrays**, not newline strings.

---

## 7. AI safety guardrails (interim) — plugin + hub

### 7.1 Server-side allow-list + preconditions (carries into C)
Shared typed catalog (source of truth). Each entry: `{ key, type, tier, requires[], incompatible_with[] }`.

- **Hub:** validate the model's recommendations against the catalog before returning (drop/annotate invalid; enforced by the Structured Outputs enum too).
- **Plugin** (`inc/ai-hub.php:524-635`): before applying, enforce:
  - `preload_css` requires non-empty `critical_css_code` (add to the guard map, not just orphan-check).
  - `disable_block_css` / `disable_gutenberg_frontend` blocked when `wp_is_block_theme()`.
  - `woo_scripts_shop_only` only when WooCommerce active.
  - Type-check every value against the catalog; reject mismatches instead of coercing (fixes the `(bool)"false"` trap).

### 7.2 Stop auto-applying infrastructure in one-click (interim)
- `js/main.js:6330-6438` — the pre-flight no longer auto-enables `.htaccess`/Redis drop-in/WebP/Cloudflare. Surface them as explicit opt-in toggles/notices instead. (Full-state snapshot of infra is a unit-C concern; removing the auto-apply eliminates the "never reverted" breakage class now.)

### 7.3 Visual gate fail-closed
- `js/main.js:7068-7253` —
  - read `data_uri` as well as `.url`;
  - require both mobile **and** desktop comparisons;
  - no screenshots / tall page / vision error → do **not** keep (fail closed);
  - remove the `carousel/slider/banner` auto-reclassification that discards real breakage.
- `js/main.js:7504` — make the error-path rollback unconditional (always attempt rollback on uncaught error).

---

## 8. Manual steps for the user (step-by-step)

These require you; everything else Claude does on the branches.

**During/after review:**
1. **Review the hub branch** `harden/security-phase0` in `ccm-api-hub` (Claude will summarise the diff). When happy: `git checkout main && git merge harden/security-phase0 && git push` — the webhook auto-deploys to Ignea.
2. **Review the plugin branch** `harden/security-phase0` in `ccm-tools`. When happy, merge to `main`, then cut the release (tag + `gh release create` with the built zips, per the release process). Claude will produce the exact commands.

**Credential rotation (do at hub-merge time — the old deploy secret is burned):**
3. Generate a new deploy secret: `openssl rand -hex 32`.
4. On Ignea, add it to `/home/ccmtoolsapi/public_html/.env` as `DEPLOY_SECRET=<value>` (Claude will give the exact edit).
5. In the GitHub repo webhook settings for `ccm-api-hub`, set the webhook **Secret** to the same value; save.
6. Test: push a trivial commit to main and confirm `logs/deploy.log` shows a successful pull.

**Live model setting (required — the DB has a seeded `claude_model` row, so changing the config default alone will NOT take effect):**
7. In the hub admin → Settings, set **Claude model** to `claude-opus-4-8`. (Claude will also provide a one-line `UPDATE app_settings SET setting_value='claude-opus-4-8' WHERE setting_key='claude_model';` as a fallback for you to run — Claude won't run prod DB writes unattended.)

## 9. Verification plan

- **SSRF:** unit-test `assertSafeExternalUrl` (accepts public https, rejects `file://`, `http://127.0.0.1`, `http://169.254.169.254`, a domain resolving to a private IP); post-deploy, confirm `ai/chat` with a `file://`/internal `site_url` is rejected.
- **`.git`:** `curl -I https://api.tools.clickclick.media/.git/HEAD` returns 404.
- **Redis RCE:** attempt to save a password containing `'` — rejected at input; confirm generated wp-config line is `var_export`-safe.
- **LH13 ingestion:** log line shows `insight` schema on a live PSI run; the AI prompt now contains populated opportunity/insight sections (dump one `ai_sessions` user message).
- **Structured output:** malformed-model simulation no longer yields silent empty; invalid `setting_key` impossible (enum).
- **Guardrails:** attempt to apply `preload_css` without critical CSS / `disable_block_css` on a block theme — both refused.
- **Visual gate:** a run with no screenshots does not keep changes.

## 10. Rollout order

1. Hub security (§4) + plugin security (§5) — merge/ship first (stops exposure).
2. Hub AI correctness (§6) + safety guardrails (§7) — ship next (stops blind/​breaking recommendations).
3. Then begin unit C design.
