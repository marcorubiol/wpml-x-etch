=== WPML x Etch ===
Contributors: zerosense
Tags: wpml, multilingual, etch, gutenberg, translation
Requires at least: 6.5
Tested up to: 6.9.4
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Makes Etch page builder fully compatible with WPML multilingual sites.

== Description ==

WPML x Etch bridges Etch and WPML so that templates, components, and page content translate correctly across languages.

Without this plugin, WPML does not see Etch content — templates break, components are invisible to the translation editor, and the builder shows the wrong language.

**What it does:**

* Registers all Etch content (text, components, props) as translatable in WPML.
* Provides an in-builder translation panel with per-language status for every page, template, component, and JSON loop.
* Opens WPML's Advanced Translation Editor directly from the panel — right context, right page, no menus.
* Includes a ready-made Language Switcher component powered by an Etch JSON loop.
* Keeps translation jobs and string packages in sync when content changes.
* Filters non-translatable expressions ({variables}, {{prop JSON}}, dot-notation refs) from translation jobs automatically.
* Verifies job freshness at read time to show accurate status even when WPML's internal flags are stale.

== Requirements ==

* WordPress 6.5+
* PHP 8.1+
* Etch Builder (active)
* WPML Multilingual CMS (active)

== Installation ==

1. Upload to `/wp-content/plugins/wpml-x-etch`.
2. Activate.
3. Ensure Etch and WPML are both active.

Recommended: install before creating translation jobs. If WPML jobs are already stuck "In Progress" from prior attempts without the plugin, cancel them in WPML Translation Management and re-send.

== Frequently Asked Questions ==

= Do I need both Etch and WPML active? =
Yes. The plugin deactivates gracefully if either is missing.

= Will this work with the free version of WPML? =
No. You need WPML Multilingual CMS with the Advanced Translation Editor (ATE).

= My translations show "In Progress" but never complete =
This happens when translations were started without the plugin. Cancel stuck jobs in WPML Translation Management and re-send.

= Does this support other page builders? =
No. Built specifically for Etch.

= What does AI translation send to Anthropic / OpenAI? =
AI translation is opt-in and admin-only. When an administrator clicks an AI translate button, the plugin sends the following to whichever provider is configured (Claude or OpenAI):

* The translatable string values from the page or component (text content, component property values, JSON loop values).
* The page title, used as context to disambiguate translations.
* The glossary entries you configured in the AI settings panel, if any.
* The source and target language names.

The plugin does not send post IDs, user data, license keys, URLs, or anything outside the translatable strings of the content being translated. The provider's standard data-handling policy applies (Anthropic and OpenAI both honour their published retention and training policies for paid API usage).

If the content you translate contains personal data of third parties, ensure you have a legal basis (consent, legitimate interest, etc.) under your jurisdiction before sending it to a third-party provider.

= Where is my AI API key stored? =
Encrypted via WordPress's `wp_encrypt()` on WP 6.8+, or stored as-is in the `wp_options` table on older WP versions. Either way, the key is admin-only — non-admin users with the `translate` capability cannot read or modify it via the panel or REST API.

== Changelog ==

