# Woo RS Product Sync — Code Review Todo

Syntax is clean across all 15 PHP files. Findings below, ordered by severity.

Status legend: ✅ FIXED, ⚪ VALIDATED NO CHANGE, 🔲 OPEN (task pending).

## Bugs

### 1. Cron sync error counts are silently dropped — ✅ FIXED
- **File:** `includes/class-cron.php:135`
- **Problem:** The stats array is initialized with key `'errors'` (plural), but `sync_product()` returns action `'error'` (singular). `isset( $stats['error'] )` is always false, so cron sync failures never increment and `last_cron_run.stats.errors` stays 0.
- **Impact:** Sync errors are hidden from cron stats; the `errors` field is effectively dead.
- **Fix applied:** Replaced the `'errors' => 0` default with `'error' => 0` (class-cron.php:88-89) so cron failures now count.

### 2. Manual AJAX sync auto-match counts stats twice — ⚪ VALIDATED NO CHANGE
- **File:** `includes/class-cron.php:255-271` + `assets/admin.js:269-273`
- **Status:** VERIFIED NOT A BUG — the `continue` at line 259 guards the re-sync path, so line 269-271 only runs once per product. No change needed.

### 3. `ajax_test_openai` Responses-API parsing lacks type guards — ✅ FIXED
- **File:** `includes/class-admin.php:347-359`
- **Problem:** `elseif ( ! empty( $response_body['output'] ) )` iterates `$output_item['content']` and reads `$content_block['text']` without `is_array()`/`isset()` guards. The equivalent production code in `class-openai.php:233-245` has these guards.
- **Impact:** A malformed OpenAI response (e.g. `output` as a non-array, or `content`/`text` absent) produces PHP warnings and can fatal if `$output_item['content']` is a string.
- **Fix applied:** Mirrored the guarded version in `class-openai.php`.

### 4. Webhook updates can be silently dropped — ✅ FIXED (behavioral change)
- **File:** `includes/class-product-sync.php:61-65`
- **Problem:** `is_category_allowed()` returns false for empty/whitespace `product_category`, and `sync_product()` skips the whole product. If RepairShopr sends an update webhook without the category field (or an admin never maps it), the existing WC product is never updated — and at logging level `changes_only`, the `skipped` row is suppressed (class-product-sync.php:701-702), so nothing is recorded.
- **Impact:** Silent missed updates.
- **Decision (confirmed with admin):** Category mapping gates NEW syncs, but products already linked via `_rs_product_id` keep syncing even when their category is unmapped or absent from the payload. This keeps already-synced products in a now-unmapped category from going stale, and handles category changes over time in either direction.
- **Fix applied:** `if ( ! self::is_category_allowed( $rs_category ) && ! self::find_wc_product( $rs_product['id'] ) )` (class-product-sync.php:62-66). `assign_wc_categories()` already no-ops on empty/unmapped categories, so WC category terms are left unchanged on those updates.

### 5. Trashed products can get silently modified — ✅ FIXED
- **File:** `includes/class-product-sync.php:193-209`
- **Problem:** `find_wc_product()`'s `$wpdb` meta fallback doesn't filter `post_status`, while `find_products_by_sku()` explicitly excludes `trash`/`auto-draft`. A trashed product linked via `_rs_product_id` is returned by `find_wc_product()`, then `update_product()` writes price/stock to it (it stays trashed, but data is mutated on a deleted product).
- **Fix applied:** Fallback now joins `posts` and filters `post_type IN ('product','product_variation')` and `post_status NOT IN ('trash','auto-draft')`, matching `find_products_by_sku()` (class-product-sync.php:206-215).

