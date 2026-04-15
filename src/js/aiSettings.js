import { msg, escapeHtml } from './utils.js';

export function buildAiSettingsHtml() {
  const m = msg();
  const bridge = window.wxeBridge || {};

  if (!bridge.aiAccess) {
    return `<div class="wxe-ai-settings-section wxe-ai-settings-section--locked" id="wxe-ai-settings-section">
    <button type="button" class="wxe-lang-switcher-accordion wxe-sidebar-section-heading wxe-ai-locked-btn" id="wxe-ai-locked-accordion">
      <span class="wxe-lang-switcher-accordion-label">${escapeHtml(m.aiTranslation || 'AI Translation')}</span>
    </button>
    <svg class="wxe-lock-overlay" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
  </div>`;
  }

  const configured = bridge.aiConfigured;
  const verified = bridge.aiVerified;
  const statusLabel = verified
    ? (m.aiConfigured || 'Configured')
    : configured
      ? (m.aiNotVerified || 'Not verified')
      : (m.aiNotConfiguredShort || 'Not configured');

  return `<div class="wxe-ai-settings-section" id="wxe-ai-settings-section">
    <button type="button" class="wxe-lang-switcher-accordion wxe-sidebar-section-heading" id="wxe-ai-settings-accordion" aria-expanded="false" aria-controls="wxe-ai-settings-body">
      <span class="wxe-lang-switcher-accordion-label">${escapeHtml(m.aiTranslation || 'AI Translation')}</span>
      <span class="wxe-lang-switcher-status" id="wxe-ai-settings-status">${escapeHtml(statusLabel)}</span>
      <svg class="wxe-lang-switcher-chevron" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
    </button>
    <div class="wxe-ai-settings-body" id="wxe-ai-settings-body" hidden>
      <div class="wxe-ai-field">
        <label class="wxe-ai-label" for="wxe-ai-provider">${escapeHtml(m.aiProvider || 'Provider')}</label>
        <select class="wxe-ai-select" id="wxe-ai-provider">
          <option value="">—</option>
          <option value="claude">Claude</option>
          <option value="openai">OpenAI</option>
        </select>
      </div>
      <div class="wxe-ai-field">
        <label class="wxe-ai-label" for="wxe-ai-key">${escapeHtml(m.aiApiKey || 'API Key')}</label>
        <div class="wxe-ai-key-row">
          <input type="password" class="wxe-ai-input" id="wxe-ai-key" placeholder="${escapeHtml(configured ? '••••••••' : m.aiEnterKey || 'Enter API key')}" autocomplete="off"${verified ? ' disabled' : ''} />
          ${!verified ? `<button type="button" class="wxe-secondary-btn wxe-ai-test-btn" id="wxe-ai-test-btn">${escapeHtml(m.aiTest || 'Verify')}</button>` : ''}
          ${verified ? `<button type="button" class="wxe-secondary-btn wxe-ai-clear-btn" id="wxe-ai-clear-btn">${escapeHtml(m.aiClearKey || 'Clear')}</button>` : ''}
        </div>
        <span class="wxe-ai-test-result" id="wxe-ai-test-result">${escapeHtml(m.aiNotVerified || 'Not verified')}</span>
      </div>
      <div class="wxe-ai-field">
        <label class="wxe-ai-label" for="wxe-ai-tone">${escapeHtml(m.aiTone || 'Tone')}</label>
        <select class="wxe-ai-select" id="wxe-ai-tone">
          <option value="formal">${escapeHtml(m.aiFormal || 'Formal')}</option>
          <option value="informal">${escapeHtml(m.aiInformal || 'Informal')}</option>
        </select>
      </div>
    </div>
  </div>`;
}