= 1.1.0 =
* Security: hardened the JS HTML-escape helper used across the panel UI. The previous implementation (`textContent → innerHTML`) escaped only `<`, `>`, and `&`, leaving `"` and `'` unescaped. Several attribute-context interpolations (post titles, language names, loop names, tooltips) were therefore vulnerable to attribute-breakout XSS by any user with `edit_posts` capability — the payload would fire when an admin opened the WPML x Etch panel on the affected post. The helper now escapes the full OWASP set (`& < > " ' /`); no call-site changes were needed.
* Security: tightened REST permissions. AI configuration and execution (`/ai/settings`, `/ai/test`, `/ai-translate*`), license management (`/license/activate`, `/license/deactivate`, `/license/status`), site-wide operations (`/resync/all`, `/toggle-loop-preset`) now require `manage_options` instead of being available to any user with `translate` capability. Read-only panel endpoints and per-post resync remain accessible to translators.
* Security: aligned the page-load payload (`wxeBridge`) with the REST permission split. License status injected via `wp_localize_script` is now reduced to `tier` + `is_valid` for users without `manage_options` (full payload — email, key_masked, expires_at — is admin-only). The `aiAccess` flag now requires `manage_options` in addition to license tier, so translators no longer see AI buttons that would only return 403. A new `canManageLicense` flag drives the license popup to render a read-only view for non-admins.
* Security: added `safeUrl()` helper to strip `javascript:`, `data:`, and `vbscript:` schemes from URL values before they are interpolated into `href`/`src` attributes in the panel. Defense-in-depth — all current URL sources come from server-trusted PHP, but this guards against any future regression where a user-controlled URL could reach an attribute sink.
* Security: completed `rel="noopener noreferrer"` on the three remaining `target="_blank"` panel links that lacked it. Footer links already had it; consistency now applied across all external links.
* Hardening: `UpdateChecker` now matches the public release zip by exact filename (`wpml-x-etch.zip`) instead of selecting the first `.zip` asset. Prevents auto-update from picking the wrong tier-specific zip if a future workflow ever uploads multiple zips per release.
* Reliability: AI prompt assembly no longer fatals when WPML returns a `null` default language code. `AiTranslationHandler::get_language_name()` now accepts `?string` and short-circuits cleanly on empty input.
* Reliability: AI provider errors now surface the actual error message from Anthropic/OpenAI in the WP_Error returned to the panel — previously a 4xx/5xx response collapsed to a bare "Unexpected response: 404", losing the provider's explanation (e.g. "model not found", "insufficient quota").
* Extensibility: AI model identifiers are now filterable. `apply_filters('zs_wxe_ai_model_claude', 'claude-sonnet-4-20250514')` and `apply_filters('zs_wxe_ai_model_openai', 'gpt-4o-mini')` let site owners pin or upgrade the model without forking the plugin.
* UX: JSON loops with partially translated strings (some translated, some new since the last translation pass) now show `Needs Update` (orange) instead of `Not Translated` (red). The aggregation reuses WPML's existing status semantics — no new state, no new UI; loops that are 100% translated stay green, loops with zero translations stay red. Useful on large loops (navigation menus, listings) where adding one new item previously made the badge appear as if all prior translation work had been lost.
* Robustness: license rate-limit transient is now scoped per user. The per-attempt cap was previously global, meaning one admin retrying could lock out other admins on multi-admin sites.
* Robustness: REST input validation hardened. `target_lang` is now validated against `wpml_active_languages` before being used in `/translate-url`, AI translate, and AI translate-loop endpoints — previously an authorised caller could pass an arbitrary language code and pollute `icl_strings` / `icl_string_translations` with rows for languages WPML doesn't actually serve. `component_id` is now validated as a real `wp_block` post before being used to create or open a translation job.
* Performance: license and AI options (`zs_wxe_license_key`, `zs_wxe_license_data`, `zs_wxe_ai_api_key`, `zs_wxe_ai_provider`, `zs_wxe_ai_tone`, `zs_wxe_ai_glossary`, `zs_wxe_ai_verified`) are now stored with `autoload=false`. They are read only inside admin/AI flows, so keeping them out of WordPress's `alloptions` cache shaves a small amount of memory off every front-end request.
* Architecture docs: added a "Translated Etch meta is derived from original" invariant section to `ARCHITECTURE.md` documenting the wildcard `etch_%` / `_etch_%` meta sync behaviour, and a "Frontend XSS audit — 2026-05-03" section recording the audit performed for this release. Removed stale claims in `ARCHITECTURE.md` about `etch/element` href being translated via WPML's `type="link"` link-conversion subsystem — that mechanism was never wired in; the self-managed Etch package handles hrefs end-to-end like any other translatable string.
* Docs: new FAQ entries in `readme.txt` covering exactly what content AI translation sends to Anthropic / OpenAI, the provider's data-handling policies, the user's compliance responsibility for third-party PII, and how/where the AI API key is stored.
* Polish: removed a redundant `escapeHtml()` call in `translation.js` where the value was being escaped twice (once at interpolation, once again inside `setStatusLoading`), causing characters like `&` in language native names to render as `&amp;` in the loading overlay.

= 1.0.10 =
* Fix: pages and posts authored in the Classic Editor or plain Gutenberg whose Etch template renders the body via `{@post-content}` no longer revert to the original language after WPML completes a translation. The post-translation handler was unconditionally rewriting the translated post's `post_content` from the original — even when the original had zero Etch blocks and there was nothing for the plugin to layer on top — silently undoing WPML's translation. The handler now short-circuits when the original `post_content` contains no `wp:etch/*` blocks, leaving WPML's output untouched. Etch-authored content is unaffected.

= 1.0.9 =
* Polish: loop and component cards in the main content area get a touch more vertical padding when they contain a single language row, so their internal proportions match the multi-language layout instead of looking cramped.
* Polish: on sites with a single secondary language, the sidebar Languages chip now renders as a clean informational badge instead of a muted pill outline — clearer that it is a "target language" indicator, not a disabled filter.