### 6. Cron + continuation can double-run the sync — ✅ FIXED
- **File:** `includes/class-cron.php:15-16`
- **Problem:** Both `HOOK` and `CONTINUATION_HOOK` call `run_sync()`. If auto-sync cron fires while a rate-limit continuation is pending, it consumes the continuation cursor, completes, deletes the option, and then the scheduled continuation event fires and runs a fresh full sync.
- **Impact:** Wastes up to 160 API calls and re-processes the catalog. Locks + rate limiter prevent corruption.
- **Fix applied:** Guard at class-cron.php:86 — if the main `HOOK` fires while a continuation event is scheduled, `run_sync()` returns early, letting the continuation finish.

### 7. AUTH_KEY-derived encryption is fragile — ⚪ VALIDATED NO CHANGE
- **File:** `includes/class-encryption.php:18`
- **Problem:** Storing RS/OpenAI keys encrypted with `AUTH_KEY` means any wp-config change (host migration, key rotation, `wp-config.php` regen) silently orphans every stored API key — sync stops with "not configured" and the keys are unrecoverable.
- **Status:** The code already prefers a dedicated `REPAIRSHOPR_SYNC_SECRET` when defined (class-encryption.php:14-22) and the admin surfaces an "encryption unavailable" notice (class-admin.php:138). This is a documented ops risk for sites falling back to `AUTH_KEY`; not a code defect. Left as-is.

### 8. Webhook only catches `WC_Data_Exception`/`RuntimeException` — ✅ FIXED
- **File:** `includes/class-webhook.php:98-102`
- **Problem:** Manual sync catches `\Throwable` (class-cron.php:231); webhook does not. An unexpected exception (e.g. from `wc_get_product()` when the WooCommerce data store misbehaves) returns HTTP 500 and aborts the request after logging.
- **Fix applied:** Replaced the two catch blocks with a single `catch ( \Throwable $e )` for consistency (class-webhook.php:97).

## Minor / Nits — ⚪ VALIDATED NO CHANGE

- **`class-openai.php:89`** uses `max_completion_tokens` even for the default non-reasoning fallback config (which historically uses `max_tokens`) — likely fine for current models, but the fallback branch at line 74 assumes non-reasoning.
- **`mask_key()`** (`includes/class-admin.php:917`) reveals the full key when `strlen($key) <= 4`; also a stored 4-char key can never be updated because masked == plaintext. Edge case only.
- **`handle_save_settings`** forces HTTPS for the RS API URL; RepairShopr is HTTPS-only SaaS, so this is intentional. Local/HTTP installs can't save a valid URL, but none is valid anyway.
- **`class-updater.php:92-137`** uses tab indentation inconsistently with the rest of the file (cosmetic).
- **`run_sync`** fallback full-scan path (no categories) re-syncs every product but `sync_product()` immediately skips unmapped ones — wasteful page fetches on large catalogs.

## New Findings — 2026-08-04 (opencode code review)

### Bugs

### 9. Webhook + sync logs grow unbounded — ✅ FIXED
- **File:** `includes/class-webhook.php:87`, `includes/class-product-sync.php:757`
- **Problem:** A row is inserted on every event with no retention policy. The webhook log stores full payloads on every call; the sync log grows one row per product per run at `logging_level=all`.
- **Impact:** Both tables grow forever on active stores; only manual Clear buttons exist, no auto-prune.
- **Fix applied:** Added a daily `WOO_RS_Cron::prune_logs()` job (`PRUNE_HOOK`, `LOG_RETENTION_DAYS = 30`) that deletes rows older than 30 days from both tables via the new `WOO_RS_DB::prune()`. Scheduled on activation/settings save and self-heals on the next cron run; cleared on deactivation.

### 10. AES-256-CBC without authentication — ✅ FIXED
- **File:** `includes/class-encryption.php:53`
- **Problem:** Ciphertext has no integrity check (no HMAC/GCM); a DB compromise allows silent tampering with the stored RS/OpenAI keys.
- **Impact:** Undetected modification of the API key ciphertext could redirect/throttle sync.
- **Fix applied:** `encrypt()` now uses AES-256-GCM with a 96-bit random IV + 128-bit tag (`v2:` format). Hosts without GCM fall back to HMAC-SHA256-then-CBC (`h1:` format). `decrypt()` verifies tags/HMACs — tampered v2/h1 payloads are rejected — and still reads all legacy CBC formats so existing stored keys keep working.

