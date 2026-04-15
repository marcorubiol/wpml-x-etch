import { state, ICON_SPARKLE, ICON_SPARKLE_OFF } from './state.js';
import { msg } from './utils.js';
import { saveBeforeOpeningAte } from './translation.js';
import { refreshLanguagesStatus } from './data.js';

/**
 * Update the global "AI Translate in view" button state based on whether
 * there are translatable (non-disabled) AI rows in the current DOM.
 */
export function updateGlobalAiButtonState() {
  const btn = document.getElementById('wxe-ai-global-btn');
  if (!btn) return;

  // Don't touch locked buttons — they have their own state.
  if (btn.classList.contains('wxe-ai-btn--locked')) return;

  const hasTranslatable = document.querySelector(
    '.wxe-component-lang-row:not(.wxe-loop-lang-row) .wxe-ai-btn:not(.wxe-ai-btn--disabled), ' +
    '.wxe-item-lang-row .wxe-ai-btn:not(.wxe-ai-btn--disabled), ' +
    '.wxe-loop-lang-row .wxe-ai-btn:not(.wxe-ai-btn--disabled)'
  );

  if (hasTranslatable) {
    btn.classList.remove('wxe-ai-btn--disabled');
    btn.disabled = false;
    btn.innerHTML = ICON_SPARKLE;
    btn.setAttribute('data-tooltip', msg().aiTranslateVisible || 'AI Translate in view');
  } else {
    btn.classList.add('wxe-ai-btn--disabled');
    btn.disabled = true;
    btn.innerHTML = ICON_SPARKLE_OFF;
    btn.setAttribute('data-tooltip', 'No content for AI translation');
  }
}

const ICON_SPINNER = '<svg class="wxe-spin" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>';
const ICON_CHECK = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
const ICON_ERROR = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
const ICON_REDO = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>';

/**
 * Find the language row DOM element for a given lang code + post/component.
 */
function findRow(langCode, postId, componentId) {
  if (componentId) {
    return document.querySelector(`.wxe-component-lang-row[data-lang-code="${langCode}"][data-component-id="${componentId}"]`);
  }
  // Try item-lang-row first (pill content like Pages/Posts) — scoped by item ID.
  if (postId) {
    const itemRow = document.querySelector(`.wxe-item-lang-row[data-lang-code="${langCode}"][data-item-id="${postId}"]`);
    if (itemRow) return itemRow;
  }
  // Fall back to component-lang-row without component-id (current context).
  return document.querySelector(`.wxe-component-lang-row[data-lang-code="${langCode}"]:not([data-component-id])`);
}

/** Pending revert timers keyed by row element. */
const rowTimers = new WeakMap();

/** Cancel any pending revert timer on a row. */
function clearRowTimer(row) {
  if (!row) return;
  const t = rowTimers.get(row);
  if (t) { clearTimeout(t); rowTimers.delete(row); }
}

/** Reset a row to its neutral visual state. */
function resetRow(row) {
  if (!row) return;
  clearRowTimer(row);
  row.classList.remove('wxe-row--translating', 'wxe-row--success', 'wxe-row--error');
  const aiBtn = row.querySelector('.wxe-ai-btn');
  if (aiBtn) { aiBtn.innerHTML = ICON_SPARKLE; aiBtn.setAttribute('data-tooltip', 'AI Translate'); }
}

/**
 * Set a row to "translating" state: spinner replaces sparkle, border pulses.
 */
function setRowTranslating(row) {
  if (!row) return;
  resetRow(row);
  row.classList.add('wxe-row--translating');
  const aiBtn = row.querySelector('.wxe-ai-btn');
  if (aiBtn) aiBtn.innerHTML = ICON_SPINNER;
}

/**
 * Set a row to "success" state: check icon, green border. Reverts after 3s.
 */
function setRowSuccess(row) {
  if (!row) return;
  clearRowTimer(row);
  row.classList.remove('wxe-row--translating', 'wxe-row--error');
  row.classList.add('wxe-row--success');
  row.dataset.status = 'translated';
  const aiBtn = row.querySelector('.wxe-ai-btn');
  if (aiBtn) aiBtn.innerHTML = ICON_CHECK;
  rowTimers.set(row, setTimeout(() => resetRow(row), 3000));
}

