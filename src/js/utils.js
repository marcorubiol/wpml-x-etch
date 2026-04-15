
// Prevents XSS when interpolating dynamic strings into innerHTML.
export function escapeHtml(s) {
  const d = document.createElement("div");
  d.textContent = s;
  return d.innerHTML;
}

// Gracefully degrades when locking module is not loaded
export function checkLock(action, ...args) {
  return window.WXELocking?.shouldBlockAction(action, ...args) || false;
}

// wp_localize_script stringifies everything: true->"1", false->"0"/""
// "0" is truthy in JS, so we must compare explicitly.
export function isPostTranslatable() {
  const v = window.wxeBridge?.isTranslatable;
  return v === 1 || v === "1" || v === true;
}

/** Shorthand for translated UI strings passed from PHP. */
export const msg = () => (window.wxeBridge && window.wxeBridge.messages) || {};

export function wait(ms) {
  return new Promise((resolve) => window.setTimeout(resolve, ms));
}
