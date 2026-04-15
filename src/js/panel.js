import { state, ICON_SEARCH, ICON_CLOSE, ICON_SPARKLE } from './state.js';
import { aiTranslateSingle, aiTranslateAll, aiTranslateLoopSingle, aiTranslateLoopAll, aiTranslateVisible } from './aiTranslate.js';
import { buildAiSettingsHtml, attachAiSettingsListeners } from './aiSettings.js';
import { buildLicenseFooterHtml, attachLicenseListeners } from './license.js';

// Small stroke icons for sidebar section headings. 14px, strokeWidth 1.5 to
// match the Back button in the panel header. Kept inline (not imported as
// files) to avoid an extra fetch for assets smaller than the HTTP overhead.
const ICON_TARGET =
  '<svg class="wxe-section-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/></svg>';
const ICON_LANGUAGES =
  '<svg class="wxe-section-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 9a2 2 0 0 1-2 2H6l-4 4V4c0-1.1.9-2 2-2h8a2 2 0 0 1 2 2z"/><path d="M18 9h2a2 2 0 0 1 2 2v11l-4-4h-6a2 2 0 0 1-2-2v-1"/></svg>';
const ICON_SLIDERS =
  '<svg class="wxe-section-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 17 2 2 4-4"/><path d="m3 7 2 2 4-4"/><path d="M13 6h8"/><path d="M13 12h8"/><path d="M13 18h8"/></svg>';
import { escapeHtml, msg, checkLock } from './utils.js';

const ICON_LOCK_MINI = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>';

function buildGlobalAiBtn(m) {
  const b = window.wxeBridge || {};
  if (b.aiAccess) {
    const dim = b.aiConfigured ? '' : ' wxe-ai-btn--dim';
    return `<button type="button" class="wxe-ai-btn wxe-ai-global-btn${dim}" id="wxe-ai-global-btn" data-action="ai-translate-visible" data-tooltip="${escapeHtml(m.aiTranslateVisible || 'AI Translate in view')}">${ICON_SPARKLE}</button>`;
  }
  if (b.lockingMode === 'supporter') {
    return `<button type="button" class="wxe-ai-btn wxe-ai-global-btn wxe-ai-btn--locked" id="wxe-ai-global-btn" data-action="ai-locked" data-tooltip="Pro license required"><span class="wxe-ai-btn-icon">${ICON_SPARKLE}</span><span class="wxe-ai-btn-icon wxe-ai-btn-icon--lock">${ICON_LOCK_MINI}</span></button>`;
  }
  return '';
}
import { getCurrentContextState, statusToBadgeClass, statusLabel, updateAllStatusIndicators, updateStatusDot, updatePillDots, calculateCombinedStatus } from './status.js';
import { switchPill, renderPillContent, updatePillVisuals } from './rendering.js';
import { filterByStatus, filterByLanguage, filterByTranslation, findLangData, clearLanguageFilter, syncLangClearBtnState, syncStatusFilterPills, syncTranslationShortcutState, applyFilters } from './filters.js';
import { saveFilterPrefs } from './filterPrefs.js';
import { fetchPillData, lazyFetchPillStatuses, refreshLanguagesStatus, startStatusRefreshInterval, resyncAll, fetchResyncStatus, startResyncStatusInterval } from './data.js';
import { openTranslation, applyPendingReturnStateToPanel, findEtchSaveButton, setStatusLoading, setStatusInfo } from './translation.js';
import { buildLangPickerHtml, attachLangPickerListeners } from './langPicker.js';

let _listenForEtchSaves = null;

export function setListenerFunctions(lfe) {
  _listenForEtchSaves = lfe;
}


function buildPanelHtml() {
  const { languages = {}, postTitle = '', postTypeLabel = '' } = window.wxeBridge;
  // Caption line under the post title: "Page · 🇬🇧 English".
  // Replaces the previous PAGE / SOURCE stacked labels with a single
  // secondary metadata line. The flag gets a hover tooltip clarifying it
  // is the source language of this specific post (which can differ from
  // the site default in WPML, though it rarely does).
  const sourceLang = Object.entries(languages).find(([, lang]) => lang.is_original);
  const typeText = postTypeLabel || msg().pageFallback || 'Page';
  const sourceTooltip = msg().sourceLanguageTooltip || 'Source language';
  const metaParts = [`<span class="wxe-post-meta-type">${escapeHtml(typeText)}</span>`];
  let sourceTooltipHtml = '';
  if (sourceLang) {
    const [, sourceLangData] = sourceLang;
    metaParts.push(
      `<span class="wxe-post-meta-source" interestfor="wxe-source-lang-tooltip">` +
      `<img src="${escapeHtml(sourceLangData.flag_url)}" alt="" width="16" height="11" class="wxe-flag">` +
      `<span class="wxe-post-meta-source-name">${escapeHtml(sourceLangData.native_name)}</span>` +
      `</span>`
    );
    sourceTooltipHtml = `<div id="wxe-source-lang-tooltip" popover="hint" class="wxe-tooltip wxe-tooltip--below-end">${escapeHtml(sourceTooltip)}</div>`;
  }
  const metaHtml =
    `<p class="wxe-post-meta">${metaParts.join('<span class="wxe-post-meta-sep" aria-hidden="true">·</span>')}</p>${sourceTooltipHtml}`;
  const langPickerHtml = buildLangPickerHtml();
  const ctxState = getCurrentContextState();
  const badgeWorst = ctxState === "complete" ? "translated" : (ctxState || "not_translated");
  const badgeClass = statusToBadgeClass(badgeWorst);
  const badgeHidden = ctxState ? "" : ' style="display:none"';
  const badgeTooltip = msg().currentContextStatus || 'Current context status';
  const statusBadgeHtml = `<span id="wxe-sidebar-badge" class="wxe-badge wxe-badge--${badgeClass}"${badgeHidden}>${escapeHtml(statusLabel(badgeWorst))}</span>`;
  const pills = window.wxeBridge.contentTypePills || [];
  const typePills = pills.filter((p) => p.id !== "on-this-page");
  const chipActive = state.filterByCurrentContext ? " wxe-pill--active" : "";
  const contextChipDot = ctxState || "empty";
  const contextGroupHtml = `<div class="wxe-pills-group"><button class="wxe-context-chip${chipActive}" id="wxe-context-chip"><span class="wxe-context-chip-title">${escapeHtml(msg().currentContext || 'Current Context')}<span class="wxe-pill-notification-dot" id="wxe-pill-dot-on-this-page" data-dot="${contextChipDot}"></span></span></button></div>`;
  const groups = [];
  let currentGroup = [];
  for (const p of typePills) {
    currentGroup.push(p);
    if (p.dividerAfter) { groups.push(currentGroup); currentGroup = []; }
  }
  if (currentGroup.length) groups.push(currentGroup);
  let typeGroupsHtml = "";
  for (const group of groups) {
    let groupInner = "";
    for (const p of group) {
      const activeClass = p.id === state.activePill ? " wxe-pill--active" : "";
      const dotState = (p.notTranslatable && !p.locked) ? "not_translatable" : "empty";
      groupInner += `<button class="wxe-pill${activeClass}" data-pill="${p.id}" title="${escapeHtml(p.label)}"><span class="wxe-pill-title">${escapeHtml(p.label)}<span class="wxe-pill-notification-dot" id="wxe-pill-dot-${p.id}" data-dot="${dotState}"></span></span></button>`;
    }
    typeGroupsHtml += `<div class="wxe-pills-group">${groupInner}</div>`;
  }
  const pillsHtml = contextGroupHtml + typeGroupsHtml;
  const m = msg();
  // The template below is intentionally kept as a single block for readability.
  // It builds the two-column panel: sidebar (left) + content area (right).
  return _buildPanelTemplate(m, statusBadgeHtml, postTitle, metaHtml, langPickerHtml, pillsHtml);
}