= 1.0.8 =
* Fix: no more spurious `needs_update` on pages containing numeric UI labels ("01", "02"), CSS-like tokens ("12px", "#fff"), or pure glyphs ("→", "•", "…"). The completeness counter now excludes these from the "is this fully translated?" check, matching how WPML itself handles them at job-assembly time — those fields never reach ATE and are auto-completed with the source value. Counting them as pending produced false "Needs update" badges on pages that were, in fact, fully translated.
* New: `StringHandler::is_not_translatable()` public static helper. Delegates to WPML's own `WPML_String_Functions::is_not_translatable()` (numeric, CSS color, CSS length) and extends it to cover WPML's gap — whitespace-only, pure Unicode symbols, pure punctuation. Safe to call from other plugin code that needs the same filter.
* Note: registration behaviour unchanged. Non-translatable strings are still written to `icl_strings` (this matches WPML's own Gutenberg handler — it does not pre-filter at registration either). The fix is purely in the completeness counter, so no backfill or data migration is needed.

= 1.0.7 =
* Fix: heal now detects "phantom pointer" half-states — rows where `icl_translations.element_id` points to a WP post that no longer exists. WPML's own completion path cannot recover from this shape: it sees a non-null element_id and takes the "update existing post" branch, which silently no-ops on a missing post. Heal now clears the phantom pointer to NULL before invoking `wpml_tm_save_data`, which makes WPML take the "create new post" branch and materialize the translated post correctly.
* Fix: widened scan SQL in `heal_half_states_for_trid()` and the `/heal-half-states` backfill endpoint to include phantom rows via a `LEFT JOIN wp_posts`. Previous versions only caught the `element_id IS NULL` shape and missed sites where WPML left behind deleted-post pointers.
* Telemetry: new `Heal clearing phantom element_id pointer` log line with `translation_id` and `phantom_element_id` so you can see when a phantom is being repaired. Regular `Heal materializing` entry now includes a `phantom: true/false` flag.

= 1.0.6 =
* Fix: half-state translations (WPML status = Complete with no translated post actually created) are now healed automatically on panel open. The panel re-invokes WPML's native completion path (`wpml_tm_save_data`), which materializes the missing post from the already-translated strings. Root cause of the half-state remains upstream in WPML/ATE — this is a defensive repair path.
* New: admin-only REST endpoint `POST /wpml-x-etch/v1/heal-half-states` for site-wide backfill of pre-existing orphan rows. Supports `dry_run=true` for audit and `trid=<id>` for scoped repair. Intended for one-off use on sites that accumulated half-states before v1.0.6.
* Protection: MySQL `GET_LOCK` on trid+lang prevents two concurrent panel requests from both triggering WPML's post-creation path (which would produce duplicate posts). Per-row circuit breaker disables healing after 3 failures in an hour, so a persistent upstream issue can't loop.
* Telemetry: every heal attempt, success, lock conflict, or failure is logged via `Logger::info`/`Logger::warning` with `trid`, `lang`, `job_id`, and `strings_count`. Use this to measure how often half-states occur in the wild.

= 1.0.5 =
* Fix: Languages filter in the sidebar rendered flag + code as plain text (no pill outline) when only one secondary language was configured. Static single-language chip now includes the base `.wxe-chip` class so it inherits pill shape like the multi-language button variant.
* Improvement: "View details" modal in the Plugins screen now shows the changelog of the available remote version, not the locally installed one. `UpdateChecker` fetches `readme.txt` as a standalone release asset (published alongside the zip) and parses it for the modal. Falls back to the local file if the asset is missing.
* Docs: narrow the "stuck jobs" install warning in readme and AGENTS — since v1.0.4 auto-resolves orphan "Complete" rows, only "In Progress" ghosts still require manual cancel + re-send.

= 1.0.4 =
* Fix: panel no longer shows "Complete" on pages that have no real translated post. Ghost rows in WPML's icl_translation_status (left over from translation attempts that predated this plugin, or from aborted jobs) are now detected at read time and reported as "Not Translated", matching WPML's own Pages-list view.

= 1.0.3 =
* Refactor: remove LicenseManager singleton — now injected via constructor like all other services.
* Refactor: replace closures in hook registrations with named methods (exclude_translation_priority_taxonomy, do_register_ui_strings).
* Refactor: inject PanelConfig into AiTranslationHandler instead of instantiating internally.
* Refactor: replace get_posts "s" search in backfill_component_refs with direct $wpdb query for better activation performance.
* Refactor: PanelConfig::get_locking_mode() is now an instance method — LicenseManager injected via constructor.
* Dev: add PHPDoc to all Logger methods.

= 1.0.2 =
* Fix: fatal TypeError on PHP 8.x when loop items use non-numeric array keys (e.g. Etch 0.0.7). Removed arithmetic on array index in LoopTranslator.
* Note: loop string names have changed — existing loop translations will need to be re-translated after updating. Run Force Sync and re-translate any loop strings.

= 1.0.1 =
* Fix: preserve significant whitespace in etch/text strings (trailing spaces before inline links were trimmed during registration, causing translations not to match at render time).

= 1.0.0 =
* First public release.