export function attachAiSettingsListeners(panel) {
  // Locked state: handled by wxe-locking.js (tooltip + click → license popup).
  if (panel.querySelector('.wxe-ai-settings-section--locked')) return;

  const accordion = panel.querySelector('#wxe-ai-settings-accordion');
  const body = panel.querySelector('#wxe-ai-settings-body');
  if (!accordion || !body) return;

  // Load current settings from server.
  loadAiSettings();

  accordion.addEventListener('click', () => {
    const expanded = accordion.getAttribute('aria-expanded') === 'true';
    const next = !expanded;
    accordion.setAttribute('aria-expanded', next ? 'true' : 'false');
    body.hidden = !next;
    const section = accordion.closest('.wxe-ai-settings-section');
    if (section) section.classList.toggle('wxe-ai-settings-section--expanded', next);

    // Collapse lang switcher when AI settings opens.
    if (next) {
      const lsAccordion = document.getElementById('wxe-lang-switcher-accordion');
      const lsBody = document.getElementById('wxe-lang-switcher-body');
      const lsSection = document.getElementById('wxe-lang-switcher-section');
      if (lsAccordion && lsBody && lsSection) {
        lsAccordion.setAttribute('aria-expanded', 'false');
        lsBody.hidden = true;
        lsSection.classList.remove('wxe-lang-switcher-section--expanded');
      }
    }
  });

  // Autosave on field change.
  const provider = panel.querySelector('#wxe-ai-provider');
  const tone = panel.querySelector('#wxe-ai-tone');
  const apiKey = panel.querySelector('#wxe-ai-key');
  if (provider) provider.addEventListener('change', saveAiSettings);
  if (tone) tone.addEventListener('change', saveAiSettings);
  if (apiKey) apiKey.addEventListener('change', saveAiSettings);

  const testBtn = panel.querySelector('#wxe-ai-test-btn');
  if (testBtn) testBtn.addEventListener('click', testAiConnection);

  const clearBtn = panel.querySelector('#wxe-ai-clear-btn');
  if (clearBtn) clearBtn.addEventListener('click', clearAiKey);
}

async function loadAiSettings() {
  const bridge = window.wxeBridge;
  if (!bridge) return;

  try {
    const resp = await fetch(`${bridge.restUrl}ai/settings`, {
      headers: { 'X-WP-Nonce': bridge.restNonce },
      credentials: 'same-origin',
    });
    if (!resp.ok) return;
    const data = await resp.json();

    const providerEl = document.getElementById('wxe-ai-provider');
    const toneEl = document.getElementById('wxe-ai-tone');
    const resultEl = document.getElementById('wxe-ai-test-result');
    if (providerEl && data.provider) providerEl.value = data.provider;
    if (toneEl && data.tone) toneEl.value = data.tone;
    if (resultEl && data.hasKey) {
      const m = msg();
      if (data.verified) {
        resultEl.textContent = m.aiTestSuccess || 'Valid';
        resultEl.className = 'wxe-ai-test-result';
      } else {
        resultEl.textContent = m.aiNotVerified || 'Not verified';
        resultEl.className = 'wxe-ai-test-result';
      }
    }
  } catch (e) {
    console.warn('[WXE] loadAiSettings error', e);
    const resultEl = document.getElementById('wxe-ai-test-result');
    const m = msg();
    if (resultEl) { resultEl.textContent = m.aiLoadError || 'Could not load settings.'; resultEl.className = 'wxe-ai-test-result wxe-ai-test-result--error'; }
  }
}

