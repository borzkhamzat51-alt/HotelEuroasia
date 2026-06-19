/**
 * Bluebookers — Login Page
 * Handles: password visibility toggle, client-side validation,
 * AJAX submit to process_login.php, and the loading/error animations.
 */
(function () {
  'use strict';

  const form        = document.getElementById('loginForm');
  const usernameEl  = document.getElementById('username');
  const passwordEl  = document.getElementById('password');
  const toggleBtn   = document.getElementById('togglePassword');
  const loginBtn    = document.getElementById('loginBtn');
  const formAlert   = document.getElementById('formAlert');
  const usernameErr = document.getElementById('usernameError');
  const passwordErr = document.getElementById('passwordError');
  const card        = document.querySelector('.login-card');

  /* ---------- Show / hide password ---------- */
  toggleBtn.addEventListener('click', function () {
    const isPassword = passwordEl.type === 'password';
    passwordEl.type = isPassword ? 'text' : 'password';
    toggleBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
    toggleBtn.classList.toggle('is-active', isPassword);
  });

  /* ---------- Helpers ---------- */
  function setFieldError(input, errorEl, message) {
    const field = input.closest('.field');
    if (message) {
      field.classList.add('is-invalid');
      errorEl.textContent = message;
    } else {
      field.classList.remove('is-invalid');
      errorEl.textContent = '';
    }
  }

  function showFormAlert(message) {
    if (!message) {
      formAlert.hidden = true;
      formAlert.textContent = '';
      return;
    }
    formAlert.hidden = false;
    formAlert.textContent = message;
  }

  function triggerShake() {
    card.classList.remove('shake');
    // restart animation even if it's already mid-shake
    void card.offsetWidth;
    card.classList.add('shake');
  }

  function setLoading(isLoading) {
    loginBtn.classList.toggle('is-loading', isLoading);
    loginBtn.disabled = isLoading;
  }

  function validate() {
    let valid = true;

    if (!usernameEl.value.trim()) {
      setFieldError(usernameEl, usernameErr, 'Username is required.');
      valid = false;
    } else {
      setFieldError(usernameEl, usernameErr, '');
    }

    if (!passwordEl.value) {
      setFieldError(passwordEl, passwordErr, 'Password is required.');
      valid = false;
    } else {
      setFieldError(passwordEl, passwordErr, '');
    }

    return valid;
  }

  /* ---------- Clear inline errors as the user types ---------- */
  usernameEl.addEventListener('input', () => setFieldError(usernameEl, usernameErr, ''));
  passwordEl.addEventListener('input', () => setFieldError(passwordEl, passwordErr, ''));

  /* ---------- Submit ---------- */
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    showFormAlert('');

    if (!validate()) {
      triggerShake();
      usernameEl.value.trim() ? passwordEl.focus() : usernameEl.focus();
      return;
    }

    setLoading(true);

    const formData = new FormData(form);

    fetch('process_login.php', {
      method: 'POST',
      body: formData,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then((res) => res.json())
      .then((data) => {
        if (data.success) {
          // Tiny success pause so the spinner doesn't just flash away.
          loginBtn.querySelector('.btn__label').textContent = 'Success!';
          setTimeout(() => {
            window.location.href = data.redirect || 'admin/dashboard.php';
          }, 350);
        } else {
          setLoading(false);
          showFormAlert(data.message || 'Invalid username or password.');
          triggerShake();
          passwordEl.value = '';
          passwordEl.focus();
        }
      })
      .catch(() => {
        setLoading(false);
        showFormAlert('Something went wrong. Please try again.');
        triggerShake();
      });
  });

})();
