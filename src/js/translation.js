import { state, RETURN_STATE_KEY } from './state.js';
import { escapeHtml, msg, wait } from './utils.js';
import { updatePillVisuals } from './rendering.js';

let _buildPanel = null;
let _openPanel = null;

export function setPanelFunctions(bp, op) {
  _buildPanel = bp;
  _openPanel = op;
}

function humanHttpError(status, m) {
  if (status === 403) return m.errorForbidden || 'Permission denied.';
  if (status === 404) return m.errorNotFound || 'Content not found.';
  if (status >= 500) return m.errorServer || 'Server error. Try again.';
  return (m.errorGeneric || 'Error %s. Try again.').replace('%s', status);
}


export async function openTranslation(lang, componentId = null) {
  if (state.isOpeningTranslation) {
    return;
  }

  state.isOpeningTranslation = true;
  setLanguageButtonsDisabled(true);

  // Update selected state in sidebar.
  document
    .querySelectorAll(".wxe-lang-item")
    .forEach((el) =>
      el.classList.toggle(
        "content-hub-list__item--selected",
        el.dataset.langCode === lang.code,
      ),
    );

  const m = msg();
  setStatusLoading(m.saving || 'Saving\u2026');

  const { currentPostId } = window.wxeBridge;

  try {
    const stillSavingTimer = setTimeout(() => {
      setStatusLoading(m.savingSlow || 'Still saving\u2026');
    }, 1500);

    const saved = await saveBeforeOpeningAte();
    clearTimeout(stillSavingTimer);

    if (!saved) {
      throw new Error(m.saveFailed || 'Save failed.');
    }

    setStatusLoading((m.preparing || 'Preparing %s\u2026').replace('%s', escapeHtml(lang.native_name)));

    const MAX_RETRIES = 5;
    const BASE_DELAY_MS = 500;

    let ateUrl = null;

    for (let attempt = 0; attempt <= MAX_RETRIES; attempt++) {
      let url = `${window.wxeBridge.restUrl}translate-url?post_id=${currentPostId}&target_lang=${encodeURIComponent(lang.code)}`;
      if (componentId) {
        url += `&component_id=${componentId}`;
      }

      const response = await fetch(url, {
        headers: { "X-WP-Nonce": window.wxeBridge.restNonce },
        credentials: "same-origin",
      });

      if (!response.ok) throw new Error(humanHttpError(response.status, m));

      let data;
      try {
        // Use text() + manual parse to handle PHP warnings/notices that
        // some hosts print before the JSON body (e.g. Deprecated notices).
        const text = await response.text();
        const jsonStart = text.indexOf('{');
        data = JSON.parse(jsonStart > 0 ? text.slice(jsonStart) : text);
      } catch (parseErr) {
        if (attempt < MAX_RETRIES) {
          setStatusLoading(m.waitingAte || 'Waiting for editor\u2026');
          await new Promise((r) => setTimeout(r, BASE_DELAY_MS * Math.pow(2, attempt)));
          continue;
        }
        throw new Error(m.ateTimeout || 'Editor timed out.');
      }

      if (data?.url) {
        ateUrl = data.url;
        break;
      }

      if (data?.status === "pending" && attempt < MAX_RETRIES) {
        setStatusLoading(m.waitingAte || 'Waiting for editor\u2026');
        await new Promise((r) => setTimeout(r, BASE_DELAY_MS * Math.pow(2, attempt)));
        continue;
      }

      throw new Error(m.ateTimeout || 'Editor timed out.');
    }

    if (!ateUrl) {
      throw new Error(m.ateTimeout || 'Editor timed out.');
    }

    setStatusInfo(m.openingEditor || 'Opening editor.');

    persistReturnState(lang.code);

    // Navigate current window to ATE, same as native WPML translation link.
    window.location.href = ateUrl;

    // Safety net: if navigation didn't happen (ATE URL failed, redirected back,
    // or empty page edge case), show an error after 8 seconds so the user
    // isn't stuck on "Opening editor." forever.
    setTimeout(() => {
      if (state.isOpeningTranslation) {
        setStatusError(m.ateTimeout || "Editor didn\u2019t respond in time. Try again.");
        state.isOpeningTranslation = false;
        setLanguageButtonsDisabled(false);
      }
    }, 8000);
  } catch (err) {
    const message =
      err instanceof Error && err.message
        ? err.message
        : (msg().saveFailed || 'Save failed.');
    setStatusError(message);
    state.isOpeningTranslation = false;
    setLanguageButtonsDisabled(false);

    // Hide overlay after 6 seconds on error
    setTimeout(() => {
      const overlay = document.getElementById("wxe-status-overlay");
      if (overlay) overlay.style.display = "none";
    }, 6000);
  }
}

