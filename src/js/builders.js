import { state, ICON_ARROW, ICON_SPARKLE } from "./state.js";
import { escapeHtml, msg, isPostTranslatable } from "./utils.js";

const EXTERNAL_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';
import { filterItems } from "./filters.js";
import { worstStatus } from "./status.js";

function aiBtn() {
  const b = window.wxeBridge;
  return b && b.aiAccess && b.aiConfigured;
}

function aiAccessible() {
  return window.wxeBridge?.aiAccess;
}

/** AI is not accessible but should show locked sparkles (supporter mode). */
function aiLocked() {
  if (aiAccessible()) return false;
  const mode = window.wxeBridge?.lockingMode || 'supporter';
  return mode === 'supporter';
}

const ICON_LOCK_MINI = '<svg class="wxe-ai-btn-lock" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>';

function sparkleBtn(langName) {
  if (aiLocked()) {
    const tooltip = 'Pro license required';
    return `<button type="button" class="wxe-ai-btn wxe-ai-btn--locked" data-action="ai-locked" data-tooltip="${tooltip}"><span class="wxe-ai-btn-icon">${ICON_SPARKLE}</span><span class="wxe-ai-btn-icon wxe-ai-btn-icon--lock">${ICON_LOCK_MINI}</span></button>`;
  }
  if (!aiAccessible()) return '';
  const dim = !aiBtn() ? ' wxe-ai-btn--dim' : '';
  const tooltip = langName ? `AI Translate to ${escapeHtml(langName)}` : 'AI Translate';
  return `<button type="button" class="wxe-ai-btn${dim}" data-action="ai-translate" data-tooltip="${tooltip}">${ICON_SPARKLE}</button>`;
}

function translateAllBtn(postId, componentId) {
  if (aiLocked()) {
    const tooltip = 'Pro license required';
    return `<button type="button" class="wxe-ai-btn wxe-ai-btn--locked" data-action="ai-locked" data-tooltip="${tooltip}"><span class="wxe-ai-btn-icon">${ICON_SPARKLE}</span><span class="wxe-ai-btn-icon wxe-ai-btn-icon--lock">${ICON_LOCK_MINI}</span></button>`;
  }
  if (!aiAccessible()) return '';
  const dim = !aiBtn() ? ' wxe-ai-btn--dim' : '';
  const m = msg();
  return `<button type="button" class="wxe-ai-btn wxe-ai-translate-all${dim}" data-action="ai-translate-all" data-post-id="${postId}"${componentId ? ` data-component-id="${componentId}"` : ''} data-tooltip="${escapeHtml(m.aiTranslateAllLangs || 'AI Translate all languages')}">${ICON_SPARKLE}</button>`;
}


export function buildSectionHeader(title, subtitle = '', subtitleUrl = '') {
  let html = `<div class="wxe-section-header"><h3>${escapeHtml(title)}</h3>`;
  if (subtitle && subtitleUrl) {
    html += `<a href="${escapeHtml(subtitleUrl)}" target="_blank" class="wxe-section-subtitle wxe-section-subtitle--link">${escapeHtml(subtitle)} ${EXTERNAL_ICON}</a>`;
  } else if (subtitle) {
    html += `<p class="wxe-section-subtitle">${escapeHtml(subtitle)}</p>`;
  }
  html += `</div>`;
  return html;
}


export function buildPageStatus() {
  if (!isPostTranslatable()) {
    const label =
      window.wxeBridge.postTypeLabel || msg().pageFallback || "Page";
    return buildNotTranslatableState(label);
  }

  const { languages, postTitle, postTypeLabel } = window.wxeBridge;

  let otherLangs = Object.entries(languages).filter(
    ([, lang]) => !lang.is_original,
  );

  if (state.selectedLanguageFilter.size) {
    otherLangs = otherLangs.filter(([code]) =>
      state.selectedLanguageFilter.has(code),
    );
  }

  if (state.selectedStatusFilter.size) {
    otherLangs = otherLangs.filter(([, lang]) =>
      state.selectedStatusFilter.has(lang.status),
    );
  }

  if (otherLangs.length === 0) return "";

  const currentId = window.wxeBridge.currentPostId;
  let html = `<div class="wxe-section">${buildSectionHeader(postTypeLabel || msg().pageFallback || "Page")}<div class="wxe-component-group"><div class="wxe-component-header-row"><a class="wxe-component-header" href="${escapeHtml(window.wxeBridge.etchUrl || '#')}">${escapeHtml(postTitle)}</a>${translateAllBtn(currentId, 0)}</div>`;

  for (const [code, lang] of otherLangs) {
    html += `
      <div class="wxe-component-lang-row" data-lang-code="${code}" data-status="${lang.status}">
        <div class="wxe-component-lang-info">
          <img src="${escapeHtml(lang.flag_url)}" alt="${escapeHtml(lang.native_name)}" width="16" height="11" class="wxe-flag">
          <span class="wxe-component-lang-name">${escapeHtml(lang.native_name)}</span>
        </div>
        <div class="wxe-row-actions">${sparkleBtn()}<button type="button" class="wxe-ate-btn" data-action="open-ate" data-tooltip="Translate in WPML">${ICON_ARROW}</button></div>
      </div>
    `;
  }

  html += `</div></div>`;
  return html;
}


