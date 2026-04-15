import { state } from './state.js';
import { updateStatusDot, updatePillDots, calculateCombinedStatus, worstStatus } from './status.js';
import { renderPillContent } from './rendering.js';
import { findLangData } from './filters.js';
import { msg } from './utils.js';

export async function fetchPillData(pillId) {
  // Skip pills that don't use the posts-by-type endpoint.
  const pillDef = (window.wxeBridge.contentTypePills || []).find(p => p.id === pillId);
  if (pillId === 'json-loops' || (pillDef && pillDef.notTranslatable)) return;
  if (state.pillCache[pillId] || state.pillLoading[pillId]) return;
  state.pillLoading[pillId] = true;

  const postType = pillId; // pill id matches post_type slug
  const url = `${window.wxeBridge.restUrl}posts-by-type?post_type=${encodeURIComponent(postType)}`;

  try {
    const res = await fetch(url, {
        headers: { "X-WP-Nonce": window.wxeBridge.restNonce },
        credentials: "same-origin",
      },
    );
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();
    if (Array.isArray(data)) {
      state.pillCache[pillId] = data;
    } else {
      state.pillCache[pillId] = [];
    }
  } catch (e) {
    console.error("[WXE] fetchPillData ERROR", pillId, e.message);
    // Retry once after a short delay before marking as error.
    if (!state.pillCache[pillId] && !state.pillLoading[pillId + '_retried']) {
      state.pillLoading[pillId + '_retried'] = true;
      state.pillLoading[pillId] = false;
      setTimeout(() => fetchPillData(pillId), 1000);
      return;
    }
    console.error("[WXE] fetchPillData PERMANENT FAIL", pillId);
    state.pillCache[pillId] = null; // null = error state
  } finally {
    state.pillLoading[pillId] = false;
    updatePillDots();
  }
}

export async function lazyFetchPillStatuses() {
  if (state.pillStatusesFetched || !window.wxeBridge) return;
  try {
    const resp = await fetch(
      `${window.wxeBridge.restUrl}pill-statuses`,
      {
        headers: { "X-WP-Nonce": window.wxeBridge.restNonce },
        credentials: "same-origin",
      }
    );
    const data = await resp.json();
    if (resp.ok && data) {
      state.pillStatusesFetched = true;
      for (const [pillId, pillState] of Object.entries(data)) {
        state.pillStatuses[pillId] = pillState;
      }
      updatePillDots();
    }
  } catch (e) {
    console.warn('[WXE] pill status fetch failed', e.message);
  }
}

export async function refreshLanguagesStatus() {
  if (!window.wxeBridge) return;
  // Dedup: if a refresh is already in-flight, return its promise.
  if (state.refreshInFlight) return state.refreshInFlight;
  state.refreshInFlight = _doRefreshLanguagesStatus();
  try { await state.refreshInFlight; } finally { state.refreshInFlight = null; }
}

