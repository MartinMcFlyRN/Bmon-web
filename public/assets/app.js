// Confirmation for destructive forms: <form data-confirm="Are you sure?">
document.addEventListener('submit', (ev) => {
  const msg = ev.target.dataset && ev.target.dataset.confirm;
  if (msg && !window.confirm(msg)) {
    ev.preventDefault();
  }
});

// Auto-refresh toggle: <input type="checkbox" data-autorefresh="30">
// The choice is remembered per browser; the page reloads only while it is visible
// and the user is not typing in a field.
document.addEventListener('DOMContentLoaded', () => {
  const box = document.querySelector('input[data-autorefresh]');
  if (!box) return;
  const key = 'bmon.autorefresh';
  const seconds = parseInt(box.dataset.autorefresh, 10) || 30;
  let timer = null;

  const read = () => { try { return localStorage.getItem(key) === '1'; } catch (e) { return false; } };
  const save = (on) => { try { localStorage.setItem(key, on ? '1' : '0'); } catch (e) { /* storage disabled */ } };

  const schedule = () => {
    clearTimeout(timer);
    if (!box.checked) return;
    timer = setTimeout(() => {
      const el = document.activeElement;
      const typing = el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') && el !== box;
      const open = document.querySelector('details[open]');
      if (document.hidden || typing || open) { schedule(); return; }
      window.location.reload();
    }, seconds * 1000);
  };

  box.checked = read();
  box.addEventListener('change', () => { save(box.checked); schedule(); });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) schedule(); });
  schedule();
});
