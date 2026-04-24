=== WPML x Etch ===
Contributors: zerosense
Tags: wpml, multilingual, etch, gutenberg, translation
Requires at least: 6.5
Tested up to: 6.9.4
Requires PHP: 8.1
Stable tag: 1.0.8
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

== Changelog ==

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
