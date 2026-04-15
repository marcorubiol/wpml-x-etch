import { state } from './state.js';
import { setLanguageButtonsDisabled, restoreAfterAteReturn, setPanelFunctions } from './translation.js';
import { buildPanel, openPanel, togglePanel, applyPanelOffset, setListenerFunctions } from './panel.js';
import { injectStatusDot } from './status.js';
import { refreshLanguagesStatus } from './data.js';
import { listenForPanelChanges, listenForKeyboard, listenForVisibility, listenForEtchSaves, listenForContextChanges } from './listeners.js';
import { loadFilterPrefs } from './filterPrefs.js';
import { msg } from './utils.js';

// Hydrate persisted Languages/Status filters before the panel is built so the
// first render reflects the user's saved preferences.
loadFilterPrefs();

// Wire cross-module deps to avoid circular imports
setPanelFunctions(buildPanel, openPanel);
setListenerFunctions(listenForEtchSaves);

window.addEventListener("load", () => {
  const maxAttempts = 50;
  const pollInterval = 100;

  function initPanel() {
    if (
      !window.etchControls ||
      !window.etchControls.builder ||
      !window.etchControls.builder.settingsBar
    ) {
      state.attempts++;
      if (state.attempts < maxAttempts) setTimeout(initPanel, pollInterval);
      return;
    }
    if (!window.wxeBridge) return;

    try {
      window.etchControls.builder.settingsBar.bottom.addBefore({
        id: "wxe-translations",
        icon: "vscode-icons:file-type-wpml",
        tooltip: msg().translations || "Translations",
        callback: togglePanel,
      });
    } catch (e) {
      // Silently fail if button cannot be added.
    }

    injectStatusDot();
    buildPanel();
    refreshLanguagesStatus(); // Correct the dot immediately (enqueue data may be stale).
    listenForPanelChanges();
    listenForKeyboard();
    listenForVisibility();
    listenForEtchSaves();
    listenForContextChanges();
    restoreAfterAteReturn();
    window.addEventListener("resize", () => {
      const panel = document.getElementById("wxe-panel");
      if (panel && panel.classList.contains("is-active")) {
        applyPanelOffset(panel);
      }
    });

    // Reset stuck state on bfcache restore (browser back button from ATE).
    window.addEventListener("pageshow", (e) => {
      if (e.persisted) {
        state.isOpeningTranslation = false;
        setLanguageButtonsDisabled(false);
        const overlay = document.getElementById("wxe-status-overlay");
        if (overlay) overlay.style.display = "none";
      }
    });
  }

  initPanel();

  // The original file also called listenForContextChanges() at the top-level
  // of the load handler (line 2368). That call is now inside initPanel() above,
  // which matches the original execution order since initPanel() runs synchronously
  // on first call when etchControls is ready, or eventually via setTimeout polling.

  // Clean up permanent lifecycle observers on page unload to prevent leaks.
  window.addEventListener("beforeunload", () => {
    if (state.contextCheckInterval) { clearInterval(state.contextCheckInterval); state.contextCheckInterval = null; }
    if (state.panelChangeObserver) { state.panelChangeObserver.disconnect(); state.panelChangeObserver = null; }
    if (state.teardownNetworkPatches) { state.teardownNetworkPatches(); }
  });
});
