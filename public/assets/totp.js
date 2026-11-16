// Two-factor set-up: draws the otpauth:// URI of [data-otpauth] as a QR code
// (qrcode-generator, MIT, in assets/vendor), without any external service.
document.querySelectorAll('[data-otpauth]').forEach((box) => {
  if (typeof qrcode !== 'function') return;
  const qr = qrcode(0, 'M');
  qr.addData(box.dataset.otpauth);
  qr.make();
  box.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 4, scalable: true });
});
