import { state } from './state.js';
import { buildPageStatus, buildComponentsList, buildItemCards, buildSectionHeader, buildAllContentHtml, buildEmptyState, buildNotTranslatableState, buildSkeleton, buildLoopCards, buildLoopsOnThisPage } from './builders.js';
import { fetchPillData } from './data.js';
import { checkLock, msg, escapeHtml } from './utils.js';
import { filterItems } from './filters.js';
import { updateGlobalAiButtonState } from './aiTranslate.js';


export function switchPill(pillId) {
  if (checkLock('switchPill', pillId)) return;

  const contentEl = document.getElementById("wxe-pill-content");

  // "on-this-page" is managed by the context chip, not the pill bar.
  if (pillId === 'on-this-page') {
    state.filterByCurrentContext = !state.filterByCurrentContext;
    if (state.filterByCurrentContext && state.activePill !== null) {
      state.activePill = null;
    }
    const chip = document.getElementById("wxe-context-chip");
    if (chip) chip.classList.toggle("wxe-pill--active", state.filterByCurrentContext);
    updatePillVisuals();
    renderPillContent();
    return;
  }

  // Save scroll position of current pill.
  if (state.activePill !== null && contentEl) {
    state.scrollPositions[state.activePill] = contentEl.scrollTop;
  }

  // Toggle: clicking active pill deselects -> All Content.
  if (state.activePill === pillId) {
    state.activePill = null;
  } else {
    state.activePill = pillId;
    // Deactivate Current Context when selecting a specific pill.
    if (state.filterByCurrentContext) {
      state.filterByCurrentContext = false;
      const chip = document.getElementById("wxe-context-chip");
      if (chip) chip.classList.remove("wxe-pill--active");
    }
  }

  updatePillVisuals();
  renderPillContent();
}

export function updatePillVisuals() {
  document.querySelectorAll(".wxe-pill").forEach((btn) => {
    btn.classList.toggle("wxe-pill--active", btn.dataset.pill === state.activePill);
  });
}

export function getPillLabel(pillId) {
  const pills = window.wxeBridge.contentTypePills || [];
  const pill = pills.find((p) => p.id === pillId);
  return pill ? pill.label : pillId;
}


export function renderPillContent() {
  const contentEl = document.getElementById("wxe-pill-content");
  if (!contentEl) return;

  // Fade out, swap, fade in.
  contentEl.classList.remove("wxe-content-fade--in");
  contentEl.classList.add("wxe-content-fade--out");

  setTimeout(() => {
    if (state.activePill === null) {
      renderAllContent(contentEl);
    } else {
      renderTypePill(state.activePill, contentEl);
    }

    // Language filters don't apply to JSON loops — they have no per-language
    // data. Disable the section visually so users aren't misled.
    const langClearBtn = document.getElementById('wxe-lang-clear-btn');
    const langSection = langClearBtn?.closest('.content-hub-list');
    if (langSection) {
      langSection.classList.toggle('wxe-section--disabled', state.activePill === 'json-loops');
    }

    contentEl.classList.remove("wxe-content-fade--out");
    contentEl.classList.add("wxe-content-fade--in");

    // Restore scroll.
    if (state.activePill !== null && state.scrollPositions[state.activePill] !== undefined) {
      contentEl.scrollTop = state.scrollPositions[state.activePill];
    } else {
      contentEl.scrollTop = 0;
    }

    // Update global AI button based on visible translatable content.
    updateGlobalAiButtonState();
  }, 100);
}

export function renderOnThisPage(container) {
  // When searching, render all content globally instead of just current page
  if (state.searchQuery) {
    renderAllContent(container);
    return;
  }

  // buildPageStatus() handles non-translatable CPTs internally.
  container.innerHTML = buildPageStatus() + buildComponentsList() + buildLoopsOnThisPage();
  if (!container.innerHTML.trim()) {
    container.innerHTML = buildEmptyState(msg().noContent || "No content.");
  }
}

export function renderTypePill(pillId, container) {
  // When searching, render all content globally instead of just this pill
  if (state.searchQuery) {
    renderAllContent(container);
    return;
  }

  if (pillId === 'on-this-page') {
    renderOnThisPage(container);
    return;
  }

  if (pillId === 'json-loops') {
    const loops = window.wxeBridge.jsonLoops || [];
    container.innerHTML = buildLoopCards(loops);
    return;
  }

  // Non-translatable pill: show informational message.
  const pillDef = (window.wxeBridge.contentTypePills || []).find(p => p.id === pillId);
  if (pillDef && pillDef.notTranslatable) {
    container.innerHTML = buildNotTranslatableState(pillDef.label);
    return;
  }

  if (state.pillCache[pillId] === null) {
    container.innerHTML = `<div class="wxe-empty-state"><p>${escapeHtml(msg().couldNotLoad || 'Could not load data.')} <button class="wxe-retry-link" data-retry-pill="${pillId}">${escapeHtml(msg().retry || 'Retry')}</button></p></div>`;
    return;
  }

  if (state.pillCache[pillId]) {
    renderItemsList(container, state.pillCache[pillId], getPillLabel(pillId), pillId);
    return;
  }

  // Not cached yet -- show skeleton and fetch.
  container.innerHTML = buildSkeleton();
  fetchPillData(pillId).then(() => {
    // Only render if still on this pill. Re-query container in case DOM was rebuilt.
    const currentContainer = document.getElementById("wxe-pill-content");
    if (state.activePill === pillId && currentContainer) {
      if (state.pillCache[pillId]) {
        renderItemsList(currentContainer, state.pillCache[pillId], getPillLabel(pillId), pillId);
      } else {
        currentContainer.innerHTML = `<div class="wxe-empty-state"><p>${escapeHtml(msg().couldNotLoad || 'Could not load data.')} <button class="wxe-retry-link" data-retry-pill="${pillId}">${escapeHtml(msg().retry || 'Retry')}</button></p></div>`;
      }
      updateGlobalAiButtonState();
    }
  });
}

export async function renderAllContent(container) {
  const pills = (window.wxeBridge.contentTypePills || []).filter(
    (p) => p.id !== "on-this-page",
  );

  // Identify pills that need fetching.
  const uncached = pills.filter(p => !state.pillCache[p.id] && state.pillCache[p.id] !== null);

  // Render what we have now.
  const html = buildAllContentHtml(pills);
  container.innerHTML = html || (uncached.length ? buildSkeleton() : buildEmptyState(msg().noContent || "No content."));

  // Fetch uncached in parallel, re-rendering as data arrives.
  if (uncached.length) {
    const promises = uncached.map((pill) =>
      fetchPillData(pill.id).then(() => {
        const liveContainer = document.getElementById("wxe-pill-content") || container;
        if (state.searchQuery || state.activePill === null) {
          liveContainer.innerHTML = buildAllContentHtml(pills)
            || buildEmptyState(msg().noContent || "No content.");
        }
      }),
    );
    await Promise.all(promises);
  }
}

export function renderItemsList(container, items, label, postTypeHint) {
  const filtered = filterItems(items, postTypeHint);
  if (!filtered.length) {
    container.innerHTML = buildEmptyState(msg().noContent || "No content.");
    return;
  }
  const cardsHtml = buildItemCards(filtered, postTypeHint);
  if (!cardsHtml) {
    container.innerHTML = buildEmptyState(msg().noContent || "No content.");
    return;
  }
  container.innerHTML = `<div class="wxe-section">${buildSectionHeader(label)}${cardsHtml}</div>`;
}
