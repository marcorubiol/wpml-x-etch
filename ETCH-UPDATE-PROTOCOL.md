# Etch Update Protocol

Etch ships updates roughly weekly. WPML × Etch reaches deep into Etch's
internals (DOM selectors, CSS variables, JS globals, block names). Each
update can quietly break us.

This document is the **post-update checklist**. Run it after every Etch
upgrade in the dev environment, before letting the update reach prod.

---

## 1. Pre-update snapshot (30 sec)

Before running the Etch update, record the current Etch version so
breakage can be bisected:

```bash
wp plugin get etch --field=version
```

Note it in the commit message of any subsequent fix.

---

## 2. Smoke test (2 min)

Open the Etch builder on a translated page (e.g. post 120 on the dev
site). Run through this list. If everything passes, you're done.

| # | Action                                                          | Expected                                                              |
|---|-----------------------------------------------------------------|-----------------------------------------------------------------------|
| 1 | Look at Etch's settings bar (bottom)                            | WPML icon button visible with status dot overlay                      |
| 2 | Hover the WPML button                                           | Etch tooltip says "Translations"                                      |
| 3 | Click the WPML button                                           | Panel slides in over the sidebar                                      |
| 4 | Look at the panel header                                        | Back button (←), title "WPML × Etch", resync icon (↻)                 |
| 5 | Hover back / resync                                             | Custom popover tooltip appears instantly                              |
| 6 | Click a language chip → Spanish                                 | Filter applies, list updates                                          |
| 7 | Click a "Needs Translation" pill                                | Opens ATE                                                             |
| 8 | Translate one string in ATE → Complete & Close                  | Returns to builder, panel re-opens, status updates                    |
| 9 | Edit the page (any change), save                                | No console errors, status indicators refresh                          |

If any step fails → go to §3.

---

## 3. Surfaces we depend on

When the smoke test fails, the breakage is almost always in one of
these four surfaces. Each row tells you **what we use**, **where**, and
**how to verify** it.

### 3.1 JS global API

| API                                                                  | File                  | Verify in DevTools console                               |
|----------------------------------------------------------------------|-----------------------|----------------------------------------------------------|
| `window.etchControls.builder.settingsBar.bottom.addBefore({...})`    | `src/js/entry.js:30`  | `typeof window.etchControls?.builder?.settingsBar?.bottom?.addBefore` → `'function'` |

If `addBefore` is gone or renamed, our button never registers. Look in
Etch's `builder.js` for the new API surface (often `addAfter`, `add`,
or a different namespace).

### 3.2 DOM selectors

We query / generate Etch class names directly. Run the grep below to
get the live list, then check each selector still exists in the
post-update DOM.

```bash
grep -nrE "etch-builder-button|content-hub|iconify--|etch-app|etch-builder[^-]" src/js src/Admin assets/wxe-panel.css
```

Critical selectors as of 2026-04 (regenerate the list with the grep above):

- `.etch-builder-button` (+ `--icon-placement-before`, `--variant-outline`, `--variant-icon`)
- `.iconify--vscode-icons` (icon class — used as fallback to find our button)
- `.etch-app, .etch-builder` (root container detection)
- `.content-hub__sidebar`
- `.content-hub__header`
- `.content-hub_sidebar__content`
- `.content-hub-list`
- `.content-hub-list__item--selected`
- `.content-hub-list__disclaimer`

If a class was renamed: update both source files **and** ARCHITECTURE.md
if relevant. Don't add fallbacks for old names — fail loud.

### 3.3 CSS variables

We read Etch's design tokens with `var(--e-foo, fallback)`. If a
variable disappears we silently get the fallback (often wrong color).

```bash
grep -nrE "var\(--e-[a-z-]+|var\(--button-font-size|var\(--icon-rotation" src/js assets
```

Tokens we currently read (regenerate with grep above):

- `--e-font-size-m`, `--e-font-size-s`
- `--e-danger`, `--e-success`
- `--e-tooltip-bg`, `--e-foreground-color`, `--e-border-radius`
- `--button-font-size`, `--icon-rotation`

After update, in DevTools console:

```js
['--e-font-size-m','--e-font-size-s','--e-danger','--e-success','--e-tooltip-bg','--e-foreground-color','--e-border-radius']
  .map(n => [n, getComputedStyle(document.documentElement).getPropertyValue(n).trim()])
```

Any empty value = removed by Etch → swap our usage or hardcode.

### 3.4 Data layer (blocks, meta, options)

These are not visual but a rename here breaks translation silently.

| Surface                          | Used in                                          | What to check                                              |
|----------------------------------|--------------------------------------------------|------------------------------------------------------------|
| `etch/component` block name      | `src/WPML/TemplateTranslator.php`                | Open a page in Etch, save, check `wp_posts.post_content` still has `<!-- wp:etch/component` |
| `<!-- wp:etch/` HTML marker      | `src/WPML/ContentTranslationHandler.php`         | Same as above                                              |
| `etch_loops`, `etch_styles` opts | `src/WPML/StringHandler.php`                     | `wp option get etch_loops` / `wp option get etch_styles`   |
| `etch_component_properties` meta | `src/WPML/TemplateTranslator.php`                | `wp post meta get <component_id> etch_component_properties`|
| `etch_*` / `_etch_*` meta prefix | `src/WPML/TranslationSync.php`                   | `wp post meta list <id> --format=json \| jq '.[] \| select(.meta_key \| startswith("etch_"))'` |

If any prefix or key was renamed (e.g. `etch_` → `etch3_`), translation
sync will silently stop copying meta to translated posts.

### 3.5 Save / lifecycle events

Our listeners hook into Etch's save flow.

```bash
grep -nrE "etch.*save|listenForEtchSaves|etch_save" src/js
```

After update, in DevTools console, save the page once and confirm our
console output appears (with `ZS_WXE_DEBUG_LOG_LEVEL` raised to 4+).
If silent, the save event was renamed.

---

## 4. After fixing breakage

1. **Bump the tested-against Etch version** in `readme.txt` (`Tested up to`)
2. **Document the rename** with a 1-line comment at the call site explaining
   the prior name and the version it changed in (helps future bisects)
3. **Commit** with prefix `etch <new-version> compat:` for grep-ability
4. **Update the memory note** `project_etch_update_protocol.md` if a
   surface category needs to be added/removed from this checklist

---

## 5. When NOT to apply this protocol

- Etch patch versions that only ship docs / readme changes
- Updates explicitly marked as "no breaking changes" by Etch authors
- Beta builds — wait for stable

---

## Why this protocol exists

WPML × Etch acts as a guest in Etch's house. We do not have a stable
public API to integrate against — we read CSS variables, query DOM
classes, and call JS globals that Etch is free to change. Each Etch
update is a small integration test we have to run by hand. This
checklist is the test plan.