async function _doRefreshLanguagesStatus() {
  const { currentPostId } = window.wxeBridge;
  try {
    const response = await fetch(
      `${window.wxeBridge.restUrl}languages-status?post_id=${currentPostId}`,
      {
        headers: { "X-WP-Nonce": window.wxeBridge.restNonce },
        credentials: "same-origin",
      },
    );
    if (!response.ok) return;
    const data = await response.json();
    if (data) {
      // Track previous component IDs to detect structural changes.
      const prevComponentIds = (window.wxeBridge.components || []).map(c => c.id).sort().join(',');

      // Update languages data.
      window.wxeBridge.languages = data.languages || data;
      window.wxeBridge.components = data.components || [];
      window.wxeBridge.loopStatuses = data.loopStatuses || window.wxeBridge.loopStatuses || {};
      if (data.isTranslatable !== undefined) {
        window.wxeBridge.isTranslatable = data.isTranslatable;
      }

      // Use server-calculated combined status (already filtered to context loops),
      // or recalculate with context-only loops as fallback.
      window.wxeBridge.combinedStatus = data.combinedStatus
        || calculateCombinedStatus(
          window.wxeBridge.languages,
          window.wxeBridge.components,
        );

      // Always update status dot and panel internals so data is fresh
      // when the user opens the panel (avoids visible status jump).
      updateStatusDot();

      // If components changed (added/removed), re-render the content area
      // instead of patching individual rows -- the DOM structure is stale.
      const newComponentIds = (window.wxeBridge.components || []).map(c => c.id).sort().join(',');
      if (prevComponentIds !== newComponentIds) {
        renderPillContent();
      }

      // Update page card rows (rows without data-component-id).
      for (const [code, lang] of Object.entries(window.wxeBridge.languages)) {
        if (lang.is_original) continue;
        const row = document.querySelector(
          `.wxe-component-lang-row:not([data-component-id])[data-lang-code="${code}"]`,
        );
        if (row) row.dataset.status = lang.status;
      }

      // Update component rows on "On This Page".
      for (const component of window.wxeBridge.components || []) {
        for (const [code, lang] of Object.entries(component.languages)) {
          if (lang.is_original) continue;
          const row = document.querySelector(
            `.wxe-component-lang-row[data-component-id="${component.id}"][data-lang-code="${code}"]`,
          );
          if (row) row.dataset.status = lang.status;
        }
      }

      // Update loop card rows.
      const loopStatuses = window.wxeBridge.loopStatuses || {};
      const allLoopStatuses = [];
      for (const ls of Object.values(loopStatuses)) {
        for (const s of Object.values(ls)) allLoopStatuses.push(s);
      }
      document.querySelectorAll(".wxe-loop-link[data-loop-name]").forEach((row) => {
        const loopName = row.dataset.loopName;
        if (loopName === "all") {
          if (allLoopStatuses.length) row.dataset.status = worstStatus(allLoopStatuses);
          return;
        }
        const loopStatus = loopStatuses[loopName] || {};
        const statuses = Object.values(loopStatus);
        if (statuses.length) {
          row.dataset.status = statuses.every(s => s === 'translated') ? 'translated' : 'not_translated';
        }
      });

      // Update individual loop language rows.
      document.querySelectorAll(".wxe-loop-lang-row[data-loop-name][data-lang-code]").forEach((row) => {
        const loopName = row.dataset.loopName;
        const langCode = row.dataset.langCode;
        const loopStatus = loopStatuses[loopName] || {};
        const status = loopStatus[langCode] || 'not_translated';
        row.dataset.status = status;
      });

      // Update pill-cached item rows.
      document.querySelectorAll(".wxe-item-lang-row").forEach((row) => {
        const itemId = parseInt(row.dataset.itemId, 10);
        const langCode = row.dataset.langCode;
        const postType = row.dataset.postType;
        const lang = findLangData(itemId, langCode, postType);
        if (lang) row.dataset.status = lang.status;
      });

      // Invalidate pill caches and loading flags, then update dots.
      Object.keys(state.pillCache).forEach(k => delete state.pillCache[k]);
      Object.keys(state.pillLoading).forEach(k => delete state.pillLoading[k]);
      state.pillStatusesFetched = false;
      updatePillDots();
      lazyFetchPillStatuses();

      // If a type pill is active, re-fetch data and patch existing rows
      // instead of full re-render (which destroys setRowSuccess states).
      if (state.activePill && state.activePill !== 'on-this-page') {
        fetchPillData(state.activePill).then(() => {
          const items = state.pillCache[state.activePill];
          if (!items || !Array.isArray(items)) return;
          // Patch data-status on existing rows without re-rendering.
          for (const item of items) {
            for (const [code, lang] of Object.entries(item.languages || {})) {
              if (lang.is_original) continue;
              const row = document.querySelector(`.wxe-item-lang-row[data-item-id="${item.id}"][data-lang-code="${code}"]`);
              if (row) row.dataset.status = lang.status;
            }
          }
          // Update global AI button via rendering (avoids circular import).
          const globalBtn = document.getElementById('wxe-ai-global-btn');
          if (globalBtn) {
            const hasTranslatable = document.querySelector(
              '.wxe-component-lang-row:not(.wxe-loop-lang-row) .wxe-ai-btn:not(.wxe-ai-btn--disabled), ' +
              '.wxe-item-lang-row .wxe-ai-btn:not(.wxe-ai-btn--disabled), ' +
              '.wxe-loop-lang-row .wxe-ai-btn:not(.wxe-ai-btn--disabled)'
            );
            globalBtn.classList.toggle('wxe-ai-btn--disabled', !hasTranslatable);
            globalBtn.disabled = !hasTranslatable;
          }
        });
      }
    }
  } catch (e) {
    console.warn('[WXE] polling error', e.message);
  }
}

