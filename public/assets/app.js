// Confirmation for destructive forms: <form data-confirm="Are you sure?">
document.addEventListener('submit', (ev) => {
  const msg = ev.target.dataset && ev.target.dataset.confirm;
  if (msg && !window.confirm(msg)) {
    ev.preventDefault();
  }
});

// Auto-refresh selector: <select data-autorefresh> with values in seconds (0 = off).
// The choice is remembered per browser; the page reloads only while it is visible,
// the user is not typing and no "All values" panel is open.
document.addEventListener('DOMContentLoaded', () => {
  const sel = document.querySelector('select[data-autorefresh]');
  if (!sel) return;
  const key = 'bmon.autorefresh';
  let timer = null;

  const read = () => {
    let v = null;
    try { v = localStorage.getItem(key); } catch (e) { /* storage disabled */ }
    if (v === '1') v = '30';   // value saved by 0.3.0 (on/off checkbox)
    return [...sel.options].some((o) => o.value === v) ? v : '0';
  };
  const save = (v) => { try { localStorage.setItem(key, v); } catch (e) { /* storage disabled */ } };

  const schedule = () => {
    clearTimeout(timer);
    const seconds = parseInt(sel.value, 10) || 0;
    if (seconds <= 0) return;
    timer = setTimeout(() => {
      const el = document.activeElement;
      const typing = el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA');
      if (document.hidden || typing || document.querySelector('details[open]')) { schedule(); return; }
      window.location.reload();
    }, seconds * 1000);
  };

  sel.value = read();
  sel.addEventListener('change', () => { save(sel.value); schedule(); sel.blur(); });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) schedule(); });
  schedule();
});

