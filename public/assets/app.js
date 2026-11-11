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