export async function saveBeforeOpeningAte() {
  try {
    const savedByApi = await triggerEtchSaveViaApi();
    if (savedByApi) {
      return true;
    }
  } catch (e) {
    return false;
  }

  const saveButton = findEtchSaveButton();
  if (!saveButton) {
    return false;
  }

  if (saveButton.disabled) {
    const busy =
      saveButton.getAttribute("aria-busy") === "true" ||
      saveButton.classList.contains("is-loading");
    return !busy;
  }

  saveButton.click();

  const becameBusy = await waitForButtonToBecomeBusy(saveButton);
  if (becameBusy) {
    const completed = await waitForSaveButtonReady(saveButton);
    if (!completed) {
      return false;
    }
  } else {
    // Some Etch versions do not expose a visible busy state.
    await wait(1200);
  }

  await wait(300);
  return true;
}

export async function triggerEtchSaveViaApi() {
  const candidates = [
    () => window.etchControls?.builder?.save?.(),
    () => window.etchControls?.builder?.actions?.save?.(),
    () => window.etchControls?.builder?.store?.save?.(),
    () => window.etchControls?.save?.(),
  ];

  for (const getResult of candidates) {
    let result;
    try {
      result = getResult();
    } catch (e) {
      continue;
    }

    if (typeof result === "undefined") {
      continue;
    }

    if (result === false) {
      continue;
    }

    if (result && typeof result.then === "function") {
      await Promise.race([
        result,
        new Promise((_, reject) =>
          window.setTimeout(() => reject(new Error(msg().saveTimeout || "Save timeout")), 20000),
        ),
      ]);
    }

    return true;
  }

  return false;
}

export function findEtchSaveButton() {
  // Prefer stable Etch class selectors scoped to the builder container.
  const container = document.querySelector('.etch-app, .etch-builder') || document;
  const byClass = container.querySelector('button.save-button, button.save-component-button');
  if (byClass) return byClass;

  // Fallback: match by text content within the builder.
  const buttons = Array.from(container.querySelectorAll('button'));
  for (const btn of buttons) {
    const label = (btn.textContent || '').trim().toLowerCase();
    if (label === 'save' || label === 'saved') {
      return btn;
    }
  }
  return null;
}

export async function waitForSaveButtonReady(btn, timeoutMs = 15000) {
  const start = Date.now();
  let wasBusy = false;

  while (Date.now() - start < timeoutMs) {
    const busy =
      btn.disabled ||
      btn.getAttribute("aria-busy") === "true" ||
      btn.classList.contains("is-loading");
    if (busy) {
      wasBusy = true;
    }
    if (wasBusy && !busy) {
      return true;
    }
    await wait(150);
  }

  return false;
}

export async function waitForButtonToBecomeBusy(btn, timeoutMs = 2000) {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    const busy =
      btn.disabled ||
      btn.getAttribute("aria-busy") === "true" ||
      btn.classList.contains("is-loading");
    if (busy) {
      return true;
    }
    await wait(100);
  }
  return false;
}

export function setStatusLoading(message) {
  const overlay = document.getElementById("wxe-status-overlay");
  const content = document.getElementById("wxe-status-overlay-content");
  if (!overlay || !content) return;

  overlay.style.display = "flex";
  content.innerHTML = `
		<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="wxe-spin">
			<path d="M21 12a9 9 0 1 1-6.219-8.56"/>
		</svg>
		${escapeHtml(message)}
	`;
}

export function setStatusError(message) {
  const overlay = document.getElementById("wxe-status-overlay");
  const content = document.getElementById("wxe-status-overlay-content");
  if (!overlay || !content) return;

  overlay.style.display = "flex";
  content.innerHTML = `
		<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--e-danger, #f26060);">
			<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
		</svg>
		${escapeHtml(message)}
	`;
}

export function setStatusInfo(message) {
  const overlay = document.getElementById("wxe-status-overlay");
  const content = document.getElementById("wxe-status-overlay-content");
  if (!overlay || !content) return;

  overlay.style.display = "flex";
  content.innerHTML = `
		<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--e-success, #67d992);">
			<polyline points="20 6 9 17 4 12"/>
		</svg>
		${escapeHtml(message)}
	`;
}

