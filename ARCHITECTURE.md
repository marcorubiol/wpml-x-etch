# Architecture

## String registration

Etch uses **self-managed string registration** with its own WPML package kind (`Etch`),
separate from WPML's built-in Gutenberg handler (`Gutenberg`). This is the same pattern
used by Elementor, Beaver Builder, and Divi.

`wpml-config.xml` sets `translate="0"` for all Etch blocks except `etch/element` which
has `translate="1"` for href attributes (link auto-translation). WPML's Gutenberg handler
ignores the `translate="0"` blocks. `StringHandler::register_post_strings()` hooks into
`wpml_page_builder_register_strings` at priority 20 (after WPML's handler at 10) and
registers only real translatable strings via `wpml_register_string`.

Source of truth: `ComponentParser::get_translatable_values()` — filters out dynamic
expressions (`{variable}`, `{{"key":"{val}"}}`, `item.prop`) at parse time. Also extracts
static hrefs from `etch/element` blocks (dynamic hrefs like `{props.url}` are filtered).

### Registration flow

1. **Shutdown priority 10**: WPML's Gutenberg handler processes blocks, ignores Etch
   (`translate="0"`), deletes its own unused strings
2. **Shutdown priority 10 (action)**: `wpml_page_builder_register_strings` fires — our
   handler at priority 20 registers Etch strings in the `Etch` package
3. **Shutdown PHP_INT_MAX**: `MetaSync::process_post()` runs snapshot updates, stale
   string cleanup, component refs, meta copy, and propagation

### Why a separate package kind

WPML's Gutenberg handler manages all packages with `kind='Gutenberg'`: it registers
strings from `wpml-config.xml`, then deletes "unused" strings. With `translate="0"`,
it registers nothing for Etch blocks → deletes everything → our strings disappear.

With `kind='Etch'`, our strings live in a separate namespace. WPML's Gutenberg handler
never touches them. String IDs are stable between saves.

## Translation application (frontend)

`ContentTranslationHandler` applies Etch string translations to `post_content` of
translated posts. Two hooks:

1. **`wpml_page_builder_string_translated` (priority 11)** — fires right after WPML's
   Gutenberg handler (priority 10) overwrites the translated post's content with the
   original. We read the ORIGINAL post's content, apply Etch translations from the
   `icl_string_translations` table, and write to the translated post.

2. **`wpml_pro_translation_completed` (priority 20)** — fallback for ATE completion
   when the Gutenberg handler did not fire. This is the primary path for wp_block
   components, which don't trigger `wpml_page_builder_string_translated`.

Key insight: translated content is always built from the **original** post's `post_content`,
not from the translated post's — the translated post may contain stale translations from
previous cycles that don't match current originals.

**Always write, even with zero translations.** If a component has only dynamic content
(`{props.label}`), no Etch strings are registered. The handler still writes the original's
block structure to the translated post — without this, Etch has no blocks to render and
the component output is empty.

This is the same pattern as Elementor: Gutenberg handler writes first (priority 10),
then the custom handler writes on top (priority 11). Two writes per save — acceptable.

## Translation status

### Status mapping

| WPML DB | WPML constant | Our status | Priority | Color |
|---|---|---|---|---|
| `status=NULL` | — | `not_translated` | 0 (worst) | Red |
| `status=1` | `ICL_TM_WAITING` | `waiting` | 1 | Rose |
| `status=2` | `ICL_TM_IN_PROGRESS` | `in_progress` | 3 | Yellow |
| `status=10, needs_update=1` | `ICL_TM_NEEDS_UPDATE` | `needs_update` | 2 | Orange |
| `status=10, needs_update=0` | `ICL_TM_COMPLETE` | `translated` | 4 (best) | Green |

`in_progress` takes display priority over `needs_update` — if someone is translating,
the "needs update" flag is irrelevant. This matches WPML's Translation Management UI.

### Status resolution

`TranslationStatusResolver` trusts `icl_translation_status` directly in the common
case. With self-managed registration (`kind='Etch'`) and the `needs_update` loop fix,
WPML's status is reliable when the database is internally consistent.

**Ghost-row guard (v1.0.4)**: `build_lang_data()` downgrades `translated` and
`needs_update` to `not_translated` when no translated post exists (`element_id` is
null or missing). Orphan rows in `icl_translation_status` appear on sites that
installed the plugin after a prior translation attempt, or where WPML jobs were
aborted. They cause false-positive "Complete" badges on pages that have no real
translation. The guard is intentionally narrow: `waiting` and `in_progress` are
left alone because those can legitimately exist before the translated post is
created. The guard aligns panel output with WPML's Pages-list view, which also
keys on post existence.

### needs_update self-reinforcing loop (WPML bug)

WPML's `save_translation()` (wpml-save-translation-data-action.class.php:52,320)
reads `$job->needs_update` from `icl_translation_status` and writes it back unchanged.
Once `needs_update=1` is set, it perpetuates on every ATE completion.

Fix: `MetaSync` records ATE completions via `wpml_pro_translation_completed`, then
at shutdown (priority 12) verifies the md5. If the current md5 matches the stored md5
(or md5 is empty), it resets `needs_update=0`. Also called from
`handle_get_languages_status` after `force_ate_sync` to fix within the same request.

**Guard**: before clearing `needs_update`, `fix_needs_update_after_ate` checks
`has_untranslated_etch_strings()`. If the post has Etch strings without translations
for that language, `needs_update` stays at 1. This prevents the fix from masking
genuinely untranslated content.

### needs_update correction on save

`MetaSync::process_post()` sets `needs_update=1` directly when translatable values
change. WPML's md5-based detection only covers `post_content` changes, not changes
in self-managed string packages. Without this, adding an `etch/text` block would not
flag existing translations as needing update.

### Job refresh on missing package strings

`TranslationJobManager::ensure_job_exists()` checks whether the current job has
`package-string-*` fields. If the post has registered Etch strings but the job lacks
them (created before strings existed), a refresh is forced regardless of `needs_update`.
This guarantees ATE shows Etch strings when the user opens the editor.

## JSON loop translation

`LoopTranslator` registers JSON loop string values via WPML String Translation API
(`icl_register_string`). Uses a smart filtering system:

1. **Allowlist** — `DEFAULT_TRANSLATABLE_FIELDS` constant defines field names that are
   always translatable (label, title, content, description, etc.)
2. **Filterable** — `zs_wxe_loop_translatable_fields` filter allows customization per loop
3. **Heuristic fallback** — for unknown fields, `is_translatable_value()` rejects URLs,
   short codes (≤3 chars), numeric strings, booleans, emails, relative paths

## WPML API usage

The plugin uses WPML's public filter API (`apply_filters('wpml_*')`) wherever possible.
`global $sitepress` is only used in `Plugin::register_post_types()` for settings
management, where no filter alternative exists.

### Public filters used

`wpml_element_trid`, `wpml_object_id`, `wpml_get_element_translations`,
`wpml_element_language_details`, `wpml_element_language_code`, `wpml_current_language`,
`wpml_default_language`, `wpml_active_languages`, `wpml_is_translated_post_type`,
`wpml_translation_job_id`, `wpml_register_string`, `wpml_tm_post_md5_content`,
`wpml_page_builder_register_strings`, `wpml_page_builder_string_translated`,
`wpml_pro_translation_completed`.

### Why direct DB queries are necessary

`wpml_get_element_translations` does NOT expose `status` or `needs_update` — it only
returns element mappings. `wpml_translation_status` exists for single trid+lang lookups
but is not efficient for bulk operations.

Direct queries to WPML tables are required for:
- **Bulk status + needs_update** — pill badges, resolver batch queries (`icl_translation_status`)
- **Package string operations** — registration cleanup, stale detection (`icl_strings`, `icl_string_packages`)
- **Batch invalidation** — component change propagation (`icl_translation_status`)
- **Translation lookups** — ContentTranslationHandler reads from `icl_strings` + `icl_string_translations`

### WPML workarounds

| Workaround | Compensates | Risk |
|---|---|---|
| `fix_needs_update_after_ate` | WPML's save_translation perpetuates needs_update=1 | If WPML fixes, our hook is no-op (idempotent) |
| ContentTranslationHandler double-write | Gutenberg handler overwrites with original | Same pattern as Elementor, standard approach |
| ContentTranslationHandler always-write | Components with only dynamic props had empty translated post_content | Writes original structure even with zero translations |
| `exclude_wp_block_title` | Component names are internal, not user-facing | Same pattern as WPML's wp_template handling |
| `translation_priority` taxonomy sync off | WPML includes it in ATE jobs | Low risk, setting-based |
| Ghost-row guard in `build_lang_data` | Orphan `status=10` rows from pre-plugin WPML attempts or aborted jobs | Intentionally narrow: only downgrades translated/needs_update when no translated post exists |

## Key classes

| Class | Responsibility |
|---|---|
| `TranslationStatusResolver` | Single source of truth for translation status. Trusts WPML directly. |
| `TranslationDataQuery` | Raw WPML database queries, status maps, lang_data building. |
| `TranslationJobManager` | Job lifecycle: create, refresh, resolve ATE URLs. |
| `ContentTranslationHandler` | Applies Etch translations to post_content after WPML's Gutenberg handler. |
| `BuilderPanel` | REST endpoints, asset enqueuing, UI delegation. |
| `MetaSync` | Shutdown handler: snapshot values, clean strings, copy meta, propagate, fix needs_update. |
| `StringHandler` | Self-managed WPML string registration and cleanup. Owns the `Etch` package kind. Excludes wp_block post_title from ATE jobs. |
| `LoopTranslator` | JSON loop string registration and translation via WPML String Translation. |
| `TemplateTranslator` | Component ref translation, template slug resolution, prop default injection (queries Etch package). |
| `TranslationSync` | Copy etch meta between posts, invalidate jobs on component changes. |
| `ResyncHandler` | On-demand translation resync: re-register strings, clean stale, copy meta, apply translations, auto-complete. |

## Resync translations

`ResyncHandler` recalculates translation state based on actual string content. Triggered
two ways:

1. **Manual** — "Resync" button in the builder panel sidebar
2. **Automatic** — silently after every Etch save (`listeners.js` → `resyncTranslations({ silent: true })`)

### What it does

1. Re-registers Etch strings for the post and its referenced components
2. Cleans stale/orphaned strings via `cleanup_stale_package_strings`
3. Copies `etch_*` meta from original to all translated posts
4. Applies Etch translations to translated post_content (`ContentTranslationHandler`)
5. Checks completeness: queries `icl_strings` + `icl_string_translations` to see if all
   strings have `status=10` translations for each language
6. If complete and not in_progress: marks `icl_translation_status` as `status=10,
   needs_update=0` with current MD5 (prevents WPML from re-triggering needs_update)
7. If NOT complete but status says complete: forces `needs_update=1` to correct the
   mismatch (defence against `fix_needs_update_after_ate` resetting prematurely)

### Why it's needed

WPML marks translations as `needs_update` on any `post_content` change — including
non-textual changes (layout, styles, adding a component with no new strings). Without
resync, the user must open ATE and save with zero changes to clear the flag.

### REST endpoint

`POST /wpml-x-etch/v1/resync` with `post_id` parameter. Returns stats:
`{ success, stats: { strings_registered, components_processed, translations_updated, up_to_date } }`

## Non-translatable string filtering

Four categories are excluded from string registration at parse time
(`ComponentParser::get_translatable_values` and `collect_translatable_values_from_blocks`):

- **`{variable}`** — Etch dynamic expressions (single braces). Regex: `^\{[^}]+\}$`
- **`{{"key":"{val}"}}`** — Etch prop JSON with embedded expressions (double braces). Regex: `^\{\{.*\}\}$`
- **`item.native_name`** — Dot-notation references (loop items, props). Regex: `^[a-zA-Z_]+\.[a-zA-Z_.]+$`
- **Non-text prop types** — Props with `type.specialized` set (select, color, url, image,
  wpMediaId, etc.) are excluded. Only `primitive: string` without `specialized` is translatable.
  Applied both to component defaults (zona A) and instance attributes in pages (zona B),
  where the component's property definitions are loaded and cached per ref.
- **Non-translatable values** — `is_translatable_value()` rejects values that look like
  numbers (`5`, `3.5`), CSS units (`1em`, `5rem`, `100%`), hex colors (`#fff`), or strings
  ≤2 chars. Applied to both prop defaults and instance attributes.
- **Unknown instance attributes** — Instance attributes of `etch/component` blocks are
  filtered by allowlist: only keys that exist in the component's `etch_component_properties`
  as translatable props are registered. Internal Etch attributes like `nestedPropSourceId`
  are automatically excluded.

With self-managed registration, these never reach WPML's database.

## WPML tables used

| Table | Used by | Purpose |
|---|---|---|
| `icl_translations` | DataQuery, JobManager, MetaSync | Element ↔ language mappings, trid groups |
| `icl_translation_status` | DataQuery, MetaSync, Sync | Job status, needs_update flags |
| `icl_translate_job` | JobManager | Translation jobs (editor, translated flag) |
| `icl_strings` | StringHandler, ContentTranslationHandler | Registered translatable strings |
| `icl_string_translations` | ContentTranslationHandler, TemplateTranslator | Per-language string translations |
| `icl_string_packages` | StringHandler, ContentTranslationHandler | String grouping by post/package (kind='Etch') |

## Known issues (open)

### Component save propagation

Saving a page does not update strings of referenced components (wp_block). The user must
save each component directly in Etch. Partially mitigated: `ResyncHandler` re-registers
strings for referenced components on every resync (manual or automatic post-save).
Full fix proposed: in `process_post`, queue referenced components for processing.

### Prop defaults in frontend — resolved

`TemplateTranslator::inject_translated_prop_defaults` queries the Etch package
(`kind='Etch'`) for translations of prop default values. Maps original value → translated
value, then injects into `$parsed_block['attrs']['attributes']` for props not explicitly
set by the instance. Etch's `ComponentPropertyResolver::get_raw_value()` prioritizes
instance attributes over property defaults, so the injected translation takes effect.

Previously used a separate prop-defaults package (`kind=title, name=defaults`) which
caused duplicate strings in ATE. Removed in v1.2.1.

## AI translation

AI translation uses Claude or OpenAI to translate Etch strings without opening ATE.

### Flow

```
User clicks "Translate to ES" (AI button)
  → AiTranslationHandler::translate()
  → 1. resync() — register strings, ensure WPML state is fresh
  → 2. ComponentParser::get_translatable_values() — extract strings
  → 3. Find string_ids in icl_strings (join with icl_string_packages, kind='Etch')
  → 4. Filter: skip strings that already have translations (status=10)
  → 5. AiClient::translate() — call AI API with untranslated strings
  → 6. Write translations to icl_string_translations (status=10)
  → 7. refresh_job_for_post($id, $lang, complete=true)
       → Creates/refreshes WPML translation job
       → complete_job_via_wpml() — calls wpml_tm_save_data() (WPML's native path)
  → 8. resync() — apply translations to post_content
  → Return { translated_count, skipped_count }
```

### Job completion: why `wpml_tm_save_data()`

We use WPML's native `wpml_tm_save_data()` to complete AI translation jobs. This is
the same function ATE calls when a translator clicks "Complete". It handles:

- `FieldCompression::compressAndTrack()` for correct gzcompress+base64 encoding
- `wpml_pro_translation_completed` hook (triggers ContentTranslationHandler)
- `icl_translation_status` update to ICL_TM_COMPLETE
- Translated post creation/update

**Why not direct DB writes?** We tried writing to `icl_translate.field_data_translated`
directly. This failed because:

1. **Encoding mismatch** — WPML uses `base64(gzcompress(value))` internally via
   `FieldCompression`. Plain `base64(value)` is not recognized during job carry-over.
2. **Job carry-over** — When a new job revision is created (via `add_translation_job`),
   WPML copies `field_data_translated` from the previous job. The carry-over compares
   formats; mismatched encoding causes translations to be silently dropped.
3. **ATE sync** — Direct writes to the local DB are not reflected in ATE's remote SaaS
   state. ATE receives job data at creation time via `create_jobs` API and never re-syncs.

Other WPML page builder integrations (Elementor, WPBakery) also never write to
`field_data_translated` directly — they all go through `wpml_tm_save_data()`.

### ATE review status (accepted limitation)

After AI translation, strings appear as "Flagged for later" in ATE's editor (orange
review icon). This is because:

