import { escapeHtml } from './utils.js';

const ICON_KEY = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/></svg>';
const ICON_SHIELD = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67 0C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>';
const ICON_WARN = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67 0C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>';
const ICON_CLOSE = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
const ICON_BMC = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><line x1="6" y1="2" x2="6" y2="4"/><line x1="10" y1="2" x2="10" y2="4"/><line x1="14" y1="2" x2="14" y2="4"/></svg>';

function getLicense() {
  return window.wxeBridge?.licenseStatus || { tier: null, is_valid: false, email: '', expires_at: null, key_masked: '' };
}

function getTier() {
  return window.wxeBridge?.lockingMode || 'supporter';
}

/**
 * Build the content footer area: optional BMC link + license status button.
 */
export function buildLicenseFooterHtml() {
  const license = getLicense();
  const tier = getTier();
  // BMC hidden only when user has a real pro license (not a dev constant override).
  const hasProLicense = license.is_valid && license.tier === 'pro';
  const hasSupporterLicense = license.is_valid && license.tier === 'supporter';

  const bmcHtml = `<a href="https://buymeacoffee.com/zerosense.studio" target="_blank" rel="noopener noreferrer" class="wxe-bmc-btn">${ICON_BMC} Buy me a coffee</a>`;

  if (hasProLicense) {
    const email = license.email ? ` \u00b7 ${escapeHtml(license.email)}` : '';
    const licenseBtn = `<button type="button" class="wxe-license-btn wxe-license-btn--pro" id="wxe-license-btn">${ICON_SHIELD} Pro${email}</button>`;
    return `<div class="wxe-content-footer">${licenseBtn}</div>`;
  }

  if (hasSupporterLicense) {
    const licenseBtn = `<button type="button" class="wxe-license-btn wxe-license-btn--supporter" id="wxe-license-btn">${ICON_SHIELD} Supporter</button>`;
    return `<div class="wxe-content-footer">${licenseBtn}${bmcHtml}</div>`;
  }

  if (license.tier && !license.is_valid) {
    const licenseBtn = `<button type="button" class="wxe-license-btn wxe-license-btn--expired" id="wxe-license-btn">${ICON_WARN} Expired \u00b7 Renew</button>`;
    return `<div class="wxe-content-footer">${licenseBtn}${bmcHtml}</div>`;
  }

  // No license: show tier label + activate button (only visible in free mode).
  const tierLabel = tier === 'free' ? 'Free' : 'Supporter';
  const licenseBtn = `<button type="button" class="wxe-license-btn wxe-license-btn--default" id="wxe-license-btn">${ICON_SHIELD} ${tierLabel}</button>`;
  return `<div class="wxe-content-footer">${licenseBtn}${bmcHtml}</div>`;
}

/**
 * Build the license popup overlay content.
 */