// Separated to keep buildPanelHtml focused on data prep.
function _buildPanelTemplate(m, statusBadgeHtml, postTitle, metaHtml, langPickerHtml, pillsHtml) {
  return `
		<div class="content-hub__sidebar">
			<div class="content-hub__header">
				<button class="etch-builder-button etch-builder-button--icon-placement-before etch-builder-button--variant-outline" id="wxe-back" interestfor="wxe-back-tooltip" aria-label="${escapeHtml(m.backToBuilder || 'Back to Builder')}" style="--button-font-size: var(--e-font-size-m); --icon-rotation: 0deg;">
					<div class="wxe-icon-wrapper"><svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="etch-icon iconify iconify--hugeicons-stroke" width="14px" height="14px" viewBox="0 0 24 24"><path d="M8.99996 16.9998L4 11.9997L9 6.99976" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"></path>
  			<path d="M4 12H20" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"></path></svg></div>
				</button>
				<div id="wxe-back-tooltip" popover="hint" class="wxe-tooltip wxe-tooltip--below-start">${escapeHtml(m.backToBuilder || 'Back to Builder')}</div>
				<h2 class="hub-title">${escapeHtml(m.panelTitle || 'WPML \u00d7 Etch')}</h2>
			</div>
			<div class="content-hub_sidebar__content">
				<div class="content-hub-list content-hub-list--current-context">
					<div class="wxe-section-header wxe-sidebar-section-heading wxe-section-header--with-badge">
						<h3>${ICON_TARGET}<span>${escapeHtml(m.currentContext || 'Current Context')}</span></h3>
						<div class="wxe-sidebar-header-actions">${statusBadgeHtml}</div>
					</div>
					<div class="wxe-post-info">
						<h2 class="wxe-post-title-text" title="${escapeHtml(postTitle)}">${escapeHtml(postTitle)}</h2>
						${metaHtml}
					</div>
				</div>
				<div class="content-hub-list">
					<div class="wxe-section-header wxe-sidebar-section-heading wxe-section-header--with-badge">
						<h3>${ICON_LANGUAGES}<span>${escapeHtml(m.languages || 'Languages')}</span></h3>
						<div class="wxe-status-shortcut" role="group" aria-label="${escapeHtml(m.languages || 'Languages')}">
							<button type="button" class="wxe-text-link wxe-status-pill wxe-status-shortcut__btn" id="wxe-lang-clear-btn" data-lang-clear aria-disabled="true" disabled>${escapeHtml(m.clear || 'Clear')}</button>
						</div>
					</div>
					<div class="wxe-filter-section">
						${langPickerHtml || '<p class="content-hub-list__disclaimer" style="padding:0;">' + escapeHtml(m.noLanguages || 'No languages configured.') + '</p>'}
					</div>
				</div>
				<div class="content-hub-list">
					<div class="wxe-section-header wxe-sidebar-section-heading wxe-section-header--with-badge">
						<h3>${ICON_SLIDERS}<span>${escapeHtml(m.status || 'Status')}</span></h3>
						<div class="wxe-status-shortcut" role="group" aria-label="${escapeHtml(m.statusShortcutLabel || 'Quick filter')}">
							<button type="button" class="wxe-text-link wxe-status-pill wxe-status-shortcut__btn" data-translation="not_translated">${escapeHtml(m.statusPending || 'Pending')}</button>
							<span class="wxe-status-shortcut__sep" aria-hidden="true">·</span>
							<button type="button" class="wxe-text-link wxe-status-pill wxe-status-shortcut__btn" data-translation="translated">${escapeHtml(m.statusDone || 'Done')}</button>
						</div>
					</div>
					<div class="wxe-filter-section">
						<div class="wxe-status-pills">
							<button class="wxe-chip wxe-status-filter" data-status="not_translated">${escapeHtml(m.statusNotTranslated || 'Not Translated')}</button>
							<button class="wxe-chip wxe-status-filter" data-status="waiting">${escapeHtml(m.statusWaiting || 'Needs Translation')}</button>
							<button class="wxe-chip wxe-status-filter" data-status="needs_update">${escapeHtml(m.statusNeedsUpdate || 'Needs Update')}</button>
							<button class="wxe-chip wxe-status-filter" data-status="in_progress">${escapeHtml(m.statusInProgress || 'In Progress')}</button>
							<button class="wxe-chip wxe-status-filter" data-status="translated">${escapeHtml(m.statusComplete || 'Complete')}</button>
						</div>
					</div>
				</div>
			</div>
			<div class="wxe-lang-switcher-section${state.langSwitcherExpanded ? ' wxe-lang-switcher-section--expanded' : ''}" id="wxe-lang-switcher-section">
				<button type="button" class="wxe-lang-switcher-accordion wxe-sidebar-section-heading" id="wxe-lang-switcher-accordion" aria-expanded="${state.langSwitcherExpanded ? 'true' : 'false'}" aria-controls="wxe-lang-switcher-body">
					<span class="wxe-lang-switcher-accordion-label">${escapeHtml(m.switcherComponent || 'Lang Switcher Component')}</span>
					<span class="wxe-lang-switcher-status" id="wxe-lang-switcher-status">${escapeHtml(window.wxeBridge.loopPresetActive ? (m.enabled || 'Enabled') : (m.disabled || 'Disabled'))}</span>
					<svg class="wxe-lang-switcher-chevron" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
				</button>
				<div class="wxe-lang-switcher-body" id="wxe-lang-switcher-body"${state.langSwitcherExpanded ? '' : ' hidden'}>
					<div class="wxe-lang-switcher-toggle-group">
						<div class="wxe-lang-switcher-row">
							<span class="wxe-lang-switcher-label">${escapeHtml(m.enableComponent || 'Enable component')}</span>
							<button class="wxe-toggle" id="wxe-loop-preset-toggle" data-state="${window.wxeBridge.loopPresetActive ? 'checked' : 'unchecked'}" role="switch" aria-checked="${window.wxeBridge.loopPresetActive ? 'true' : 'false'}">
								<span class="wxe-toggle-thumb" data-state="${window.wxeBridge.loopPresetActive ? 'checked' : 'unchecked'}"></span>
							</button>
						</div>
						<p class="wxe-lang-switcher-desc">${escapeHtml(m.enableComponentDesc || 'Registers a JSON loop so the switcher works as an Etch component.')}</p>
					</div>
					<button class="wxe-secondary-btn wxe-copy-component-btn" id="wxe-copy-component-btn" ${window.wxeBridge.loopPresetActive ? '' : 'disabled'}>
						<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
						<span id="wxe-copy-component-label">${escapeHtml(m.copyComponent || 'Copy Component')}</span>
					</button>
				</div>
			</div>
			${buildAiSettingsHtml()}
			<div class="wxe-sidebar-footer" id="wxe-sidebar-footer">
				<div class="wxe-sidebar-footer-title">
					<span class="wxe-sidebar-footer-title__label">
						<span>WPML</span>
						<svg class="wxe-sidebar-footer-title__icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
					</span>
					<div class="wxe-status-shortcut" role="group" aria-label="${escapeHtml(m.resync || 'Force Sync')}">
						<button type="button" class="wxe-text-link wxe-status-pill wxe-status-shortcut__btn" id="wxe-resync-all-btn" data-state="idle" interestfor="wxe-resync-tooltip" aria-label="${escapeHtml(m.resync || 'Force Sync')}">
							<span id="wxe-resync-run-label">${escapeHtml(m.resync || 'Force Sync')}</span>
						</button>
					</div>
					<div id="wxe-resync-tooltip" popover="hint" class="wxe-tooltip wxe-tooltip--above-end wxe-resync-tooltip">
						<div class="wxe-resync-tooltip__group">
							<div class="wxe-resync-tooltip__label">${escapeHtml(m.scopeAllSite || 'All site')}</div>
							<div class="wxe-resync-tooltip__value" id="wxe-resync-tooltip-global">${escapeHtml(m.resyncNeverRun || 'Never run')}</div>
						</div>
						<div class="wxe-resync-tooltip__group">
							<div class="wxe-resync-tooltip__label">${escapeHtml(m.scopeCurrentContext || 'Current context')}</div>
							<div class="wxe-resync-tooltip__value" id="wxe-resync-tooltip-local">—</div>
						</div>
					</div>
				</div>
				<nav class="wxe-sidebar-footer-links" aria-label="${escapeHtml(m.quickAccess || 'Quick WPML Access')}">
					<a href="/wp-admin/admin.php?page=tm/menu/settings" target="_blank" rel="noopener" class="wxe-text-link wxe-footer-text-link">${escapeHtml(m.wpmlSettings || 'Settings')}</a>
					<span class="wxe-footer-sep" aria-hidden="true">·</span>
					<a href="/wp-admin/admin.php?page=wpml-string-translation/menu/string-translation.php" target="_blank" rel="noopener" class="wxe-text-link wxe-footer-text-link">${escapeHtml(m.wpmlStrings || 'Strings')}</a>
					<span class="wxe-footer-sep" aria-hidden="true">·</span>
					<a href="/wp-admin/admin.php?page=tm/menu/translations-queue.php" target="_blank" rel="noopener" class="wxe-text-link wxe-footer-text-link">${escapeHtml(m.wpmlTranslations || 'Translations')}</a>
				</nav>
			</div>
		</div>
		<div class="wxe-content">
			<div class="wxe-status-overlay" id="wxe-status-overlay" role="status" aria-live="polite" style="display: none;">
				<div class="wxe-status-overlay-content" id="wxe-status-overlay-content"></div>
			</div>
			<div class="wxe-pills-wrapper">
				<div class="wxe-search-toggle-wrapper">
					<button class="wxe-search-toggle" id="wxe-search-toggle" title="${escapeHtml(m.search || 'Search')}">${ICON_SEARCH}</button>
				</div>
				<div class="wxe-pills-divider"></div>
				<div class="wxe-pills-bar" id="wxe-pills-bar">${pillsHtml}</div>
				<div class="wxe-search-inline" id="wxe-search-inline"><input type="text" id="wxe-search-input" class="wxe-search-input" placeholder="${escapeHtml(m.searchPlaceholder || 'Search by title\u2026')}" aria-label="${escapeHtml(m.searchPlaceholder || 'Search by title\u2026')}" /></div>
				${buildGlobalAiBtn(m)}
			</div>
			<div id="wxe-pill-content" class="wxe-components-content wxe-content-fade--in"></div>
			${buildLicenseFooterHtml()}
		</div>
	`;
}


