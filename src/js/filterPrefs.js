// Persists Languages + Status filter selections across reloads / sessions.
// Current Context, search, activePill etc. are intentionally NOT persisted.

import { state } from './state.js';

export const FILTER_PREFS_KEY = "wxe_filter_prefs_v1";

export function loadFilterPrefs() {
  let raw;
  try {
    raw = window.localStorage.getItem(FILTER_PREFS_KEY);
  } catch (e) {
    return;
  }
  if (!raw) return;

  let parsed;
  try {
    parsed = JSON.parse(raw);
  } catch (e) {
    // Malformed payload — drop it so the next save overwrites cleanly.
    try { window.localStorage.removeItem(FILTER_PREFS_KEY); } catch (_) {}
    return;
  }

  if (!parsed || typeof parsed !== "object") return;

  const langs = Array.isArray(parsed.selectedLanguageFilter) ? parsed.selectedLanguageFilter : null;
  const statuses = Array.isArray(parsed.selectedStatusFilter) ? parsed.selectedStatusFilter : null;
  if (!langs || !statuses) {
    try { window.localStorage.removeItem(FILTER_PREFS_KEY); } catch (_) {}
    return;
  }

  state.selectedLanguageFilter = new Set(langs.filter((v) => typeof v === "string"));
  state.selectedStatusFilter = new Set(statuses.filter((v) => typeof v === "string"));
}

export function saveFilterPrefs() {
  const payload = {
    selectedLanguageFilter: [...state.selectedLanguageFilter],
    selectedStatusFilter: [...state.selectedStatusFilter],
    savedAt: Date.now(),
  };
  try {
    window.localStorage.setItem(FILTER_PREFS_KEY, JSON.stringify(payload));
  } catch (e) {
    // Quota / private mode — ignore, panel must still work.
  }
}