export function buildComponentsList() {
  // When searching globally, skip this to avoid duplicates with wp_block pill data
  if (state.searchQuery) return "";

  const { components } = window.wxeBridge;

  if (!components || components.length === 0) return "";

  let cardsHtml = "";

  for (const component of components) {
    let componentLangs = Object.entries(component.languages).filter(
      ([, lang]) => !lang.is_original,
    );

    if (state.selectedLanguageFilter.size) {
      componentLangs = componentLangs.filter(([code]) =>
        state.selectedLanguageFilter.has(code),
      );
    }

    if (state.selectedStatusFilter.size) {
      componentLangs = componentLangs.filter(([, lang]) =>
        state.selectedStatusFilter.has(lang.status),
      );
    }

    if (componentLangs.length === 0) continue;

    cardsHtml += `<div class="wxe-component-group"><div class="wxe-component-header-row"><a class="wxe-component-header" href="${escapeHtml(component.etch_url || '#')}">${escapeHtml(component.title)}</a>${translateAllBtn(component.id, component.id)}</div>`;

    for (const [code, lang] of componentLangs) {
      cardsHtml += `
        <div class="wxe-component-lang-row" data-component-id="${component.id}" data-lang-code="${code}" data-status="${lang.status}">
          <div class="wxe-component-lang-info">
            <img src="${escapeHtml(lang.flag_url)}" alt="${escapeHtml(lang.native_name)}" width="16" height="11" class="wxe-flag">
            <span class="wxe-component-lang-name">${escapeHtml(lang.native_name)}</span>
          </div>
          <div class="wxe-row-actions">${sparkleBtn(lang.native_name)}<button type="button" class="wxe-ate-btn" data-action="open-ate" data-tooltip="Translate in WPML">${ICON_ARROW}</button></div>
        </div>
      `;
    }

    cardsHtml += `</div>`;
  }

  if (!cardsHtml) return "";

  // Add header when rendering components.
  return (
    `<div class="wxe-section">${buildSectionHeader(msg().components || "Components")}` +
    cardsHtml +
    `</div>`
  );
}

export function buildItemCards(items, postTypeHint) {
  let html = "";
  for (const item of items) {
    let langRows = Object.entries(item.languages).filter(
      ([, l]) => !l.is_original,
    );
    if (state.selectedLanguageFilter.size) {
      langRows = langRows.filter(([code]) =>
        state.selectedLanguageFilter.has(code),
      );
    }
    if (state.selectedStatusFilter.size) {
      langRows = langRows.filter(([, lang]) =>
        state.selectedStatusFilter.has(lang.status),
      );
    }
    if (!langRows.length) continue;

    const isComp = postTypeHint === "wp_block" || (!postTypeHint && item._isComponent);
    html += `<div class="wxe-component-group"><div class="wxe-component-header-row"><a class="wxe-component-header" href="${escapeHtml(item.etch_url || '#')}">${escapeHtml(item.title)}</a>${translateAllBtn(item.id, isComp ? item.id : 0)}</div>`;
    for (const [code, lang] of langRows) {
      html += `
        <div class="wxe-item-lang-row" data-item-id="${item.id}" data-lang-code="${code}" data-post-type="${postTypeHint || ""}" data-status="${lang.status}">
          <div class="wxe-component-lang-info">
            <img src="${escapeHtml(lang.flag_url)}" alt="${escapeHtml(lang.native_name)}" width="16" height="11" class="wxe-flag">
            <span class="wxe-component-lang-name">${escapeHtml(lang.native_name)}</span>
          </div>
          <div class="wxe-row-actions">${sparkleBtn()}<button type="button" class="wxe-ate-btn" data-action="open-ate" data-tooltip="Translate in WPML">${ICON_ARROW}</button></div>
        </div>`;
    }
    html += `</div>`;
  }
  return html;
}