/**
 * Set a row to "error" state: error icon, red border. Reverts after 5s.
 */
function setRowError(row, message) {
  if (!row) return;
  clearRowTimer(row);
  row.classList.remove('wxe-row--translating', 'wxe-row--success');
  row.classList.add('wxe-row--error');
  const aiBtn = row.querySelector('.wxe-ai-btn');
  if (aiBtn) { aiBtn.innerHTML = ICON_ERROR; aiBtn.setAttribute('data-tooltip', message || 'Translation failed'); }
  rowTimers.set(row, setTimeout(() => resetRow(row), 5000));
}

/**
 * Set a row to "already done" state: brief check, then back to normal.
 */
function setRowAlreadyDone(row) {
  if (!row) return;
  clearRowTimer(row);
  row.classList.remove('wxe-row--translating', 'wxe-row--error');
  row.classList.add('wxe-row--success');
  const aiBtn = row.querySelector('.wxe-ai-btn');
  if (aiBtn) aiBtn.innerHTML = ICON_CHECK;
  rowTimers.set(row, setTimeout(() => resetRow(row), 2000));
}

/** Pending retranslation confirm timers keyed by button element. */
const retranslateTimers = new WeakMap();

/**
 * Enter "confirm retranslate" mode on a button: redo icon for 3s.
 * Uses a data attribute flag so the existing panel click handler
 * can detect it and call with force=true on the next click.
 */
function enterRetranslateConfirm(aiBtn) {
  if (!aiBtn) return;

  // Cancel any previous confirm timer.
  const prev = retranslateTimers.get(aiBtn);
  if (prev) clearTimeout(prev);

  // Visual + flag.
  aiBtn.innerHTML = ICON_REDO;
  aiBtn.setAttribute('data-tooltip', 'Retranslate? Click again');
  aiBtn.setAttribute('data-retranslate', '1');

  // Revert after 3s if no second click.
  const timer = setTimeout(() => {
    clearRetranslateConfirm(aiBtn);
  }, 3000);

  retranslateTimers.set(aiBtn, timer);
}

function clearRetranslateConfirm(aiBtn) {
  if (!aiBtn) return;
  const timer = retranslateTimers.get(aiBtn);
  if (timer) clearTimeout(timer);
  retranslateTimers.delete(aiBtn);
  aiBtn.innerHTML = ICON_SPARKLE;
  aiBtn.setAttribute('data-tooltip', 'AI Translate');
  aiBtn.removeAttribute('data-retranslate');
}

/**
 * Translate a single post to a single language via AI.
 */
export async function aiTranslateSingle(langCode, postId, componentId, triggerBtn) {
  if (state.isAiTranslating) return;

  const bridge = window.wxeBridge;
  if (!bridge) return;

  if (!bridge.aiConfigured) {
    const accordion = document.getElementById('wxe-ai-settings-accordion');
    if (accordion && accordion.getAttribute('aria-expanded') !== 'true') {
      accordion.click();
    }
    return;
  }

  const row = findRow(langCode, postId, componentId);

  // If the row is already translated and button is NOT in confirm mode,
  // enter confirm mode instead of calling the server.
  if (row && row.dataset.status === 'translated' && triggerBtn && triggerBtn.getAttribute('data-retranslate') !== '1') {
    enterRetranslateConfirm(triggerBtn);
    return;
  }

  // If button is in confirm mode, use force and clear the confirm state.
  const force = triggerBtn && triggerBtn.getAttribute('data-retranslate') === '1';
  if (force) clearRetranslateConfirm(triggerBtn);

  state.isAiTranslating = true;
  setRowTranslating(row);

  try {
    // Save Etch before first translate, skip on force retranslate.
    if (!force) {
      try { await saveBeforeOpeningAte(); } catch { /* ignore */ }
    }

    const effectivePostId = componentId || postId || bridge.currentPostId;

    const response = await fetch(`${bridge.restUrl}ai-translate`, {
      method: 'POST',
      headers: { 'X-WP-Nonce': bridge.restNonce, 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        post_id: effectivePostId,
        target_lang: langCode,
        component_id: componentId || 0,
        force,
      }),
    });

    if (!response.ok) {
      const err = await response.json().catch(() => ({}));
      throw new Error(err.message || `HTTP ${response.status}`);
    }

    const data = await response.json();

    if (data.translated_count > 0) {
      setRowSuccess(row);
    } else {
      setRowAlreadyDone(row);
    }

    refreshLanguagesStatus();

  } catch (err) {
    const message = err instanceof Error && err.message ? err.message : (msg().aiError || 'Translation failed.');
    setRowError(row, message);
  } finally {
    state.isAiTranslating = false;
    if (row && row.classList.contains('wxe-row--translating')) {
      resetRow(row);
    }
  }
}