export function setLanguageButtonsDisabled(disabled) {
  window._wxeIsOpening = disabled;
  document
    .querySelectorAll(".wxe-component-lang-row, .wxe-item-lang-row")
    .forEach((el) => {
      el.style.opacity = disabled ? "0.5" : "";
      el.style.pointerEvents = disabled ? "none" : "";
    });
}

export function persistReturnState(langCode) {
  const payload = {
    langCode,
    postId: window.wxeBridge?.currentPostId,
    at: Date.now(),
    activePill: state.activePill,
    filterByCurrentContext: state.filterByCurrentContext,
    selectedLanguageFilter: [...state.selectedLanguageFilter],
    selectedStatusFilter: [...state.selectedStatusFilter],
  };

  try {
    window.sessionStorage.setItem(RETURN_STATE_KEY, JSON.stringify(payload));
  } catch (e) {
    // Ignore storage issues and continue with query-parameter restoration.
  }
}

export function clearReturnState() {
  try {
    window.sessionStorage.removeItem(RETURN_STATE_KEY);
  } catch (e) {
    // Ignore.
  }
}

export function restoreAfterAteReturn() {
  const urlParams = new URLSearchParams(window.location.search);
  let isReturning = urlParams.has("zs_wxe_return");
  let lang = urlParams.get("zs_wxe_lang");
  let storedState = null;

  // Read stored state (must happen before clearReturnState).
  try {
    storedState = JSON.parse(
      window.sessionStorage.getItem(RETURN_STATE_KEY) || "null",
    );
  } catch (e) {
    // Ignore.
  }

  // Fallback: check sessionStorage in case URL params were stripped.
  if (!isReturning && storedState) {
    if (
      storedState.postId === window.wxeBridge?.currentPostId &&
      Date.now() - storedState.at < 5 * 60 * 1000
    ) {
      isReturning = true;
      lang = lang || storedState.langCode;
    }
  }

  if (!isReturning) {
    return;
  }

  clearReturnState();

  // Clean URL without page reload — remove our params and ATE's return params.
  const cleaned = window.location.search
    .replace(/[?&](?:zs_wxe_return|zs_wxe_lang|complete_no_changes|ate_original_id|ate_job_id|complete|ate_status)[^&]*/g, "")
    .replace(/^&/, "?")
    .replace(/^\?$/, "");
  if (cleaned !== window.location.search) {
    window.history.replaceState({}, document.title, window.location.pathname + cleaned || window.location.pathname);
  }

  // Build panel first (resets all filter state), then restore from ATE return.
  _buildPanel();

  // Restore filter state from before ATE navigation (after buildPanel reset).
  if (storedState) {
    if (storedState.activePill != null) {
      state.activePill = storedState.activePill;
      state.filterByCurrentContext = false;
    }
    if (storedState.filterByCurrentContext === true && storedState.activePill == null) {
      state.filterByCurrentContext = true;
    }
    if (Array.isArray(storedState.selectedLanguageFilter) && storedState.selectedLanguageFilter.length) {
      state.selectedLanguageFilter = new Set(storedState.selectedLanguageFilter);
    }
    if (Array.isArray(storedState.selectedStatusFilter) && storedState.selectedStatusFilter.length) {
      state.selectedStatusFilter = new Set(storedState.selectedStatusFilter);
    }

    // Update visuals to match restored state.
    updatePillVisuals();
    const chip = document.getElementById("wxe-context-chip");
    if (chip) chip.classList.toggle("wxe-pill--active", state.filterByCurrentContext);

    // Update sidebar filter visuals.
    document.querySelectorAll(".wxe-lang-item").forEach(el => {
      el.classList.toggle("wxe-pill--active", state.selectedLanguageFilter.has(el.dataset.langCode));
    });
    document.querySelectorAll(".wxe-status-filter").forEach(el => {
      el.classList.toggle("wxe-status-filter--active", state.selectedStatusFilter.has(el.dataset.status));
    });

  }

  if (lang) {
    state.pendingReturnState = lang;
  }

  // Delay opening to ensure Etch and REST are fully ready.
  // openPanel() calls refreshLanguagesStatus() which handles rendering.
  setTimeout(() => {
    _openPanel();
  }, 500);
}

export function applyPendingReturnStateToPanel() {
  if (!state.pendingReturnState) {
    return;
  }

  const langCode = state.pendingReturnState;
  if (langCode) {
    document.querySelectorAll(".wxe-lang-item").forEach((el) => {
      el.classList.toggle(
        "content-hub-list__item--selected",
        el.dataset.langCode === langCode,
      );
    });
  }

  state.pendingReturnState = null;
}
