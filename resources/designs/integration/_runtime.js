// Shared client helpers for the Laravel integration layer (see resources/designs/integration/*.js).
// Tells support.js not to re-fetch the page, and to load React from this server instead of unpkg.com.
window.__resources = Object.assign(window.__resources || {}, {
  'https://unpkg.com/react@18.3.1/umd/react.production.min.js': '/vendor/react.production.min.js',
  'https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js': '/vendor/react-dom.production.min.js'
});
window.rs = {
  async api(method, url, body) {
    const res = await fetch(url, {
      method, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': window.RS.csrf },
      body: body === undefined ? undefined : JSON.stringify(body)
    });
    let data = null; try { data = await res.json(); } catch (e) {}
    if (!res.ok) {
      const msg = (data && (data.message || (data.errors && Object.values(data.errors)[0][0]))) || ('Request failed (' + res.status + ')');
      const err = new Error(msg); err.status = res.status; err.data = data; throw err;
    }
    return data;
  },
  get: (u) => window.rs.api('GET', u),
  post: (u, b) => window.rs.api('POST', u, b || {}),
  put: (u, b) => window.rs.api('PUT', u, b || {}),
  del: (u) => window.rs.api('DELETE', u),
  /** Value of a named form control currently on the page ('' if absent). */
  val(name) { const el = document.querySelector('[name="' + name + '"]'); if (!el) return ''; return el.type === 'checkbox' ? el.checked : el.value; },
  /** Values of all checked checkboxes with this name. */
  checked(name) { return [...document.querySelectorAll('[name="' + name + '"]')].filter(e => e.checked).map(e => e.value); },
  greeting() {
    const h = new Date().getHours(), first = ((window.RS.user && window.RS.user.name) || '').split(' ')[0];
    return (h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening') + (first ? ', ' + first : '');
  },
  logout() { window.rs.post('/logout').finally(() => { window.location.href = '/'; }); }
};