/**
 * Translate a single post to ALL pending languages via AI.
 * Iterates rows sequentially so the user sees per-row progress.
 */
export async function aiTranslateAll(postId, componentId, triggerBtn) {
  if (state.isAiTranslating) return;

  const bridge = window.wxeBridge;
  if (!bridge) return;

  if (!bridge.aiConfigured) {
    const accordion = document.getElementById('wxe-ai-settings-accordion');
    if (accordion && accordion.getAttribute('aria-expanded') !== 'true') {
      accordion.click();
    }
    return;
  }

  // Check if all rows in this card are already translated.
  const effectivePostId = componentId || postId || bridge.currentPostId;
  const selector = componentId
    ? `.wxe-component-lang-row[data-component-id="${componentId}"]`
    : `.wxe-component-lang-row:not([data-component-id])`;
  const rows = document.querySelectorAll(selector);

  const allTranslated = rows.length > 0 && Array.from(rows).every(r => r.dataset.status === 'translated');
  if (allTranslated && triggerBtn && triggerBtn.getAttribute('data-retranslate') !== '1') {
    enterRetranslateConfirm(triggerBtn);
    return;
  }

  const force = triggerBtn && triggerBtn.getAttribute('data-retranslate') === '1';
  if (force) clearRetranslateConfirm(triggerBtn);

  state.isAiTranslating = true;
  if (triggerBtn) triggerBtn.innerHTML = ICON_SPINNER;

  try {
    if (!force) {
      try { await saveBeforeOpeningAte(); } catch { /* ignore */ }
    }

    // Mark all rows as translating.
    rows.forEach(row => setRowTranslating(row));

    const response = await fetch(`${bridge.restUrl}ai-translate-all`, {
      method: 'POST',
      headers: { 'X-WP-Nonce': bridge.restNonce, 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        post_id: effectivePostId,
        component_id: componentId || 0,
        force,
      }),
    });

    if (!response.ok) {
      const err = await response.json().catch(() => ({}));
      throw new Error(err.message || `HTTP ${response.status}`);
    }

    const data = await response.json();
    const results = data.languages || [];

    const rowMap = {};
    rows.forEach(row => { rowMap[row.dataset.langCode] = row; });

    let anyTranslated = false;
    for (const result of results) {
      const row = rowMap[result.lang];
      if (result.error) {
        setRowError(row, result.error);
      } else if (result.translated_count > 0) {
        setRowSuccess(row);
        anyTranslated = true;
      } else {
        setRowAlreadyDone(row);
      }
    }

    rows.forEach(row => {
      if (row.classList.contains('wxe-row--translating')) setRowAlreadyDone(row);
    });

    if (!anyTranslated && triggerBtn) {
      rows.forEach(row => resetRow(row));
      enterRetranslateConfirm(triggerBtn);
      return;
    }

    refreshLanguagesStatus();

    if (triggerBtn) {
      triggerBtn.innerHTML = ICON_CHECK;
      triggerBtn.style.color = 'var(--wxe-status-translated)';
      triggerBtn.style.opacity = '1';
      setTimeout(() => {
        triggerBtn.innerHTML = ICON_SPARKLE;
        triggerBtn.style.color = '';
        triggerBtn.style.opacity = '';
      }, 3000);
    }

  } catch (err) {
    const message = err instanceof Error && err.message ? err.message : (msg().aiError || 'Translation failed.');
    // Mark all still-translating rows as error.
    document.querySelectorAll('.wxe-row--translating').forEach(row => {
      setRowError(row, message);
    });
    // Trigger button: red error, then revert.
    if (triggerBtn) {
      triggerBtn.innerHTML = ICON_ERROR;
      triggerBtn.style.color = 'var(--wxe-status-not-translated)';
      triggerBtn.style.opacity = '1';
      setTimeout(() => {
        triggerBtn.innerHTML = ICON_SPARKLE;
        triggerBtn.style.color = '';
        triggerBtn.style.opacity = '';
      }, 5000);
    }
  } finally {
    state.isAiTranslating = false;
  }
}

