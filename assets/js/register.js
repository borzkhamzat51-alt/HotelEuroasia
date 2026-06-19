/**
 * Bluebookers — Register Page
 * Handles: field validation, password strength meter, live confirm-password
 * match check, show/hide toggles, AJAX submit to process_register.php,
 * and the loading/error animations (mirrors script.js for the login page).
 */
(function () {
  'use strict';

  const form           = document.getElementById('registerForm');
  const fullNameEl      = document.getElementById('fullName');
  const emailEl         = document.getElementById('email');
  const phoneEl         = document.getElementById('phone');
  const passwordEl      = document.getElementById('password');
  const confirmEl       = document.getElementById('confirmPassword');
  const toggleBtn        = document.getElementById('togglePassword');
  const toggleConfirmBtn = document.getElementById('toggleConfirmPassword');
  const registerBtn     = document.getElementById('registerBtn');
  const formAlert       = document.getElementById('formAlert');
  const strengthMeter    = document.getElementById('strengthMeter');
  const card            = document.querySelector('.register-card');

  const errors = {
    fullName: document.getElementById('fullNameError'),
    email: document.getElementById('emailError'),
    phone: document.getElementById('phoneError'),
    password: document.getElementById('passwordError'),
    confirmPassword: document.getElementById('confirmPasswordError'),
  };

  /* ---------- Show / hide password (both fields) ---------- */
  function wireToggle(btn, input) {
    btn.addEventListener('click', function () {
      const isPassword = input.type === 'password';
      input.type = isPassword ? 'text' : 'password';
      btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
      btn.classList.toggle('is-active', isPassword);
    });
  }
  wireToggle(toggleBtn, passwordEl);
  wireToggle(toggleConfirmBtn, confirmEl);

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
    void card.offsetWidth;
    card.classList.add('shake');
  }

  function setLoading(isLoading) {
    registerBtn.classList.toggle('is-loading', isLoading);
    registerBtn.disabled = isLoading;
  }

  /* ---------- Field-level validators ---------- */
  const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  const PHONE_RE = /^[0-9+()\-\s]{7,}$/;

  function validateFullName() {
    const v = fullNameEl.value.trim();
    if (!v) return setFieldError(fullNameEl, errors.fullName, 'Full name is required.'), false;
    if (v.length < 2) return setFieldError(fullNameEl, errors.fullName, 'Please enter your full name.'), false;
    setFieldError(fullNameEl, errors.fullName, '');
    return true;
  }

  function validateEmail() {
    const v = emailEl.value.trim();
    if (!v) return setFieldError(emailEl, errors.email, 'Email address is required.'), false;
    if (!EMAIL_RE.test(v)) return setFieldError(emailEl, errors.email, 'Enter a valid email address.'), false;
    setFieldError(emailEl, errors.email, '');
    return true;
  }

  function validatePhone() {
    const v = phoneEl.value.trim();
    if (!v) return setFieldError(phoneEl, errors.phone, 'Phone number is required.'), false;
    if (!PHONE_RE.test(v)) return setFieldError(phoneEl, errors.phone, 'Enter a valid phone number.'), false;
    setFieldError(phoneEl, errors.phone, '');
    return true;
  }

  function validatePassword() {
    const v = passwordEl.value;
    if (!v) return setFieldError(passwordEl, errors.password, 'Password is required.'), false;
    if (v.length < 8) return setFieldError(passwordEl, errors.password, 'Use at least 8 characters.'), false;
    setFieldError(passwordEl, errors.password, '');
    return true;
  }

  function validateConfirm() {
    const v = confirmEl.value;
    const field = confirmEl.closest('.field');
    if (!v) {
      field.classList.remove('is-match');
      setFieldError(confirmEl, errors.confirmPassword, 'Please confirm your password.');
      return false;
    }
    if (v !== passwordEl.value) {
      field.classList.remove('is-match');
      setFieldError(confirmEl, errors.confirmPassword, 'Passwords do not match.');
      return false;
    }
    field.classList.add('is-match');
    setFieldError(confirmEl, errors.confirmPassword, '');
    return true;
  }

  /* ---------- Password strength meter ---------- */
  function scorePassword(value) {
    let score = 0;
    if (value.length >= 8) score++;
    if (value.length >= 12) score++;
    if (/[A-Z]/.test(value) && /[a-z]/.test(value)) score++;
    if (/[0-9]/.test(value)) score++;
    if (/[^A-Za-z0-9]/.test(value)) score++;
    return Math.min(score, 4);
  }

  function updateStrengthMeter() {
    const score = scorePassword(passwordEl.value);
    strengthMeter.classList.remove('is-weak', 'is-fair', 'is-good', 'is-strong');
    if (!passwordEl.value) return;
    if (score <= 1) strengthMeter.classList.add('is-weak');
    else if (score === 2) strengthMeter.classList.add('is-fair');
    else if (score === 3) strengthMeter.classList.add('is-good');
    else strengthMeter.classList.add('is-strong');
  }

  /* ---------- Live feedback as the user types ---------- */
  fullNameEl.addEventListener('input', () => setFieldError(fullNameEl, errors.fullName, ''));
  emailEl.addEventListener('input', () => setFieldError(emailEl, errors.email, ''));
  phoneEl.addEventListener('input', () => setFieldError(phoneEl, errors.phone, ''));

  passwordEl.addEventListener('input', () => {
    setFieldError(passwordEl, errors.password, '');
    updateStrengthMeter();
    // Re-check the match live, since the target value just changed
    if (confirmEl.value) validateConfirm();
  });

  confirmEl.addEventListener('input', () => {
    // Real-time match check, not just on submit
    validateConfirm();
  });

  function validateAll() {
    // Run all four validators; intentionally not short-circuited so every
    // field lights up its own error at once.
    const a = validateFullName();
    const b = validateEmail();
    const c = validatePhone();
    const d = validatePassword();
    const e = validateConfirm();
    return a && b && c && d && e;
  }

  /* ---------- Submit ---------- */
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    showFormAlert('');

    if (!validateAll()) {
      triggerShake();
      return;
    }

    setLoading(true);

    const formData = new FormData(form);

    fetch('process_register.php', {
      method: 'POST',
      body: formData,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then((res) => res.json())
      .then((data) => {
        if (data.success) {
          registerBtn.querySelector('.btn__label').textContent = 'Success!';
          setTimeout(() => {
            window.location.href = data.redirect || 'index.php';
          }, 450);
        } else {
          setLoading(false);
          showFormAlert(data.message || 'Something went wrong. Please check your details and try again.');
          triggerShake();

          // Surface field-specific errors if the backend sends them.
          if (data.errors) {
            Object.keys(data.errors).forEach((key) => {
              const map = {
                full_name: [fullNameEl, errors.fullName],
                email: [emailEl, errors.email],
                phone: [phoneEl, errors.phone],
                password: [passwordEl, errors.password],
              };
              if (map[key]) setFieldError(map[key][0], map[key][1], data.errors[key]);
            });
          }
        }
      })
      .catch(() => {
        setLoading(false);
        showFormAlert('Something went wrong. Please try again.');
        triggerShake();
      });
  });

})();