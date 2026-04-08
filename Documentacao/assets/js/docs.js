'use strict';

// ── Theme ────────────────────────────────────────────────────────────────────
const themeBtn  = document.getElementById('theme-btn');
const themeIcon = document.getElementById('theme-icon');

function applyTheme(dark) {
  document.body.classList.toggle('dark', dark);
  if (themeIcon) themeIcon.className = dark ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
  localStorage.setItem('docs-dark', dark ? '1' : '0');
}

const savedDark = localStorage.getItem('docs-dark') === '1' ||
  (localStorage.getItem('docs-dark') === null && window.matchMedia('(prefers-color-scheme: dark)').matches);
applyTheme(savedDark);

if (themeBtn) {
  themeBtn.addEventListener('click', () => applyTheme(!document.body.classList.contains('dark')));
}

// ── Mobile sidebar ───────────────────────────────────────────────────────────
const sidebar   = document.getElementById('docs-sidebar');
const overlay   = document.getElementById('docs-overlay');

// ── Navigation ───────────────────────────────────────────────────────────────
const navLinks = document.querySelectorAll('.docs-nav-link[data-page]');
const pages    = document.querySelectorAll('.docs-page');

function showPage(pageId) {
  pages.forEach(p => p.classList.toggle('active', p.id === 'page-' + pageId));
  navLinks.forEach(l => l.classList.toggle('active', l.dataset.page === pageId));
  window.scrollTo({ top: 0, behavior: 'instant' });
  history.replaceState(null, '', '#' + pageId);
  // Close sidebar on mobile
  if (sidebar) sidebar.classList.remove('open');
  if (overlay) overlay.classList.remove('show');
}

navLinks.forEach(link => {
  link.addEventListener('click', e => {
    e.preventDefault();
    showPage(link.dataset.page);
  });
});

// Load page from hash
const hash = location.hash.replace('#', '');
const validPages = Array.from(navLinks).map(l => l.dataset.page);
showPage(validPages.includes(hash) ? hash : (validPages[0] || 'introducao'));
const menuBtn   = document.getElementById('menu-btn');

if (menuBtn) {
  menuBtn.addEventListener('click', () => {
    sidebar.classList.toggle('open');
    overlay.classList.toggle('show');
  });
}
if (overlay) {
  overlay.addEventListener('click', () => {
    sidebar.classList.remove('open');
    overlay.classList.remove('show');
  });
}

// ── Copy code ────────────────────────────────────────────────────────────────
document.querySelectorAll('.code-copy-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const pre = btn.closest('.code-block').querySelector('pre');
    const text = pre ? pre.innerText : '';
    navigator.clipboard.writeText(text).then(() => {
      const orig = btn.innerHTML;
      btn.innerHTML = '<i class="fa-solid fa-check"></i> Copiado!';
      setTimeout(() => { btn.innerHTML = orig; }, 2000);
    });
  });
});

// ── Scroll to top ────────────────────────────────────────────────────────────
const scrollBtn = document.getElementById('scroll-top');
window.addEventListener('scroll', () => {
  if (scrollBtn) scrollBtn.classList.toggle('show', window.scrollY > 400);
});
if (scrollBtn) {
  scrollBtn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
}

// ── Active nav on scroll ─────────────────────────────────────────────────────
const observer = new IntersectionObserver(entries => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      const id = entry.target.id;
      document.querySelectorAll('.docs-nav-sub .docs-nav-link').forEach(l => {
        l.classList.toggle('active', l.getAttribute('href') === '#' + id);
      });
    }
  });
}, { rootMargin: '-20% 0px -70% 0px' });

document.querySelectorAll('h2[id], h3[id]').forEach(h => observer.observe(h));