function attachPanelListeners(panel) {
  document.getElementById("wxe-back").addEventListener("click", closePanel);
  attachLangPickerListeners(panel);
  const resyncAllBtn = document.getElementById('wxe-resync-all-btn');
  if (resyncAllBtn) resyncAllBtn.addEventListener('click', () => resyncAll());
  // Hydrate the status indicator from the persisted last-run state and
  // start the relative-time tick. Both are no-ops on subsequent panel rebuilds.
  fetchResyncStatus();
  startResyncStatusInterval();
  const langSwitcherAccordion = document.getElementById('wxe-lang-switcher-accordion');
  const langSwitcherBody = document.getElementById('wxe-lang-switcher-body');
  const langSwitcherSection = document.getElementById('wxe-lang-switcher-section');
  if (langSwitcherAccordion && langSwitcherBody && langSwitcherSection) {
    langSwitcherAccordion.addEventListener('click', () => {
      const expanded = langSwitcherAccordion.getAttribute('aria-expanded') === 'true';
      const next = !expanded;
      state.langSwitcherExpanded = next;
      langSwitcherAccordion.setAttribute('aria-expanded', next ? 'true' : 'false');
      langSwitcherSection.classList.toggle('wxe-lang-switcher-section--expanded', next);
      langSwitcherBody.hidden = !next;
      // Collapse AI settings when lang switcher opens.
      if (next) {
        const aiAccordion = document.getElementById('wxe-ai-settings-accordion');
        const aiBody = document.getElementById('wxe-ai-settings-body');
        const aiSection = document.getElementById('wxe-ai-settings-section');
        if (aiAccordion && aiBody && aiSection) {
          aiAccordion.setAttribute('aria-expanded', 'false');
          aiBody.hidden = true;
          aiSection.classList.remove('wxe-ai-settings-section--expanded');
        }
      }
    });
  }
  const chipEl = document.getElementById("wxe-context-chip");
  if (chipEl) {
    chipEl.addEventListener("click", () => {
      if (checkLock('toggleContext') || chipEl.classList.contains('wxe-context-chip--locked')) return;
      state.filterByCurrentContext = !state.filterByCurrentContext;
      if (state.filterByCurrentContext && state.activePill !== null) { state.activePill = null; updatePillVisuals(); }
      chipEl.classList.toggle("wxe-pill--active", state.filterByCurrentContext);
      renderPillContent();
    });
  }
  panel.querySelectorAll(".wxe-pill").forEach((btn) => {
    btn.addEventListener("click", () => {
      if (state.filterByCurrentContext) {
        state.filterByCurrentContext = false;
        const chip = document.getElementById("wxe-context-chip");
        if (chip) chip.classList.remove("wxe-pill--active");
      }
      switchPill(btn.dataset.pill);
    });
  });
  const searchToggle = document.getElementById("wxe-search-toggle");
  const searchInline = document.getElementById("wxe-search-inline");
  const pillsBar = document.getElementById("wxe-pills-bar");
  if (searchToggle && searchInline && pillsBar) {
    searchToggle.addEventListener("click", () => {
      if (checkLock('search')) return;
      const isOpen = searchInline.classList.contains("wxe-search-inline--visible");
      if (!isOpen) {
        state.preSearchContext = state.filterByCurrentContext;
        state.preSearchPill = state.activePill;
        state.filterByCurrentContext = false;
        state.activePill = null;
        const chip = document.getElementById("wxe-context-chip");
        if (chip) chip.classList.remove("wxe-pill--active");
        updatePillVisuals();
        renderPillContent();
        pillsBar.classList.add("wxe-pills-bar--hidden");
        searchToggle.classList.add("wxe-search-toggle--active");
        searchToggle.innerHTML = ICON_CLOSE;
        setTimeout(() => {
          searchInline.classList.add("wxe-search-inline--visible");
          const input = document.getElementById("wxe-search-input");
          if (input) input.focus();
        }, 200);
      } else {
        searchInline.classList.remove("wxe-search-inline--visible");
        searchToggle.classList.remove("wxe-search-toggle--active");
        searchToggle.innerHTML = ICON_SEARCH;
        setTimeout(() => { pillsBar.classList.remove("wxe-pills-bar--hidden"); }, 200);
        state.searchQuery = "";
        const input = document.getElementById("wxe-search-input");
        if (input) input.value = "";
        state.filterByCurrentContext = state.preSearchContext;
        state.activePill = state.preSearchPill;
        const chip = document.getElementById("wxe-context-chip");
        if (chip) chip.classList.toggle("wxe-pill--active", state.filterByCurrentContext);
        updatePillVisuals();
        renderPillContent();
      }
    });
  }
  const searchInput = document.getElementById("wxe-search-input");
  if (searchInput) {
    searchInput.addEventListener("input", (e) => {
      if (checkLock('search')) return;
      clearTimeout(state.searchDebounceTimer);
      state.searchDebounceTimer = setTimeout(() => {
        state.searchQuery = e.target.value.trim();
        renderPillContent();
      }, 150);
    });
  }
  panel.addEventListener("click", (e) => {
    const closeAction = e.target.closest('[data-wxe-action="close-panel"]');
    if (closeAction) { closePanel(); return; }

    // AI locked: open license popup.
    const aiLockedBtn = e.target.closest('[data-action="ai-locked"]');
    if (aiLockedBtn) {
      e.stopPropagation();
      if (typeof window.wxeShowLicensePopup === 'function') window.wxeShowLicensePopup();
      return;
    }

    // AI translate: single language.
    const aiBtnEl = e.target.closest('[data-action="ai-translate"]');
    if (aiBtnEl) {
      e.stopPropagation();
      const row = aiBtnEl.closest('.wxe-component-lang-row, .wxe-item-lang-row');
      if (row) {
        const langCode = row.dataset.langCode;
        const postId = parseInt(row.dataset.itemId || row.dataset.componentId || '0', 10) || window.wxeBridge.currentPostId;
        const componentId = parseInt(row.dataset.componentId || '0', 10);
        aiTranslateSingle(langCode, postId, componentId, aiBtnEl);
      }
      return;
    }

    // AI translate: entire current context (page + components + loops).
    const aiContextBtn = e.target.closest('[data-action="ai-translate-context"]');
    if (aiContextBtn) {
      e.stopPropagation();
      aiTranslateAll(window.wxeBridge.currentPostId, 0, aiContextBtn);
      return;
    }

    // AI translate: single loop language.
    const aiLoopBtn = e.target.closest('[data-action="ai-translate-loop"]');
    if (aiLoopBtn) {
      e.stopPropagation();
      aiTranslateLoopSingle(aiLoopBtn.dataset.loopId, aiLoopBtn.dataset.targetLang, aiLoopBtn);
      return;
    }

    // AI translate: all visible content.
    const aiVisibleBtn = e.target.closest('[data-action="ai-translate-visible"]');
    if (aiVisibleBtn) {
      e.stopPropagation();
      aiTranslateVisible(aiVisibleBtn);
      return;
    }

    // AI translate: all languages for a loop.
    const aiLoopAllBtn = e.target.closest('[data-action="ai-translate-loop-all"]');
    if (aiLoopAllBtn) {
      e.stopPropagation();
      aiTranslateLoopAll(aiLoopAllBtn.dataset.loopId, aiLoopAllBtn);
      return;
    }

    // AI translate: all languages for a post.
    const aiAllBtn = e.target.closest('[data-action="ai-translate-all"]');
    if (aiAllBtn) {
      e.stopPropagation();
      const postId = parseInt(aiAllBtn.dataset.postId || '0', 10) || window.wxeBridge.currentPostId;
      const componentId = parseInt(aiAllBtn.dataset.componentId || '0', 10);
      aiTranslateAll(postId, componentId, aiAllBtn);
      return;
    }

    const filterBadge = e.target.closest(".wxe-status-filter");
    if (filterBadge && filterBadge.dataset.status) { filterByStatus(filterBadge.dataset.status); return; }
    const clearAllBtn = e.target.closest("#wxe-clear-all-filters");
    if (clearAllBtn) { clearLanguageFilter(); state.selectedStatusFilter.clear(); state.searchQuery = ''; const si = document.getElementById('wxe-search-input'); if (si) si.value = ''; syncStatusFilterPills(); syncTranslationShortcutState(); saveFilterPrefs(); applyFilters(); return; }
    const langClearBtn = e.target.closest("[data-lang-clear]");
    if (langClearBtn) { if (!langClearBtn.disabled) clearLanguageFilter(); return; }
    const translationPill = e.target.closest(".wxe-status-pill[data-translation]");
    if (translationPill) { filterByTranslation(translationPill.dataset.translation); return; }
    const sidebarBtn = e.target.closest(".content-hub__sidebar .wxe-lang-btn");
    if (sidebarBtn) {
      const otherLangs = Object.entries(window.wxeBridge.languages).filter(([, lang]) => !lang.is_original);
      if (otherLangs.length > 1) filterByLanguage(sidebarBtn.dataset.langCode);
      return;
    }
    if (window._wxeIsOpening) return;
    const ateBtn = e.target.closest('[data-action="open-ate"]');
    if (ateBtn) {
      e.stopPropagation();
      const row = ateBtn.closest('.wxe-component-lang-row, .wxe-item-lang-row');
      if (!row) return;
      const langCode = row.dataset.langCode;
      const componentId = parseInt(row.dataset.componentId || '0', 10);
      const itemId = parseInt(row.dataset.itemId || '0', 10);
      const postType = row.dataset.postType;
      if (row.classList.contains('wxe-item-lang-row') && itemId) {
        const lang = findLangData(itemId, langCode, postType);
        if (lang) {
          const isCurrentPage = itemId === window.wxeBridge.currentPostId;
          isCurrentPage ? openTranslation(lang) : openTranslation(lang, itemId);
        }
      } else {
        const lang = window.wxeBridge.languages[langCode];
        if (lang) { componentId ? openTranslation(lang, componentId) : openTranslation(lang); }
      }
      return;
    }
    const toggle = e.target.closest("#wxe-loop-preset-toggle");
    if (toggle) { toggleLoopPreset(toggle); return; }
    const copyBtn = e.target.closest("#wxe-copy-component-btn");
    if (copyBtn) { copyLanguageSwitcherComponent(copyBtn); return; }
    const overlayReload = e.target.closest("#wxe-loop-save-reload");
    if (overlayReload) { saveAndReload(); return; }
    const overlayDismiss = e.target.closest("#wxe-loop-dismiss");
    if (overlayDismiss) { const overlay = document.getElementById("wxe-status-overlay"); if (overlay) overlay.style.display = "none"; return; }
    const retryBtn = e.target.closest('.wxe-retry-link');
    if (retryBtn) {
      const retryPill = retryBtn.dataset.retryPill;
      if (retryPill) { state.pillCache[retryPill] = undefined; delete state.pillLoading[retryPill + '_retried']; renderPillContent(); }
      return;
    }
  });

  // ── Shared data-tooltip hover system ────────────────────────────────
  // Single tooltip element repositioned on hover, matching the lock
  // tooltip pattern in wxe-locking.js.
  const sharedTip = document.createElement('div');
  sharedTip.className = 'wxe-tooltip wxe-shared-tooltip';
  sharedTip.style.position = 'fixed';
  sharedTip.style.zIndex = '9999';
  sharedTip.style.display = 'none';
  sharedTip.style.pointerEvents = 'none';
  panel.appendChild(sharedTip);

  panel.addEventListener('mouseenter', (e) => {
    const trigger = e.target.closest('[data-tooltip]');
    if (!trigger) return;
    const text = trigger.getAttribute('data-tooltip');
    if (!text) return;
    sharedTip.textContent = text;
    sharedTip.style.display = '';
    const rect = trigger.getBoundingClientRect();
    const tipRect = sharedTip.getBoundingClientRect();
    let top = rect.top - tipRect.height - 6;
    let left = rect.left + (rect.width / 2) - (tipRect.width / 2);
    // Flip below if no room above.
    if (top < 4) {
      top = rect.bottom + 6;
    }
    // Keep within viewport horizontally.
    if (left < 4) left = 4;
    if (left + tipRect.width > window.innerWidth - 4) {
      left = window.innerWidth - tipRect.width - 4;
    }
    sharedTip.style.top = top + 'px';
    sharedTip.style.left = left + 'px';
  }, true);

  panel.addEventListener('mouseleave', (e) => {
    const trigger = e.target.closest('[data-tooltip]');
    if (!trigger) return;
    sharedTip.style.display = 'none';
  }, true);
}