async function saveAiSettings() {
  const bridge = window.wxeBridge;
  if (!bridge) return;

  const provider = document.getElementById('wxe-ai-provider')?.value || '';
  const apiKey = document.getElementById('wxe-ai-key')?.value || '';
  const tone = document.getElementById('wxe-ai-tone')?.value || 'formal';

  try {
    const body = { provider, tone };
    if (apiKey) body.api_key = apiKey;

    const resp = await fetch(`${bridge.restUrl}ai/settings`, {
      method: 'POST',
      headers: {
        'X-WP-Nonce': bridge.restNonce,
        'Content-Type': 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify(body),
    });

    const data = await resp.json();
    const statusEl = document.getElementById('wxe-ai-settings-status');
    const m = msg();

    if (data.hasKey && data.provider) {
      window.wxeBridge.aiConfigured = true;
      // Clear key field after save.
      const keyEl = document.getElementById('wxe-ai-key');
      if (keyEl) { keyEl.value = ''; keyEl.placeholder = '••••••••'; }
    } else {
      if (statusEl) statusEl.textContent = m.aiNotConfiguredShort || 'Not configured';
    }
  } catch (e) {
    console.warn('[WXE] saveAiSettings error', e);
    const resultEl = document.getElementById('wxe-ai-test-result');
    const m = msg();
    if (resultEl) { resultEl.textContent = m.aiSaveError || 'Could not save settings.'; resultEl.className = 'wxe-ai-test-result wxe-ai-test-result--error'; }
  }
}

/**
 * Rebuild the key row buttons to reflect current state.
 */
function refreshKeyRow(configured, verified) {
  const m = msg();
  const keyEl = document.getElementById('wxe-ai-key');
  const row = keyEl?.closest('.wxe-ai-key-row');
  if (!row) return;

  // Remove existing buttons.
  row.querySelectorAll('.wxe-ai-test-btn, .wxe-ai-clear-btn').forEach(b => b.remove());

  // Update input state.
  if (keyEl) {
    keyEl.disabled = verified;
    keyEl.placeholder = configured ? '••••••••' : (m.aiEnterKey || 'Enter API key');
    if (!configured) keyEl.value = '';
  }

  // Add buttons.
  if (!verified) {
    const verifyBtn = document.createElement('button');
    verifyBtn.type = 'button';
    verifyBtn.className = 'wxe-secondary-btn wxe-ai-test-btn';
    verifyBtn.id = 'wxe-ai-test-btn';
    verifyBtn.textContent = m.aiTest || 'Verify';
    verifyBtn.addEventListener('click', testAiConnection);
    row.appendChild(verifyBtn);
  }
  if (verified) {
    const clearBtn = document.createElement('button');
    clearBtn.type = 'button';
    clearBtn.className = 'wxe-secondary-btn wxe-ai-clear-btn';
    clearBtn.id = 'wxe-ai-clear-btn';
    clearBtn.textContent = m.aiClearKey || 'Clear';
    clearBtn.addEventListener('click', clearAiKey);
    row.appendChild(clearBtn);
  }
}

async function clearAiKey() {
  const bridge = window.wxeBridge;
  if (!bridge) return;
  const m = msg();

  try {
    await fetch(`${bridge.restUrl}ai/settings`, {
      method: 'POST',
      headers: {
        'X-WP-Nonce': bridge.restNonce,
        'Content-Type': 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify({ api_key_clear: true }),
    });

    window.wxeBridge.aiConfigured = false;
    window.wxeBridge.aiVerified = false;
    const statusEl = document.getElementById('wxe-ai-settings-status');
    if (statusEl) statusEl.textContent = m.aiNotConfiguredShort || 'Not configured';
    const resultEl = document.getElementById('wxe-ai-test-result');
    if (resultEl) { resultEl.textContent = m.aiKeyCleared || 'Key removed'; resultEl.className = 'wxe-ai-test-result'; }
    refreshKeyRow(false, false);
  } catch (e) {
    console.warn('[WXE] clearAiKey error', e);
    const resultEl = document.getElementById('wxe-ai-test-result');
    const m = msg();
    if (resultEl) { resultEl.textContent = m.aiClearError || 'Could not clear key.'; resultEl.className = 'wxe-ai-test-result wxe-ai-test-result--error'; }
  }
}

async function testAiConnection() {
  const bridge = window.wxeBridge;
  if (!bridge) return;

  const resultEl = document.getElementById('wxe-ai-test-result');
  const testBtn = document.getElementById('wxe-ai-test-btn');
  const m = msg();

  if (resultEl) { resultEl.textContent = m.aiTesting || 'Verifying\u2026'; resultEl.className = 'wxe-ai-test-result'; }
  if (testBtn) testBtn.disabled = true;

  // Save first so the key is stored before testing.
  await saveAiSettings();

  try {
    const resp = await fetch(`${bridge.restUrl}ai/test`, {
      method: 'POST',
      headers: { 'X-WP-Nonce': bridge.restNonce },
      credentials: 'same-origin',
    });

    const data = await resp.json();

    if (resp.ok && data.success) {
      window.wxeBridge.aiConfigured = true;
      window.wxeBridge.aiVerified = true;
      if (resultEl) { resultEl.textContent = m.aiTestSuccess || 'Valid'; resultEl.className = 'wxe-ai-test-result'; }
      const statusEl = document.getElementById('wxe-ai-settings-status');
      if (statusEl) statusEl.textContent = m.aiConfigured || 'Configured';
      refreshKeyRow(true, true);
    } else {
      if (resultEl) { resultEl.textContent = data.message || m.aiTestFailed || 'Invalid key'; resultEl.className = 'wxe-ai-test-result wxe-ai-test-result--error'; }
    }
  } catch (e) {
    if (resultEl) { resultEl.textContent = m.aiTestFailed || 'Invalid key'; resultEl.className = 'wxe-ai-test-result wxe-ai-test-result--error'; }
  } finally {
    if (testBtn) testBtn.disabled = false;
  }
}