// Reachability of Monit httpd on the host page: <dd data-monit-check="/status/ID/monit-check">
document.addEventListener('DOMContentLoaded', () => {
  const el = document.querySelector('[data-monit-check]');
  if (!el) return;
  const dot = el.querySelector('.dot');
  const label = el.querySelector('.monit-check-label');
  const show = (state, text, title) => {
    dot.className = 'dot ' + (state === 'ok' ? 'dot-ok' : state === 'fail' ? 'dot-fail' : 'dot-unknown');
    label.className = 'monit-check-label ' + (state === 'ok' || state === 'fail' ? state : 'muted');
    label.textContent = text;
    el.title = title || '';
  };
  fetch(el.dataset.monitCheck, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then((r) => (r.ok ? r.json() : r.json().catch(() => ({})).then((j) => Promise.reject(new Error(j.error || ('HTTP ' + r.status))))))
    .then((d) => show(d.state, d.label + (d.target ? ' · ' + d.target.replace(/^https?:\/\//, '') : ''), d.detail))
    .catch((e) => show('unknown', 'Check failed', e.message));
});

// Dialogs: <a data-open-dialog="id"> opens <dialog id="id"> as a modal.
document.addEventListener('click', (ev) => {
  const opener = ev.target.closest('[data-open-dialog]');
  if (opener) {
    const dlg = document.getElementById(opener.dataset.openDialog);
    if (dlg && typeof dlg.showModal === 'function') {
      ev.preventDefault();
      if (!dlg.open) dlg.showModal();
    }
    return;
  }
  // Copy buttons: <button data-copy="element-id">
  const copy = ev.target.closest('[data-copy]');
  if (copy) {
    const field = document.getElementById(copy.dataset.copy);
    if (!field) return;
    const done = () => { const t = copy.textContent; copy.textContent = 'Copied'; setTimeout(() => { copy.textContent = t; }, 1500); };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(field.value).then(done, () => { field.select(); });
    } else {
      field.select();
      try { document.execCommand('copy'); done(); } catch (e) { /* the text stays selected */ }
    }
  }
});

// Download link: create it with fetch and show it in the dialog, without reloading.
document.addEventListener('submit', (ev) => {
  const form = ev.target;
  if (!form.matches('[data-link-form]') || ev.defaultPrevented) return;
  ev.preventDefault();
  const dlg = form.closest('dialog');
  const button = form.querySelector('button');
  button.disabled = true;
  fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then((r) => (r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status))))
    .then((d) => {
      const box = dlg.querySelector('[data-link-result]');
      box.replaceChildren();
      const wrap = document.createElement('div');
      wrap.className = 'new-link';
      const row = (labelText, note, id, value, multiline) => {
        const label = document.createElement('label');
        label.htmlFor = id;
        label.textContent = labelText + ' ';
        const small = document.createElement('span');
        small.className = 'muted small';
        small.textContent = note;
        label.appendChild(small);
        const line = document.createElement('div');
        line.className = 'copy-row' + (multiline ? ' copy-row-block' : '');
        const field = document.createElement(multiline ? 'textarea' : 'input');
        field.id = id;
        field.readOnly = true;
        field.spellcheck = false;
        if (multiline) { field.className = 'code'; field.rows = value.split('\n').length; } else { field.type = 'text'; }
        field.value = value;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn';
        btn.dataset.copy = id;
        btn.textContent = 'Copy';
        line.append(field, btn);
        wrap.append(label, line);
      };
      row('Download link', '(expires ' + d.expires + ')', 'dl-url', d.url, false);
      row('Download and install on the agent', '(as root)', 'dl-cmd', d.commands, true);
      box.appendChild(wrap);
      const status = dlg.querySelector('[data-link-status]');
      if (status) status.remove();
      button.textContent = 'Create a new link';
      wrap.querySelector('#dl-url').select();
    })
    .catch((e) => { window.alert('The link could not be created: ' + e.message); })
    .finally(() => { button.disabled = false; });
});

// Dropdown menus in the top bar: <div data-menu><button>…</button><div class="tab-menu-list">…</div></div>
// (hover and keyboard focus open them with CSS; click/tap toggles, Escape and outside clicks close)
document.addEventListener('click', (ev) => {
  const btn = ev.target.closest('[data-menu] > .tab-menu-btn');
  document.querySelectorAll('[data-menu].open').forEach((m) => {
    if (!btn || m !== btn.parentElement) {
      m.classList.remove('open');
      m.querySelector('.tab-menu-btn').setAttribute('aria-expanded', 'false');
    }
  });
  if (btn) {
    const open = btn.parentElement.classList.toggle('open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
});
document.addEventListener('keydown', (ev) => {
  if (ev.key !== 'Escape') return;
  document.querySelectorAll('[data-menu]').forEach((m) => {
    m.classList.remove('open');
    m.querySelector('.tab-menu-btn').setAttribute('aria-expanded', 'false');
    if (m.contains(document.activeElement)) document.activeElement.blur();
  });
});

// AgentConf → agent page: Auto-update status, refreshed every 5 s while the page
// is visible (<div data-update-status="url">, an HTML fragment of the server).
(() => {
  const box = document.querySelector('[data-update-status]');
  if (!box) return;
  const age = document.querySelector('[data-update-age]');
  let busy = false;
  const refresh = () => {
    if (busy || document.hidden) return;
    busy = true;
    fetch(box.dataset.updateStatus, { credentials: 'same-origin', headers: { Accept: 'text/html' }, cache: 'no-store', redirect: 'manual' })
      .then((r) => (r.ok ? r.text() : Promise.reject(new Error('HTTP ' + r.status))))
      .then((html) => {
        if (html.trim() !== box.innerHTML.trim()) {
          const pre = box.querySelector('pre');
          const scroll = pre ? pre.scrollTop : 0;
          box.innerHTML = html;
          const pre2 = box.querySelector('pre');
          if (pre2) pre2.scrollTop = scroll;
        }
        if (age) { age.textContent = '· refreshed every 5 s, last at ' + new Date().toLocaleTimeString(); age.classList.remove('text-fail'); }
      })
      .catch(() => { if (age) { age.textContent = '· could not refresh (retrying)'; age.classList.add('text-fail'); } })
      .finally(() => { busy = false; });
  };
  setInterval(refresh, 5000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
})();

// Editable rows (custom template fields): <tbody data-rows>, <template data-row-template>,
// [data-add-row] adds one, [data-remove-row] removes its row.
document.addEventListener('click', (ev) => {
  const add = ev.target.closest('[data-add-row]');
  if (add) {
    const form = add.closest('form');
    const tpl = form && form.querySelector('template[data-row-template]');
    const body = form && form.querySelector('[data-rows]');
    if (tpl && body) {
      body.appendChild(tpl.content.cloneNode(true));
      const first = body.lastElementChild && body.lastElementChild.querySelector('input');
      if (first) first.focus();
    }
    return;
  }
  const rm = ev.target.closest('[data-remove-row]');
  if (rm) rm.closest('tr').remove();
});

// Live filter: <input data-filter="#container">; hides the [data-filter-item] whose text
// (data-filter-text, else their content) does not contain every word typed, the
// [data-filter-group] left empty, and shows [data-filter-empty] when nothing matches.
document.querySelectorAll('input[data-filter]').forEach((input) => {
  const box = document.querySelector(input.dataset.filter);
  if (!box) return;
  const items = [...box.querySelectorAll('[data-filter-item]')];
  const text = (el) => (el.dataset.filterText || el.textContent).toLowerCase();
  const apply = () => {
    const words = input.value.toLowerCase().split(/\s+/).filter(Boolean);
    let shown = 0;
    items.forEach((el) => {
      const t = text(el);
      const ok = words.every((w) => t.includes(w));
      el.hidden = !ok;
      if (ok) shown++;
    });
    box.querySelectorAll('[data-filter-group]').forEach((g) => {
      g.hidden = !g.querySelector('[data-filter-item]:not([hidden])');
    });
    const empty = box.querySelector('[data-filter-empty]');
    if (empty) empty.hidden = shown > 0;
  };
  input.addEventListener('input', apply);
  input.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') { input.value = ''; apply(); } });
});