export function buildPanel() {
  const existing = document.getElementById("wxe-panel");
  if (existing) existing.remove();

  // Reset pill state when rebuilding panel.
  state.activePill = null;
  state.filterByCurrentContext = true;
  Object.keys(state.pillCache).forEach((k) => delete state.pillCache[k]);
  Object.keys(state.pillLoading).forEach((k) => delete state.pillLoading[k]);
  Object.keys(state.scrollPositions).forEach((k) => delete state.scrollPositions[k]);
  Object.keys(state.pillStatuses).forEach((k) => delete state.pillStatuses[k]);
  state.searchQuery = "";
  state.pillStatusesFetched = false;

  state.lastBuiltPostId = window.wxeBridge.currentPostId;

  // Build DOM.
  const panel = document.createElement("div");
  panel.id = "wxe-panel";
  panel.className = "wxe-panel";
  panel.innerHTML = buildPanelHtml();

  // Append inside .etch-app__panels so sidebar and bottom bar stay visible.
  const etchPanels = document.querySelector(".etch-app__panels");
  if (etchPanels) {
    if (getComputedStyle(etchPanels).position === "static") {
      etchPanels.style.position = "relative";
    }
    etchPanels.appendChild(panel);
  } else {
    document.body.appendChild(panel);
  }

  // Attach event listeners.
  attachPanelListeners(panel);
  attachAiSettingsListeners(panel);
  attachLicenseListeners(panel);

  // Ensure the Languages "Clear" shortcut reflects any restored selection.
  syncLangClearBtnState();
  // Restore visual state for the granular Status pills + Pending/Done
  // shortcuts from persisted filter prefs (chip HTML is static, language
  // chips already get this baked in via buildLangPickerHtml).
  syncStatusFilterPills();
  syncTranslationShortcutState();

  updateAllStatusIndicators();
  updatePillDots();
  lazyFetchPillStatuses();

  // Initial render with current filter state
  renderPillContent();

  // Re-apply locking UI after panel rebuild (FREE/SUPPORTER modes).
  if ( window.WXELocking ) {
    setTimeout( function () {
      window.WXELocking.applyLockUI();
    }, 100 );
  }
}

