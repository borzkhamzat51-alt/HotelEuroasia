/**
 * Bluebookers — Calendar (Reservations)
 * Fixed drag: moving a bar preserves its duration (width) exactly.
 * Sidebar now shows: Room Name → Status → Room Number → Room Code → Category.
 * Fixed room context menu – header excluded, roomId parsed as int.
 */
(function () {
  'use strict';

  const cfg = window.BB_CALENDAR;
  const STORAGE_KEY     = 'bb_selected_date';
  const STORAGE_BAR_KEY = 'bb_selected_bar';

  // ─── PERSISTENT CALENDAR STATE ─────────────────────────────────────
  const CAL_STATE_KEY = 'bb_cal_state_' + (cfg.branch || 'default');

  let _smoothScrollActive = false;

  function saveCalState(patch) {
    try {
      const current = JSON.parse(localStorage.getItem(CAL_STATE_KEY) || '{}');
      const next = Object.assign({}, current, patch);
      localStorage.setItem(CAL_STATE_KEY, JSON.stringify(next));
    } catch(e) {}
  }

  function loadCalState() {
    try { return JSON.parse(localStorage.getItem(CAL_STATE_KEY) || '{}'); } catch(e) { return {}; }
  }

  function clearCalState(key) {
    try {
      const current = JSON.parse(localStorage.getItem(CAL_STATE_KEY) || '{}');
      delete current[key];
      localStorage.setItem(CAL_STATE_KEY, JSON.stringify(current));
    } catch(e) {}
  }

  let _scrollSaveTimer = null;
  function saveScrollPosition() {
    if (_scrollSaveTimer) return;
    _scrollSaveTimer = setTimeout(function() {
      _scrollSaveTimer = null;
      const wrap = document.querySelector('.cal-grid-wrap');
      if (wrap) saveCalState({ scrollLeft: wrap.scrollLeft, scrollTop: window.scrollY });
    }, 200);
  }

  window.addEventListener('scroll', saveScrollPosition, { passive: true });

  // ─── SCROLL POSITION CALCULATORS ──────────────────────────────────
  function offsetFromGrid(el) {
    const grid = document.querySelector('.cal-grid');
    let left = 0, top = 0, node = el;
    while (node && node !== grid) {
      left += node.offsetLeft;
      top  += node.offsetTop;
      node  = node.offsetParent;
    }
    return { left: left, top: top };
  }

  function pageTopOf(el) {
    let top = 0, node = el;
    while (node) { top += node.offsetTop; node = node.offsetParent; }
    return top;
  }

  function formatLocalDate(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
  }

  // ─── Helper functions for room sidebar ────────────────────────────
  function roomStatusLabel(room) {
    if (room.room_status === 'maintenance') return 'Out of Order';
    if (room.room_status === 'occupied') return 'Checked In';
    if (room.room_status === 'reserved') return 'Reserved';
    return (room.cleaning_status !== 'Clean') ? 'Vacant Dirty' : 'Vacant Clean';
  }

  function roomStatusKey(room) {
    if (room.room_status === 'available' && room.cleaning_status !== 'Clean') return 'needs_cleaning';
    return room.room_status;
  }

  const ROOM_CODE_KNOWN = {
    'Studio w/ Veranda': 'STD-V',
    'Studio': 'STD',
    'Family A 1BR w/ Veranda': 'FAM-A',
    'Family B 1BR w/ Veranda': 'FAM-B'
  };
  const ROOM_CODE_STOPWORDS = ['w/', 'with', 'the', 'and', '1br'];
  function roomCode(roomType) {
    if (ROOM_CODE_KNOWN[roomType]) return ROOM_CODE_KNOWN[roomType];
    let letters = '';
    (roomType || '').split(/\s+/).forEach(function (word) {
      const clean = word.toLowerCase().replace(/^\/+|\/+$/g, '');
      if (clean === '' || ROOM_CODE_STOPWORDS.indexOf(clean) !== -1) return;
      letters += word.charAt(0).toUpperCase();
    });
    return letters !== '' ? letters.slice(0, 4) : 'RM';
  }

  function roomFloor(roomNumber) {
    return roomNumber ? String(roomNumber).charAt(0) : '?';
  }

  function roomCategory(roomType) {
    const t = (roomType || '').toLowerCase();
    if (t.includes('suite')) return 'Suite';
    if (t.includes('deluxe')) return 'Deluxe';
    if (t.includes('family')) return 'Deluxe';
    return 'Standard';
  }

  // ─── BAR ICONS ──────────────────────────────────────────────────────
  const BAR_ICONS = {
    checked_in: '<svg viewBox="0 0 24 24" class="cal-bar__icon" fill="none" aria-hidden="true"><path d="M5 4h6a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M19 12H9m0 0 3.5-3.5M9 12l3.5 3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    checked_out: '<svg viewBox="0 0 24 24" class="cal-bar__icon" fill="none" aria-hidden="true"><path d="M13 4H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 12h10m0 0-3.5-3.5M19 12l-3.5 3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    cancelled: '<svg viewBox="0 0 24 24" class="cal-bar__icon" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path d="m9 9 6 6m0-6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    reserved: '<svg viewBox="0 0 24 24" class="cal-bar__icon" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
  };

  // ─── MODAL ──────────────────────────────────────────────────────────
  const overlay = document.getElementById('reservationModal');
  const content = document.getElementById('reservationModalContent');
  const closeBtn = document.getElementById('reservationModalClose');
  const newBtn = document.getElementById('newReservationBtn');

  function openModal() { overlay.hidden = false; }
  function closeModal() { overlay.hidden = true; content.innerHTML = ''; }

  function showConfirmDialog(message, title) {
    title = title || 'Confirm Changes';
    return new Promise(function (resolve) {
      const existing = document.getElementById('bbConfirmDialog');
      if (existing) existing.remove();

      const dialogOverlay = document.createElement('div');
      dialogOverlay.id = 'bbConfirmDialog';
      dialogOverlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;z-index:10050;padding:20px;';
      dialogOverlay.innerHTML =
        '<div style="background:#fff;border-radius:12px;max-width:380px;width:100%;padding:26px 24px 22px;box-shadow:0 24px 60px -12px rgba(0,0,0,0.35);text-align:center;font-family:\'Inter\',sans-serif;">' +
          '<h3 style="margin:0 0 10px;font-size:1.05rem;font-weight:700;color:#16324f;">' + title + '</h3>' +
          '<p style="margin:0 0 22px;font-size:0.86rem;color:#5b7693;line-height:1.55;">' + message + '</p>' +
          '<div style="display:flex;gap:10px;justify-content:center;">' +
            '<button type="button" id="bbConfirmCancel" style="flex:1;padding:10px 16px;border-radius:6px;border:1.5px solid #c5deef;background:#dceaf8;color:#2c4a68;font-weight:600;font-size:0.85rem;cursor:pointer;font-family:inherit;">Cancel</button>' +
            '<button type="button" id="bbConfirmOk" style="flex:1;padding:10px 16px;border-radius:6px;border:none;background:#3b7dd8;color:#fff;font-weight:600;font-size:0.85rem;cursor:pointer;font-family:inherit;">Confirm</button>' +
          '</div>' +
        '</div>';

      document.body.appendChild(dialogOverlay);

      function cleanup(result) {
        dialogOverlay.remove();
        document.removeEventListener('keydown', onKey);
        resolve(result);
      }
      function onKey(e) {
        if (e.key === 'Escape') cleanup(false);
      }
      document.addEventListener('keydown', onKey);

      dialogOverlay.querySelector('#bbConfirmCancel').addEventListener('click', function () { cleanup(false); });
      dialogOverlay.querySelector('#bbConfirmOk').addEventListener('click', function () { cleanup(true); });
      dialogOverlay.addEventListener('click', function (e) { if (e.target === dialogOverlay) cleanup(false); });
    });
  }

  closeBtn.addEventListener('click', closeModal);
  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) closeModal();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !overlay.hidden) closeModal();
  });

  // ─── FORM RENDER ────────────────────────────────────────────────────
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

  function wireFormSubmit(isEdit, id) {
    const form = document.getElementById('resvForm');
    const roomSel = form.querySelector('[name="room_id"]');
    function isDirtyRoom(roomId) {
      const room = cfg.rooms.find(function(r) { return String(r.id) === String(roomId); });
      return room && room.room_status === 'available' && room.cleaning_status !== 'Clean';
    }
    if (roomSel) {
      roomSel.addEventListener('change', function() {
        if (isDirtyRoom(this.value)) {
          alert('This room is Vacant Dirty. Please mark it as clean before creating a reservation.');
          this.value = '';
        }
      });
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!isEdit && roomSel && isDirtyRoom(roomSel.value)) {
        alert('This room is Vacant Dirty. Please mark it as clean before creating a reservation.');
        return;
      }
      const confirmMsg = isEdit
        ? 'Are you sure you want to save these changes to this reservation?'
        : 'Are you sure you want to create this reservation?';
      showConfirmDialog(confirmMsg, 'Confirm Changes').then(function (confirmed) {
        if (!confirmed) return;
        const fd = new FormData(form);
        fd.append('action', isEdit ? 'update' : 'create');
        fd.append('csrf_token', cfg.csrfToken);
        fetch('/process_reservation.php', { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (res.success) {
              handleReservationSaveSuccess(res);
              closeModal();
            } else {
              const resv = isEdit ? Object.assign({ id: id }, formToObject(fd)) : formToObject(fd);
              renderForm(resv, null, Object.assign({ _general: res.message }, res.errors || {}));
            }
          })
          .catch(function (err) {
            console.error('[calendar.js] AJAX error:', err);
            alert('Error: ' + (err.message || 'Something went wrong. Please try again.'));
          });
      });
    });
  }

  function formToObject(fd) {
    const obj = {};
    fd.forEach(function (value, key) { obj[key] = value; });
    return obj;
  }

  function deleteReservation(id) {
    showConfirmDialog('Delete this reservation permanently? This cannot be undone.', 'Confirm Deletion').then(function (confirmed) {
      if (!confirmed) return;
      const fd = new FormData();
      fd.append('action', 'delete');
      fd.append('id', id);
      fd.append('csrf_token', cfg.csrfToken);
      fetch('/process_reservation.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.success) {
            const bar = document.querySelector('.cal-bar[data-reservation-id="' + id + '"]');
            if (bar) bar.remove();
            if (Array.isArray(res.rooms)) {
              res.rooms.forEach(updateRoomSidebar);
            }
          } else {
            alert(res.message || 'Could not delete this reservation.');
          }
        })
        .catch(function (err) {
          console.error('[calendar.js] Delete AJAX error:', err);
          alert('Error: ' + (err.message || 'Could not delete.'));
        });
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

  // ─── UPDATE UI HELPERS ─────────────────────────────────────────────
  function updateUIFromServer(serverResv) {
    if (!serverResv || !serverResv.id) return;

    const id = serverResv.id;
    let bar = document.querySelector('.cal-bar[data-reservation-id="' + id + '"]');

    if (serverResv.status === 'checked_out') {
      if (bar) bar.remove();
      return;
    }

    if (!bar) {
      const creationRow = document.querySelector('.cal-row[data-room-id="' + serverResv.room_id + '"]');
      const creationTrack = creationRow ? creationRow.querySelector('.cal-row__track') : null;
      if (!creationTrack) {
        console.warn('Could not find a room track to place a new bar for reservation id:', id);
        return;
      }
      bar = document.createElement('div');
      bar.className = 'cal-bar';
      bar.dataset.reservationId = id;
      creationTrack.appendChild(bar);
    }

    const oldResv = bar.dataset.reservation ? JSON.parse(bar.dataset.reservation) : {};
    Object.assign(oldResv, serverResv);
    bar.dataset.reservation = JSON.stringify(oldResv);
    bar.dataset.checkIn = serverResv.check_in;
    bar.dataset.checkOut = serverResv.check_out;
    bar.dataset.roomId = serverResv.room_id;
    bar.dataset.reservationId = id;

    if (!bar.querySelector('.cal-bar__name')) {
      bar.innerHTML = (BAR_ICONS[serverResv.status] || BAR_ICONS.reserved) + '<span class="cal-bar__name"></span>';
    }

    const nameSpan = bar.querySelector('.cal-bar__name');
    if (nameSpan) nameSpan.textContent = serverResv.guest_full_name || '';
    const iconSpan = bar.querySelector('.cal-bar__icon');
    if (iconSpan && BAR_ICONS[serverResv.status]) {
      iconSpan.outerHTML = BAR_ICONS[serverResv.status];
    }

    bar.className = bar.className.split(' ').filter(function(c) {
      return !c.startsWith('cal-bar--');
    }).join(' ');
    bar.classList.add('cal-bar--' + serverResv.status);
    bar.classList.remove('cal-bar--just-updated');
    void bar.offsetWidth;
    bar.classList.add('cal-bar--just-updated');

    const targetRow = document.querySelector('.cal-row[data-room-id="' + serverResv.room_id + '"]');
    if (targetRow) {
      const targetTrack = targetRow.querySelector('.cal-row__track');
      if (targetTrack && bar.parentElement !== targetTrack) {
        targetTrack.appendChild(bar);
      }
    }

    const track = bar.closest('.cal-row__track');
    if (track) {
      const slots = Array.prototype.slice.call(track.querySelectorAll('.cal-day-slot'));
      if (slots.length > 0) {
        const monthDate = new Date(slots[0].dataset.date + 'T00:00:00');
        const checkIn = new Date(serverResv.check_in + 'T00:00:00');
        const checkOut = new Date(serverResv.check_out + 'T00:00:00');
        const startOffset = Math.round((checkIn - monthDate) / 86400000);
        const endOffset = Math.round((checkOut - monthDate) / 86400000);
        const duration = endOffset - startOffset;
        const totalDays = slots.length;
        bar.style.left = (startOffset / totalDays * 100) + '%';
        bar.style.width = (duration / totalDays * 100) + '%';
        bar.title = serverResv.guest_full_name + ' • ' + serverResv.check_in + ' to ' + serverResv.check_out + ' • ' + (cfg.statusLabels[serverResv.status] || serverResv.status);
      }
    }

    if (!bar.__bbWired) {
      try {
        wireBarInteractions(bar);
        bar.__bbWired = true;
      } catch (err) {
        console.error('[calendar.js] failed to wire a new bar:', err);
      }
    }
    if (typeof bar.__bbRefreshHandles === 'function') {
      bar.__bbRefreshHandles();
    }
  }

  function updateRoomSidebar(room) {
    if (!room || !room.id) return;
    const row = document.querySelector('.cal-row[data-room-id="' + room.id + '"]');
    if (!row) return;

    const statusKey = roomStatusKey(room);
    const statusLabel = roomStatusLabel(room);
    const isAvailableNow = room.room_status === 'available';
    const isMaintenance = room.room_status === 'maintenance';
    const hasFullDetails = room.room_number !== undefined && room.room_type !== undefined;
    const floor = hasFullDetails ? roomFloor(room.room_number) : row.dataset.floor;
    const category = roomCategory(room.room_type);

    row.classList.toggle('maintenance', isMaintenance);
    row.dataset.statusKey = statusKey;
    row.dataset.available = isAvailableNow ? '1' : '0';
    if (hasFullDetails) {
      row.dataset.roomType = room.room_type;
      row.dataset.floor = floor;
    }

    const track = row.querySelector('.cal-row__track');
    const hasBookings = !!(track && track.querySelector('.cal-bar'));
    row.dataset.hasBookings = hasBookings ? '1' : '0';

    const labelCol = row.querySelector('.cal-label-col');
    if (labelCol && hasFullDetails) {
      labelCol.dataset.roomNumber = room.room_number;

      const nameEl = labelCol.querySelector('.cal-room-name');
      if (nameEl) nameEl.textContent = room.room_type;

      const pill = labelCol.querySelector('.cal-status-pill');
      if (pill) {
        pill.className = 'cal-status-pill cal-status-pill--' + statusKey;
        pill.textContent = statusLabel;
      }

      const numEl = labelCol.querySelector('.cal-room-number');
      if (numEl) numEl.textContent = 'RM' + room.room_number;

      const catEl = labelCol.querySelector('.cal-room-category');
      if (catEl) catEl.textContent = category;
    }

    // Maintenance badge
    if (labelCol) {
      let badge = labelCol.querySelector('.maintenance-badge');
      if (isMaintenance && !badge) {
        badge = document.createElement('span');
        badge.className = 'maintenance-badge';
        badge.textContent = '⚠ Out of Order';
        labelCol.appendChild(badge);
      } else if (!isMaintenance && badge) {
        badge.remove();
      }
    }

    if (typeof window.__bbApplyCalFilters === 'function') {
      window.__bbApplyCalFilters();
    }
  }

  function handleReservationSaveSuccess(res) {
    updateUIFromServer(res.reservation);
    if (Array.isArray(res.rooms)) {
      res.rooms.forEach(updateRoomSidebar);
    }
    if (res.reservation && res.reservation.id) {
      saveCalState({ barId: String(res.reservation.id), date: null });
    }
  }

  function reloadWithCacheBuster() {
    const url = new URL(window.location.href);
    url.searchParams.set('t', Date.now());
    window.location.href = url.toString();
  }

  function updateRoomStatus(roomId, newStatus) {
    const fd = new FormData();
    fd.append('action', 'update_status');
    fd.append('room_id', roomId);
    fd.append('new_status', newStatus);
    fd.append('csrf_token', cfg.csrfToken);

    fetch('/process_room_action.php', { method: 'POST', body: fd })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          if (data.room) updateRoomSidebar(data.room);
          if (data.reservation) updateUIFromServer(data.reservation);
        } else {
          alert('Error updating room status: ' + (data.message || 'Unknown error.'));
        }
      })
      .catch(function(err) {
        console.error('[calendar] Room status update error:', err);
        alert('Network error.');
      });
  }

  // ─── SELECTION / HIGHLIGHT ─────────────────────────────────────────
  let selectedBar = null;
  let selectedReservation = null;

  function selectBar(bar) {
    if (selectedBar) {
      selectedBar.classList.remove('cal-bar--selected');
    }
    if (bar) {
      bar.classList.add('cal-bar--selected');
      selectedBar = bar;
      try {
        selectedReservation = JSON.parse(bar.dataset.reservation);
        saveCalState({ barId: String(selectedReservation.id), date: null });
      } catch(e) {
        selectedReservation = null;
      }
    } else {
      selectedBar = null;
      selectedReservation = null;
      clearCalState('barId');
    }
  }

  let selectedDate = null;
  let selectedSlotEl = null;

  function highlightSlot(slotEl) {
    if (selectedSlotEl) {
      selectedSlotEl.classList.remove('cal-day-slot--selected');
    }
    if (slotEl) {
      slotEl.classList.add('cal-day-slot--selected');
      selectedSlotEl = slotEl;
      selectedDate = slotEl.dataset.date;
      saveCalState({ date: selectedDate, roomId: slotEl.dataset.roomId || null, barId: null });
    } else {
      selectedSlotEl = null;
      selectedDate = null;
      clearCalState('date');
      clearCalState('roomId');
    }
  }

  // ─── SMOOTH CENTERING ─────────────────────────────────────────────
  function smoothCenterIn(wrap, el, onDone) {
    const pos = offsetFromGrid(el);

    const targetLeft = Math.max(0, pos.left - wrap.clientWidth  / 2 + el.offsetWidth  / 2);
    const targetTop  = Math.max(0, pageTopOf(el) - window.innerHeight / 2 + el.offsetHeight / 2);

    let hDone = false, vDone = false;
    function checkBothDone() {
      if (hDone && vDone) {
        _smoothScrollActive = false;
        if (onDone) onDone();
      }
    }

    function makeSettleWatcher(emitter, currentVal, targetVal, markDone) {
      if (Math.abs(currentVal - targetVal) < 2) { markDone(); return; }
      let timer = null;
      function onScroll() {
        clearTimeout(timer);
        timer = setTimeout(function() {
          emitter.removeEventListener('scroll', onScroll);
          markDone();
        }, 120);
      }
      emitter.addEventListener('scroll', onScroll, { passive: true });
      setTimeout(function() {
        emitter.removeEventListener('scroll', onScroll);
        clearTimeout(timer);
        markDone();
      }, 1200);
    }

    makeSettleWatcher(wrap,   wrap.scrollLeft, targetLeft, function() { hDone = true; checkBothDone(); });
    makeSettleWatcher(window, window.scrollY,  targetTop,  function() { vDone = true; checkBothDone(); });

    _smoothScrollActive = true;
    wrap.scrollTo({ left: targetLeft, behavior: 'smooth' });
    window.scrollTo({ top:  targetTop,  behavior: 'smooth' });

    setTimeout(function() { _smoothScrollActive = false; }, 1400);
  }

  function restoreCalendarState() {
    const state = loadCalState();
    const wrap  = document.querySelector('.cal-grid-wrap');
    if (!wrap) return;

    if (state.barId) {
      const bars = document.querySelectorAll('.cal-bar');
      let targetBar = null;
      for (let i = 0; i < bars.length; i++) {
        try {
          const r = JSON.parse(bars[i].dataset.reservation);
          if (String(r.id) === String(state.barId)) { targetBar = bars[i]; break; }
        } catch(e) {}
      }
      if (targetBar) {
        smoothCenterIn(wrap, targetBar, function() {
          selectBar(targetBar);
        });
        return;
      } else {
        clearCalState('barId');
      }
    }

    if (state.date) {
      let targetSlot = null;
      if (state.roomId) {
        targetSlot = document.querySelector(
          '.cal-day-slot[data-room-id="' + state.roomId + '"][data-date="' + state.date + '"]'
        );
      }
      if (!targetSlot) {
        targetSlot = document.querySelector('.cal-day-slot[data-date="' + state.date + '"]');
      }

      if (targetSlot) {
        smoothCenterIn(wrap, targetSlot, function() {
          highlightSlot(targetSlot);
          requestAnimationFrame(function() {
            targetSlot.classList.remove('cal-day-slot--restored');
            void targetSlot.offsetWidth;
            targetSlot.classList.add('cal-day-slot--restored');
            setTimeout(function() {
              targetSlot.classList.remove('cal-day-slot--restored');
            }, 1500);
          });
        });
        return;
      } else {
        clearCalState('date');
        clearCalState('roomId');
      }
    }

    if (state.scrollLeft != null || state.scrollTop != null) {
      wrap.scrollTo({ left: Math.max(0, state.scrollLeft || 0), behavior: 'smooth' });
      window.scrollTo({ top: Math.max(0, state.scrollTop  || 0), behavior: 'smooth' });
    }
  }

  // ─── CONTEXT MENU ──────────────────────────────────────────────────
  let contextMenu = null;
  let contextReservation = null;
  let contextBar = null;
  let contextRoomId = null;
  let contextMenuType = null;

  function buildContextMenu() {
    if (contextMenu) {
      contextMenu.remove();
      contextMenu = null;
    }
    contextMenu = document.createElement('div');
    contextMenu.className = 'context-menu';
    contextMenu.id = 'bbContextMenu';
    contextMenu.style.cssText = 'display:none;position:fixed;z-index:10000;background:#fff;border-radius:8px;box-shadow:0 8px 30px rgba(0,0,0,0.2);padding:6px 0;min-width:200px;font-family:\'Inter\',sans-serif;';
    document.body.appendChild(contextMenu);
  }

  document.addEventListener('click', function (e) {
    if (contextMenu && contextMenu.style.display !== 'none' && !contextMenu.contains(e.target)) {
      hideContextMenu();
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && contextMenu && contextMenu.style.display !== 'none') {
      hideContextMenu();
    }
  });

  function showContextMenu(x, y, type, data) {
    contextMenuType = type;
    if (type === 'bar') {
      contextReservation = data.resv;
      contextBar = data.bar;
      contextRoomId = null;
    } else if (type === 'room') {
      contextRoomId = data.roomId;
      contextReservation = null;
      contextBar = null;
    }

    buildContextMenu();
    const ul = contextMenu.querySelector('ul') || document.createElement('ul');
    ul.innerHTML = '';
    ul.style.cssText = 'list-style:none;margin:0;padding:0;';

    let items = [];
    if (type === 'bar') {
      items = [
        { label: 'Edit Reservation', action: 'edit' },
        { divider: true },
        { label: 'Cancel Reservation', action: 'cancel' },
        { divider: true },
        { label: 'Check In', action: 'checkin' },
        { label: 'Check Out', action: 'checkout' },
        { label: 'Group Check In', action: 'group_checkin' },
        { label: 'Group Check Out', action: 'group_checkout' },
        { divider: true },
        { label: 'Extend Stay', action: 'extend' },
        { label: 'Early Check-Out', action: 'early_checkout' },
        { divider: true },
        { label: 'Room Move', action: 'move' },
        { label: 'Upgrade Room', action: 'upgrade' },
        { label: 'Downgrade Room', action: 'downgrade' },
        { divider: true },
        { label: 'Adjust Room Rate', action: 'rate' },
        { divider: true },
        { label: 'Guest Folio', action: 'guest_folio' },
        { label: 'Master Folio', action: 'master_folio' },
        { divider: true },
        { label: 'View Guest Profile', action: 'profile' },
        { label: 'Reservation History', action: 'history' },
        { divider: true },
        { label: 'Print Registration Card', action: 'print_regcard' },
        { label: 'Print Folio', action: 'print_folio' },
      ];
    } else if (type === 'room') {
      items = [
        { label: 'Mark Available (Vacant Clean)', action: 'status_available' },
        { label: 'Mark Vacant Dirty', action: 'status_needs_cleaning' },
        { divider: true },
        { label: 'Mark Out of Order / Maintenance', action: 'status_maintenance' },
        { divider: true },
        { label: 'Edit Room Details', action: 'edit_room' },
      ];
    }

    items.forEach(function(item) {
      if (item.divider) {
        const li = document.createElement('li');
        li.style.cssText = 'height:1px;background:#e5e7eb;margin:4px 8px;padding:0;';
        ul.appendChild(li);
        return;
      }
      const li = document.createElement('li');
      li.textContent = item.label;
      li.dataset.action = item.action;
      li.style.cssText = 'padding:8px 16px;cursor:pointer;font-size:0.85rem;color:#1a2332;transition:background 0.15s;';
      li.addEventListener('mouseenter', function() { this.style.background = '#f0f2f5'; });
      li.addEventListener('mouseleave', function() { this.style.background = 'transparent'; });
      li.addEventListener('click', function(e) {
        e.stopPropagation();
        const action = this.dataset.action;
        console.log('[context] Menu item clicked:', action);
        if (type === 'bar') {
          handleContextAction(action);
        } else if (type === 'room') {
          handleRoomStatusAction(action);
        }
        hideContextMenu();
      });
      ul.appendChild(li);
    });

    contextMenu.innerHTML = '';
    contextMenu.appendChild(ul);

    contextMenu.style.left = '0px';
    contextMenu.style.top = '0px';
    contextMenu.style.display = 'block';
    const menuWidth = contextMenu.offsetWidth || 200;
    const menuHeight = contextMenu.offsetHeight || 100;

    let left = x;
    let top = y;
    const margin = 8;
    if (left + menuWidth > window.innerWidth - margin) {
      left = window.innerWidth - menuWidth - margin;
    }
    if (left < margin) left = margin;
    if (top + menuHeight > window.innerHeight - margin) {
      top = window.innerHeight - menuHeight - margin;
    }
    if (top < margin) top = margin;

    contextMenu.style.left = left + 'px';
    contextMenu.style.top = top + 'px';
  }

  function hideContextMenu() {
    if (contextMenu) contextMenu.style.display = 'none';
    contextRoomId = null;
    contextMenuType = null;
  }

  // ─── Room status actions ──────────────────────────────────────────
  const ROOM_STATUS_MENU_LABELS = {
    available: 'Available (Vacant Clean)',
    needs_cleaning: 'Vacant Dirty',
    occupied: 'Occupied',
    reserved: 'Reserved',
    maintenance: 'Out of Order / Maintenance'
  };

  function handleRoomStatusAction(action) {
    const roomId = parseInt(contextRoomId, 10);
    if (!roomId) {
      alert('Invalid room selected.');
      return;
    }

    if (action === 'edit_room') {
      openEditRoomModal(roomId);
      return;
    }

    let newStatus;
    switch(action) {
      case 'status_available': newStatus = 'available'; break;
      case 'status_needs_cleaning': newStatus = 'needs_cleaning'; break;
      case 'status_occupied': newStatus = 'occupied'; break;
      case 'status_reserved': newStatus = 'reserved'; break;
      case 'status_maintenance': newStatus = 'maintenance'; break;
      default: return;
    }

    let confirmMsg = 'Set this room to "' + (ROOM_STATUS_MENU_LABELS[newStatus] || newStatus) + '"?';
    if ((newStatus === 'occupied' || newStatus === 'reserved')) {
      confirmMsg += ' If there\'s no active booking for this room, a placeholder walk-in reservation will be created — edit it afterward to fill in the real guest details.';
    }

    showConfirmDialog(confirmMsg, 'Confirm Status Change')
      .then(function(confirmed) {
        if (confirmed) {
          updateRoomStatus(roomId, newStatus);
        }
      });
  }

  // ─── Edit Room Details ────────────────────────────────────────────
  function openEditRoomModal(roomId) {
    const row = document.querySelector('.cal-row[data-room-id="' + roomId + '"]');
    const labelCol = row ? row.querySelector('.cal-label-col') : null;
    const currentNumber = labelCol ? labelCol.dataset.roomNumber : '';
    const currentType = row ? row.dataset.roomType : '';
    const rateEl = labelCol ? labelCol.querySelector('.cal-room-rate') : null;
    const currentPrice = rateEl ? rateEl.textContent.replace(/[^\d.]/g, '') : '';

    const modal = document.createElement('div');
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;z-index:10050;';
    const inner = document.createElement('div');
    inner.style.cssText = 'background:#fff;border-radius:8px;padding:24px;max-width:380px;width:100%;font-family:\'Inter\',sans-serif;';
    inner.innerHTML =
      '<h3 style="margin-top:0;">Edit Room Details</h3>' +
      '<div style="display:flex;flex-direction:column;gap:12px;margin:14px 0;">' +
        '<div><label style="display:block;font-size:0.78rem;font-weight:600;color:#2c4a68;margin-bottom:4px;">Room Number</label>' +
        '<input type="text" id="editRoomNumber" value="' + (currentNumber || '').replace(/"/g, '&quot;') + '" style="width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;"></div>' +
        '<div><label style="display:block;font-size:0.78rem;font-weight:600;color:#2c4a68;margin-bottom:4px;">Room Type</label>' +
        '<input type="text" id="editRoomType" value="' + (currentType || '').replace(/"/g, '&quot;') + '" style="width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;"></div>' +
        '<div><label style="display:block;font-size:0.78rem;font-weight:600;color:#2c4a68;margin-bottom:4px;">Price per Night (₱)</label>' +
        '<input type="number" id="editRoomPrice" min="0" step="1" value="' + (currentPrice || 0) + '" style="width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;"></div>' +
        '<p id="editRoomError" style="color:#b3433f;font-size:0.8rem;margin:0;display:none;"></p>' +
      '</div>' +
      '<div style="display:flex;gap:10px;justify-content:flex-end;">' +
        '<button id="editRoomCancelBtn" style="padding:8px 16px;border:1px solid #ccc;background:#f9f9f9;border-radius:4px;cursor:pointer;font-family:inherit;">Cancel</button>' +
        '<button id="editRoomSaveBtn" style="padding:8px 16px;background:#3b7dd8;color:#fff;border:none;border-radius:4px;cursor:pointer;font-family:inherit;">Save</button>' +
      '</div>';
    modal.appendChild(inner);
    document.body.appendChild(modal);

    modal.querySelector('#editRoomCancelBtn').addEventListener('click', function () { modal.remove(); });
    modal.addEventListener('click', function (e) { if (e.target === modal) modal.remove(); });

    modal.querySelector('#editRoomSaveBtn').addEventListener('click', function () {
      const errorEl = modal.querySelector('#editRoomError');
      const number = modal.querySelector('#editRoomNumber').value.trim();
      const type = modal.querySelector('#editRoomType').value.trim();
      const price = modal.querySelector('#editRoomPrice').value;

      if (!number || !type) {
        errorEl.textContent = 'Room number and type are both required.';
        errorEl.style.display = 'block';
        return;
      }

      const fd = new FormData();
      fd.append('action', 'update_room_details');
      fd.append('room_id', roomId);
      fd.append('room_number', number);
      fd.append('room_type', type);
      fd.append('price_per_night', price);
      fd.append('csrf_token', cfg.csrfToken);

      fetch('/process_room_action.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.success) {
            if (data.room) updateRoomSidebar(data.room);
            modal.remove();
          } else {
            errorEl.textContent = data.message || 'Could not save room details.';
            errorEl.style.display = 'block';
          }
        })
        .catch(function (err) {
          console.error('[calendar] Edit room details error:', err);
          errorEl.textContent = 'Network error.';
          errorEl.style.display = 'block';
        });
    });
  }

  // ─── Context action handlers ──────────────────────────────────────
  function handleContextAction(action) {
    let r = contextReservation;

    if (!r && selectedBar) {
      try {
        r = JSON.parse(selectedBar.dataset.reservation);
        contextReservation = r;
        contextBar = selectedBar;
      } catch (e) {
        console.warn('[context] Could not parse selected bar data');
      }
    }

    if (!r) {
      alert('No reservation data available.');
      return;
    }

    console.log('[context] Handling action:', action, 'reservation:', r);

    switch(action) {
      case 'edit':
        renderForm(r, null);
        break;

      case 'cancel':
        showConfirmDialog('Cancel this reservation?', 'Confirm Cancellation').then(function(confirmed) {
          if (!confirmed) return;
          updateReservationStatus(r.id, 'cancelled', r);
        });
        break;

      case 'checkin':
        showConfirmDialog('Check in this guest?', 'Confirm Check-In').then(function(confirmed) {
          if (!confirmed) return;
          updateReservationStatus(r.id, 'checked_in', r);
        });
        break;

      case 'checkout':
        showConfirmDialog('Check out this guest?', 'Confirm Check-Out').then(function(confirmed) {
          if (!confirmed) return;
          updateReservationStatus(r.id, 'checked_out', r);
        });
        break;

      case 'group_checkin':
        showConfirmDialog('Check in this guest?', 'Confirm Group Check-In').then(function(confirmed) {
          if (!confirmed) return;
          updateReservationStatus(r.id, 'checked_in', r);
        });
        break;

      case 'group_checkout':
        showConfirmDialog('Check out this guest?', 'Confirm Group Check-Out').then(function(confirmed) {
          if (!confirmed) return;
          updateReservationStatus(r.id, 'checked_out', r);
        });
        break;

      case 'extend': {
        const extendDays = prompt('Extend stay by how many days?', '1');
        if (extendDays !== null && !isNaN(extendDays) && parseInt(extendDays) > 0) {
          const newOut = new Date(r.check_out);
          newOut.setDate(newOut.getDate() + parseInt(extendDays));
          updateReservationDates(r.id, r.check_in, formatLocalDate(newOut), r);
        }
        break;
      }

      case 'early_checkout': {
        const newDate = prompt('Enter new check-out date (YYYY-MM-DD):', r.check_out);
        if (newDate && /^\d{4}-\d{2}-\d{2}$/.test(newDate)) {
          updateReservationDates(r.id, r.check_in, newDate, r);
        }
        break;
      }

      case 'move':
      case 'upgrade':
      case 'downgrade': {
        if (!cfg.rooms || cfg.rooms.length === 0) {
          alert('No rooms available to move to.');
          return;
        }
        const roomOptionsHtml = cfg.rooms.map(function(room) {
          return '<option value="' + room.id + '">RM' + room.room_number + ' — ' + room.room_type + '</option>';
        }).join('');
        const moveModal = document.createElement('div');
        moveModal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;z-index:10050;';
        const inner = document.createElement('div');
        inner.style.cssText = 'background:#fff;border-radius:8px;padding:20px;max-width:400px;width:100%;';
        inner.innerHTML = '<h3 style="margin-top:0;">Select New Room</h3>' +
                          '<select id="moveRoomSelect" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;margin-bottom:12px;">' +
                          roomOptionsHtml +
                          '</select>' +
                          '<div style="display:flex;gap:10px;justify-content:flex-end;">' +
                          '<button id="moveCancelBtn" style="padding:8px 16px;border:1px solid #ccc;background:#f9f9f9;border-radius:4px;cursor:pointer;">Cancel</button>' +
                          '<button id="moveConfirmBtn" style="padding:8px 16px;background:#3b7dd8;color:#fff;border:none;border-radius:4px;cursor:pointer;">Move</button>' +
                          '</div>';
        moveModal.appendChild(inner);
        document.body.appendChild(moveModal);
        moveModal.querySelector('#moveCancelBtn').addEventListener('click', function() { moveModal.remove(); });
        moveModal.querySelector('#moveConfirmBtn').addEventListener('click', function() {
          const newRoomId = parseInt(document.getElementById('moveRoomSelect').value);
          moveModal.remove();

          const fd = new FormData();
          fd.append('action', 'update');
          fd.append('id', r.id);
          fd.append('csrf_token', cfg.csrfToken);
          fd.append('room_id', newRoomId);

          const fields = [
            'guest_full_name', 'contact_number', 'email', 'address',
            'valid_id_type', 'valid_id_number', 'check_in', 'check_out',
            'num_adults', 'num_children', 'status',
            'room_rate', 'security_deposit', 'total_amount', 'amount_paid',
            'payment_method', 'notes', 'special_requests'
          ];
          fields.forEach(function(key) {
            fd.append(key, r[key] !== undefined && r[key] !== null ? r[key] : '');
          });

          console.log('[context] Moving reservation – full payload:', Object.fromEntries(fd));

          fetch('/process_reservation.php', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
              console.log('[context] Move response:', data);
              if (data.success) {
                handleReservationSaveSuccess(data);
              } else {
                const errorMsg = data.errors
                  ? Object.values(data.errors).join('\n')
                  : data.message || 'Error moving reservation.';
                alert('Error:\n' + errorMsg);
              }
            })
            .catch(err => {
              console.error('[context] Move error:', err);
              alert('Network error.');
            });
        });
        break;
      }

      case 'rate': {
        const newRate = prompt('Enter new room rate:', r.room_rate);
        if (newRate !== null && !isNaN(newRate)) {
          const fd = new FormData();
          fd.append('action', 'update');
          fd.append('id', r.id);
          fd.append('csrf_token', cfg.csrfToken);
          fd.append('room_rate', newRate);
          Object.keys(r).forEach(function(key) {
            if (key !== 'room_rate' && key !== 'id') {
              fd.append(key, r[key]);
            }
          });
          console.log('[context] Updating rate:', Object.fromEntries(fd));
          fetch('/process_reservation.php', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
              console.log('[context] Rate update response:', data);
              if (data.success) {
                handleReservationSaveSuccess(data);
              } else {
                const errorMsg = data.errors
                  ? Object.values(data.errors).join('\n')
                  : data.message || 'Error updating rate.';
                alert('Error:\n' + errorMsg);
              }
            })
            .catch(err => {
              console.error('[context] Rate error:', err);
              alert('Network error.');
            });
        }
        break;
      }

      case 'guest_folio':
      case 'master_folio': {
        const folioModal = document.createElement('div');
        folioModal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;z-index:10050;';
        const folioInner = document.createElement('div');
        folioInner.style.cssText = 'background:#fff;border-radius:8px;padding:24px;max-width:500px;width:100%;max-height:80vh;overflow-y:auto;';
        const nights = Math.round((new Date(r.check_out) - new Date(r.check_in)) / 86400000);
        const balance = (parseFloat(r.total_amount) || 0) - (parseFloat(r.amount_paid) || 0);
        folioInner.innerHTML = '<h3>' + (action === 'guest_folio' ? 'Guest Folio' : 'Master Folio') + '</h3>' +
          '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;margin:12px 0;">' +
          '<div><strong>Guest:</strong> ' + (r.guest_full_name || 'N/A') + '</div>' +
          '<div><strong>Room:</strong> RM' + (r.room_number || '?') + '</div>' +
          '<div><strong>Check-in:</strong> ' + r.check_in + '</div>' +
          '<div><strong>Check-out:</strong> ' + r.check_out + '</div>' +
          '<div><strong>Nights:</strong> ' + nights + '</div>' +
          '<div><strong>Status:</strong> ' + (cfg.statusLabels[r.status] || r.status) + '</div>' +
          '<div><strong>Total Amount:</strong> ₱' + parseFloat(r.total_amount || 0).toFixed(2) + '</div>' +
          '<div><strong>Amount Paid:</strong> ₱' + parseFloat(r.amount_paid || 0).toFixed(2) + '</div>' +
          '<div><strong>Balance:</strong> ₱' + balance.toFixed(2) + (balance > 0 ? ' <span style="color:#b3433f;">(Due)</span>' : '') + '</div>' +
          '<div><strong>Payment Method:</strong> ' + (r.payment_method || 'N/A') + '</div>' +
          '</div>';
        if (r.special_requests) {
          folioInner.innerHTML += '<p><strong>Special Requests:</strong> ' + r.special_requests + '</p>';
        }
        if (r.notes) {
          folioInner.innerHTML += '<p><strong>Notes:</strong> ' + r.notes + '</p>';
        }
        folioInner.innerHTML += '<button id="folioCloseBtn" style="margin-top:16px;padding:8px 20px;background:#3b7dd8;color:#fff;border:none;border-radius:4px;cursor:pointer;">Close</button>';
        folioModal.appendChild(folioInner);
        document.body.appendChild(folioModal);
        folioModal.querySelector('#folioCloseBtn').addEventListener('click', function() { folioModal.remove(); });
        folioModal.addEventListener('click', function(e) { if (e.target === folioModal) folioModal.remove(); });
        break;
      }

      case 'profile': {
        const profileModal = document.createElement('div');
        profileModal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;z-index:10050;';
        const inner = document.createElement('div');
        inner.style.cssText = 'background:#fff;border-radius:8px;padding:24px;max-width:400px;width:100%;';
        inner.innerHTML = '<h3>Guest Profile</h3>' +
          '<div style="margin:12px 0;">' +
          '<p><strong>Name:</strong> ' + (r.guest_full_name || 'N/A') + '</p>' +
          '<p><strong>Contact:</strong> ' + (r.contact_number || 'N/A') + '</p>' +
          '<p><strong>Email:</strong> ' + (r.email || 'N/A') + '</p>' +
          '<p><strong>Address:</strong> ' + (r.address || 'N/A') + '</p>' +
          '<p><strong>Valid ID:</strong> ' + (r.valid_id_type || 'N/A') + ' #' + (r.valid_id_number || 'N/A') + '</p>' +
          '</div>' +
          '<button id="profileCloseBtn" style="padding:8px 20px;background:#3b7dd8;color:#fff;border:none;border-radius:4px;cursor:pointer;">Close</button>';
        profileModal.appendChild(inner);
        document.body.appendChild(profileModal);
        profileModal.querySelector('#profileCloseBtn').addEventListener('click', function() { profileModal.remove(); });
        profileModal.addEventListener('click', function(e) { if (e.target === profileModal) profileModal.remove(); });
        break;
      }

      case 'history': {
        const historyModal = document.createElement('div');
        historyModal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;z-index:10050;';
        const inner = document.createElement('div');
        inner.style.cssText = 'background:#fff;border-radius:8px;padding:24px;max-width:500px;width:100%;max-height:70vh;overflow-y:auto;';
        let html = '<h3>Reservation History</h3><ul style="list-style:none;padding:0;margin:0;">';
        if (r._activity && r._activity.length) {
          r._activity.forEach(function(entry) {
            const when = new Date(entry.created_at.replace(' ', 'T')).toLocaleString();
            const who = entry.full_name || entry.username || 'Unknown';
            html += '<li style="padding:8px 0;border-bottom:1px solid #eee;">' +
              '<strong>' + entry.action.charAt(0).toUpperCase() + entry.action.slice(1) + '</strong> by ' + who +
              ' — ' + when +
              (entry.details ? '<br><span style="color:#666;font-size:0.85rem;">' + entry.details + '</span>' : '') +
              '</li>';
          });
        } else {
          html += '<li>No activity recorded.</li>';
        }
        html += '</ul>';
        html += '<button id="historyCloseBtn" style="margin-top:16px;padding:8px 20px;background:#3b7dd8;color:#fff;border:none;border-radius:4px;cursor:pointer;">Close</button>';
        inner.innerHTML = html;
        historyModal.appendChild(inner);
        document.body.appendChild(historyModal);
        historyModal.querySelector('#historyCloseBtn').addEventListener('click', function() { historyModal.remove(); });
        historyModal.addEventListener('click', function(e) { if (e.target === historyModal) historyModal.remove(); });
        break;
      }

      case 'print_regcard': {
        const printWindow = window.open('', '_blank', 'width=800,height=600');
        if (!printWindow) {
          alert('Please allow pop-ups to print.');
          return;
        }
        const nights = Math.round((new Date(r.check_out) - new Date(r.check_in)) / 86400000);
        printWindow.document.write(`
          <html><head><title>Registration Card</title>
          <style>body{font-family:sans-serif;padding:40px;max-width:600px;margin:0 auto;}
          h1{color:#16324f;border-bottom:2px solid #3b7dd8;padding-bottom:10px;}
          .row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #eee;}
          .label{font-weight:600;color:#5b7693;}.value{font-weight:500;}
          .print-btn{display:none;}
          @media print{.print-btn{display:none;}}
</style></head><body>
<h1>Registration Card</h1>
<div class="row"><span class="label">Guest Name</span><span class="value">${r.guest_full_name || 'N/A'}</span></div>
<div class="row"><span class="label">Room</span><span class="value">RM${r.room_number || '?'}</span></div>
<div class="row"><span class="label">Check-in</span><span class="value">${r.check_in}</span></div>
<div class="row"><span class="label">Check-out</span><span class="value">${r.check_out}</span></div>
<div class="row"><span class="label">Nights</span><span class="value">${nights}</span></div>
<div class="row"><span class="label">Adults</span><span class="value">${r.num_adults || 1}</span></div>
<div class="row"><span class="label">Children</span><span class="value">${r.num_children || 0}</span></div>
<div class="row"><span class="label">Contact</span><span class="value">${r.contact_number || 'N/A'}</span></div>
<div class="row"><span class="label">Email</span><span class="value">${r.email || 'N/A'}</span></div>
<div class="row"><span class="label">Valid ID</span><span class="value">${r.valid_id_type || 'N/A'} #${r.valid_id_number || 'N/A'}</span></div>
<div class="row"><span class="label">Rate/Night</span><span class="value">₱${parseFloat(r.room_rate || 0).toFixed(2)}</span></div>
<div class="row"><span class="label">Total Amount</span><span class="value">₱${parseFloat(r.total_amount || 0).toFixed(2)}</span></div>
<div class="row"><span class="label">Amount Paid</span><span class="value">₱${parseFloat(r.amount_paid || 0).toFixed(2)}</span></div>
<div class="row"><span class="label">Payment Method</span><span class="value">${r.payment_method || 'N/A'}</span></div>
<div style="margin-top:30px;text-align:center;color:#5b7693;font-size:0.8rem;">Generated by Bluebookers PMS</div>
<button class="print-btn" onclick="window.print()">Print</button>
</body></html>
`);
        printWindow.document.close();
        setTimeout(function() { printWindow.print(); }, 500);
        break;
      }

      case 'print_folio': {
        const printWindow = window.open('', '_blank', 'width=800,height=600');
        if (!printWindow) {
          alert('Please allow pop-ups to print.');
          return;
        }
        const nights = Math.round((new Date(r.check_out) - new Date(r.check_in)) / 86400000);
        const balance = (parseFloat(r.total_amount) || 0) - (parseFloat(r.amount_paid) || 0);
        printWindow.document.write(`
          <html><head><title>Folio</title>
          <style>body{font-family:sans-serif;padding:40px;max-width:600px;margin:0 auto;}
          h1{color:#16324f;border-bottom:2px solid #3b7dd8;padding-bottom:10px;}
          .row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #eee;}
          .label{font-weight:600;color:#5b7693;}.value{font-weight:500;}
          .total{font-weight:700;border-top:2px solid #16324f;margin-top:10px;padding-top:10px;}
          .print-btn{display:none;}
          @media print{.print-btn{display:none;}}
</style></head><body>
<h1>Guest Folio</h1>
<div class="row"><span class="label">Guest Name</span><span class="value">${r.guest_full_name || 'N/A'}</span></div>
<div class="row"><span class="label">Room</span><span class="value">RM${r.room_number || '?'}</span></div>
<div class="row"><span class="label">Check-in</span><span class="value">${r.check_in}</span></div>
<div class="row"><span class="label">Check-out</span><span class="value">${r.check_out}</span></div>
<div class="row"><span class="label">Nights</span><span class="value">${nights}</span></div>
<div class="row"><span class="label">Rate/Night</span><span class="value">₱${parseFloat(r.room_rate || 0).toFixed(2)}</span></div>
<div class="row total"><span class="label">Total Amount</span><span class="value">₱${parseFloat(r.total_amount || 0).toFixed(2)}</span></div>
<div class="row"><span class="label">Amount Paid</span><span class="value">₱${parseFloat(r.amount_paid || 0).toFixed(2)}</span></div>
<div class="row"><span class="label">Balance Due</span><span class="value" style="${balance > 0 ? 'color:#b3433f;' : ''}">₱${balance.toFixed(2)}</span></div>
<div class="row"><span class="label">Payment Method</span><span class="value">${r.payment_method || 'N/A'}</span></div>
${r.special_requests ? `<div class="row"><span class="label">Special Requests</span><span class="value">${r.special_requests}</span></div>` : ''}
<div style="margin-top:30px;text-align:center;color:#5b7693;font-size:0.8rem;">Generated by Bluebookers PMS</div>
<button class="print-btn" onclick="window.print()">Print</button>
</body></html>
`);
        printWindow.document.close();
        setTimeout(function() { printWindow.print(); }, 500);
        break;
      }

      default:
        console.warn('[context] Unknown action:', action);
    }
  }

  // ─── Helpers for status/dates update ──────────────────────────────
  function updateReservationStatus(id, newStatus, reservation) {
    const r = reservation || contextReservation;
    if (!r) {
      alert('No reservation data available.');
      return;
    }

    const fd = new FormData();
    fd.append('action', 'update');
    fd.append('id', id);
    fd.append('csrf_token', cfg.csrfToken);
    fd.append('status', newStatus);
    Object.keys(r).forEach(function(key) {
      if (key !== 'status' && key !== 'id') {
        fd.append(key, r[key] !== undefined && r[key] !== null ? r[key] : '');
      }
    });

    console.log('[update] Updating status – full payload:', Object.fromEntries(fd));

    fetch('/process_reservation.php', { method: 'POST', body: fd })
      .then(res => res.json())
      .then(data => {
        console.log('[update] Status update response:', data);
        if (data.success) {
          handleReservationSaveSuccess(data);
        } else {
          const errorMsg = data.errors
            ? Object.values(data.errors).join('\n')
            : data.message || 'Error updating status.';
          alert('Error:\n' + errorMsg);
        }
      })
      .catch(err => {
        console.error('[update] Status update error:', err);
        alert('Network error.');
      });
  }

  function updateReservationDates(id, checkIn, checkOut, reservation) {
    const r = reservation || contextReservation;
    if (!r) {
      alert('No reservation data available.');
      return;
    }

    const fd = new FormData();
    fd.append('action', 'update');
    fd.append('id', id);
    fd.append('csrf_token', cfg.csrfToken);
    fd.append('check_in', checkIn);
    fd.append('check_out', checkOut);
    Object.keys(r).forEach(function(key) {
      if (key !== 'check_in' && key !== 'check_out' && key !== 'id') {
        fd.append(key, r[key] !== undefined && r[key] !== null ? r[key] : '');
      }
    });

    console.log('[update] Updating dates – full payload:', Object.fromEntries(fd));

    fetch('/process_reservation.php', { method: 'POST', body: fd })
      .then(res => res.json())
      .then(data => {
        console.log('[update] Dates update response:', data);
        if (data.success) {
          handleReservationSaveSuccess(data);
        } else {
          const errorMsg = data.errors
            ? Object.values(data.errors).join('\n')
            : data.message || 'Error updating dates.';
          alert('Error:\n' + errorMsg);
        }
      })
      .catch(err => {
        console.error('[update] Dates update error:', err);
        alert('Network error.');
      });
  }

  // ─── ENTRY POINTS ──────────────────────────────────────────────────
  newBtn.addEventListener('click', function () {
    renderForm(null, {});
  });

  document.querySelectorAll('.cal-day-slot').forEach(function (slot) {
    slot.addEventListener('click', function () {
      const row = this.closest('.cal-row');
      if (row && row.classList.contains('maintenance')) {
        alert('This room is currently Out of Order. Please clear the maintenance flag on the Layout page before booking.');
        return;
      }
      if (row && row.dataset.statusKey === 'needs_cleaning') {
        alert('This room is Vacant Dirty. Please mark it as clean before creating a new reservation.');
        return;
      }
      highlightSlot(this);
    });
  });

  document.querySelectorAll('.cal-label-col[data-room-id]').forEach(function (label) {
    label.addEventListener('click', function (e) {
      const roomId = this.dataset.roomId;
      if (!roomId) return;

      const row = this.closest('.cal-row');
      if (row && row.dataset.statusKey === 'needs_cleaning') {
        alert('This room is Vacant Dirty. Please mark it as clean before creating a new reservation.');
        return;
      }

      let checkIn = selectedDate;
      if (!checkIn) {
        const today = new Date();
        checkIn = formatLocalDate(today);
      }
      const checkOutDate = new Date(checkIn + 'T00:00:00');
      checkOutDate.setDate(checkOutDate.getDate() + 1);
      const checkOut = formatLocalDate(checkOutDate);

      renderForm(null, { room_id: roomId, check_in: checkIn, check_out: checkOut });
    });
  });

  document.addEventListener('click', function(e) {
    if (!e.target.closest('.cal-day-slot')) {
      highlightSlot(null);
    }
    if (!e.target.closest('.cal-bar')) {
      selectBar(null);
    }
  });

  // ─── DRAG LOGIC ────────────────────────────────────────────────────
  function wireBarInteractions(bar) {
    function getSlotsForTrack(track) {
      return Array.prototype.slice.call(track.querySelectorAll('.cal-day-slot'));
    }

    function dateOffset(dateStr, slots) {
      if (!slots || slots.length === 0) return 0;
      const base = new Date(slots[0].dataset.date + 'T00:00:00');
      const target = new Date(dateStr + 'T00:00:00');
      return Math.round((target - base) / 86400000);
    }

    function dateAtOffset(idx, slots) {
      const base = new Date(slots[0].dataset.date + 'T00:00:00');
      const d = new Date(base);
      d.setDate(d.getDate() + idx);
      return formatLocalDate(d);
    }

    function getSlotUnderMouse(clientX, slots) {
      let closestIdx = 0;
      let closestDist = Infinity;
      for (let i = 0; i < slots.length; i++) {
        const rect = slots[i].getBoundingClientRect();
        if (clientX >= rect.left && clientX < rect.right) {
          return i;
        }
        const centerX = (rect.left + rect.right) / 2;
        const dist = Math.abs(clientX - centerX);
        if (dist < closestDist) {
          closestDist = dist;
          closestIdx = i;
        }
      }
      return closestIdx;
    }

    function applyPosition(startIdx, endIdx, track) {
      const slots = getSlotsForTrack(track);
      const total = slots.length;
      if (total === 0) return;
      bar.style.left = (startIdx / total * 100) + '%';
      bar.style.width = ((endIdx - startIdx) / total * 100) + '%';
    }

    function readCurrentState() {
      const row = bar.closest('.cal-row');
      const track = bar.closest('.cal-row__track');
      const resv = JSON.parse(bar.dataset.reservation);
      const isMaintenanceRow = row && row.classList.contains('maintenance');
      const draggable = !isMaintenanceRow && (resv.status === 'reserved' || resv.status === 'checked_in');
      const slots = getSlotsForTrack(track);
      const startIdx = dateOffset(resv.check_in, slots);
      const endIdx = dateOffset(resv.check_out, slots);
      const duration = endIdx - startIdx;
      const totalDays = slots.length;
      const canMove = (totalDays - duration) > 0;
      return { row: row, track: track, resv: resv, draggable: draggable, slots: slots, startIdx: startIdx, endIdx: endIdx, duration: duration, totalDays: totalDays, canMove: canMove };
    }

    function refreshHandles(state) {
      bar.querySelectorAll('.cal-bar__handle').forEach(function (h) { h.remove(); });
      bar.classList.remove('is-draggable');
      if (state.draggable && state.totalDays > 0 && state.canMove) {
        bar.classList.add('is-draggable');
        if (state.startIdx >= 0 && state.startIdx <= state.totalDays) {
          const leftHandle = document.createElement('span');
          leftHandle.className = 'cal-bar__handle cal-bar__handle--left';
          bar.appendChild(leftHandle);
        }
        if (state.endIdx >= 0 && state.endIdx <= state.totalDays) {
          const rightHandle = document.createElement('span');
          rightHandle.className = 'cal-bar__handle cal-bar__handle--right';
          bar.appendChild(rightHandle);
        }
      }
    }

    refreshHandles(readCurrentState());
    bar.__bbRefreshHandles = function () { refreshHandles(readCurrentState()); };

    let dragMode = null;
    let currentTrack = null;
    let currentRow = null;
    let originalTrack = null;
    let originalRow = null;
    let offsetDays = 0;
    let anchorStartIdx = 0;
    let liveStartIdx = 0;
    let liveEndIdx = 0;
    let moved = false;
    let slots = [];
    let origStartIdx = 0;
    let origEndIdx = 0;
    let duration = 0;
    let resv = null;

    function clearDropTarget() {
      document.querySelectorAll('.cal-row.drop-target').forEach(function (r) { r.classList.remove('drop-target'); });
    }

    function revertToOriginal() {
      if (currentTrack !== originalTrack) {
        originalTrack.appendChild(bar);
        currentTrack = originalTrack;
        currentRow = originalRow;
        slots = getSlotsForTrack(currentTrack);
      }
      clearDropTarget();
      liveStartIdx = origStartIdx;
      liveEndIdx = origEndIdx;
      applyPosition(origStartIdx, origEndIdx, currentTrack);
    }

    function onPointerDown(e) {
      if (e.button === 2) return;

      const state = readCurrentState();
      refreshHandles(state);
      resv = state.resv;
      originalRow = state.row;
      originalTrack = state.track;
      currentTrack = state.track;
      currentRow = state.row;
      slots = state.slots;
      origStartIdx = state.startIdx;
      origEndIdx = state.endIdx;
      duration = state.duration;

      if (!state.draggable || state.totalDays === 0) return;
      const handleSide = e.target.classList && e.target.classList.contains('cal-bar__handle--left') ? 'left'
                        : e.target.classList && e.target.classList.contains('cal-bar__handle--right') ? 'right' : null;

      if (!handleSide && !state.canMove) return;

      e.preventDefault();

      moved = false;
      liveStartIdx = origStartIdx;
      liveEndIdx = origEndIdx;

      anchorStartIdx = liveStartIdx;
      const cursorSlotStart = getSlotUnderMouse(e.clientX, slots);
      offsetDays = cursorSlotStart - anchorStartIdx;

      if (handleSide === 'left' && origStartIdx >= 0) {
        dragMode = 'resize-left';
      } else if (handleSide === 'right' && origEndIdx <= slots.length) {
        dragMode = 'resize-right';
      } else if (!handleSide && state.canMove) {
        dragMode = 'move';
      } else {
        dragMode = null;
        return;
      }

      if (dragMode) {
        bar.classList.add('is-dragging');
        if (dragMode !== 'move' && bar.setPointerCapture) {
          try { bar.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
        }
        document.body.style.userSelect = 'none';
        document.addEventListener('pointermove', onPointerMove);
        document.addEventListener('pointerup', onPointerUp);
        e.stopPropagation();
      }
    }

    function findRowAtY(clientY) {
      const rows = Array.prototype.slice.call(document.querySelectorAll('.cal-row[data-room-id]'));
      for (let i = 0; i < rows.length; i++) {
        const r = rows[i].getBoundingClientRect();
        if (clientY >= r.top && clientY <= r.bottom) return rows[i];
      }
      return null;
    }

    function onPointerMove(e) {
      if (!dragMode) return;
      moved = true;

      if (dragMode === 'move') {
        const hoveredRow = findRowAtY(e.clientY);
        if (hoveredRow && hoveredRow !== currentRow
            && !hoveredRow.classList.contains('maintenance')
            && hoveredRow.dataset.statusKey !== 'needs_cleaning') {
          const newTrack = hoveredRow.querySelector('.cal-row__track');
          if (newTrack) {
            newTrack.appendChild(bar);
            currentTrack = newTrack;
            currentRow = hoveredRow;
            slots = getSlotsForTrack(currentTrack);
            clearDropTarget();
            hoveredRow.classList.add('drop-target');
          }
        }
      }

      const mouseIdx = getSlotUnderMouse(e.clientX, slots);

      if (dragMode === 'move') {
        let newStart = mouseIdx - offsetDays;
        const maxStart = slots.length - duration;
        newStart = Math.max(0, Math.min(newStart, maxStart));
        liveStartIdx = newStart;
        liveEndIdx = liveStartIdx + duration;
      } else if (dragMode === 'resize-left') {
        let newStart = Math.min(mouseIdx, liveEndIdx - 1);
        newStart = Math.max(0, newStart);
        liveStartIdx = newStart;
      } else if (dragMode === 'resize-right') {
        let newEnd = Math.max(mouseIdx, liveStartIdx + 1);
        newEnd = Math.min(slots.length, newEnd);
        liveEndIdx = newEnd;
      }

      applyPosition(liveStartIdx, liveEndIdx, currentTrack);
    }

    function onPointerUp(e) {
      document.removeEventListener('pointermove', onPointerMove);
      document.removeEventListener('pointerup', onPointerUp);
      document.body.style.userSelect = '';
      bar.classList.remove('is-dragging');
      clearDropTarget();
      const mode = dragMode;
      dragMode = null;

      if (!mode) {
        return;
      }

      if (!moved) {
        revertToOriginal();
        return;
      }

      const roomChanged = currentRow !== originalRow;
      if (!roomChanged && liveStartIdx === origStartIdx && liveEndIdx === origEndIdx) {
        return;
      }

      const finalStart = liveStartIdx;
      const finalEnd = liveEndIdx;
      const newCheckIn = dateAtOffset(finalStart, slots);
      const newCheckOut = dateAtOffset(finalEnd, slots);
      const newRoomId = currentRow.dataset.roomId;

      const siblingBars = Array.prototype.slice.call(currentTrack.querySelectorAll('.cal-bar')).filter(function (b) { return b !== bar; });
      const conflict = siblingBars.some(function (b) {
        const other = JSON.parse(b.dataset.reservation);
        return other.check_in < newCheckOut && other.check_out > newCheckIn;
      });

      if (conflict) {
        alert('That would overlap another reservation in this room.');
        revertToOriginal();
        return;
      }

      if (currentRow.dataset.statusKey === 'needs_cleaning') {
        alert('This room is Vacant Dirty. Please mark it as clean before moving a reservation here.');
        revertToOriginal();
        return;
      }

      let confirmMsg;
      if (roomChanged && (finalStart !== origStartIdx || finalEnd !== origEndIdx)) {
        confirmMsg = 'Move this reservation to Room ' + currentRow.querySelector('.cal-label-col').dataset.roomNumber + ', ' + newCheckIn + ' – ' + newCheckOut + '?';
      } else if (roomChanged) {
        confirmMsg = 'Move this reservation to Room ' + currentRow.querySelector('.cal-label-col').dataset.roomNumber + '?';
      } else {
        const verb = mode === 'move' ? 'move this reservation to' : 'change this reservation\'s dates to';
        confirmMsg = 'Are you sure you want to ' + verb + ' ' + newCheckIn + ' – ' + newCheckOut + '?';
      }

      showConfirmDialog(confirmMsg, 'Confirm Changes').then(function (confirmed) {
        if (!confirmed) {
          revertToOriginal();
          return;
        }

        const fd = new FormData();
        fd.append('action', 'update');
        fd.append('id', resv.id);
        fd.append('csrf_token', cfg.csrfToken);
        fd.append('room_id', newRoomId);
        fd.append('guest_full_name', resv.guest_full_name || '');
        fd.append('contact_number', resv.contact_number || '');
        fd.append('email', resv.email || '');
        fd.append('address', resv.address || '');
        fd.append('valid_id_type', resv.valid_id_type || '');
        fd.append('valid_id_number', resv.valid_id_number || '');
        fd.append('check_in', newCheckIn);
        fd.append('check_out', newCheckOut);
        fd.append('num_adults', resv.num_adults || 1);
        fd.append('num_children', resv.num_children || 0);
        fd.append('status', resv.status);
        fd.append('room_rate', resv.room_rate || 0);
        fd.append('security_deposit', resv.security_deposit || 0);
        fd.append('total_amount', resv.total_amount || 0);
        fd.append('amount_paid', resv.amount_paid || 0);
        fd.append('payment_method', resv.payment_method || '');
        fd.append('notes', resv.notes || '');
        fd.append('special_requests', resv.special_requests || '');

        console.log('[DRAG] Sending update:', Object.fromEntries(fd));

        fetch('/process_reservation.php', { method: 'POST', body: fd })
          .then(function (r) {
            return r.json().catch(function () {
              throw new Error('Server returned invalid JSON. Check PHP error log.');
            });
          })
          .then(function (res) {
            if (res.success) {
              handleReservationSaveSuccess(res);
            } else {
              const errorMsg = res.errors
                ? Object.values(res.errors).join('\n')
                : res.message || 'Error updating reservation.';
              alert('Error:\n' + errorMsg);
              revertToOriginal();
            }
          })
          .catch(function (err) {
            console.error('[DRAG] Error:', err);
            alert('Error: ' + (err.message || 'Network error. Please try again.'));
            revertToOriginal();
          });
      });
    }

    bar.addEventListener('pointerdown', onPointerDown);
  }

  document.querySelectorAll('.cal-bar').forEach(function (bar) {
    try {
      wireBarInteractions(bar);
      bar.__bbWired = true;
    } catch (err) {
      console.error('[calendar.js] failed to wire a bar:', err);
    }

    try {
      const resv = JSON.parse(bar.dataset.reservation);
      if (resv.id) bar.dataset.reservationId = resv.id;
    } catch(e) {}
  });

  // ─── DELEGATED EVENTS (click & context menu) ──────────────────────
  const calGridEl = document.querySelector('.cal-grid');
  if (calGridEl) {
    calGridEl.addEventListener('click', function (e) {
      const bar = e.target.closest('.cal-bar');
      if (!bar) return;
      e.stopPropagation();
      try {
        const resv = JSON.parse(bar.dataset.reservation);
        selectBar(bar);
        contextReservation = resv;
        contextBar = bar;
      } catch (err) { /* ignore */ }
    });

    calGridEl.addEventListener('contextmenu', function (e) {
      const bar = e.target.closest('.cal-bar');
      if (bar) {
        e.preventDefault();
        e.stopPropagation();
        try {
          const resv = JSON.parse(bar.dataset.reservation);
          showContextMenu(e.clientX, e.clientY, 'bar', { resv: resv, bar: bar });
        } catch (err) {
          console.error('[calendar] Error parsing reservation:', err);
          alert('Error reading reservation data.');
        }
        return;
      }

      // Only body rows – exclude the header
      const label = e.target.closest('.cal-label-col[data-room-id]');
      if (label && !label.classList.contains('cal-label-col--header') && label.dataset.roomId) {
        e.preventDefault();
        e.stopPropagation();
        showContextMenu(e.clientX, e.clientY, 'room', { roomId: label.dataset.roomId });
      }
    });
  }

  // ─── RESTORE STATE ──────────────────────────────────────────────────
  function scrollToReservationOnLoad() {
    const hash = window.location.hash;
    if (!hash || !hash.startsWith('#reservation-')) return;

    const id = parseInt(hash.replace('#reservation-', ''), 10);
    if (isNaN(id)) return;

    setTimeout(function() {
      const allBars = document.querySelectorAll('.cal-bar');
      let targetBar = null;

      for (let i = 0; i < allBars.length; i++) {
        const bar = allBars[i];
        try {
          const data = JSON.parse(bar.dataset.reservation);
          if (data.id === id || data.reservation_id === id || data.ID === id) {
            targetBar = bar;
            break;
          }
        } catch(e) {}
      }

      if (targetBar) {
        const wrap = document.querySelector('.cal-grid-wrap');
        if (wrap) {
          const barRect = targetBar.getBoundingClientRect();
          const wrapRect = wrap.getBoundingClientRect();
          const scrollLeft = wrap.scrollLeft + (barRect.left - wrapRect.left) - (wrapRect.width / 2) + (barRect.width / 2);
          wrap.scrollTo({ left: Math.max(0, scrollLeft), behavior: 'smooth' });
        } else {
          targetBar.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
        }
        selectBar(targetBar);
      }
    }, 500);
  }

  function initRestore() {
    const wrap = document.querySelector('.cal-grid-wrap');
    if (wrap) wrap.addEventListener('scroll', saveScrollPosition, { passive: true });

    setTimeout(function() {
      requestAnimationFrame(function() {
        requestAnimationFrame(function() {
          restoreCalendarState();
        });
      });
    }, 250);
  }

  if (document.readyState === 'complete') {
    scrollToReservationOnLoad();
    initRestore();
  } else {
    window.addEventListener('load', function() {
      scrollToReservationOnLoad();
      initRestore();
    });
  }

  // ─── TODAY LINE, BOUNDARIES, TOP SCROLL, FILTERS ──────────────────
  (function wireTodayLine() {
    const grid = document.querySelector('.cal-grid');
    if (!grid) return;
    let lineEl = null;

    function position() {
      const todaySlot = grid.querySelector('.cal-day-slot.is-today');
      if (!todaySlot) return;
      const gridRect = grid.getBoundingClientRect();
      const slotRect = todaySlot.getBoundingClientRect();
      if (!lineEl) {
        lineEl = document.createElement('div');
        lineEl.className = 'cal-today-line';
        grid.appendChild(lineEl);
      }
      lineEl.style.left = (slotRect.left - gridRect.left) + 'px';
      lineEl.style.width = slotRect.width + 'px';
    }

    position();
    let resizeTimer = null;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(position, 150);
    });
  })();

  (function wireDayBoundaryLines() {
    const grid = document.querySelector('.cal-grid');
    const firstRow = grid ? grid.querySelector('.cal-row[data-room-id]') : null;
    if (!grid || !firstRow) return;
    const slots = Array.prototype.slice.call(firstRow.querySelectorAll('.cal-day-slot'));
    if (slots.length === 0) return;

    let lineEls = [];

    function draw() {
      lineEls.forEach(function (el) { el.remove(); });
      lineEls = [];
      const gridRect = grid.getBoundingClientRect();

      function addLineAt(xPx) {
        const line = document.createElement('div');
        line.className = 'cal-day-boundary';
        line.style.left = xPx + 'px';
        grid.appendChild(line);
        lineEls.push(line);
      }

      slots.forEach(function (slot) {
        const r = slot.getBoundingClientRect();
        addLineAt(r.left - gridRect.left);
      });
      const lastRect = slots[slots.length - 1].getBoundingClientRect();
      addLineAt(lastRect.right - gridRect.left);
    }

    draw();
    let resizeTimer = null;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(draw, 150);
    });
  })();

  (function wireTopScroll() {
    const topScroll = document.querySelector('.cal-top-scroll');
    const spacer = document.querySelector('.cal-top-scroll__spacer');
    const wrap = document.querySelector('.cal-grid-wrap');
    const grid = document.querySelector('.cal-grid');
    if (!topScroll || !spacer || !wrap || !grid) return;

    let syncing = false;

    function syncWidth() {
      spacer.style.width = grid.scrollWidth + 'px';
    }

    topScroll.addEventListener('scroll', function () {
      if (syncing || _smoothScrollActive) return;
      syncing = true;
      wrap.scrollLeft = topScroll.scrollLeft;
      syncing = false;
    });
    wrap.addEventListener('scroll', function () {
      if (syncing) return;
      syncing = true;
      topScroll.scrollLeft = wrap.scrollLeft;
      syncing = false;
    });

    syncWidth();
    let resizeTimer = null;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(syncWidth, 150);
    });
  })();

  (function wireFilters() {
    const typeSel = document.getElementById('filterRoomType');
    const statusSel = document.getElementById('filterRoomStatus');
    const floorSel = document.getElementById('filterFloor');
    const availSel = document.getElementById('filterAvailability');
    const occSel = document.getElementById('filterOccupancy');
    const resetBtn = document.getElementById('calFilterReset');
    const countEl = document.getElementById('calFilterCount');
    if (!typeSel) return;

    const rows = Array.prototype.slice.call(document.querySelectorAll('.cal-row[data-room-id]'));

    function applyFilters() {
      let visible = 0;
      rows.forEach(function (row) {
        const show =
          (!typeSel.value || row.dataset.roomType === typeSel.value) &&
          (!statusSel.value || row.dataset.statusKey === statusSel.value) &&
          (!floorSel.value || row.dataset.floor === floorSel.value) &&
          (!availSel.value || row.dataset.available === availSel.value) &&
          (!occSel.value || row.dataset.hasBookings === occSel.value);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
      });
      if (countEl) countEl.textContent = visible + ' of ' + rows.length + ' rooms';
    }

    [typeSel, statusSel, floorSel, availSel, occSel].forEach(function (sel) {
      sel.addEventListener('change', applyFilters);
    });
    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        typeSel.value = '';
        statusSel.value = '';
        floorSel.value = '';
        availSel.value = '';
        occSel.value = '';
        applyFilters();
      });
    }

    applyFilters();
    window.__bbApplyCalFilters = applyFilters;
  })();

  // ─── LIVE ROOM SYNC (WebSocket) ────────────────────────────────────
  (function wireRoomRealtimeSync() {
    const WS_PORT = 8081;
    const WS_URL = (window.location.protocol === 'https:' ? 'wss://' : 'ws://') + window.location.hostname + ':' + WS_PORT;

    let socket = null;
    let reconnectAttempts = 0;
    let reconnectTimer = null;
    const MAX_RECONNECT_DELAY_MS = 30000;

    function scheduleReconnect() {
      if (reconnectTimer) return;
      const delay = Math.min(1000 * Math.pow(1.5, reconnectAttempts), MAX_RECONNECT_DELAY_MS);
      reconnectAttempts++;
      reconnectTimer = setTimeout(function () {
        reconnectTimer = null;
        connect();
      }, delay);
    }

    function connect() {
      try {
        socket = new WebSocket(WS_URL);
      } catch (err) {
        console.warn('[calendar] Room sync WebSocket unavailable:', err.message);
        return;
      }

      socket.addEventListener('open', function () {
        reconnectAttempts = 0;
        console.log('[calendar] Live room sync connected.');
      });

      socket.addEventListener('message', function (e) {
        let msg;
        try {
          msg = JSON.parse(e.data);
        } catch (err) {
          return;
        }
        if (msg && msg.type === 'rooms_changed' && Array.isArray(msg.rooms)) {
          msg.rooms.forEach(updateRoomSidebar);
        }
        if (msg && msg.type === 'reservations_changed' && Array.isArray(msg.reservations)) {
          msg.reservations.forEach(updateUIFromServer);
        }
      });

      socket.addEventListener('close', scheduleReconnect);
      socket.addEventListener('error', function () {});
    }

    connect();
  })();

})();