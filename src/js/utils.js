
// Prevents XSS when interpolating dynamic strings into innerHTML.
// Escapes the OWASP-recommended set: &, <, >, ", ', /. The previous
// textContent → innerHTML technique escaped only &, <, > (the HTML text
// serialization spec), leaving " and ' unescaped — which broke out of
// quoted-attribute contexts (title=, alt=, data-*=, aria-label=).
const ESCAPE_HTML_MAP = {
  "&": "&amp;",
  "<": "&lt;",
  ">": "&gt;",
  '"': "&quot;",
  "'": "&#39;",
  "/": "&#x2F;",
};
export function escapeHtml(s) {
  return String(s ?? "").replace(/[&<>"'/]/g, (c) => ESCAPE_HTML_MAP[c]);
}

// Defense-in-depth: rejects javascript: and data: schemes before a URL
// is interpolated into href= or src=. All current URL sources come from
// server-trusted PHP, but this guards against a future regression where
// a user-controlled URL could reach an attribute sink.
export function safeUrl(s) {
  const url = String(s ?? "").trim();
  if (/^(javascript|data|vbscript):/i.test(url)) {
    return "";
  }
  return url;
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