export function getOurButton() {
  const settingsBar = document.querySelector(
    ".settings-bar, .etch-settings-bar, .etch-app__settings-bar",
  );
  if (!settingsBar) return null;
  // Find the button with our icon.
  return Array.from(settingsBar.querySelectorAll('button')).find(btn => {
    // Look for our specific icon in the SVG or the attribute.
    return btn.innerHTML.includes('vscode-icons:file-type-wpml') ||
           btn.getAttribute('title') === 'Translations' ||
           btn.innerHTML.includes('iconify--vscode-icons');
  });
}

export function togglePanel() {
  const panel = document.getElementById("wxe-panel");
  if (panel && panel.classList.contains("is-active")) {
    closePanel();
  } else {
    openPanel();
  }
}

export async function openPanel() {
  let panel = document.getElementById("wxe-panel");
  if (!panel) return;

  // Suppress observer reactions to all DOM mutations caused by opening
  // our panel (buildPanel rebuild, activeBtn.click(), classList changes).
  // Must be set before ANY DOM manipulation to avoid race conditions.
  state.isOpeningPanel = true;

  // Data should already be pre-loaded by checkPostIdChange
  // Check if panel needs to be rebuilt with fresh data
  const urlPostId = (() => {
    const match = window.location.href.match(/[?&]post_id=(\d+)/);
    return match ? parseInt(match[1], 10) : null;
  })();

  const currentPostId = window.wxeBridge?.currentPostId ? parseInt(window.wxeBridge.currentPostId, 10) : null;
  const builtPostId = state.lastBuiltPostId ? parseInt(state.lastBuiltPostId, 10) : null;

  // If panel was built for a different post_id, rebuild it
  if (currentPostId && builtPostId && currentPostId !== builtPostId) {
    buildPanel();
    panel = document.getElementById("wxe-panel");
    if (!panel) return;
  }

  // Only refresh if data is stale (shouldn't happen normally)
  if (urlPostId && urlPostId !== currentPostId) {
    // Show loading state
    panel.classList.add("is-active", "wxe-panel--loading");
    applyPanelOffset(panel);

    try {
      const resp = await fetch(
        `${window.wxeBridge.restUrl}languages-status?post_id=${urlPostId}`,
        {
          headers: { "X-WP-Nonce": window.wxeBridge.restNonce },
          credentials: "same-origin",
        }
      );
      const data = await resp.json();

      if (resp.ok && data) {
        window.wxeBridge.languages = data.languages || {};
        window.wxeBridge.components = data.components || [];
        window.wxeBridge.combinedStatus = data.combinedStatus
            || calculateCombinedStatus(window.wxeBridge.languages, window.wxeBridge.components);
        window.wxeBridge.currentPostId = urlPostId;
        window.wxeBridge.currentPostType = data.currentPostType || '';
        window.wxeBridge.postTitle = data.postTitle || '';
        window.wxeBridge.postTypeLabel = data.postTypeLabel || '';
        if (data.isTranslatable !== undefined) {
          window.wxeBridge.isTranslatable = data.isTranslatable;
        }

        buildPanel();

        const newPanel = document.getElementById("wxe-panel");
        if (newPanel) {
          applyPanelOffset(newPanel);
          newPanel.classList.remove("wxe-panel--loading");

          const settingsBar = document.querySelector(
            ".settings-bar, .etch-settings-bar, .etch-app__settings-bar",
          );
          const activeBtn = settingsBar && settingsBar.querySelector('button[selected="true"]');
          const ourBtn = getOurButton();
          if (activeBtn && activeBtn !== ourBtn) {
            activeBtn.click();
          }
          if (ourBtn) {
            ourBtn.setAttribute("selected", "true");
          }

          newPanel.classList.add("is-active");
          updateStatusDot();
        }
        // Always reset the flag, even if panel element wasn't found after buildPanel().
        requestAnimationFrame(() => { state.isOpeningPanel = false; });
        return;
      }
    } catch (err) {
      // Failed to refresh context on panel open
      panel.classList.remove("wxe-panel--loading");
      state.isOpeningPanel = false;
    }
  }

  // Normal path: data is already fresh, just open the panel
  applyPanelOffset(panel);

  const settingsBar = document.querySelector(
    ".settings-bar, .etch-settings-bar, .etch-app__settings-bar",
  );

  const activeBtn =
    settingsBar && settingsBar.querySelector('button[selected="true"]');
  const ourBtn = getOurButton();
  if (activeBtn && activeBtn !== ourBtn) {
    activeBtn.click();
  }

  if (ourBtn) {
    ourBtn.setAttribute("selected", "true");
  }

  panel.classList.add("is-active");

  // Re-create observers and intervals that closePanel() cleaned up.
  if (!state.saveButtonObserver) {
    _listenForEtchSaves();
  }
  if (!state.statusRefreshInterval) {
    startStatusRefreshInterval();
  }

  if (state.pendingReturnState) {
    applyPendingReturnStateToPanel();
    state.pendingReturnState = null;
  } else {
    // Always default to Current Context on open. Languages/Status filters
    // are user preferences and stay as they were.
    state.filterByCurrentContext = true;
    state.activePill = null;
    state.searchQuery = "";
    const chip = document.getElementById("wxe-context-chip");
    if (chip) chip.classList.add("wxe-pill--active");
    updatePillVisuals();
    // Close search bar if it was left open.
    const searchInline = document.getElementById("wxe-search-inline");
    const searchInput = document.getElementById("wxe-search-input");
    const searchToggle = document.getElementById("wxe-search-toggle");
    const pillsBar = document.getElementById("wxe-pills-bar");
    if (searchInline) searchInline.classList.remove("wxe-search-inline--visible");
    if (searchToggle) { searchToggle.classList.remove("wxe-search-toggle--active"); searchToggle.innerHTML = ICON_SEARCH; }
    if (searchInput) searchInput.value = "";
    if (pillsBar) pillsBar.classList.remove("wxe-pills-bar--hidden");
    // The content area still holds whatever the previous activePill rendered
    // before closing — re-render so it matches the reset state (Current
    // Context) and the persisted Languages/Status filters apply correctly.
    renderPillContent();
    // Snapshot the loop preset state so a double-toggle can dismiss the
    // reload overlay (no actual change ⇒ no reload needed).
    state.loopPresetOriginal = !!window.wxeBridge.loopPresetActive;
    // Always collapse the Switcher Component accordion on reopen — it's an
    // occasional-use tool, not a sticky preference.
    state.langSwitcherExpanded = false;
    const switcherSection = document.getElementById("wxe-lang-switcher-section");
    const switcherBtn = document.getElementById("wxe-lang-switcher-accordion");
    const switcherBody = document.getElementById("wxe-lang-switcher-body");
    if (switcherSection) switcherSection.classList.remove("wxe-lang-switcher-section--expanded");
    if (switcherBtn) switcherBtn.setAttribute("aria-expanded", "false");
    if (switcherBody) switcherBody.hidden = true;
    // Collapse AI settings accordion on reopen.
    const aiSection = document.getElementById("wxe-ai-settings-section");
    const aiBtn = document.getElementById("wxe-ai-settings-accordion");
    const aiBody = document.getElementById("wxe-ai-settings-body");
    if (aiSection) aiSection.classList.remove("wxe-ai-settings-section--expanded");
    if (aiBtn) aiBtn.setAttribute("aria-expanded", "false");
    if (aiBody) aiBody.hidden = true;
  }

  refreshLanguagesStatus();

  // Wait one frame for Svelte to flush reactive DOM updates before re-enabling the observer.
  requestAnimationFrame(() => { state.isOpeningPanel = false; });
}