/** Internal: retranslate all languages with force=true (triggered by double-tap). */
async function doTranslateAllForce(postId, componentId, triggerBtn) {
  const bridge = window.wxeBridge;
  state.isAiTranslating = true;
  if (triggerBtn) triggerBtn.innerHTML = ICON_SPINNER;

  const effectivePostId = componentId || postId || bridge.currentPostId;
  const selector = componentId
    ? `.wxe-component-lang-row[data-component-id="${componentId}"]`
    : `.wxe-component-lang-row:not([data-component-id])`;
  const rows = document.querySelectorAll(selector);
  rows.forEach(row => setRowTranslating(row));

  try {
    const response = await fetch(`${bridge.restUrl}ai-translate-all`, {
      method: 'POST',
      headers: { 'X-WP-Nonce': bridge.restNonce, 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ post_id: effectivePostId, component_id: componentId || 0, force: true }),
    });

    if (!response.ok) {
      const err = await response.json().catch(() => ({}));
      throw new Error(err.message || `HTTP ${response.status}`);
    }

    const data = await response.json();
    const rowMap = {};
    rows.forEach(row => { rowMap[row.dataset.langCode] = row; });

    for (const result of (data.languages || [])) {
      const row = rowMap[result.lang];
      if (result.error) setRowError(row, result.error);
      else if (result.translated_count > 0) setRowSuccess(row);
      else setRowAlreadyDone(row);
    }
    rows.forEach(row => { if (row.classList.contains('wxe-row--translating')) setRowAlreadyDone(row); });

    refreshLanguagesStatus();
    if (triggerBtn) {
      triggerBtn.innerHTML = ICON_CHECK;
      triggerBtn.style.color = 'var(--wxe-status-translated)';
      setTimeout(() => { triggerBtn.innerHTML = ICON_SPARKLE; triggerBtn.style.color = ''; }, 3000);
    }
  } catch (err) {
    rows.forEach(row => { if (row.classList.contains('wxe-row--translating')) setRowError(row, err instanceof Error ? err.message : 'Failed.'); });
    if (triggerBtn) {
      triggerBtn.innerHTML = ICON_ERROR;
      setTimeout(() => { triggerBtn.innerHTML = ICON_SPARKLE; triggerBtn.style.color = ''; }, 5000);
    }
  } finally {
    state.isAiTranslating = false;
  }
}

/**
 * Translate a single loop to a single language via AI.
 */
export async function aiTranslateLoopSingle(loopId, langCode, triggerBtn) {
  if (state.isAiTranslating) return;

  const bridge = window.wxeBridge;
  if (!bridge || !bridge.aiConfigured) return;

  const row = document.querySelector(`.wxe-loop-lang-row[data-loop-id="${loopId}"][data-lang-code="${langCode}"]`);

  // Already translated + not in confirm mode → enter confirm.
  if (row && row.dataset.status === 'translated' && triggerBtn && triggerBtn.getAttribute('data-retranslate') !== '1') {
    enterRetranslateConfirm(triggerBtn);
    return;
  }

  const force = triggerBtn && triggerBtn.getAttribute('data-retranslate') === '1';
  if (force) clearRetranslateConfirm(triggerBtn);

  state.isAiTranslating = true;
  setRowTranslating(row);

  try {
    const response = await fetch(`${bridge.restUrl}ai-translate-loop`, {
      method: 'POST',
      headers: { 'X-WP-Nonce': bridge.restNonce, 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ loop_id: loopId, target_lang: langCode, force }),
    });

    if (!response.ok) {
      const err = await response.json().catch(() => ({}));
      throw new Error(err.message || `HTTP ${response.status}`);
    }

    const data = await response.json();
    if (data.translated_count > 0) {
      setRowSuccess(row);
    } else {
      setRowAlreadyDone(row);
    }

    refreshLanguagesStatus();
  } catch (err) {
    setRowError(row, err instanceof Error ? err.message : 'Translation failed.');
  } finally {
    state.isAiTranslating = false;
    if (row && row.classList.contains('wxe-row--translating')) resetRow(row);
  }
}