function buildLicensePopupHtml() {
  const license = getLicense();
  const hasKey = !!license.key_masked;

  let statusHtml;
  if (hasKey && license.is_valid) {
    statusHtml = `
      <div class="wxe-license-info">
        <div class="wxe-license-info-row"><span class="wxe-license-info-label">Status</span><span class="wxe-license-info-value wxe-license-info-value--pro">${escapeHtml(license.tier === 'pro' ? 'Pro' : 'Supporter')}</span></div>
        ${license.email ? `<div class="wxe-license-info-row"><span class="wxe-license-info-label">Email</span><span class="wxe-license-info-value">${escapeHtml(license.email)}</span></div>` : ''}
        ${license.expires_at ? `<div class="wxe-license-info-row"><span class="wxe-license-info-label">Expires</span><span class="wxe-license-info-value">${escapeHtml(license.expires_at.split('T')[0])}</span></div>` : ''}
        <div class="wxe-license-info-row"><span class="wxe-license-info-label">Key</span><span class="wxe-license-info-value wxe-license-info-value--mono">${escapeHtml(license.key_masked)}</span></div>
      </div>
      <div class="wxe-license-activate-form wxe-license-change-form">
        <label class="wxe-ai-label" for="wxe-license-key-input">Change license</label>
        <div class="wxe-ai-key-row">
          <input type="text" class="wxe-ai-input" id="wxe-license-key-input" placeholder="Enter new license key" autocomplete="off" />
          <button type="button" class="wxe-secondary-btn" id="wxe-license-activate-btn">Activate</button>
        </div>
        <span class="wxe-license-result" id="wxe-license-result"></span>
      </div>
      <button type="button" class="wxe-secondary-btn wxe-license-deactivate" id="wxe-license-deactivate">Deactivate</button>`;
  } else if (hasKey && !license.is_valid) {
    statusHtml = `
      <div class="wxe-license-info">
        <div class="wxe-license-info-row"><span class="wxe-license-info-label">Status</span><span class="wxe-license-info-value wxe-license-info-value--expired">Expired</span></div>
        <div class="wxe-license-info-row"><span class="wxe-license-info-label">Key</span><span class="wxe-license-info-value wxe-license-info-value--mono">${escapeHtml(license.key_masked)}</span></div>
      </div>
      <div class="wxe-license-actions">
        <a href="https://zerosense.studio/account" target="_blank" rel="noopener" class="wxe-secondary-btn">Renew</a>
        <button type="button" class="wxe-secondary-btn wxe-license-deactivate" id="wxe-license-deactivate">Deactivate</button>
      </div>`;
  } else {
    const tierLabel = getTier() === 'free' ? 'Free' : 'Supporter';
    statusHtml = `
      <div class="wxe-license-tier-badge">${escapeHtml(tierLabel)}</div>
      <div class="wxe-license-activate-form">
        <label class="wxe-ai-label" for="wxe-license-key-input">License key</label>
        <div class="wxe-ai-key-row">
          <input type="text" class="wxe-ai-input" id="wxe-license-key-input" placeholder="Enter your license key" autocomplete="off" />
          <button type="button" class="wxe-secondary-btn" id="wxe-license-activate-btn">Activate</button>
        </div>
        <span class="wxe-license-result" id="wxe-license-result"></span>
      </div>`;
  }

  return `
    <div class="wxe-license-popup">
      <div class="wxe-license-popup-header">
        <span class="wxe-license-popup-title">License</span>
        <button type="button" class="wxe-license-popup-close" id="wxe-license-popup-close">${ICON_CLOSE}</button>
      </div>
      <div class="wxe-license-popup-body">
        ${statusHtml}
      </div>
      <div class="wxe-license-popup-footer">
        <a href="https://zerosense.studio" target="_blank" rel="noopener" class="wxe-text-link wxe-footer-text-link">zerosense.studio</a>
      </div>
    </div>`;
}

export function showPopup() {
  const overlay = document.getElementById('wxe-status-overlay');
  const content = document.getElementById('wxe-status-overlay-content');
  if (!overlay || !content) return;

  content.innerHTML = buildLicensePopupHtml();
  overlay.style.display = 'flex';
  overlay.classList.add('wxe-license-overlay-active');

  // Close handlers.
  const closeBtn = document.getElementById('wxe-license-popup-close');
  if (closeBtn) closeBtn.addEventListener('click', hidePopup);

  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) hidePopup();
  }, { once: true });

  // Activate handler.
  const activateBtn = document.getElementById('wxe-license-activate-btn');
  if (activateBtn) activateBtn.addEventListener('click', handleActivate);

  // Deactivate handler.
  const deactivateBtn = document.getElementById('wxe-license-deactivate');
  if (deactivateBtn) deactivateBtn.addEventListener('click', handleDeactivate);
}

function hidePopup() {
  const overlay = document.getElementById('wxe-status-overlay');
  if (overlay) {
    overlay.style.display = 'none';
    overlay.classList.remove('wxe-license-overlay-active');
  }
}

