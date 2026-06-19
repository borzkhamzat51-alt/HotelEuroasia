/**
 * Bluebookers — Calendar (Reservations) interactions.
 */
(function () {
  'use strict';

  const cfg = window.BB_CALENDAR;
  const overlay = document.getElementById('reservationModal');
  const content = document.getElementById('reservationModalContent');
  const closeBtn = document.getElementById('reservationModalClose');
  const newBtn = document.getElementById('newReservationBtn');

  function openModal() { overlay.hidden = false; }
  function closeModal() { overlay.hidden = true; content.innerHTML = ''; }

  closeBtn.addEventListener('click', closeModal);
  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) closeModal();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !overlay.hidden) closeModal();
  });

  function roomOptions(selectedRoomId) {
    return cfg.rooms.map(function (r) {
      const sel = String(r.id) === String(selectedRoomId) ? 'selected' : '';
      return '<option value="' + r.id + '" ' + sel + '>RM' + r.room_number + ' — ' + r.room_type + ' (₱' + Number(r.price_per_night).toLocaleString() + '/night)</option>';
    }).join('');
  }

  function optionList(map, selected) {
    return Object.keys(map).map(function (key) {
      const sel = key === selected ? 'selected' : '';
      return '<option value="' + key + '" ' + sel + '>' + map[key] + '</option>';
    }).join('');
  }

  function fieldError(errors, key) {
    return errors && errors[key] ? '<span class="form-error">' + errors[key] + '</span>' : '';
  }

  function renderForm(resv, prefill, errors) {
    resv = resv || {};
    prefill = prefill || {};
    const isEdit = !!resv.id;
    const roomId = resv.room_id || prefill.room_id || (cfg.rooms[0] && cfg.rooms[0].id) || '';
    const checkIn = resv.check_in || prefill.check_in || '';
    const checkOut = resv.check_out || prefill.check_out || '';

    let html = '';
    html += '<h2 style="font-family:\'Playfair Display\',serif; margin-bottom:4px;">' + (isEdit ? 'Reservation Details' : 'New Reservation') + '</h2>';
    html += '<p style="color:var(--ink-500); font-size:0.85rem; margin-bottom:6px;">' + cfg.branchLabel + '</p>';

    html += '<form id="resvForm" class="resv-form" autocomplete="off">';
    if (isEdit) html += '<input type="hidden" name="id" value="' + resv.id + '">';

    html += '<h3>Guest Information</h3><div class="resv-grid">';
    html += field('Full Name', 'guest_full_name', resv.guest_full_name, 'text', errors, true);
    html += field('Contact Number', 'contact_number', resv.contact_number, 'text', errors);
    html += field('Email Address', 'email', resv.email, 'email', errors);
    html += field('Address', 'address', resv.address, 'text', errors);
    html += field('Valid ID Type', 'valid_id_type', resv.valid_id_type, 'text', errors);
    html += field('Valid ID Number', 'valid_id_number', resv.valid_id_number, 'text', errors);
    html += '</div>';

    html += '<h3>Booking Information</h3><div class="resv-grid">';
    html += '<div><label for="room_id">Room Number</label><select id="room_id" name="room_id" required>' + roomOptions(roomId) + '</select>' + fieldError(errors, 'room_id') + '</div>';
    html += '<div><label for="status">Booking Status</label><select id="status" name="status">' + optionList(cfg.statusLabels, resv.status || 'reserved') + '</select></div>';
    html += field('Check-in Date', 'check_in', checkIn, 'date', errors, true);
    html += field('Check-out Date', 'check_out', checkOut, 'date', errors, true);
    html += field('Number of Adults', 'num_adults', resv.num_adults || 1, 'number', errors);
    html += field('Number of Children', 'num_children', resv.num_children || 0, 'number', errors);
    html += '</div>';

    html += '<h3>Payment Information</h3><div class="resv-grid">';
    html += field('Room Rate', 'room_rate', resv.room_rate || 0, 'number', errors);
    html += field('Security Deposit', 'security_deposit', resv.security_deposit || 0, 'number', errors);
    html += field('Total Amount', 'total_amount', resv.total_amount || 0, 'number', errors);
    html += field('Amount Paid', 'amount_paid', resv.amount_paid || 0, 'number', errors);
    html += '<div><label for="payment_method">Payment Method</label><select id="payment_method" name="payment_method"><option value="">— Select —</option>' + optionList(cfg.paymentLabels, resv.payment_method || '') + '</select></div>';
    html += '</div>';
    html += '<div class="resv-balance">Remaining Balance: <span id="resvBalance">₱0.00</span></div>';

    html += '<h3>Additional Information</h3><div class="resv-grid">';
    html += '<div class="resv-grid--full"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="2">' + (resv.notes || '') + '</textarea></div>';
    html += '<div class="resv-grid--full"><label for="special_requests">Special Requests</label><textarea id="special_requests" name="special_requests" rows="2">' + (resv.special_requests || '') + '</textarea></div>';
    html += '</div>';

    if (errors && errors._general) {
      html += '<p class="form-error" style="margin-top:14px;">' + errors._general + '</p>';
    }

    html += '<div class="resv-actions">';
    html += '<button type="submit" class="btn btn--primary">' + (isEdit ? 'Save Changes' : 'Create Reservation') + '</button>';
    if (isEdit && cfg.canDelete) {
      html += '<button type="button" class="btn btn--danger" id="resvDeleteBtn">Delete</button>';
    }
    html += '<button type="button" class="btn btn--secondary" id="resvCancelBtn">Cancel</button>';
    html += '</div>';
    html += '</form>';

    if (isEdit) {
      html += '<details class="resv-log"><summary>Activity Log</summary><ul id="resvLogList"><li>Loading…</li></ul></details>';
    }

    content.innerHTML = html;

    wireBalance();
    wireFormSubmit(isEdit, resv.id);
    wireAutoCheckout();

    document.getElementById('resvCancelBtn').addEventListener('click', closeModal);
    if (isEdit && cfg.canDelete) {
      document.getElementById('resvDeleteBtn').addEventListener('click', function () { deleteReservation(resv.id); });
    }
    if (isEdit) loadActivityLog(resv);

    openModal();
  }

  function field(label, name, value, type, errors, required) {
    value = value === undefined || value === null ? '' : value;
    const id = name;
    return '<div><label for="' + id + '">' + label + (required ? ' *' : '') + '</label>' +
      '<input type="' + type + '" id="' + id + '" name="' + name + '" value="' + String(value).replace(/"/g, '&quot;') + '"' + (required ? ' required' : '') + ' autocomplete="off">' +
      fieldError(errors, name) + '</div>';
  }

  function wireBalance() {
    const form = document.getElementById('resvForm');
    const balanceEl = document.getElementById('resvBalance');
    function recalc() {
      const total = parseFloat(form.total_amount.value) || 0;
      const paid = parseFloat(form.amount_paid.value) || 0;
      const remaining = total - paid;
      balanceEl.textContent = '₱' + remaining.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      balanceEl.style.color = remaining > 0 ? '#b3433f' : 'inherit';
    }
    form.total_amount.addEventListener('input', recalc);
    form.amount_paid.addEventListener('input', recalc);
    recalc();
  }

  // ─── AUTO‑CHECKOUT: 30 DAYS AFTER CHECK‑IN ─────────────────────
  function wireAutoCheckout() {
    const form = document.getElementById('resvForm');
    const checkIn = form.check_in;
    const checkOut = form.check_out;
    if (!checkIn || !checkOut) return;

    // Only auto‑set for new reservations (not editing existing ones)
    const isEdit = !!form.querySelector('input[name="id"]');
    if (isEdit) return;

    function setCheckout30Days() {
      const dateVal = checkIn.value;
      if (!dateVal) return;
      const d = new Date(dateVal);
      d.setDate(d.getDate() + 30);
      const year = d.getFullYear();
      const month = String(d.getMonth() + 1).padStart(2, '0');
      const day = String(d.getDate()).padStart(2, '0');
      checkOut.value = year + '-' + month + '-' + day;
    }

    if (checkIn.value) {
      setCheckout30Days();
    }

    checkIn.addEventListener('change', setCheckout30Days);
  }

  function wireFormSubmit(isEdit, id) {
    const form = document.getElementById('resvForm');
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      const fd = new FormData(form);
      fd.append('action', isEdit ? 'update' : 'create');
      fd.append('csrf_token', cfg.csrfToken);

      fetch('../process_reservation.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.success) {
            window.location.reload();
          } else {
            const resv = isEdit ? Object.assign({ id: id }, formToObject(fd)) : formToObject(fd);
            renderForm(resv, null, Object.assign({ _general: res.message }, res.errors || {}));
          }
        })
        .catch(function () {
          renderForm(formToObject(fd), null, { _general: 'Something went wrong. Please try again.' });
        });
    });
  }

  function formToObject(fd) {
    const obj = {};
    fd.forEach(function (value, key) { obj[key] = value; });
    return obj;
  }

  function deleteReservation(id) {
    if (!confirm('Delete this reservation permanently? This cannot be undone.')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    fd.append('csrf_token', cfg.csrfToken);
    fetch('../process_reservation.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success) {
          window.location.reload();
        } else {
          alert(res.message || 'Could not delete this reservation.');
        }
      });
  }

  function loadActivityLog(resv) {
    const list = document.getElementById('resvLogList');
    if (!list) return;
    const entries = resv._activity || [];
    if (entries.length === 0) {
      list.innerHTML = '<li>No activity recorded yet.</li>';
      return;
    }
    list.innerHTML = entries.map(function (e) {
      const who = e.full_name || e.username || 'Unknown';
      const when = new Date(e.created_at.replace(' ', 'T')).toLocaleString();
      return '<li><strong>' + e.action.charAt(0).toUpperCase() + e.action.slice(1) + '</strong> by ' + who + ' — ' + when + (e.details ? '<br>' + e.details : '') + '</li>';
    }).join('');
  }

  // --- Entry points ------------------------------------------------
  // --- Entry points ------------------------------------------------
  newBtn.addEventListener('click', function () { renderForm(null, {}); });

  document.querySelectorAll('.cal-day-slot').forEach(function (slot) {
    slot.addEventListener('click', function () {
      const row = this.closest('.cal-row');
      if (row && row.classList.contains('maintenance')) {
        alert('This room is currently Out of Order. Please clear the maintenance flag on the Layout page before booking.');
        return;
      }
      const roomId = slot.dataset.roomId;
      const date = slot.dataset.date;
      const nextDay = new Date(date);
      nextDay.setDate(nextDay.getDate() + 1);
      renderForm(null, { room_id: roomId, check_in: date, check_out: nextDay.toISOString().slice(0, 10) });
    });
  });

  document.querySelectorAll('.cal-label-col[data-room-id]').forEach(function (label) {
    label.addEventListener('click', function () {
      const row = this.closest('.cal-row');
      if (row && row.classList.contains('maintenance')) {
        alert('This room is currently Out of Order. Please clear the maintenance flag on the Layout page before booking.');
        return;
      }
      renderForm(null, { room_id: label.dataset.roomId });
    });
  });

  document.querySelectorAll('.cal-day-header').forEach(function (header, idx) {
    header.style.cursor = 'pointer';
    header.addEventListener('click', function () {
      const slots = document.querySelectorAll('.cal-row__track .cal-day-slot');
      const firstRoomDate = slots[idx] ? slots[idx].dataset.date : null;
      renderForm(null, { check_in: firstRoomDate });
    });
  });

  document.querySelectorAll('.cal-bar').forEach(function (bar) {
    bar.addEventListener('click', function (e) {
      e.stopPropagation();
      // Allow editing existing reservations even on maintenance rows
      const resv = JSON.parse(bar.dataset.reservation);
      renderForm(resv, null);
    });
  });

})();