export function buildSectionForType(pillId, label, items) {
  const filtered = filterItems(items, pillId);
  if (!filtered.length) return "";

  const cardsHtml = buildItemCards(filtered, pillId);
  if (!cardsHtml) return "";

  return (
    `<div class="wxe-section">${buildSectionHeader(label)}` +
    cardsHtml +
    `</div>`
  );
}

/**
 * Builds HTML for all content types from cached pill data.
 * Single source of truth for the filter/context logic.
 * Used by renderAllContent (async, fetches uncached) and refresh callbacks.
 *
 * @param {Array} pills Content type pill definitions (excluding on-this-page).
 * @return {string} HTML string (may be empty if nothing cached/matching).
 */
export function buildAllContentHtml(pills) {
  let html = "";

  if (state.searchQuery) {
    for (const pill of pills) {
      if (pill.id === "json-loops") {
        const loops = (window.wxeBridge.jsonLoops || []).filter((l) =>
          l.name.toLowerCase().includes(state.searchQuery.toLowerCase()),
        );
        if (loops.length) html += buildLoopCards(loops, false);
        continue;
      }
      if (state.pillCache[pill.id] && state.pillCache[pill.id].length) {
        html += buildSectionForType(
          pill.id,
          pill.label,
          state.pillCache[pill.id],
        );
      }
    }
    return html;
  }

  if (state.filterByCurrentContext) {
    html += buildPageStatus() + buildComponentsList() + buildLoopsOnThisPage();
  }

  const currentPostType = window.wxeBridge?.currentPostType;
  for (const pill of pills) {
    if (pill.id === "json-loops") {
      const allLoops = window.wxeBridge.jsonLoops || [];
      if (allLoops.length && !state.filterByCurrentContext) {
        html += buildLoopCards(allLoops, false);
      }
      continue;
    }
    if (
      state.filterByCurrentContext &&
      (pill.id === currentPostType || pill.id === "wp_block")
    ) {
      continue;
    }
    if (state.pillCache[pill.id] && state.pillCache[pill.id].length) {
      html += buildSectionForType(
        pill.id,
        pill.label,
        state.pillCache[pill.id],
      );
    }
  }
  return html;
}


export function buildEmptyState(message) {
  const hasFilters = state.selectedLanguageFilter.size > 0
    || state.selectedStatusFilter.size > 0
    || state.searchQuery;
  if (hasFilters) {
    const m = msg();
    return `<div class="wxe-empty-state wxe-empty-state--filtered"><p>${escapeHtml(m.noFilterResults || 'No items match current filters')} <span class="wxe-status-shortcut__sep" aria-hidden="true">·</span> <button type="button" class="wxe-text-link" id="wxe-clear-all-filters">${escapeHtml(m.clearFilters || 'Clear filters')}</button></p></div>`;
  }
  return `<div class="wxe-empty-state"><p>${escapeHtml(message)}</p></div>`;
}

export function buildNotTranslatableState(label) {
  const m = msg();
  const settingsUrl =
    window.wxeBridge.wpmlSettingsUrl ||
    "/wp-admin/admin.php?page=tm%2Fmenu%2Fsettings#ml-content-setup-sec-7";
  const externalIcon = EXTERNAL_ICON;
  return `<div class="wxe-section">${buildSectionHeader(label)}<div class="wxe-component-group"><div class="wxe-not-translatable-info"><p>${escapeHtml(m.notTranslatableInfo || "This content type is not enabled for translation in WPML.")}</p><a href="${escapeHtml(settingsUrl)}" target="_blank" class="wxe-not-translatable-link"><span>${escapeHtml(m.enableTranslation || "Enable in WPML Settings")}</span> ${externalIcon}</a></div></div></div>`;
}

export function buildSkeleton() {
  let html = "";
  for (let i = 0; i < 3; i++) {
    html += `<div class="wxe-skeleton-card"><div class="wxe-skeleton-line wxe-skeleton-line--title"></div><div class="wxe-skeleton-line"></div><div class="wxe-skeleton-line"></div></div>`;
  }
  return html;
}