### 11. `update_product()` silently no-ops on a vanished product — ✅ FIXED
- **File:** `includes/class-product-sync.php:395-397`
- **Problem:** When `wc_get_product()` returns null, `update_product()` returns `array()`; the caller logs `skipped` instead of `error`.
- **Impact:** A product deleted between `find_wc_product()` and the update looks like a healthy skip.
- **Fix applied:** `update_product()` now returns `WP_Error('wc_product_not_found', ...)` with the `wc_product_id`; the existing caller branch logs it as an `error` row.

### 12. Error columns exist but are never rendered in the UI — ✅ FIXED
- **File:** `includes/class-admin.php:799-807`
- **Problem:** The schema (0.4.0) added `error_code`/`error_message` and `log_sync()` populates them, but the Logs tab table only shows Time/RS/WC/Action/Source/Changes.
- **Impact:** Failures are invisible in the UI unless they happen to appear in the changes JSON.
- **Fix applied:** Added an "Error" column to the sync log table rendering `error_code` (summary) with the full `error_message` expandable underneath.

### 13. Webhook always returns HTTP 200 — ✅ FIXED
- **File:** `includes/class-webhook.php:104-107`
- **Problem:** Success is returned even when the body is not JSON or `id` is absent (`:91-102` silently drops it).
- **Impact:** RepairShopr never retries a malformed delivery; the event is permanently lost with no error log.
- **Fix applied:** Non-JSON bodies and payloads without a product `id` now log an `error` row (`malformed_payload` / `missing_product_id`) and return HTTP 400 so RepairShopr can retry. Valid deliveries still return 200.

### 14. Manual sync aborts the whole batch on the first non-auto-matchable error — ✅ FIXED
- **File:** `includes/class-cron.php:268-274`
- **Problem:** `wp_send_json_error` is called for any `error` action (e.g. `wc_save_failed`), halting the run. The cron path counts and continues instead.
- **Impact:** A transient save failure makes the manual sync loop forever on the same product.
- **Fix applied:** `stats['error']` was added; non-interactive errors (save failures, exceptions, etc.) are now counted and the batch continues. `rs_sku_conflict` / `rs_duplicate_wc_sku` still stop the batch so the admin can resolve them on screen. `assets/admin.js` surfaces the error count in the status line.

### 15. Webhook dropped under contention with OpenAI rewrite — ✅ FIXED
- **File:** `includes/class-locks.php:32-35`, `includes/class-openai.php:21`
- **Problem:** Max lock wait is 5 s (20 × 250 ms) vs a 300 s product-lock TTL. A webhook arriving while another worker holds the product lock during a slow OpenAI rewrite (up to 180 s timeout) gets `lock_busy` and is lost.
- **Impact:** Webhook updates silently dropped (200, no retry).
- **Fix applied:** The OpenAI rewrite is now deferred until *after* `sync_product()` releases the per-product lock (`openai_rewrite` marker in the sync result). Lock hold time is back to the fast DB-write path, so the 5 s wait is plenty; no wait-time increase needed.

### 16. Cron auto-sync has no exception guard around `sync_product()` — ✅ FIXED
- **File:** `includes/class-cron.php:168,194`
- **Problem:** The webhook handler (`class-webhook.php:113-117`) and the manual AJAX path (`class-cron.php:263-269`) both wrap `sync_product()` in `try/catch ( \Throwable )`. The cron loop calls it bare. A `WC_Data_Exception`/`TypeError` thrown inside `sync_product_locked()` (e.g. from a WC setter in `apply_fields()`, or `wp_set_object_terms()` misbehaving) aborts the entire `run_sync()`.
- **Impact:** Silent partial/failed auto-syncs — no continuation cursor is saved, `last_cron_run` is never updated, and nothing reaches the sync log (only the PHP error log).
- **Fix applied:** Both cron-loop `sync_product()` calls (category + fallback paths) are now wrapped in `try/catch ( \Throwable )`, mirroring the manual AJAX path: an `error` row is logged with code `sync_exception`, `stats['error']` is incremented, and the loop continues.

