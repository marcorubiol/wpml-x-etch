import { state } from './state.js';
import { renderPillContent } from './rendering.js';
import { updateLangPickerSummary } from './langPicker.js';
import { saveFilterPrefs } from './filterPrefs.js';

function toggleSetItem(set, value) {
  if (set.has(value)) {
    set.delete(value);
  } else {
    set.add(value);
  }
}

const PENDING_STATUSES = ["not_translated", "waiting", "in_progress", "needs_update"];

/**
 * Sync the Pending/Done shortcut buttons' active state from the current
 * selection. A shortcut is "active" only when the selection EXACTLY matches
 * its set — strict equality, not "any of". Anything else (custom granular
 * selection, mixed buckets) leaves both shortcuts inactive.
 */
export function syncTranslationShortcutState() {
  const selected = state.selectedStatusFilter;
  const allPending = PENDING_STATUSES.every(s => selected.has(s));
  const noPending  = PENDING_STATUSES.every(s => !selected.has(s));
  const hasDone    = selected.has("translated");

  const pendingBtn = document.querySelector(".wxe-status-pill[data-translation='not_translated']");
  const doneBtn    = document.querySelector(".wxe-status-pill[data-translation='translated']");

  if (pendingBtn) pendingBtn.classList.toggle("wxe-pill--active", allPending && !hasDone);
  if (doneBtn)    doneBtn.classList.toggle("wxe-pill--active", hasDone && noPending);
}

export function filterByStatus(status) {
  toggleSetItem(state.selectedStatusFilter, status);

  // Update status filter visual state
  document.querySelectorAll(".wxe-status-filter").forEach((pill) => {
    pill.classList.toggle("wxe-status-filter--active", state.selectedStatusFilter.has(pill.dataset.status));
  });

  // Keep the Pending/Done shortcut in sync with the granular selection.
  syncTranslationShortcutState();

  saveFilterPrefs();

  // Rebuild sections with filter applied
  applyFilters();
}

/**
 * Keep the "Clear" shortcut next to the Languages header in sync with the
 * current language selection. When no language is selected the button is
 * visually and functionally disabled (still visible to reserve layout space
 * and hint at the action's existence), mirroring the Pending/Done shortcut
 * pattern used by the Status section.
 */
/**
 * Reflect the current `state.selectedStatusFilter` set on the granular
 * status pill buttons. Used after panel rebuild to restore visuals from
 * persisted preferences.
 */
export function syncStatusFilterPills() {
  document.querySelectorAll(".wxe-status-filter").forEach((pill) => {
    pill.classList.toggle("wxe-status-filter--active", state.selectedStatusFilter.has(pill.dataset.status));
  });
}

export function syncLangClearBtnState() {
  const btn = document.getElementById("wxe-lang-clear-btn");
  if (!btn) return;
  const hasSelection = state.selectedLanguageFilter.size > 0;
  btn.disabled = !hasSelection;
  btn.setAttribute("aria-disabled", hasSelection ? "false" : "true");
}

export function filterByLanguage(langCode) {
  toggleSetItem(state.selectedLanguageFilter, langCode);

  // Update sidebar visual state
  document.querySelectorAll(".wxe-lang-item").forEach((item) => {
    item.classList.toggle("wxe-pill--active", state.selectedLanguageFilter.has(item.dataset.langCode));
  });

  // Refresh the language picker's trigger summary and aria-selected.
  updateLangPickerSummary();

  // Enable/disable the Clear shortcut based on whether any language is now
  // selected.
  syncLangClearBtnState();

  saveFilterPrefs();

  // Rebuild sections with filter applied
  applyFilters();
}

/**
 * Clear all language filters. Mirrors `filterByLanguage` housekeeping but
 * empties the set in one shot. No-op when nothing is selected (the shortcut
 * button is disabled in that case anyway).
 */