export function startStatusRefreshInterval() {
  if (state.statusRefreshInterval) return;
  state.statusRefreshInterval = setInterval(() => {
    if (!document.hidden) refreshLanguagesStatus();
  }, 15000);
}

// ---------- Resync ----------------------------------------------------------
//
// Two scopes (mirrors the backend):
//   - Local: current post + its components. Triggered automatically after
//     every Etch save. Cheap, runs in the background.
//   - Global: every Etch post on the site. Triggered manually by the
//     "Resync All" button. Brings the whole site back into alignment.
//
// The visible status line in the WPML Sync section is driven EXCLUSIVELY by
// the global scope. Successful local resyncs are silent — the panel doesn't
// announce them, because users already know they just saved (and a stats
// line that drifts between "47 posts" and "1 post" depending on which scope
// ran last is incoherent next to a button labelled "Resync All"). Local
// errors do flash a transient red state, then revert to the cached global.

const RESYNC_STATUS_LABELS = {
  syncing: 'Syncing…',
  ok:      'Synced',
  error:   'Sync failed',
  idle:    '',
};

/** Compact relative time formatter for the status line. */
function formatRelativeTime(timestampSeconds) {
  if (!timestampSeconds) return '';
  const diffSec = Math.max(0, Math.floor(Date.now() / 1000) - timestampSeconds);
  if (diffSec < 5)     return msg().syncedJustNow || 'just now';
  if (diffSec < 60)    return `${diffSec}s ago`;
  if (diffSec < 3600)  return `${Math.floor(diffSec / 60)}m ago`;
  if (diffSec < 86400) return `${Math.floor(diffSec / 3600)}h ago`;
  return `${Math.floor(diffSec / 86400)}d ago`;
}

/** Tiny printf-style helper for translatable templates. Supports `%s`,
 *  `%d`, and positional `%1$d` / `%2$s` markers. Missing args render as
 *  an empty string so a misformed template never throws. */
function fmt(template, ...args) {
  if (!template) return '';
  let auto = 0;
  return String(template).replace(/%(?:(\d+)\$)?[ds]/g, (_match, posStr) => {
    const idx = posStr ? parseInt(posStr, 10) - 1 : auto++;
    return args[idx] != null ? String(args[idx]) : '';
  });
}

/** Compose a tooltip value from a `· `-joined prefix line and an
 *  optional ratio line. The ratio always gets its own line via `\n`
 *  (rendered as a hard break by `white-space: pre-line` on .wxe-tooltip)
 *  so the phrase "X of Y …" never gets mid-broken by natural wrapping. */
function composeTooltipValue(prefixParts, ratio) {
  const prefix = prefixParts.filter(Boolean).join(' · ');
  if (!ratio) return prefix || '—';
  if (!prefix) return ratio;
  return `${prefix}\n${ratio}`;
}

/** Build the All Site tooltip value from the global last-run + site health. */
function buildAllSiteValue(globalRun, health, m) {
  const prefixParts = [];
  if (globalRun && globalRun.timestamp) {
    prefixParts.push(formatRelativeTime(globalRun.timestamp));
    const entries = parseInt(globalRun.stats?.posts_processed || 0, 10);
    if (entries > 0) prefixParts.push(fmt(m.entriesFmt || '%d entries', entries));
  } else {
    prefixParts.push(m.resyncNeverRun || 'Never run');
  }
  const ratio = (health && health.total > 0)
    ? fmt(m.translationsCompleteFmt || '%1$d of %2$d translations complete',
          health.complete, health.total)
    : null;
  return composeTooltipValue(prefixParts, ratio);
}