export function getSettingsBarWidth() {
  const settingsBar = document.querySelector(
    ".settings-bar, .etch-settings-bar, .etch-app__settings-bar",
  );
  if (settingsBar && settingsBar.offsetWidth > 0) {
    return settingsBar.offsetWidth;
  }
  // Try to wait a bit for Etch to load
  setTimeout(() => {
    const panel = document.getElementById("wxe-panel");
    if (panel && panel.classList.contains("is-active")) {
      applyPanelOffset(panel);
    }
  }, 100);
  return 56;
}

export function applyPanelOffset(panel) {
  panel.style.left = `${getSettingsBarWidth()}px`;
}

export function closePanel() {
  const panel = document.getElementById("wxe-panel");
  if (!panel || !panel.classList.contains("is-active")) return;

  panel.classList.remove("is-active");

  const ourBtn = getOurButton();
  if (ourBtn) {
    ourBtn.setAttribute("selected", "false");
  }

  // Reset selected state.
  document
    .querySelectorAll(".wxe-lang-item")
    .forEach((el) => el.classList.remove("content-hub-list__item--selected"));


  // Keep statusRefreshInterval, network patches, and saveButtonObserver
  // alive even when the panel is closed -- the toolbar status dot needs
  // them to stay current. They are cleaned up on page unload instead.
}