/**
 * Translate a loop to ALL languages via AI.
 */
export async function aiTranslateLoopAll(loopId, triggerBtn) {
  if (state.isAiTranslating) return;

  const bridge = window.wxeBridge;
  if (!bridge || !bridge.aiConfigured) return;

  const rows = document.querySelectorAll(`.wxe-loop-lang-row[data-loop-id="${loopId}"]`);

  const allTranslated = rows.length > 0 && Array.from(rows).every(r => r.dataset.status === 'translated');
  if (allTranslated && triggerBtn && triggerBtn.getAttribute('data-retranslate') !== '1') {
    enterRetranslateConfirm(triggerBtn);
    return;
  }

  const force = triggerBtn && triggerBtn.getAttribute('data-retranslate') === '1';
  if (force) clearRetranslateConfirm(triggerBtn);

  state.isAiTranslating = true;
  if (triggerBtn) triggerBtn.innerHTML = ICON_SPINNER;
  rows.forEach(row => setRowTranslating(row));

  try {
    const response = await fetch(`${bridge.restUrl}ai-translate-loop-all`, {
      method: 'POST',
      headers: { 'X-WP-Nonce': bridge.restNonce, 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ loop_id: loopId, force }),
    });

    if (!response.ok) {
      const err = await response.json().catch(() => ({}));
      throw new Error(err.message || `HTTP ${response.status}`);
    }

    const data = await response.json();
    const results = data.languages || [];

    const rowMap = {};
    rows.forEach(row => { rowMap[row.dataset.langCode] = row; });

    let anyTranslated = false;
    for (const result of results) {
      const row = rowMap[result.lang];
      if (result.error) {
        setRowError(row, result.error);
      } else if (result.translated_count > 0) {
        setRowSuccess(row);
        anyTranslated = true;
      } else {
        setRowAlreadyDone(row);
      }
    }

    rows.forEach(row => {
      if (row.classList.contains('wxe-row--translating')) setRowAlreadyDone(row);
    });

    if (!anyTranslated && triggerBtn) {
      rows.forEach(row => resetRow(row));
      enterRetranslateConfirm(triggerBtn);
      return;
    }

    refreshLanguagesStatus();

    if (triggerBtn) {
      triggerBtn.innerHTML = ICON_CHECK;
      triggerBtn.style.color = 'var(--wxe-status-translated)';
      triggerBtn.style.opacity = '1';
      setTimeout(() => {
        triggerBtn.innerHTML = ICON_SPARKLE;
        triggerBtn.style.color = '';
        triggerBtn.style.opacity = '';
      }, 3000);
    }
  } catch (err) {
    rows.forEach(row => {
      if (row.classList.contains('wxe-row--translating')) {
        setRowError(row, err instanceof Error ? err.message : 'Translation failed.');
      }
    });
    if (triggerBtn) {
      triggerBtn.innerHTML = ICON_ERROR;
      triggerBtn.style.color = 'var(--wxe-status-not-translated)';
      setTimeout(() => {
        triggerBtn.innerHTML = ICON_SPARKLE;
        triggerBtn.style.color = '';
        triggerBtn.style.opacity = '';
      }, 5000);
    }
  } finally {
    state.isAiTranslating = false;
  }
}

