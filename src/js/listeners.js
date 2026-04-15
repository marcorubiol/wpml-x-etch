import { state } from './state.js';
import { closePanel, getOurButton, buildPanel } from './panel.js';
import { switchPill } from './rendering.js';
import { refreshLanguagesStatus, startStatusRefreshInterval, resyncLocal } from './data.js';
import { updateStatusDot, calculateCombinedStatus } from './status.js';
import { checkLock } from './utils.js';
import { findEtchSaveButton } from './translation.js';


export function listenForPanelChanges() {
  // Etch manages its sidebar buttons via selected="true"/"false".
  // Our button is injected and NOT managed by Etch, so Etch never
  // changes its selected attribute. Instead, we watch the settings bar
  // for ANY Etch button receiving selected="true" -- that means the
  // user activated a native Etch panel and we should close ours.
  const startObserving = () => {
    const settingsBar = document.querySelector(
      ".settings-bar, .etch-settings-bar, .etch-app__settings-bar",
    );
    if (!settingsBar) {
      setTimeout(startObserving, 200);
      return;
    }

    state.panelChangeObserver = new MutationObserver((mutations) => {
      if (state.isOpeningPanel) return;

      const panel = document.getElementById("wxe-panel");
      if (!panel || !panel.classList.contains("is-active")) return;

      for (const mutation of mutations) {
        if (
          mutation.attributeName === "selected" &&
          mutation.target.getAttribute("selected") === "true" &&
          mutation.target !== getOurButton()
        ) {
          closePanel();
          return;
        }
      }
    });

    // subtree:true catches attribute changes on all child buttons.
    state.panelChangeObserver.observe(settingsBar, {
      attributes: true,
      attributeFilter: ["selected"],
      subtree: true,
    });
  };

  startObserving();
}

export function listenForKeyboard() {
  document.addEventListener("keydown", (e) => {
    const panel = document.getElementById("wxe-panel");
    const isOpen = panel && panel.classList.contains("is-active");
    if (!isOpen) return;

    // Escape: if search is open, close search first; otherwise close panel.
    if (e.key === "Escape") {
      const searchInline = document.getElementById("wxe-search-inline");
      if (searchInline && searchInline.classList.contains("wxe-search-inline--visible")) {
        const searchToggle = document.getElementById("wxe-search-toggle");
        if (searchToggle) searchToggle.click();
        return;
      }
      closePanel();
      return;
    }

    // Skip shortcuts when an input is focused.
    const active = document.activeElement;
    if (active && (active.tagName === "INPUT" || active.tagName === "TEXTAREA" || active.isContentEditable)) {
      return;
    }

    // "/" opens and focuses search bar.
    if (e.key === "/") {
      e.preventDefault();
      if (checkLock('search')) return;
      const searchInline = document.getElementById("wxe-search-inline");
      const searchToggle = document.getElementById("wxe-search-toggle");
      if (searchInline && !searchInline.classList.contains("wxe-search-inline--visible") && searchToggle) {
        searchToggle.click();
      }
      const searchInput = document.getElementById("wxe-search-input");
      if (searchInput) searchInput.focus();
      return;
    }

    // 1-9 selects pill by index (only without modifier keys).
    if (!e.metaKey && !e.ctrlKey && !e.altKey && !e.shiftKey) {
      const num = parseInt(e.key, 10);
      if (num >= 1 && num <= 9) {
        const pills = window.wxeBridge.contentTypePills || [];
        if (num <= pills.length) {
          e.preventDefault();
          switchPill(pills[num - 1].id);
        }
      }
    }
  });
}

export function listenForEtchSaves() {
  const postId = window.wxeBridge?.currentPostId;
  let refreshScheduled = false;

  function scheduleRefresh() {
    if (refreshScheduled) return;
    refreshScheduled = true;
    // Minimal delay to debounce rapid successive saves, then refresh immediately.
    // The REST endpoint flushes pending save queue server-side, so no need to wait.
    setTimeout(async () => {
      await refreshLanguagesStatus();
      refreshScheduled = false;
      resyncLocal();
    }, 100);
  }

  // Regex to match postId as a full path segment (avoids false positives: /1 matching /100).
  const postIdSegmentRe = new RegExp("/" + postId + "(?:\\D|$)");

  // -- XHR prototype patch (works even for XHR created before this runs) --
  // Store originals so we can restore them on teardown.
  const origOpen = XMLHttpRequest.prototype.open;
  const origSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function (method, url) {
    this._wxeMethod = method;
    this._wxeUrl = typeof url === "string" ? url : "";
    return origOpen.apply(this, arguments);
  };
  XMLHttpRequest.prototype.send = function () {
    if (
      postId &&
      (this._wxeMethod === "POST" ||
        this._wxeMethod === "PUT" ||
        this._wxeMethod === "PATCH") &&
      this._wxeUrl.includes("/wp-json/") &&
      postIdSegmentRe.test(this._wxeUrl)
    ) {
      this.addEventListener("load", () => {
        if (this.status >= 200 && this.status < 300) scheduleRefresh();
      });
    }
    return origSend.apply(this, arguments);
  };

  // -- fetch patch (works if Etch references window.fetch at call time) --
  const origFetch = window.fetch;
  window.fetch = function (input) {
    // Extract URL from all possible fetch() call signatures:
    // fetch("url", opts), fetch(Request), fetch(URL)
    let url = "";
    if (typeof input === "string") {
      url = input;
    } else if (input instanceof Request) {
      url = input.url;
    } else if (input instanceof URL) {
      url = input.href;
    } else if (input && typeof input === "object" && input.url) {
      url = input.url;
    }

    const method =
      (input instanceof Request ? input.method : null) ||
      arguments[1]?.method ||
      "GET";
    const promise = origFetch.apply(this, arguments);
    if (
      postId &&
      (method === "POST" || method === "PUT" || method === "PATCH") &&
      url.includes("/wp-json/") &&
      postIdSegmentRe.test(url)
    ) {
      promise
        .then((res) => { if (res.ok) scheduleRefresh(); })
        .catch(e => console.warn('[WXE] save-detect fetch failed', e.message));
    }
    return promise;
  };

  // Register teardown to restore original prototypes when no longer needed.
  state.teardownNetworkPatches = () => {
    XMLHttpRequest.prototype.open = origOpen;
    XMLHttpRequest.prototype.send = origSend;
    window.fetch = origFetch;
    state.teardownNetworkPatches = null;
  };

  // -- Watch Etch Save button for completion --
  const saveButton = findEtchSaveButton();
  if (saveButton) {
    state.saveButtonObserver = new MutationObserver(() => {
      const wasBusy = saveButton.dataset.wxeSaveBusy === "true";
      const isBusy =
        saveButton.disabled ||
        saveButton.getAttribute("aria-busy") === "true" ||
        saveButton.classList.contains("is-loading");

      saveButton.dataset.wxeSaveBusy = isBusy ? "true" : "false";

      // Detect save completion: was busy, now not busy
      if (wasBusy && !isBusy) {
        // Etch save completed, refreshing component list
        scheduleRefresh();
      }
    });

    state.saveButtonObserver.observe(saveButton, {
      attributes: true,
      attributeFilter: ["disabled", "aria-busy", "class"],
    });

    // Watching Etch Save button for changes
  }

  // -- Polling fallback (guarantees update if both patches miss) --
  startStatusRefreshInterval();
}