async function toggleLoopPreset(toggle) {
  const m = msg();
  try {
    const res = await fetch(`${window.wxeBridge.restUrl}toggle-loop-preset`, {
      method: 'POST',
      headers: { 'X-WP-Nonce': window.wxeBridge.restNonce },
      credentials: 'same-origin',
    });
    if (!res.ok) return;
    const data = await res.json();
    const newState = data.active ? 'checked' : 'unchecked';
    toggle.dataset.state = newState;
    toggle.setAttribute('aria-checked', data.active ? 'true' : 'false');
    toggle.querySelector('.wxe-toggle-thumb').dataset.state = newState;
    window.wxeBridge.loopPresetActive = data.active;

    const statusEl = document.getElementById('wxe-lang-switcher-status');
    if (statusEl) statusEl.textContent = data.active ? (m.enabled || 'Enabled') : (m.disabled || 'Disabled');

    const copyBtn = document.getElementById('wxe-copy-component-btn');
    if (copyBtn) copyBtn.disabled = !data.active;

    // If toggled back to the original state, no reload is needed — dismiss overlay.
    if (data.active === state.loopPresetOriginal) {
      const overlay = document.getElementById('wxe-status-overlay');
      if (overlay) overlay.style.display = 'none';
    } else {
      showLoopToggleOverlay(data.active);
    }
  } catch (e) {
    // Silently fail.
  }
}

