/* Sidebar toggle */
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');
const hamburger = document.getElementById('hamburger');

function openSidebar() {
  sidebar?.classList.add('open');
  overlay?.classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeSidebar() {
  sidebar?.classList.remove('open');
  overlay?.classList.remove('open');
  document.body.style.overflow = '';
}

hamburger?.addEventListener('click', openSidebar);
overlay?.addEventListener('click', closeSidebar);

// Close sidebar when a nav link is tapped on mobile
sidebar?.querySelectorAll('.nav-item').forEach(el => {
  el.addEventListener('click', () => {
    if (window.innerWidth <= 768) closeSidebar();
  });
});

// Swipe left to close sidebar
(function() {
  let startX = 0;
  sidebar?.addEventListener('touchstart', e => { startX = e.touches[0].clientX; }, { passive: true });
  sidebar?.addEventListener('touchend', e => {
    if (startX - e.changedTouches[0].clientX > 60) closeSidebar();
  }, { passive: true });
})();

/* Modal helpers */
function openModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.add('open');
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.remove('open');
}

document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-backdrop')) {
    e.target.classList.remove('open');
  }
  if (e.target.classList.contains('modal-close')) {
    e.target.closest('.modal-backdrop')?.classList.remove('open');
  }
});

/* Confirm delete */
function confirmDelete(formEl) {
  if (confirm('本当に削除しますか？この操作は元に戻せません。')) {
    formEl.submit();
  }
  return false;
}

/* Number formatting */
function fmtJpy(n) {
  return '¥' + Math.round(n).toLocaleString('ja-JP');
}