export function listenForVisibility() {
  document.addEventListener("visibilitychange", () => {
    if (!document.hidden) {
      const panel = document.getElementById("wxe-panel");
      if (panel && panel.classList.contains("is-active")) {
        // 1s delay gives WPML's invisible webhook time to update local statuses after ATE completion
        setTimeout(refreshLanguagesStatus, 1000);
      }
    }
  });
}

export function listenForContextChanges() {
  let lastPostId = null;

  // Extract post_id from URL
  const getPostIdFromUrl = () => {
    const match = window.location.href.match(/[?&]post_id=(\d+)/);
    return match ? parseInt(match[1], 10) : null;
  };

  // Refresh wxeBridge data for new post_id
  const refreshContextData = async (postId) => {
    if (!postId) return false;

    try {
      const resp = await fetch(
        `${window.wxeBridge.restUrl}languages-status?post_id=${postId}`,
        {
          headers: { "X-WP-Nonce": window.wxeBridge.restNonce },
          credentials: "same-origin",
        }
      );
      const data = await resp.json();

      if (resp.ok && data) {
        // Update wxeBridge with fresh data
        window.wxeBridge.languages = data.languages || {};
        window.wxeBridge.components = data.components || [];
        window.wxeBridge.combinedStatus = data.combinedStatus
            || calculateCombinedStatus(window.wxeBridge.languages, window.wxeBridge.components);
        window.wxeBridge.currentPostId = postId;
        window.wxeBridge.currentPostType = data.currentPostType || '';
        window.wxeBridge.postTitle = data.postTitle || '';
        window.wxeBridge.postTypeLabel = data.postTypeLabel || '';
        if (data.isTranslatable !== undefined) {
          window.wxeBridge.isTranslatable = data.isTranslatable;
        }

        return true;
      }
    } catch (err) {
      // Failed to refresh context data
    }
    return false;
  };

  // Check for post_id changes (guarded against concurrent calls from multiple triggers).
  let contextCheckInFlight = false;
  const checkPostIdChange = async () => {
    if (contextCheckInFlight) return;
    contextCheckInFlight = true;
    try {

    const currentPostId = getPostIdFromUrl();

    if (currentPostId && currentPostId !== lastPostId) {
      lastPostId = currentPostId;

      // Always refresh data in background, even if panel is closed
      // This way data is ready when user opens the panel
      const panel = document.getElementById("wxe-panel");
      const panelIsOpen = panel && panel.classList.contains("is-active");

      if (panelIsOpen) {
        panel.classList.add("wxe-panel--loading");
      }

      const success = await refreshContextData(currentPostId);

      if (success) {
        updateStatusDot();
        if (panelIsOpen) {
          buildPanel();
          const newPanel = document.getElementById("wxe-panel");
          if (newPanel) {
            newPanel.classList.remove("wxe-panel--loading");
          }
          // Force immediate polling for fully fresh data.
          refreshLanguagesStatus();
        }
      } else if (panelIsOpen && panel) {
        panel.classList.remove("wxe-panel--loading");
      }
    }

    } finally {
      contextCheckInFlight = false;
    }
  };

  // Initialize with current post_id
  lastPostId = getPostIdFromUrl();

  // Listen for popstate (back/forward navigation)
  window.addEventListener("popstate", () => {
    setTimeout(checkPostIdChange, 100);
  });

  // Intercept pushState and replaceState
  const originalPushState = history.pushState;
  const originalReplaceState = history.replaceState;

  history.pushState = function() {
    originalPushState.apply(this, arguments);
    setTimeout(checkPostIdChange, 100);
  };

  history.replaceState = function() {
    originalReplaceState.apply(this, arguments);
    setTimeout(checkPostIdChange, 100);
  };

  // Poll for changes as fallback
  startContextCheckInterval();

  function startContextCheckInterval() {
    if (state.contextCheckInterval) return;
    state.contextCheckInterval = setInterval(checkPostIdChange, 2000);
  }
}