function showLoopToggleOverlay(enabled) {
  const m = msg();
  const overlay = document.getElementById("wxe-status-overlay");
  const content = document.getElementById("wxe-status-overlay-content");
  if (!overlay || !content) return;

  const title = enabled
    ? (m.loopEnabled || 'Language Switcher enabled.')
    : (m.loopDisabled || 'Language Switcher disabled.');

  overlay.style.display = "flex";
  content.innerHTML = `
    <div class="wxe-loop-overlay">
      <p class="wxe-loop-overlay-title">${escapeHtml(title)}</p>
      <p class="wxe-loop-overlay-desc">${escapeHtml(m.reloadToApply || 'Reload to apply changes.')}</p>
      <div class="wxe-loop-overlay-actions">
        <button class="wxe-loop-overlay-btn wxe-loop-overlay-btn--secondary" id="wxe-loop-dismiss">${escapeHtml(m.dismiss || 'Dismiss')}</button>
        <button class="wxe-loop-overlay-btn wxe-loop-overlay-btn--primary" id="wxe-loop-save-reload">${escapeHtml(m.saveAndReload || 'Save & Reload')}</button>
      </div>
    </div>
  `;
}

async function saveAndReload() {
  const m = msg();
  const saveButton = findEtchSaveButton();

  if (saveButton && !saveButton.disabled) {
    setStatusLoading(m.saving || 'Saving before we proceed.');
    saveButton.click();
    await new Promise(resolve => {
      let attempts = 0;
      const poll = () => {
        attempts++;
        if (attempts > 50) { resolve(); return; }
        if (saveButton.disabled) {
          const waitDone = () => {
            if (!saveButton.disabled || attempts > 50) { resolve(); return; }
            attempts++;
            setTimeout(waitDone, 200);
          };
          setTimeout(waitDone, 200);
        } else {
          setTimeout(resolve, 1200);
        }
      };
      setTimeout(poll, 300);
    });
  }

  setStatusInfo(m.reloading || 'Reloading.');
  setTimeout(() => { window.location.href = window.location.href; }, 300);
}

async function copyLanguageSwitcherComponent(btn) {
  const m = msg();
  const json = window.wxeBridge.switcherComponentJson || '';
  if (!json) return;
  try {
    await navigator.clipboard.writeText(json);
    closePanel();
  } catch (e) {
    // Clipboard API not available.
  }
}
