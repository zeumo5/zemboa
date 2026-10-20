/** Progressive enhancements only: native forms/links work without JavaScript. */
const navToggle = document.querySelector('.zm-menu-toggle');
const nav = document.getElementById('zm-main-nav');

if (navToggle && nav) {
  document.documentElement.classList.add('zm-js');
  const closeNav = () => {
    nav.classList.remove('zm-nav-open');
    navToggle.setAttribute('aria-expanded', 'false');
  };
  navToggle.addEventListener('click', () => {
    const opening = navToggle.getAttribute('aria-expanded') !== 'true';
    nav.classList.toggle('zm-nav-open', opening);
    navToggle.setAttribute('aria-expanded', String(opening));
  });
  nav.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeNav));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && navToggle.getAttribute('aria-expanded') === 'true') {
      closeNav();
      navToggle.focus();
    }
  });
  document.addEventListener('click', (event) => {
    if (!nav.contains(event.target) && !navToggle.contains(event.target)) closeNav();
  });
  document.addEventListener('focusin', (event) => {
    if (!nav.contains(event.target) && !navToggle.contains(event.target)) closeNav();
  });
  window.addEventListener('pageshow', closeNav);
  window.addEventListener('resize', () => {
    if (window.innerWidth > 960) closeNav();
  });
}

const forms = document.querySelectorAll('[data-zm-search]');
forms.forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (event.defaultPrevented) return;
    const submitButton = form.querySelector('button[type="submit"]');
    if (!submitButton) return;
    form.dataset.zmBusy = 'true';
    submitButton.disabled = true;
    submitButton.setAttribute('aria-busy', 'true');
  });
});
window.addEventListener('pageshow', () => {
  forms.forEach((form) => {
    delete form.dataset.zmBusy;
    const button = form.querySelector('button[type="submit"]');
    if (button) { button.disabled = false; button.removeAttribute('aria-busy'); }
  });
});