/** Internal: retranslate all loop languages with force=true. */
async function doTranslateLoopAllForce(loopId, triggerBtn) {
  const bridge = window.wxeBridge;
  state.isAiTranslating = true;
  if (triggerBtn) triggerBtn.innerHTML = ICON_SPINNER;

  const rows = document.querySelectorAll(`.wxe-loop-lang-row[data-loop-id="${loopId}"]`);
  rows.forEach(row => setRowTranslating(row));

  try {
    const response = await fetch(`${bridge.restUrl}ai-translate-loop-all`, {
      method: 'POST',
      headers: { 'X-WP-Nonce': bridge.restNonce, 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ loop_id: loopId, force: true }),
    });

    if (!response.ok) {
      const err = await response.json().catch(() => ({}));
      throw new Error(err.message || `HTTP ${response.status}`);
    }

    const data = await response.json();
    const rowMap = {};
    rows.forEach(row => { rowMap[row.dataset.langCode] = row; });

    for (const result of (data.languages || [])) {
      const row = rowMap[result.lang];
      if (result.error) setRowError(row, result.error);
      else if (result.translated_count > 0) setRowSuccess(row);
      else setRowAlreadyDone(row);
    }
    rows.forEach(row => { if (row.classList.contains('wxe-row--translating')) setRowAlreadyDone(row); });

    refreshLanguagesStatus();
    if (triggerBtn) {
      triggerBtn.innerHTML = ICON_CHECK;
      triggerBtn.style.color = 'var(--wxe-status-translated)';
      setTimeout(() => { triggerBtn.innerHTML = ICON_SPARKLE; triggerBtn.style.color = ''; }, 3000);
    }
  } catch (err) {
    rows.forEach(row => { if (row.classList.contains('wxe-row--translating')) setRowError(row, err instanceof Error ? err.message : 'Failed.'); });
    if (triggerBtn) {
      triggerBtn.innerHTML = ICON_ERROR;
      setTimeout(() => { triggerBtn.innerHTML = ICON_SPARKLE; triggerBtn.style.color = ''; }, 5000);
    }
  } finally {
    state.isAiTranslating = false;
  }
}

/**
 * Translate ALL visible content in the panel (posts, components, loops).
 * Respects active filters — only processes rows currently in the DOM.
 */
