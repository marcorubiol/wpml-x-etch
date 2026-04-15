import { STATUS_PRIORITY, state } from './state.js';
import { msg, isPostTranslatable } from './utils.js';

export function worstStatus(statuses) {
  let worst = "translated";
  let worstP = STATUS_PRIORITY[worst];
  for (const s of statuses) {
    const p = STATUS_PRIORITY[s] ?? 3;
    if (p < worstP) { worst = s; worstP = p; }
  }
  return worst;
}

export function statusLabel(status) {
  const m = msg();
  if (status === 'not_translatable') return m.statusNotTranslatable || 'Not Translatable';
  if (status === 'translated')       return m.statusComplete        || 'Complete';
  if (status === 'needs_update')     return m.statusNeedsUpdate     || 'Needs Update';
  if (status === 'in_progress')      return m.statusInProgress      || 'In Progress';
  if (status === 'waiting')          return m.statusWaiting         || 'Needs Translation';
  return m.statusNotTranslated || 'Not Translated';
}

export function statusToBadgeClass(status) {
  if (status === 'not_translatable') return 'muted';
  if (status === 'translated')       return 'success';
  if (status === 'needs_update')     return 'warning';
  if (status === 'in_progress')      return 'info';
  if (status === 'waiting')          return 'waiting';
  return 'danger';
}

export function computeAggregateDotState(languages) {
  const isArray = Array.isArray(languages);
  const statuses = isArray
    ? languages.map(l => l.status)
    : Object.values(languages).filter((l) => !l.is_original).map((l) => l.status);

  if (statuses.length === 0) return null;
  const worst = worstStatus(statuses);
  if (worst === "translated") return "complete";
  if (worst === "not_translatable") return "not_translatable";
  return worst;
}

// Toolbar dot, sidebar badge, and Current Context pill dot all call this.
export function collectComponentStatuses() {
  const statuses = [];
  for (const comp of window.wxeBridge.components || []) {
    for (const lang of Object.values(comp.languages)) {
      if (!lang.is_original) statuses.push(lang.status);
    }
  }
  return statuses;
}

export function getCurrentContextState() {
  if (!window.wxeBridge) return null;

  if (isPostTranslatable()) {
    // Translatable page: use combinedStatus (already merges page + component statuses).
    const combined = window.wxeBridge.combinedStatus;
    if (combined) {
      const statuses = Object.values(combined).filter(l => !l.is_original).map(l => l.status);
      if (statuses.length === 0) return null;
      const worst = worstStatus(statuses);
      return worst === "translated" ? "complete" : worst;
    }

    // Fallback: combinedStatus not yet calculated -- merge manually.
    const langStatuses = Object.values(window.wxeBridge.languages || {})
      .filter(l => !l.is_original).map(l => l.status);
    const compStatuses = collectComponentStatuses();
    const all = [...langStatuses, ...compStatuses];
    if (all.length === 0) return null;
    const worst = worstStatus(all);
    return worst === "translated" ? "complete" : worst;
  }

  // Non-translatable page: only component statuses matter.
  const compStatuses = collectComponentStatuses();
  if (compStatuses.length > 0) {
    const worst = worstStatus(compStatuses);
    return worst === "translated" ? "complete" : worst;
  }
  return "not_translatable";
}


export function getContextLoopStatuses() {
  const all = window.wxeBridge.loopStatuses || {};
  const jsonLoops = window.wxeBridge.jsonLoops || [];
  const onPage = new Set(jsonLoops.filter(l => l.onThisPage).map(l => l.name));
  const filtered = {};
  for (const [name, statuses] of Object.entries(all)) {
    if (onPage.has(name)) filtered[name] = statuses;
  }
  return filtered;
}


export function calculateCombinedStatus(pageLanguages, components, loopStatuses) {
  const combined = {};
  const loops = loopStatuses || getContextLoopStatuses();

  for (const [code, lang] of Object.entries(pageLanguages)) {
    const statuses = [lang.status];

    for (const component of components) {
      const componentLang = component.languages[code];
      if (!componentLang || componentLang.is_original) {
        continue;
      }

      statuses.push(componentLang.status);
    }

    for (const loopStatus of Object.values(loops)) {
      if (loopStatus[code]) {
        statuses.push(loopStatus[code]);
      }
    }

    combined[code] = {
      code: lang.code,
      native_name: lang.native_name,
      flag_url: lang.flag_url,
      is_original: lang.is_original,
      status: worstStatus(statuses),
    };
  }

  return combined;
}

/**
 * Updates all three status indicators (toolbar dot, sidebar badge, CC pill dot)
 * from the single source of truth: getCurrentContextState().
 * Null-guards each element -- safe to call whether panel is open or closed.
 */
