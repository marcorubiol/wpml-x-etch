=== WPML x Etch ===
Contributors: zerosense
Tags: wpml, multilingual, etch, gutenberg, translation
Requires at least: 6.5
Tested up to: 6.9.4
Requires PHP: 8.1
Stable tag: 1.0.0
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

Install before starting translations. If you already tried translating Etch content without this plugin, cancel stuck jobs in WPML and re-send them.

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

= 1.0.0 =
* First public release.