export async function aiTranslateVisible(triggerBtn) {
  if (state.isAiTranslating) return;

  const bridge = window.wxeBridge;
  if (!bridge || !bridge.aiConfigured) return;

  // Check visible rows — exclude rows that belong to non-Etch posts (disabled sparkles).
  const allVisibleRows = document.querySelectorAll('.wxe-component-lang-row:not(.wxe-loop-lang-row), .wxe-item-lang-row, .wxe-loop-lang-row');
  const translatableRows = Array.from(allVisibleRows).filter(r => !r.querySelector('.wxe-ai-btn--disabled'));

  if (translatableRows.length === 0) {
    // Nothing to translate with AI in the visible content.
    if (triggerBtn) {
      triggerBtn.classList.add('wxe-ai-btn--disabled');
      triggerBtn.setAttribute('data-tooltip', 'No content for AI translation');
      setTimeout(() => {
        triggerBtn.classList.remove('wxe-ai-btn--disabled');
        triggerBtn.setAttribute('data-tooltip', 'AI Translate in view');
      }, 2000);
    }
    return;
  }

  const allTranslated = translatableRows.every(r => r.dataset.status === 'translated');
  if (allTranslated && triggerBtn && triggerBtn.getAttribute('data-retranslate') !== '1') {
    enterRetranslateConfirm(triggerBtn);
    return;
  }

  const force = triggerBtn && triggerBtn.getAttribute('data-retranslate') === '1';
  if (force) clearRetranslateConfirm(triggerBtn);

  state.isAiTranslating = true;
  if (triggerBtn) triggerBtn.innerHTML = ICON_SPINNER;

  try {
    // No save here — each individual ai-translate call handles its own
    // save if needed. Saving globally doesn't make sense when translating
    // multiple posts across different content types.

    // Collect visible post/component rows grouped by effective post ID.
    // Row types: .wxe-component-lang-row (current context + components),
    //            .wxe-item-lang-row (pill content like Pages/Posts).
    // The post ID lives on the card's "translate all" button or the row's data-item-id.
    const postGroups = new Map();
    document.querySelectorAll('.wxe-component-lang-row:not(.wxe-loop-lang-row), .wxe-item-lang-row').forEach(row => {
      const card = row.closest('.wxe-component-group');
      const allBtn = card?.querySelector('[data-action="ai-translate-all"]');
      const postId = parseInt(row.dataset.itemId || allBtn?.dataset.postId || '0', 10) || bridge.currentPostId;
      const componentId = parseInt(row.dataset.componentId || allBtn?.dataset.componentId || '0', 10);
      const key = `${postId}:${componentId}`;
      if (!postGroups.has(key)) postGroups.set(key, { postId, componentId, rows: [] });
      postGroups.get(key).rows.push(row);
    });

    // Collect visible loop rows grouped by loop ID.
    const loopGroups = new Map();
    document.querySelectorAll('.wxe-loop-lang-row').forEach(row => {
      const loopId = row.dataset.loopId;
      if (!loopId) return;
      if (!loopGroups.has(loopId)) loopGroups.set(loopId, []);
      loopGroups.get(loopId).push(row);
    });

    // Skip groups where all rows are already translated (unless force).
    if (!force) {
      for (const [key, group] of postGroups) {
        if (group.rows.every(r => r.dataset.status === 'translated')) postGroups.delete(key);
      }
      for (const [key, rows] of loopGroups) {
        if (rows.every(r => r.dataset.status === 'translated')) loopGroups.delete(key);
      }
    }

    // Mark remaining as translating — skip rows already translated.
    for (const g of postGroups.values()) g.rows.forEach(r => { if (r.dataset.status !== 'translated') setRowTranslating(r); });
    for (const rows of loopGroups.values()) rows.forEach(r => { if (r.dataset.status !== 'translated') setRowTranslating(r); });

    // Translate each visible row individually — respects language filters.
    for (const { postId, componentId, rows } of postGroups.values()) {
      for (const row of rows) {
        if (row.dataset.status === 'translated' && !force) continue;
        const langCode = row.dataset.langCode;
        if (!langCode) continue;
        try {
          const resp = await fetch(`${bridge.restUrl}ai-translate`, {
            method: 'POST',
            headers: { 'X-WP-Nonce': bridge.restNonce, 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ post_id: postId, target_lang: langCode, component_id: componentId, force }),
          });
          if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
          const data = await resp.json();
          if (data.translated_count > 0) setRowSuccess(row);
          else setRowAlreadyDone(row);
        } catch (err) {
          setRowError(row, err instanceof Error ? err.message : 'Failed.');
        }
      }
    }

    // Translate each visible loop row individually.
    for (const [loopId, rows] of loopGroups) {
      for (const row of rows) {
        if (row.dataset.status === 'translated' && !force) continue;
        const langCode = row.dataset.langCode;
        if (!langCode) continue;
        try {
          const resp = await fetch(`${bridge.restUrl}ai-translate-loop`, {
            method: 'POST',
            headers: { 'X-WP-Nonce': bridge.restNonce, 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ loop_id: loopId, target_lang: langCode, force }),
          });
          if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
          const data = await resp.json();
          if (data.translated_count > 0) setRowSuccess(row);
          else setRowAlreadyDone(row);
        } catch (err) {
          setRowError(row, err instanceof Error ? err.message : 'Failed.');
        }
      }
    }

    document.querySelectorAll('.wxe-row--translating').forEach(r => setRowAlreadyDone(r));
    refreshLanguagesStatus();

    if (triggerBtn) {
      triggerBtn.innerHTML = ICON_CHECK;
      triggerBtn.style.color = 'var(--wxe-status-translated)';
      setTimeout(() => { triggerBtn.innerHTML = ICON_SPARKLE; triggerBtn.style.color = ''; }, 3000);
    }
  } catch (err) {
    document.querySelectorAll('.wxe-row--translating').forEach(r => setRowError(r, err instanceof Error ? err.message : 'Failed.'));
    if (triggerBtn) {
      triggerBtn.innerHTML = ICON_ERROR;
      setTimeout(() => { triggerBtn.innerHTML = ICON_SPARKLE; triggerBtn.style.color = ''; }, 5000);
    }
  } finally {
    state.isAiTranslating = false;
  }
}