/** Build the Current Context tooltip value from local last-run + live langs. */
function buildCurrentContextValue(localRun, m) {
  const prefixParts = [];

  // Scope/ago line: only when the cached local belongs to the post in view.
  const currentPostId = window.wxeBridge?.currentPostId
    ? parseInt(window.wxeBridge.currentPostId, 10)
    : null;
  const matchesCurrentPost =
    localRun &&
    currentPostId &&
    parseInt(localRun.post_id, 10) === currentPostId;

  if (matchesCurrentPost) {
    prefixParts.push(formatRelativeTime(localRun.timestamp));
    const typeLabel = (window.wxeBridge?.postTypeLabel || 'page').toLowerCase();
    let scope = fmt(m.thisScopeFmt || 'This %s', typeLabel);
    const componentsCount = Array.isArray(window.wxeBridge?.components)
      ? window.wxeBridge.components.length
      : 0;
    if (componentsCount === 1) {
      scope += ' ' + (m.plusComponentSingular || '+ 1 component');
    } else if (componentsCount > 1) {
      scope += ' ' + fmt(m.plusComponentsFmt || '+ %d components', componentsCount);
    }
    prefixParts.push(scope);
  }

  // Live language health for the current post — read from wxeBridge.languages
  // so it stays in sync with whatever refreshLanguagesStatus most recently
  // wrote, without having to wait for the next /resync/status fetch.
  const langs = Object.values(window.wxeBridge?.languages || {});
  const nonOriginal = langs.filter((l) => !l.is_original);
  const total = nonOriginal.length;
  const ratio = total > 0
    ? fmt(m.languagesCompleteFmt || '%1$d of %2$d languages complete',
          nonOriginal.filter((l) => l.status === 'translated').length, total)
    : null;

  return composeTooltipValue(prefixParts, ratio);
}

/**
 * Update the Force Sync UI in the sidebar footer. Drives:
 * - The shortcut button label (`#wxe-resync-run-label`) and `data-state`
 *   attribute (CSS flips it red on error, dimmed on syncing).
 * - The two value rows of the hover tooltip (`#wxe-resync-tooltip-global`
 *   and `#wxe-resync-tooltip-local`). Each row combines the relevant last
 *   run snapshot with a live "X of Y complete" health ratio.
 *
 * `lastRun` here is always the GLOBAL snapshot. The local snapshot, the
 * site health aggregate, and the per-language status are read from
 * `state.lastResyncLocal` / `state.siteHealth` / `wxeBridge.languages`
 * directly so callers don't need to thread them through.
 */
export function updateResyncStatusUI(uiState, lastRun = null) {
  const btn      = document.getElementById('wxe-resync-all-btn');
  const labelEl  = document.getElementById('wxe-resync-run-label');
  const globalEl = document.getElementById('wxe-resync-tooltip-global');
  const localEl  = document.getElementById('wxe-resync-tooltip-local');
  if (!btn) return;

  btn.dataset.state = uiState;

  const m = msg();
  const baseLabel = m.resync || 'Force Sync';
  let buttonLabel = baseLabel;
  let buttonDisabled = false;
  let ariaLabel = baseLabel;
  let globalText;

  if (uiState === 'syncing') {
    buttonLabel    = m.resyncing || 'Syncing…';
    buttonDisabled = true;
    ariaLabel      = buttonLabel;
    globalText     = buttonLabel;
  } else if (uiState === 'error') {
    buttonLabel = m.resyncRetry || 'Retry';
    ariaLabel   = m.resyncError || 'Sync failed';
    globalText  = ariaLabel;
  } else {
    globalText = buildAllSiteValue(lastRun, state.siteHealth, m);
    if (lastRun && lastRun.timestamp) {
      ariaLabel = `${baseLabel}, last run ${formatRelativeTime(lastRun.timestamp)}`;
    }
  }

  if (labelEl)  labelEl.textContent  = buttonLabel;
  if (globalEl) globalEl.textContent = globalText;
  if (localEl)  localEl.textContent  = buildCurrentContextValue(state.lastResyncLocal, m);

  btn.disabled = buttonDisabled;
  btn.setAttribute('aria-label', ariaLabel);
}