export function updateAllStatusIndicators() {
  const ctxState = getCurrentContextState();
  const status = ctxState === "complete" ? "translated" : (ctxState || "not_translated");

  // 1. Toolbar dot.
  const dot = document.getElementById("wxe-status-dot");
  if (dot) {
    if (ctxState) { dot.setAttribute("data-dot", ctxState); }
    else { dot.removeAttribute("data-dot"); }
  }

  // 2. Sidebar badge — hidden when state is unknown (null).
  const badge = document.getElementById("wxe-sidebar-badge");
  if (badge) {
    if (ctxState) {
      badge.className = `wxe-badge wxe-badge--${statusToBadgeClass(status)}`;
      badge.textContent = statusLabel(status);
      badge.style.display = "";
    } else {
      badge.style.display = "none";
    }
  }

  // 3. Current Context pill dot.
  const pillDot = document.getElementById("wxe-pill-dot-on-this-page");
  if (pillDot) {
    pillDot.setAttribute("data-dot", ctxState || "empty");
  }
}

// Alias for call sites that only need the toolbar dot (e.g. checkPostIdChange).
export const updateStatusDot = updateAllStatusIndicators;

export function updatePillDots() {

  const pills = window.wxeBridge.contentTypePills || [];
  for (const p of pills) {
    if (p.id === "on-this-page") continue;
    const dot = document.getElementById(`wxe-pill-dot-${p.id}`);
    if (!dot) continue;

    if (p.notTranslatable && !p.locked) {
      dot.setAttribute("data-dot", "not_translatable");
      continue;
    }

    // JSON Loops pill: derive status from loopStatuses.
    if (p.id === 'json-loops') {
      const loops = window.wxeBridge.loopStatuses || {};
      const allStatuses = [];
      for (const loopStatus of Object.values(loops)) {
        for (const s of Object.values(loopStatus)) {
          allStatuses.push(s);
        }
      }
      if (allStatuses.length) {
        dot.setAttribute("data-dot", worstStatus(allStatuses) || "empty");
      }
      continue;
    }

    if (state.pillCache[p.id] && state.pillCache[p.id].length > 0) {
      let allLanguages = [];
      for (const item of state.pillCache[p.id]) {
        Object.values(item.languages).forEach(l => {
          if (!l.is_original) allLanguages.push(l);
        });
      }
      const dotState = computeAggregateDotState(allLanguages);
      dot.setAttribute("data-dot", dotState || "empty");
    } else if (state.pillStatuses[p.id]) {
      // Use stored status from lazyFetchPillStatuses if cache is empty
      dot.setAttribute("data-dot", state.pillStatuses[p.id]);
    } else {
      dot.setAttribute("data-dot", "empty");
    }
  }
}

export function injectStatusDot() {
  // Etch renders the settings-bar buttons asynchronously via Svelte/signals,
  // so the button may not exist yet when initPanel() runs.
  // Strategy: try several selectors, and if nothing found, watch the DOM
  // with a MutationObserver and retry until we find it (or give up).

  function findBtn() {
    // 1. Etch may render a <li> or wrapper with the registered id.
    const byWrapperId = document.querySelector("#wxe-translations button");
    if (byWrapperId) return byWrapperId;

    // 2. Etch may set a data-id attribute on the <li> or the button itself.
    const byDataId = document.querySelector(
      '[data-id="wxe-translations"] button, button[data-id="wxe-translations"]',
    );
    if (byDataId) return byDataId;

    // 3. Fallback: the button that contains our specific icon class.
    const byIcon = document
      .querySelector(".iconify--vscode-icons")
      ?.closest("button.etch-builder-button");
    if (byIcon) return byIcon;

    return null;
  }

  function attachDot(btn) {
    // Avoid double-injection.
    if (document.getElementById("wxe-status-dot")) return;

    // Status dot attached silently

    // .etch-builder-button already has position:relative in Etch's own CSS,
    // so we can place the dot directly inside it without an extra wrapper.
    const dot = document.createElement("span");
    dot.id = "wxe-status-dot";
    btn.appendChild(dot);
    // No initial color — first REST refresh will set the correct state.
  }

  const btn = findBtn();
  if (btn) {
    attachDot(btn);
    return;
  }

  // Button not in DOM yet -- watch for it.
  // We observe document.body because the Etch settings bar is rendered
  // asynchronously by Svelte and its container element is not reliably
  // available at this point. A narrower target would be preferable for
  // performance, but risks missing the button if the container changes
  // between Etch navigations. This observer self-destructs after finding
  // the button or after 500 mutations, so the cost is bounded.
  let watchAttempts = 0;
  const observer = new MutationObserver(() => {
    watchAttempts++;
    const found = findBtn();
    if (found) {
      observer.disconnect();
      attachDot(found);
    } else if (watchAttempts > 500) {
      observer.disconnect();
    }
  });
  observer.observe(document.body, { childList: true, subtree: true });
}