### 17. Deferred OpenAI rewrite is unguarded in cron and never retried — ✅ FIXED
- **File:** `class-product-sync.php:739-752`, `class-openai.php:314-318`
- **Problem:** `maybe_rewrite_product_description()` calls `$product->save()` with no try/catch, and `maybe_openai_rewrite()` has no guard. This runs after the product lock is released inside `sync_product()`, so in the cron path an exception is uncaught (see #16). Separately: when an OpenAI call fails (network/API error), `_rs_description_raw` already matches on the next sync, so the description is treated as unchanged and the rewrite is **never retried** — the product keeps the raw, un-rewritten description indefinitely.
- **Fix applied:** `maybe_openai_rewrite()` is fully guarded (`try/catch \Throwable`). A failed or throttled rewrite writes a `_rs_openai_rewrite_pending` marker; `sync_product()` retries any product carrying that marker even when the RS description hasn't changed. Success clears the marker and records `_rs_openai_rewritten_at`. `maybe_rewrite_product_description()` now also returns structured `save_failed`/`product_not_found` results instead of letting WC throw.

### 18. No throttling of OpenAI rewrites during full syncs — ✅ FIXED
- **File:** `class-product-sync.php:94-99`
- **Problem:** Every 'created'/'updated' product whose description changed triggers one OpenAI API call (each up to 60-180 s timeout depending on model). The first manual full sync (or cron pass) after enabling OpenAI can issue one call per product with a description — no limiter or cooldown.
- **Impact:** Cost blowups and multi-hour sync runs; a 429/5xx from OpenAI is not handled differently than any other error.
- **Fix applied:** Added a fixed-window rate limiter in `WOO_RS_OpenAI::consume_rewrite_slot()` (default **25 rewrites / 60 s**, lock-guarded, shared across sync sources). The cap is configurable via a new **Max Rewrites per Minute** setting (`woo_rs_product_sync_openai_max_rewrites`, 0 = unlimited). Throttled rewrites are marked pending and retried on later syncs, so a bulk backlog drains gradually instead of in one burst.

### 19. Webhook returns HTTP 200 even when the sync fails in-flight — ✅ FIXED
- **File:** `includes/class-webhook.php:113-122`
- **Problem:** If `sync_product()` returns an `error` action (`wc_save_failed`, `wc_product_not_found`, `lock_busy`, …), `handle()` still answers 200. RepairShopr considers the delivery successful and never retries; the only record is the sync-log error row. For `lock_busy` (todo #15 shrank the window but did not eliminate it) the event is effectively lost.
- **Fix applied:** `handle()` now inspects the sync result. Retryable failures (`lock_busy`, `wc_save_failed`, `wc_product_not_found`) return **503** so RS redelivers; uncaught exceptions return **500**. Durable business errors (`rs_sku_conflict`, `rs_duplicate_wc_sku`, …) keep 200 so an admin can resolve them on screen.

### 20. Re-categorizing a product never removes stale mapped WC categories — ✅ FIXED
- **File:** `includes/class-product-sync.php:643-652,533-538`
- **Problem:** When a product moves from a mapped RS category to an unmapped/absent one, `assign_wc_categories()` returns early and the previously-mapped WC terms stay on the product forever. Combined with the bug-#4 decision (keep syncing linked products), products can accumulate stale category terms that are never reconciled.
- **Fix applied:** `assign_wc_categories()` no longer early-returns on unmapped/empty categories during updates. With an unmapped new category, `$mapped_ids` is empty so the merge drops the old mapped terms while preserving manually-assigned ones — the same reconciliation used for mapped→mapped moves. `$is_new` keeps the old early-return so WC's default "Uncategorized" isn't stripped on create. Known limitation: when a webhook payload omits `product_category` entirely, `update_product()` can't detect a change; the cron/full-sync path always includes the field, so stale terms are reconciled there.

### 21. `since_updated_at` is likely not a RepairShopr product field — ✅ FIXED (additive fallback)
- **File:** `includes/class-product-sync.php:43`
- **Problem:** `$meta_fields` maps `since_updated_at` → `_rs_last_updated`. RepairShopr product objects expose `updated_at`; `since_updated_at` is a *query* parameter for incremental listing, not a field on the returned product. As written, `_rs_last_updated` is never populated.
- **Fix applied:** `updated_at` → `_rs_last_updated` is mapped alongside the legacy `since_updated_at` key, with `updated_at` processed last so it wins when a payload sends both. The change is safe without live-API verification: if `updated_at` is not a product field, `array_key_exists()` is false and it's simply skipped — the existing fallback still works.

## Minor / Nits — status updated

- **`class-product-sync.php:66,119`** — ✅ FIXED: the category-gate `find_wc_product()` result is hoisted and passed into `sync_product_locked()`; the in-lock lookup only re-runs when the gate didn't resolve it (mapped-category path), so each product now costs 1 lookup instead of up to 2.
- **`class-updater.php:220`** — ✅ FIXED: removed `home_url()` from the GitHub User-Agent; it now sends `WordPress/<ver>; Woo-RS-Product-Sync/<ver>`.
- **`class-api-client.php:76-89`** — ⚪ VALIDATED NO CHANGE: `exhaust_rate_limit()` throttles all callers for 60 s after a 429 by design, to keep a manual admin refresh from hammering RepairShopr; the window is short and bounded.
- **`class-plugin.php:162-163`** — ✅ FIXED: `migrate_old_data()` now compares column sets (`tables_compatible()`) before the `INSERT IGNORE ... SELECT *` copy; a drifted legacy schema leaves the old table in place and logs instead of dropping rows.
- **`class-product-sync.php:132-135,708-721`** — ✅ FIXED: covered by bug #15 — the OpenAI rewrite no longer runs while the product lock is held.

## More Nits — 2026-08-04 (opencode code review, continued) — status updated

- **`class-api-client.php:174-194`** — ✅ FIXED: `fetch_all_products()` was dead code (every caller uses `fetch_products_page()`); removed it.
- **README vs code rate-limit mismatch** — ✅ FIXED: README now says "160 calls per 60 seconds", matching `class-api-client.php:9-10` and DEVELOPMENT.md.
- **`class-admin.php:496-499`** — ✅ FIXED: the Dashboard now reads `$last_cron_run['stats']` through `wp_parse_args()` key guards (no more PHP notices on stale options) and renders the `error` count (bold) alongside created/updated/skipped.
- **`class-admin.php:322-360` vs `class-openai.php:181-245`** — ✅ FIXED: extracted the shared request/response handling into `WOO_RS_OpenAI::request_rewrite()`; both `rewrite_description()` and `ajax_test_openai()` now use it, so the test tool and the real sync cannot drift again (bug #3).
- **`class-product-sync.php:809-815`** — ✅ FIXED: `get_sync_logs()` now resolves its table via `WOO_RS_DB::table('sync_log')` instead of building the name inline.
- **`class-cron.php:203-208`** — ⚪ VALIDATED NO CHANGE: the non-rate-limit `break` paths still fall through to the end-of-function `update_option('woo_rs_product_sync_last_cron_run', …)`, so partial stats ARE persisted there. The only paths that skip the write are rate-limit continuations (intentional — the run isn't complete) and config-missing early returns. The #16 try/catch removes the one case (an escaping exception) that could truly abort mid-run without persisting stats.
- **`DEVELOPMENT.md:31`** — ✅ FIXED (docs): the stale "lock lifetime covers the longest OpenAI request" claim (contradicted by the #15 deferral) now documents the deferred-rewrite design, plus the new rewrite throttle/retry markers.
