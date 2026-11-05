// Calls to the admin's JSON API. Every call sends the page's CSRF token.
const token = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

async function send(url, init) {
  let res;
  try {
    res = await fetch(url, { credentials: 'same-origin', ...init, headers: { 'X-CSRF-Token': token(), Accept: 'application/json', ...(init.headers || {}) } });
  } catch {
    throw new Error('No connection to the server. Check the internet and try again.');
  }
  let data = null;
  try { data = await res.json(); } catch { /* not JSON */ }
  if (!res.ok) throw new Error(data?.error || `The server answered ${res.status}. Please try again.`);
  return data;
}

/** POST JSON, get JSON back. Throws Error(message) with the server's words. */
export const api = (url, body) => send(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });

/** POST a FormData (pictures). */
export const postForm = (url, form) => send(url, { method: 'POST', body: form });

/** Escape text for innerHTML. */
export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
