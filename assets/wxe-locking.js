/**
 * Zero WPML × Etch - Locking Module
 * 
 * This file contains all premium feature locking logic.
 * Remove this file completely for free distribution.
 * 
 * @package WpmlXEtch
 */

(function () {
  "use strict";

  window.WXELocking = {
    /**
     * Check if a feature is locked.
     * 
     * @param {string} feature - Feature to check ('search', 'pill', 'context')
     * @returns {boolean|Function}
     */
    isLocked(feature) {
      const bridge = window.wxeBridge || {};

      switch (feature) {
        case "search":
          return !!bridge.searchLocked;

        case "pill":
          return (pillId) => {
            const pills = bridge.contentTypePills || [];
            const pill = pills.find((p) => p.id === pillId);
            return pill?.locked || false;
          };

        case "context":
          const pills = bridge.contentTypePills || [];
          const contextPill = pills.find((p) => p.id === "on-this-page");
          return contextPill?.locked || false;

        default:
          return false;
      }
    },

    /**
     * Check if an action should be blocked.
     * 
     * @param {string} action - Action type
     * @param {...any} args - Action arguments
     * @returns {boolean}
     */
    shouldBlockAction(action, ...args) {
      switch (action) {
        case "search":
          return this.isLocked("search");

        case "switchPill":
          return this.isLocked("pill")(args[0]);

        case "toggleContext":
          return this.isLocked("context");

        default:
          return false;
      }
    },

    /**
     * Apply all locking UI states.
     */
    applyLockUI() {
      this.lockSearchIfNeeded();
      this.lockPillsIfNeeded();
      this.lockContextIfNeeded();
      this.lockFiltersIfNeeded();
      this.lockAiSectionIfNeeded();
      this.lockQuickAccessIfNeeded();
    },

    /**
     * Lock search bar if needed.
     */
    lockSearchIfNeeded() {
      if (!this.isLocked("search")) return;

      const toggle = document.getElementById("wxe-search-toggle");
      if (toggle) {
        toggle.classList.add("wxe-search-toggle--locked");
        // Add class to existing magnifier SVG for CSS toggling.
        const magnifier = toggle.querySelector("svg");
        if (magnifier) magnifier.classList.add("wxe-search-toggle-magnifier");
        // Inject hidden lock SVG (shown on hover via CSS).
        if (!toggle.querySelector(".wxe-search-toggle-lock")) {
          toggle.appendChild(this.createLockSVG("wxe-search-toggle-lock", 14, 2));
        }
      }
    },

    /**
     * Lock pills if needed.
     */
    lockPillsIfNeeded() {
      const pills = window.wxeBridge?.contentTypePills || [];

      pills.forEach((pill) => {
        if (pill.id === 'on-this-page') return;
        if (!pill.locked) return;

        const pillBtn = document.querySelector(`[data-pill="${pill.id}"]`);
        if (pillBtn) {
          pillBtn.classList.add("wxe-pill--locked");
          this.injectPillLockIcon(pillBtn);
          this.makeClickable(pillBtn);
        }
      });
    },

    /**
     * Lock context chip if needed.
     */
    lockContextIfNeeded() {
      const mode = window.wxeBridge?.lockingMode || "free";
      if ("free" !== mode) return;

      const contextChip = document.getElementById("wxe-context-chip");
      if (!contextChip) return;
      contextChip.classList.add("wxe-context-chip--locked");
      // Inject hidden lock icon (shown on hover via CSS).
      if (!contextChip.querySelector(".wxe-context-lock")) {
        contextChip.appendChild(this.createLockSVG("wxe-context-lock", 14, 2));
      }
      this.makeClickable(contextChip);
    },

    /**
     * Lock filter sections if needed.
     */
    lockFiltersIfNeeded() {
      // Filters are available in supporter and pro — only lock in free mode.
      const mode = window.wxeBridge?.lockingMode || "free";
      if ("free" !== mode) return;

      // Lock entire filter list blocks (header + chips together).
      // The filter sections are the 2nd and 3rd .content-hub-list in the sidebar
      // (after .content-hub-list--current-context).
      const allLists = document.querySelectorAll(".content-hub_sidebar__content > .content-hub-list");
      allLists.forEach((list) => {
        // Skip the Current Context section.
        if (list.classList.contains("content-hub-list--current-context")) return;
        // Only lock lists that contain a .wxe-filter-section.
        if (!list.querySelector(".wxe-filter-section")) return;

        list.classList.add("wxe-filter-list--locked");
        this.makeClickable(list);
        this.injectLockOverlay(list);
      });
    },

    /**
     * Lock AI Translation section if needed.
     */
    lockAiSectionIfNeeded() {
      const section = document.querySelector(".wxe-ai-settings-section--locked");
      if (!section) return;
      const mode = window.wxeBridge?.lockingMode || "free";
      const msg = mode === "free" ? undefined : "Pro license required";
      this.makeClickable(section, msg);
    },

    /**
     * Lock Quick WPML Access footer if needed.
     */
    lockQuickAccessIfNeeded() {
      const mode = window.wxeBridge?.lockingMode || "free";
      if ("free" !== mode) return;
      const footer = document.getElementById("wxe-sidebar-footer");
      if (!footer) return;
      footer.classList.add("wxe-sidebar-footer--locked");
      // Disable interestfor tooltips inside the locked footer.
      footer.querySelectorAll("[interestfor]").forEach(el => el.removeAttribute("interestfor"));
      this.makeClickable(footer);
      this.injectLockOverlay(footer);
      const header = footer.querySelector(".content-hub-list__header");
      if (header && !header.querySelector(".wxe-sidebar-lock")) {
        this.injectSidebarLockIcon(header);
      }
    },

    /**
     * Create a lock SVG element parsed in the correct SVG namespace.
     *
     * Using innerHTML on a div ensures the browser parses the SVG in the SVG
     * namespace, so attributes like viewBox are case-sensitive and work correctly.
     *
     * @param {string} cssClass   - Class name for the SVG element.
     * @param {number} size       - Width and height in pixels.
     * @param {number} strokeWidth - Stroke width value.
     * @returns {SVGElement}
     */
    createLockSVG(cssClass, size, strokeWidth) {
      const div = document.createElement("div");
      div.innerHTML = '<svg class="' + cssClass + '" xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' + strokeWidth + '" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>';
      return div.firstElementChild;
    },

    /**
     * Inject lock icon into search bar.
     *
     * @param {HTMLElement} searchBar
     */
    injectSearchLockIcon(searchBar) {
      if (searchBar.querySelector(".wxe-search-lock")) return;
      searchBar.appendChild(this.createLockSVG("wxe-search-lock", 12, 2));
    },

    /**
     * Inject lock icon into pill.
     *
     * @param {HTMLElement} pillBtn
     */
    injectPillLockIcon(pillBtn) {
      if (pillBtn.querySelector('.wxe-pill-lock')) return;
      pillBtn.appendChild(this.createLockSVG("wxe-pill-lock", 14, 2.5));
    },

    /**
     * Inject lock icon into context chip.
     *
     * @param {HTMLElement} contextChip
     */
    injectContextLockIcon(contextChip) {
      if (contextChip.querySelector('.wxe-pill-lock')) return;
      contextChip.appendChild(this.createLockSVG("wxe-pill-lock", 10, 2));
    },

    /**
     * Inject lock icon into sidebar header.
     *
     * @param {HTMLElement} header
     */
    injectSidebarLockIcon(header) {
      if (header.querySelector('.wxe-sidebar-lock')) return;
      header.appendChild(this.createLockSVG("wxe-sidebar-lock", 12, 2));
    },

    /** Unlock message shown on hover. */
    unlockMsg: "Click to activate your license",

    /** Shared tooltip element, created once. */
    _tooltip: null,

    /**
     * Get or create the shared lock tooltip element.
     */
    getTooltip() {
      if (this._tooltip) return this._tooltip;

      const tip = document.createElement("div");
      tip.className = "wxe-tooltip wxe-lock-tooltip";
      tip.textContent = this.unlockMsg;
      tip.style.position = "fixed";
      tip.style.zIndex = "9999";
      tip.style.display = "none";
      tip.style.pointerEvents = "none";
      // Append inside the panel so CSS custom properties resolve correctly.
      const panel = document.getElementById("wxe-panel") || document.body;
      panel.appendChild(tip);
      this._tooltip = tip;
      return tip;
    },

    /**
     * Show tooltip near trigger element.
     */
    showTooltip(trigger, customMsg) {
      const tip = this.getTooltip();
      if (customMsg) tip.textContent = customMsg;
      else tip.textContent = this.unlockMsg;
      tip.style.display = "";

      const rect = trigger.getBoundingClientRect();
      const tipRect = tip.getBoundingClientRect();

      // Position above the element, centered horizontally.
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

      tip.style.top = top + "px";
      tip.style.left = left + "px";
    },

    /**
     * Hide the shared tooltip.
     */
    hideTooltip() {
      if (this._tooltip) {
        this._tooltip.style.display = "none";
      }
    },

    /**
     * Make a locked element clickable to open the license popup.
     * Adds hover tooltip + pointer cursor + click handler.
     */
    makeClickable(el, tooltipMsg) {
      el.style.pointerEvents = "auto";
      el.style.cursor = "pointer";
      el.addEventListener("mouseenter", () => this.showTooltip(el, tooltipMsg));
      el.addEventListener("mouseleave", () => this.hideTooltip());
      el.addEventListener("click", (e) => {
        e.preventDefault();
        e.stopPropagation();
        this.hideTooltip();
        this.openLicensePopup();
      });
    },

    /**
     * Inject a centered lock SVG overlay that appears on hover
     * (same pattern as context chip lock).
     */
    injectLockOverlay(el) {
      if (el.querySelector(".wxe-lock-overlay")) return;
      el.appendChild(this.createLockSVG("wxe-lock-overlay", 18, 2));
    },

    /**
     * Open the license popup. Delegates to the module-exported function
     * registered on window by license.js.
     */
    openLicensePopup() {
      if (typeof window.wxeShowLicensePopup === "function") {
        window.wxeShowLicensePopup();
      }
    },

    /**
     * Initialize locking on panel ready.
     */
    init() {
      // Wait for panel to be rendered with a more robust check
      const tryApplyLock = () => {
        const panel = document.getElementById("wxe-panel");
        const pillsBar = document.getElementById("wxe-pills-bar");
        const searchInput = document.getElementById("wxe-search-input");
        
        // Only apply if all key elements exist
        if (panel && pillsBar && searchInput) {
          // Small delay to ensure DOM is fully settled
          setTimeout(() => {
            this.applyLockUI();
          }, 100);
          return true;
        }
        return false;
      };

      // Try immediate application
      if (tryApplyLock()) {
        return;
      }

      // Otherwise observe for changes
      const observer = new MutationObserver((mutations, obs) => {
        if (tryApplyLock()) {
          obs.disconnect();
        }
      });

      observer.observe(document.body, {
        childList: true,
        subtree: true,
      });

      // Fallback: retry after a delay
      setTimeout(() => {
        tryApplyLock();
      }, 500);
    },
  };

  // Auto-initialize when DOM is ready
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
      window.WXELocking.init();
    });
  } else {
    window.WXELocking.init();
  }
})();