1. `wpml_tm_save_data()` fires the `wpml_tm_applied_job_status` filter
2. `ApplyJob::addJobStatusHook()` auto-sets `review_status = NEEDS_REVIEW` for package jobs
3. `Jobs::setReviewStatus($jobId, 'ACCEPTED')` updates the LOCAL DB (`icl_translation_status`)
4. But ATE is a **remote SaaS** — it has its own review state that is set at job creation
   time via the `create_jobs` API. There is no API to push review status changes TO ATE.

**Decision**: We accept this. The translations work correctly on the frontend. The orange
flag is cosmetic within WPML's ATE editor. Attempting to fight ATE's remote state adds
fragile code with no functional benefit. If a user wants to clear the flags, they can
click "Accept all" in ATE.

Investigated April 2026. If WPML adds a review status sync API in the future, revisit.

### Key classes

| Class | Responsibility |
|---|---|
| `AiTranslationHandler` | Orchestrates: extract → AI call → write → complete → resync |
| `AiClient` | Builds prompts, calls Claude/OpenAI APIs, validates responses |
| `AiSettings` | Provider config, API key storage (encrypted via `wp_encrypt` on WP 6.8+) |

## Post meta keys

| Key | Stored on | Purpose |
|---|---|---|
| `_zs_wxe_values` | Original post | Sorted array of translatable text values. Used for change detection. |
| `_zs_wxe_component_refs` | Pages/posts | JSON array of component (wp_block) IDs used in the post. Enables reverse-lookup for component change propagation. |
