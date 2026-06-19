/**
 * Bluebookers — Dashboard
 * Handles: mobile nav toggle + the Settings dropdown menu.
 */
(function () {
  'use strict';

  const toggle = document.getElementById('navToggle');
  const navbar = document.getElementById('navbar');

  if (toggle && navbar) {
    toggle.addEventListener('click', function () {
      const isOpen = navbar.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      toggle.classList.toggle('is-active', isOpen);
    });
  }

  const dropdown = document.getElementById('settingsDropdown');
  const dropdownToggle = document.getElementById('settingsToggle');

  if (dropdown && dropdownToggle) {
    dropdownToggle.addEventListener('click', function (e) {
      e.stopPropagation();
      const isOpen = dropdown.classList.toggle('is-open');
      dropdownToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    document.addEventListener('click', function (e) {
      if (!dropdown.contains(e.target)) {
        dropdown.classList.remove('is-open');
        dropdownToggle.setAttribute('aria-expanded', 'false');
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        dropdown.classList.remove('is-open');
        dropdownToggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

})();
