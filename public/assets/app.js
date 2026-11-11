// Confirmation for destructive forms: <form data-confirm="Are you sure?">
document.addEventListener('submit', (ev) => {
  const msg = ev.target.dataset && ev.target.dataset.confirm;
  if (msg && !window.confirm(msg)) {
    ev.preventDefault();
  }
});