async function handleActivate() {
  const input = document.getElementById('wxe-license-key-input');
  const result = document.getElementById('wxe-license-result');
  const btn = document.getElementById('wxe-license-activate-btn');
  if (!input || !btn) return;

  const key = input.value.trim();
  if (!key) {
    if (result) { result.textContent = 'Enter a license key'; result.className = 'wxe-license-result wxe-license-result--error'; }
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Activating\u2026';
  if (result) { result.textContent = ''; result.className = 'wxe-license-result'; }

  try {
    const bridge = window.wxeBridge || {};
    const res = await fetch(`${bridge.restUrl}license/activate`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': bridge.restNonce },
      body: JSON.stringify({ key }),
    });
    const data = await res.json();

    if (!res.ok) {
      const msg = data?.message || data?.error || 'Activation failed';
      if (result) { result.textContent = msg; result.className = 'wxe-license-result wxe-license-result--error'; }
      btn.disabled = false;
      btn.textContent = 'Activate';
      return;
    }

    // Update bridge and refresh UI.
    await refreshAfterLicenseChange();
    hidePopup();
  } catch (err) {
    if (result) { result.textContent = err.message; result.className = 'wxe-license-result wxe-license-result--error'; }
    btn.disabled = false;
    btn.textContent = 'Activate';
  }
}

async function handleDeactivate() {
  const btn = document.getElementById('wxe-license-deactivate');
  if (!btn) return;

  btn.disabled = true;
  btn.textContent = 'Deactivating\u2026';

  try {
    const bridge = window.wxeBridge || {};
    const res = await fetch(`${bridge.restUrl}license/deactivate`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': bridge.restNonce },
    });

    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      const msg = data?.message || 'Deactivation failed';
      const result = document.getElementById('wxe-license-result');
      if (result) { result.textContent = msg; result.className = 'wxe-license-result wxe-license-result--error'; }
      btn.disabled = false;
      btn.textContent = 'Deactivate';
      return;
    }

    await refreshAfterLicenseChange();
    hidePopup();
  } catch {
    btn.disabled = false;
    btn.textContent = 'Deactivate';
  }
}

async function refreshAfterLicenseChange() {
  const bridge = window.wxeBridge || {};
  try {
    const res = await fetch(`${bridge.restUrl}license/status`, {
      headers: { 'X-WP-Nonce': bridge.restNonce },
    });
    const status = await res.json();
    bridge.licenseStatus = status;

    // Determine new locking mode from license.
    if (status.is_valid && status.tier) {
      bridge.lockingMode = status.tier;
    } else {
      // Fall back to default (no license).
      bridge.lockingMode = bridge._defaultLockingMode || 'supporter';
    }

    // Update AI access based on new tier.
    bridge.aiAccess = bridge.lockingMode === 'pro';

    // Re-render the footer.
    const footer = document.querySelector('.wxe-content-footer');
    if (footer) {
      footer.outerHTML = buildLicenseFooterHtml();
    }

    // Re-apply locking if the module exists.
    if (window.WXELocking && typeof window.WXELocking.applyLockUI === 'function') {
      window.WXELocking.applyLockUI();
    }

    // Reload the page to fully refresh panel state (pills, AI settings, etc.).
    // This is the simplest reliable approach for v1.
    window.location.reload();
  } catch {
    // Fallback: reload page on fetch failure.
    window.location.reload();
  }
}

// Expose globally so wxe-locking.js (non-module) can call it.
window.wxeShowLicensePopup = showPopup;

/**
 * Attach license button click listener.
 */
export function attachLicenseListeners(panel) {
  panel.addEventListener('click', (e) => {
    const btn = e.target.closest('#wxe-license-btn');
    if (btn) {
      e.stopPropagation();
      showPopup();
    }
  });

  // Store the default locking mode so we can fall back after deactivation.
  const bridge = window.wxeBridge || {};
  if (!bridge._defaultLockingMode) {
    // If license is active, the current lockingMode is from the license.
    // The default is what it would be without the license.
    if (bridge.licenseStatus?.is_valid && bridge.licenseStatus?.tier) {
      bridge._defaultLockingMode = 'supporter'; // filter default
    } else {
      bridge._defaultLockingMode = bridge.lockingMode;
    }
  }
}
