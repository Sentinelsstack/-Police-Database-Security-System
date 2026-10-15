// assets/js/app.js - focus main + session timeout warning (13min warn, 15min logout)
document.addEventListener('DOMContentLoaded', () => {
  const main = document.getElementById('main');
  if (main) main.focus({ preventScroll: true });

  const modalEl = document.getElementById('timeoutModal');
  if (!modalEl || typeof bootstrap === 'undefined') return;
  const modal = new bootstrap.Modal(modalEl, { backdrop: 'static', keyboard: false });
  const countEl = document.getElementById('timeoutCount');
  let warned = false;
  let remaining = 120;
  let timer = null;
  const WARN_AFTER = 780 * 1000; // 13 min
  let lastActivity = Date.now();
  ['click', 'keydown', 'mousemove'].forEach(ev => document.addEventListener(ev, () => { lastActivity = Date.now(); }, { passive: true }));

  document.getElementById('timeoutStay').addEventListener('click', () => {
    fetch(window.location.href, { method: 'HEAD', credentials: 'same-origin' }).catch(() => {});
    modal.hide();
    warned = false;
    remaining = 120;
    lastActivity = Date.now();
    if (timer) { clearInterval(timer); timer = null; }
  });

  setInterval(() => {
    if (warned) return;
    if (Date.now() - lastActivity >= WARN_AFTER) {
      warned = true;
      modal.show();
      document.getElementById('timeoutStay').focus();
      timer = setInterval(() => {
        remaining -= 1;
        if (countEl) countEl.textContent = String(remaining);
        if (remaining <= 0) window.location.href = 'logout.php';
      }, 1000);
    }
  }, 5000);
});