/** Re-render the relative timestamp from cached state. */
function refreshResyncStatusFromCache() {
  if (state.lastResync) {
    updateResyncStatusUI('ok', state.lastResync);
  } else {
    updateResyncStatusUI('idle');
  }
}

/** Fetch the persisted last-run state for both scopes + site health, render. */
export async function fetchResyncStatus() {
  try {
    const resp = await fetch(`${window.wxeBridge.restUrl}resync/status`, {
      headers: { 'X-WP-Nonce': window.wxeBridge.restNonce },
      credentials: 'same-origin',
    });
    if (!resp.ok) return;
    const data = await resp.json();
    const globalRun = data && data.global && data.global.timestamp ? data.global : null;
    const localRun  = data && data.local  && data.local.timestamp  ? data.local  : null;
    state.lastResync      = globalRun;
    state.lastResyncLocal = localRun;
    state.siteHealth      = data && data.site_health ? data.site_health : null;
    updateResyncStatusUI(globalRun ? 'ok' : 'idle', globalRun);
  } catch (e) {
    // Silent — status indicator just stays empty.
  }
}

/** Tick the relative-time label every 30s so "2s ago" stays accurate. */
export function startResyncStatusInterval() {
  if (state.resyncStatusInterval) return;
  state.resyncStatusInterval = setInterval(() => {
    if (!document.hidden) refreshResyncStatusFromCache();
  }, 30000);
}

/** Internal — POST to a resync endpoint and refresh UI on completion. */
async function runResync(endpoint, body, scope) {
  if (state.isResyncing) return;
  state.isResyncing = true;

  // Only the global scope drives the visible status line. Local resyncs
  // run silently — no "Syncing…" label, no button disable, no rewrite of
  // state.lastResync on success. The user already knows they just saved.
  const isGlobal = scope === 'global';
  if (isGlobal) updateResyncStatusUI('syncing');

  try {
    const resp = await fetch(`${window.wxeBridge.restUrl}${endpoint}`, {
      method: 'POST',
      headers: {
        'X-WP-Nonce': window.wxeBridge.restNonce,
        'Content-Type': 'application/json',
      },
      credentials: 'same-origin',
      body: body ? JSON.stringify(body) : undefined,
    });
    if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
    const data = await resp.json();

    const now = Math.floor(Date.now() / 1000);
    if (isGlobal) {
      state.lastResync = {
        timestamp: now,
        stats: data.stats || {},
      };
      updateResyncStatusUI('ok', state.lastResync);
    } else {
      // Local: refresh the cache and silently re-render the tooltip so
      // the "Current context" row reflects the new stats immediately
      // (without waiting for a panel rebuild). The button label stays
      // "Force Sync" because we re-render with the cached global state.
      state.lastResyncLocal = {
        timestamp: now,
        stats: data.stats || {},
        post_id: window.wxeBridge?.currentPostId
          ? parseInt(window.wxeBridge.currentPostId, 10)
          : 0,
      };
      refreshResyncStatusFromCache();
    }

    await refreshLanguagesStatus();
  } catch (e) {
    console.error('[WXE] resync error', e);
    // Both scopes flash an error so failed background syncs don't go
    // unnoticed. Local errors revert to the cached global state after
    // a few seconds via refreshResyncStatusFromCache().
    updateResyncStatusUI('error');
    setTimeout(refreshResyncStatusFromCache, 4000);
  } finally {
    state.isResyncing = false;
  }
}

/** Auto-resync after save: scoped to the current post + its components. */
export async function resyncLocal() {
  return runResync('resync', { post_id: window.wxeBridge.currentPostId }, 'local');
}

/** Manual "Resync All" button: every Etch post on the site. */
export async function resyncAll() {
  return runResync('resync/all', null, 'global');
}