export function buildLoopsOnThisPage() {
  if (state.searchQuery) return "";

  const loops = (window.wxeBridge.jsonLoops || []).filter((l) => l.onThisPage);
  if (!loops.length) return "";

  const cardsHtml = buildLoopLangCards(loops);
  if (!cardsHtml) return "";

  return `<div class="wxe-section">${buildSectionHeader(msg().jsonLoopsTitle || "JSON Loops", msg().loopSubtitle || "Managed via WPML String Translation", "/wp-admin/admin.php?page=wpml-string-translation/menu/string-translation.php&context=Etch+JSON+Loops")}${cardsHtml}</div>`;
}


/**
 * Build loop cards with per-language rows (sparkle + arrow), same pattern as component cards.
 */
function buildLoopLangCards(loops) {
  const languages = window.wxeBridge.languages || {};
  const loopStatuses = window.wxeBridge.loopStatuses || {};
  let cardsHtml = "";

  for (const loop of loops) {
    const loopStatus = loopStatuses[loop.name] || {};

    let langRows = Object.entries(languages).filter(([, lang]) => !lang.is_original);

    if (state.selectedLanguageFilter.size) {
      langRows = langRows.filter(([code]) => state.selectedLanguageFilter.has(code));
    }

    if (state.selectedStatusFilter.size) {
      langRows = langRows.filter(([code]) =>
        state.selectedStatusFilter.has(loopStatus[code] || "not_translated"),
      );
    }

    if (!langRows.length) continue;

    cardsHtml += `<div class="wxe-component-group"><div class="wxe-component-header-row"><div class="wxe-loop-header-info"><span class="wxe-component-header">${escapeHtml(loop.name)}</span><a href="${escapeHtml(loop.url)}" target="_blank" class="wxe-loop-st-link">Open in String Translation ${EXTERNAL_ICON}</a></div>${loopTranslateAllBtn(loop.id)}</div>`;

    for (const [code, lang] of langRows) {
      const status = loopStatus[code] || "not_translated";
      cardsHtml += `
        <div class="wxe-component-lang-row wxe-loop-lang-row" data-loop-id="${escapeHtml(loop.id)}" data-lang-code="${code}" data-status="${status}" data-loop-name="${escapeHtml(loop.name)}">
          <div class="wxe-component-lang-info">
            <img src="${escapeHtml(lang.flag_url)}" alt="${escapeHtml(lang.native_name)}" width="16" height="11" class="wxe-flag">
            <span class="wxe-component-lang-name">${escapeHtml(lang.native_name)}</span>
          </div>
          <div class="wxe-row-actions">${loopSparkleBtn(loop.id, code, lang.native_name)}</div>
        </div>`;
    }

    cardsHtml += `</div>`;
  }

  return cardsHtml;
}

function loopSparkleBtn(loopId, langCode, langName) {
  if (!aiAccessible()) return '';
  const dim = !aiBtn() ? ' wxe-ai-btn--dim' : '';
  const tooltip = langName ? `AI Translate to ${escapeHtml(langName)}` : 'AI Translate';
  return `<button type="button" class="wxe-ai-btn${dim}" data-action="ai-translate-loop" data-loop-id="${escapeHtml(loopId)}" data-target-lang="${langCode}" data-tooltip="${tooltip}">${ICON_SPARKLE}</button>`;
}

function loopTranslateAllBtn(loopId) {
  if (!aiAccessible()) return '';
  const m = msg();
  const dim = !aiBtn() ? ' wxe-ai-btn--dim' : '';
  return `<button type="button" class="wxe-ai-btn wxe-ai-translate-all${dim}" data-action="ai-translate-loop-all" data-loop-id="${escapeHtml(loopId)}" data-tooltip="${escapeHtml(m.aiTranslateAllLangs || 'AI Translate all languages')}">${ICON_SPARKLE}</button>`;
}

export function buildLoopCards(loops, showInfoCard = true) {
  if (!loops || !loops.length) {
    return buildEmptyState(msg().noLoops || "No JSON loops found.");
  }

  const cardsHtml = buildLoopLangCards(loops);

  if (!cardsHtml && !showInfoCard) return "";

  return `<div class="wxe-section">${buildSectionHeader(msg().jsonLoopsTitle || "JSON Loops", msg().loopSubtitle || "Managed via WPML String Translation", "/wp-admin/admin.php?page=wpml-string-translation/menu/string-translation.php&context=Etch+JSON+Loops")}${cardsHtml}</div>`;
}

