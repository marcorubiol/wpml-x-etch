import { state } from './state.js';
import { escapeHtml, msg } from './utils.js';

/**
 * Languages filter — a wrap of compact 1-click chips.
 *
 * Each chip is a small flag + 2-letter code button. Clicking toggles that
 * language's filter via the existing delegated handler (panel.js: any click
 * inside `.content-hub__sidebar .wxe-lang-btn` calls `filterByLanguage`).
 *
 * Scaling: chips wrap freely in flex; the container has a max-block-size
 * with overflow-y so the section never overflows the sidebar even with
 * 50+ languages. For 1–15 languages there is no scroll at all.
 *
 * Exports `buildLangPickerHtml`, `attachLangPickerListeners`, and
 * `updateLangPickerSummary` to keep the public API used by `panel.js` and
 * `filters.js`. The latter two are no-ops in this implementation — visual
 * state is fully driven by the existing `.wxe-lang-item` class toggle in
 * `filters.js::filterByLanguage()`.
 */

function getOtherLangs() {
  const languages = (window.wxeBridge && window.wxeBridge.languages) || {};
  return Object.entries(languages).filter(([, lang]) => !lang.is_original);
}

function buildChipHtml(code, lang) {
  const isSelected = state.selectedLanguageFilter.has(code);
  const codeLabel  = (code || '').slice(0, 2).toUpperCase();
  const activeCls  = isSelected ? ' wxe-pill--active' : '';
  const name       = lang.native_name || code;
  return `<button type="button" class="wxe-chip wxe-lang-chip wxe-lang-btn wxe-lang-item${activeCls}" data-lang-code="${escapeHtml(code)}" title="${escapeHtml(name)}" aria-label="${escapeHtml(name)}" aria-pressed="${isSelected ? 'true' : 'false'}">
    <img src="${escapeHtml(lang.flag_url)}" alt="" width="16" height="11" class="wxe-flag">
    <span>${escapeHtml(codeLabel)}</span>
  </button>`;
}

export function buildLangPickerHtml() {
  const m          = msg();
  const otherLangs = getOtherLangs();

  if (otherLangs.length === 0) {
    return `<p class="content-hub-list__disclaimer" style="padding:0;">${escapeHtml(m.noLanguages || 'No languages configured.')}</p>`;
  }

  // 1 language: render as a single static chip with no interaction —
  // there's nothing to filter when only one secondary language exists.
  if (otherLangs.length === 1) {
    const [code, lang] = otherLangs[0];
    return `<div class="wxe-lang-filter wxe-lang-filter--static" id="wxe-lang-filter">
      <div class="wxe-chip wxe-lang-chip wxe-lang-chip--static" aria-label="${escapeHtml(lang.native_name || code)}">
        <img src="${escapeHtml(lang.flag_url)}" alt="" width="16" height="11" class="wxe-flag">
        <span>${escapeHtml((code || '').slice(0, 2).toUpperCase())}</span>
      </div>
    </div>`;
  }

  let chips = '';
  for (const [code, lang] of otherLangs) {
    chips += buildChipHtml(code, lang);
  }

  return `<div class="wxe-lang-filter" id="wxe-lang-filter" role="group" aria-label="${escapeHtml(m.languages || 'Languages')}">
    ${chips}
  </div>`;
}

/**
 * No-op. Click handling lives in the existing delegated listener in
 * `panel.js` (`.content-hub__sidebar .wxe-lang-btn`). Visual state is
 * synced by `filters.js::filterByLanguage()` which toggles
 * `.wxe-pill--active` on every `.wxe-lang-item`.
 */
export function attachLangPickerListeners(_panel) {
  /* intentionally empty */
}

/**
 * Sync `aria-pressed` on chips after `filterByLanguage` runs. The active
 * class is already toggled by `filters.js`; this just keeps the ARIA
 * state in agreement for assistive tech.
 */
export function updateLangPickerSummary() {
  const filter = document.getElementById('wxe-lang-filter');
  if (!filter || filter.classList.contains('wxe-lang-filter--static')) return;
  const selected = state.selectedLanguageFilter;
  filter.querySelectorAll('.wxe-lang-chip').forEach((chip) => {
    const code = chip.dataset.langCode;
    if (!code) return;
    chip.setAttribute('aria-pressed', selected.has(code) ? 'true' : 'false');
  });
}