export function clearLanguageFilter() {
  if (state.selectedLanguageFilter.size === 0) return;
  state.selectedLanguageFilter.clear();

  document.querySelectorAll(".wxe-lang-item").forEach((item) => {
    item.classList.remove("wxe-pill--active");
  });

  updateLangPickerSummary();
  syncLangClearBtnState();
  saveFilterPrefs();
  applyFilters();
}

export function filterByTranslation(value) {
  const selected = state.selectedStatusFilter;

  // Pending and Done are mutually exclusive shortcuts: clicking one always
  // clears the opposing side first, then toggles the clicked side. Both
  // active simultaneously is meaningless (it equals "no filter") and would
  // confuse users.
  if (value === "translated") {
    const wasActive = selected.has("translated");
    PENDING_STATUSES.forEach(s => selected.delete(s));
    if (wasActive) {
      selected.delete("translated");
    } else {
      selected.add("translated");
    }
  } else {
    const allPendingSelected = PENDING_STATUSES.every(s => selected.has(s));
    selected.delete("translated");
    if (allPendingSelected) {
      PENDING_STATUSES.forEach(s => selected.delete(s));
    } else {
      PENDING_STATUSES.forEach(s => selected.add(s));
    }
  }

  // Reflect the new selection on the granular pills.
  document.querySelectorAll(".wxe-status-filter").forEach((pill) => {
    pill.classList.toggle("wxe-status-filter--active", selected.has(pill.dataset.status));
  });

  // Reflect on the shortcut buttons themselves.
  syncTranslationShortcutState();

  saveFilterPrefs();

  applyFilters();
}

export function applyFilters() {
  renderPillContent();
}

export function filterItems(items, postTypeHint) {
  let result = items;

  // Apply current context filter (bypass when searching globally)
  if (state.filterByCurrentContext && !state.searchQuery) {
    result = result.filter((item) => isInCurrentContext(item, postTypeHint));
  }

  // Translation filter is applied at row level in buildItemCards (like status filter)

  // Apply search filter
  if (state.searchQuery) {
    const q = state.searchQuery.toLowerCase();
    result = result.filter((item) => (item.title || '').toLowerCase().includes(q));
  }

  return result;
}

export function isInCurrentContext(item, postTypeHint) {
  if (!state.filterByCurrentContext) return true;

  const currentPostId = window.wxeBridge?.currentPostId;
  const currentPostType = window.wxeBridge?.currentPostType;

  // Current page/template itself (parseInt handles string/number mismatch from wp_localize_script).
  if (parseInt(item.id, 10) === parseInt(currentPostId, 10)) return true;

  // Components used on current page
  if (postTypeHint === 'wp_block' || item._isComponent) {
    const componentIds = (window.wxeBridge.components || []).map(c => parseInt(c.id, 10));
    return componentIds.includes(parseInt(item.id, 10));
  }

  // For templates: only show the current template being edited
  if (postTypeHint === 'wp_template' && currentPostType === 'wp_template') {
    return item.id === currentPostId;
  }

  // Default: not in context
  return false;
}

export function findLangData(itemId, langCode, postType) {
  // Try On This Page data first.
  if (itemId === window.wxeBridge.currentPostId) {
    return window.wxeBridge.languages[langCode];
  }
  // Try components on this page.
  const comp = (window.wxeBridge.components || []).find((c) => c.id === itemId);
  if (comp) return comp.languages[langCode];
  // Try pill cache.
  const cacheKey = postType || state.activePill;
  if (cacheKey && state.pillCache[cacheKey]) {
    const item = state.pillCache[cacheKey].find((c) => c.id === itemId);
    if (item) return item.languages[langCode];
  }
  // Try all caches.
  for (const key of Object.keys(state.pillCache)) {
    if (!state.pillCache[key]) continue;
    const item = state.pillCache[key].find((c) => c.id === itemId);
    if (item) return item.languages[langCode];
  }
  return null;
}
