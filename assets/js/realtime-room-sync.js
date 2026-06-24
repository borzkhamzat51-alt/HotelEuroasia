/**
 * Bluebookers — Layout live room sync
 * ------------------------------------------------------------------
 * Connects to the ws-server/ process (see ws-server/README.md) and
 * updates .room-card elements in place when a room's data changes
 * elsewhere — Calendar, another Layout tab, or any other source that
 * writes to the rooms table.
 *
 * This is a standalone, additive file: it doesn't depend on or modify
 * layout.js, it only reads/writes the data-* attributes and markup
 * structure layout.php / layout_1st_floor.php / layout_2nd_floor.php
 * already render. If layout.js changes how room cards are built, this
 * file's selectors may need updating to match — see the field list
 * below for exactly what it touches.
 *
 * Scope note: room-level fields (number, type, price, status, cleaning/
 * maintenance state) sync via a rooms_changed broadcast, handled by
 * updateRoomCard() below. Guest name and stay dates belong to the
 * active reservation, not the room, so they sync separately via a
 * reservations_changed broadcast (the ws-server now polls both tables —
 * see ws-server/server.js), handled by updateRoomCardReservation().
 * Checking out (from here or from Calendar) clears the guest/dates back
 * to the room's plain available-state display; the matching Calendar
 * change removes the reservation's bar entirely rather than just
 * restyling it.
 *
 * Also handles editing a room's number/type/price directly from the
 * floor-plan card (right-click → Edit Room Details) — the same
 * capability added to Calendar's room sidebar this session, posting to
 * the same process_room_action.php `update_room_details` action. Left
 * click is intentionally left alone here since it's already wired by
 * layout.js to open the existing status/guest modal — this only adds a
 * right-click handler, so it can't collide with that.
 *
 * Also handles a full reservation-management menu from the floor-plan
 * card (right-click) — create/edit reservation, check in/out, cancel,
 * extend stay, early check-out, move to another room, guest profile,
 * reservation history, print registration card/folio, and Edit Room
 * Details (number/type/price). This mirrors Calendar's bar context
 * menu action-for-action, posting to the same process_reservation.php
 * / process_room_action.php endpoints Calendar uses — but since none of
 * Calendar's supporting UI (the reservation modal, its form helpers,
 * the confirm dialog) exists on these pages, everything below is a
 * self-contained reimplementation built fresh, not a reuse of
 * calendar.js. Left-click is untouched — still layout.js's existing
 * status/guest modal.
 *
 * Room cards only carry a reduced data-* field set (no reservation id,
 * address, valid ID, payment fields, etc.), so anything that needs the
 * full reservation row fetches it fresh via process_reservation.php's
 * get_active_reservation action before acting — this also means every
 * action always operates on current data rather than whatever was in
 * the page's initial HTML.
 */
(function () {
  'use strict';

  const WS_PORT = 8081;
  const WS_URL = (window.location.protocol === 'https:' ? 'wss://' : 'ws://') + window.location.hostname + ':' + WS_PORT;

  const STATUS_LABELS = { reserved: 'Reserved', checked_in: 'Checked In', checked_out: 'Checked Out', cancelled: 'Cancelled' };
  const PAYMENT_LABELS = { cash: 'Cash', gcash: 'GCash', bank_transfer: 'Bank Transfer', card: 'Credit/Debit Card' };
  const LAYOUT_ROOMS = window.BB_LAYOUT_ROOMS || [];

  function injectModalStyles() {
    if (document.getElementById('bbModalStyles')) return;
    const style = document.createElement('style');
    style.id = 'bbModalStyles';
    style.textContent = `
      .bb-modal-overlay { position:fixed; inset:0; background:rgba(22,50,79,0.45); backdrop-filter:blur(3px); display:flex; align-items:center; justify-content:center; z-index:10050; padding:20px; }
      .bb-modal-card { background:var(--white); border-radius:var(--radius-lg); box-shadow:var(--shadow-card); padding:28px 30px; max-width:600px; width:100%; max-height:90vh; overflow-y:auto; font-family:var(--font-sans); animation:bbModalIn 240ms var(--ease-out); box-sizing:border-box; }
      .bb-modal-card--sm { max-width:400px; }
      .bb-modal-card--md { max-width:460px; }
      @keyframes bbModalIn { from { opacity:0; transform:translateY(10px) scale(0.98); } to { opacity:1; transform:none; } }
      .bb-modal-card h2 { font-family:var(--font-serif); color:var(--blue-900); font-size:1.45rem; margin:0 0 4px; }
      .bb-modal-card h3 { font-family:var(--font-sans); font-size:0.82rem; font-weight:700; color:var(--blue-700); text-transform:uppercase; letter-spacing:0.04em; margin:22px 0 12px; padding-bottom:8px; border-bottom:1px solid var(--blue-100); }
      .bb-modal-card h3:first-of-type { margin-top:16px; }
      .bb-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px 16px; }
      .bb-grid--full { grid-column:1 / -1; }
      .bb-field { display:flex; flex-direction:column; }
      .bb-field label { font-size:0.76rem; font-weight:600; color:var(--blue-900); margin-bottom:6px; }
      .bb-field input, .bb-field select, .bb-field textarea { border:1.5px solid transparent; background:var(--gray-100); border-radius:var(--radius-sm); padding:11px 12px; font-family:var(--font-sans); font-size:0.88rem; color:var(--blue-900); transition:border-color 200ms var(--ease-out), background 200ms var(--ease-out), box-shadow 200ms var(--ease-out); outline:none; width:100%; box-sizing:border-box; }
      .bb-field input:focus, .bb-field select:focus, .bb-field textarea:focus { background:var(--white); border-color:var(--blue-500); box-shadow:0 0 0 4px rgba(59,125,216,0.15); }
      .bb-field textarea { resize:vertical; min-height:56px; }
      .bb-balance { margin-top:16px; padding:12px 16px; background:var(--blue-50); border-radius:var(--radius-sm); font-size:0.85rem; font-weight:600; color:var(--blue-900); display:flex; justify-content:space-between; align-items:center; }
      .bb-balance strong { font-size:0.95rem; }
      .bb-form-error { display:block; color:#b3433f; font-size:0.74rem; margin-top:5px; }
      .bb-form-error--general { background:rgba(179,67,63,0.10); border:1px solid rgba(179,67,63,0.25); border-radius:var(--radius-sm); padding:10px 12px; margin-top:16px; font-size:0.82rem; color:#b3433f; }
      .bb-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:22px; padding-top:18px; border-top:1px solid var(--gray-300); }
      .bb-btn { border:none; border-radius:var(--radius-md); padding:11px 22px; font-family:var(--font-sans); font-size:0.85rem; font-weight:600; cursor:pointer; transition:filter 180ms var(--ease-out), transform 180ms var(--ease-out); }
      .bb-btn:active { transform:translateY(1px) scale(0.99); }
      .bb-btn--primary { background:var(--blue-500); color:var(--white); box-shadow:var(--shadow-btn); }
      .bb-btn--primary:hover { filter:brightness(1.08); }
      .bb-btn--secondary { background:var(--gray-100); color:var(--blue-900); border:1.5px solid var(--gray-300); }
      .bb-btn--secondary:hover { background:var(--blue-50); }
      .bb-info-row { display:flex; justify-content:space-between; gap:16px; padding:9px 0; border-bottom:1px solid var(--gray-100); font-size:0.86rem; }
      .bb-info-row .bb-label { color:var(--gray-500); font-weight:600; flex-shrink:0; }
      .bb-info-row .bb-value { color:var(--blue-900); font-weight:500; text-align:right; }
      .bb-history-list { list-style:none; margin:0; padding:0; }
      .bb-history-list li { padding:10px 0; border-bottom:1px solid var(--gray-100); font-size:0.84rem; color:var(--blue-900); }
      .bb-history-list li .bb-who { color:var(--gray-500); font-weight:500; }
      .bb-history-list li .bb-detail { display:block; color:var(--gray-500); font-size:0.8rem; margin-top:2px; }
      .bb-context-menu { position:fixed; z-index:10000; background:var(--white); border-radius:var(--radius-md); box-shadow:var(--shadow-card); padding:6px; min-width:230px; font-family:var(--font-sans); border:1px solid var(--blue-100); }
      .bb-context-menu ul { list-style:none; margin:0; padding:0; }
      .bb-context-menu li.bb-item { padding:9px 14px; border-radius:var(--radius-sm); cursor:pointer; font-size:0.84rem; color:var(--blue-900); transition:background 150ms var(--ease-out); }
      .bb-context-menu li.bb-item:hover { background:var(--blue-50); }
      .bb-context-menu li.bb-divider { height:1px; background:var(--gray-300); margin:5px 8px; }
      .rc-guest { font-weight:600; font-size:0.9rem; color:var(--blue-700); margin-top:4px; min-height:0; }
      .rc-guest:empty { display:none; }
      /* Duration display */
      .bb-duration { background:var(--sky-50); border-radius:var(--radius-sm); padding:8px 12px; margin:8px 0 12px; font-size:0.9rem; border:1px solid var(--sky-200); }
      .bb-duration__display { font-weight:700; color:var(--blue-700); font-size:1rem; margin-top:4px; }
    `;
    document.head.appendChild(style);
  }
  injectModalStyles();

  function roomStatusLabel(status, isDirty) {
    if (status === 'maintenance') return 'Out of Order';
    if (status === 'occupied') return 'Checked In';
    if (status === 'reserved') return 'Reserved';
    return isDirty ? 'Vacant Dirty' : 'Vacant Clean';
  }

  function roomStatusKey(status, isDirty) {
    if (status === 'available' && isDirty) return 'needs_cleaning';
    return status;
  }

  function formatDateDisplay(checkIn, checkOut, status, isDirty) {
    if (checkIn && checkOut) {
      const fmt = function (d) {
        const dt = new Date(d + 'T00:00:00');
        return dt.toLocaleDateString('en-US', { month: 'short', day: '2-digit' });
      };
      return fmt(checkIn) + ' - ' + fmt(checkOut);
    }
    if (status === 'available') return isDirty ? 'Vacant Dirty' : 'Vacant Clean';
    return '';
  }

  function updateRoomCard(room) {
    if (!room || !room.id) return;
    const card = document.querySelector('.room-card[data-room-id="' + room.id + '"]');
    if (!card) return;

    const isDirty = room.cleaning_status !== 'Clean';

    card.className = card.className
      .split(' ')
      .filter(function (c) { return c.indexOf('status-') !== 0 && c !== 'room-card--dirty'; })
      .join(' ');
    card.classList.add('status-' + room.room_status);
    if (room.room_status === 'available' && isDirty) {
      card.classList.add('room-card--dirty');
    }

    card.dataset.status = room.room_status;
    card.dataset.typeMain = room.room_type;
    card.dataset.price = room.price_per_night;
    card.dataset.roomNumber = room.room_number;
    card.dataset.cleaning = room.cleaning_status;
    if (room.maintenance_status !== undefined) card.dataset.maintenance = room.maintenance_status;
    if (room.last_occupancy !== undefined && room.last_occupancy !== null) card.dataset.lastOccupancy = room.last_occupancy;

    const titleEl = card.querySelector('.rc-title');
    if (titleEl) titleEl.textContent = room.room_type;

    const priceEl = card.querySelector('.rc-price');
    if (priceEl) priceEl.textContent = room.price_per_night ? '₱' + Number(room.price_per_night).toLocaleString() : '--';

    const footerEl = card.querySelector('.rc-footer');
    if (footerEl) footerEl.textContent = 'RM ' + room.room_number;

    const datesEl = card.querySelector('.rc-dates');
    if (datesEl) {
      if (room.room_status === 'available' || room.room_status === 'maintenance') {
        datesEl.textContent = formatDateDisplay(null, null, room.room_status, isDirty);
      }
    }

    if (room.room_status === 'available' || room.room_status === 'maintenance') {
      const guestEl = card.querySelector('.rc-guest');
      if (guestEl) guestEl.textContent = '';
    }

    card.setAttribute('title', 'RM' + room.room_number);
  }

  function updateRoomCardReservation(resv) {
    if (!resv || !resv.room_id) return;
    const card = document.querySelector('.room-card[data-room-id="' + resv.room_id + '"]');
    if (!card) return;

    const cardStatus = card.dataset.status;
    const isActive = (resv.status === 'reserved' || resv.status === 'checked_in')
                  && cardStatus !== 'available';
    const guestEl = card.querySelector('.rc-guest');

    if (isActive) {
      if (guestEl) guestEl.textContent = resv.guest_full_name || '';
      card.dataset.guestName = resv.guest_full_name || '';
      card.dataset.checkIn = resv.check_in || '';
      card.dataset.checkOut = resv.check_out || '';
      if (resv.num_adults !== undefined) card.dataset.pax = resv.num_adults;

      const datesEl = card.querySelector('.rc-dates');
      const computedDate = formatDateDisplay(resv.check_in, resv.check_out, card.dataset.status, card.dataset.cleaning !== 'Clean');
      if (datesEl && computedDate !== null) datesEl.textContent = computedDate;
    } else {
      if (guestEl) guestEl.textContent = '';
      card.dataset.guestName = '';
      card.dataset.checkIn = '';
      card.dataset.checkOut = '';
      const datesEl = card.querySelector('.rc-dates');
      const computedDate = formatDateDisplay(null, null, card.dataset.status, card.dataset.cleaning !== 'Clean');
      if (datesEl && computedDate !== null) datesEl.textContent = computedDate;
    }
  }

  function showConfirmDialog(message, title) {
    title = title || 'Confirm Changes';
    return new Promise(function (resolve) {
      const existing = document.getElementById('bbConfirmDialog');
      if (existing) existing.remove();

      const dialogOverlay = document.createElement('div');
      dialogOverlay.id = 'bbConfirmDialog';
      dialogOverlay.className = 'bb-modal-overlay';
      dialogOverlay.style.zIndex = '10060';
      dialogOverlay.innerHTML =
        '<div class="bb-modal-card bb-modal-card--sm" style="text-align:center;">' +
          '<h2 style="font-size:1.15rem;">' + title + '</h2>' +
          '<p style="margin:14px 0 22px;font-size:0.86rem;color:var(--gray-700);line-height:1.55;">' + message + '</p>' +
          '<div class="bb-actions" style="border-top:none;padding-top:0;margin-top:0;justify-content:center;">' +
            '<button type="button" id="bbConfirmCancel" class="bb-btn bb-btn--secondary" style="flex:1;">Cancel</button>' +
            '<button type="button" id="bbConfirmOk" class="bb-btn bb-btn--primary" style="flex:1;">Confirm</button>' +
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

  function fetchActiveReservation(roomId) {
    return fetch('/process_reservation.php?action=get_active_reservation&room_id=' + roomId)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) throw new Error(data.message || 'Could not load reservation.');
        return data.reservation;
      });
  }

  // ─── Reservation form helpers ──────────────────────────────────────
  function field(label, name, value, type, errors, required) {
    value = value === undefined || value === null ? '' : value;
    return '<div class="bb-field"><label for="' + name + '">' + label + (required ? ' *' : '') + '</label>' +
      '<input type="' + type + '" id="' + name + '" name="' + name + '" value="' + String(value).replace(/"/g, '&quot;') + '"' + (required ? ' required' : '') + ' autocomplete="off">' +
      fieldError(errors, name) + '</div>';
  }

  function fieldError(errors, key) {
    return errors && errors[key] ? '<span class="bb-form-error">' + errors[key] + '</span>' : '';
  }

  function optionList(map, selected) {
    return Object.keys(map).map(function (key) {
      const sel = key === selected ? 'selected' : '';
      return '<option value="' + key + '" ' + sel + '>' + map[key] + '</option>';
    }).join('');
  }

  function roomOptions(selectedRoomId) {
    return LAYOUT_ROOMS.map(function (r) {
      const sel = String(r.id) === String(selectedRoomId) ? 'selected' : '';
      return '<option value="' + r.id + '" ' + sel + '>RM' + r.room_number + ' — ' + r.room_type + ' (₱' + Number(r.price_per_night).toLocaleString() + '/night)</option>';
    }).join('');
  }

  function isDirtyLayoutRoom(roomId) {
    const card = document.querySelector('.room-card[data-room-id="' + roomId + '"]');
    if (card) {
      return card.dataset.status === 'available' && card.dataset.cleaning !== 'Clean';
    }
    const room = LAYOUT_ROOMS.find(function (r) { return String(r.id) === String(roomId); });
    if (!room || room.cleaning_status === undefined) return false;
    return room.room_status === 'available' && room.cleaning_status !== 'Clean';
  }

  function formToObject(fd) {
    const obj = {};
    fd.forEach(function (value, key) { obj[key] = value; });
    return obj;
  }

  // ─── Reservation form modal ────────────────────────────────────────
  function renderReservationForm(resv, prefillRoomId, errors) {
    resv = resv || {};
    const isEdit = !!resv.id;
    const roomId = resv.room_id || prefillRoomId || (LAYOUT_ROOMS[0] && LAYOUT_ROOMS[0].id) || '';

    const existing = document.getElementById('bbResvFormModal');
    if (existing) existing.remove();

    const modal = document.createElement('div');
    modal.id = 'bbResvFormModal';
    modal.className = 'bb-modal-overlay';
    const inner = document.createElement('div');
    inner.className = 'bb-modal-card';

    let html = '<h2>' + (isEdit ? 'Reservation Details' : 'New Reservation') + '</h2>';
    html += '<form id="bbResvForm" autocomplete="off">';
    if (isEdit) html += '<input type="hidden" name="id" value="' + resv.id + '">';

    html += '<h3>Guest Information</h3><div class="bb-grid">';
    html += field('Full Name', 'guest_full_name', resv.guest_full_name, 'text', errors, true);
    html += field('Contact Number', 'contact_number', resv.contact_number, 'text', errors);
    html += field('Email Address', 'email', resv.email, 'email', errors);
    html += field('Address', 'address', resv.address, 'text', errors);
    html += field('Valid ID Type', 'valid_id_type', resv.valid_id_type, 'text', errors);
    html += field('Valid ID Number', 'valid_id_number', resv.valid_id_number, 'text', errors);
    html += '</div>';

    html += '<h3>Booking Information</h3><div class="bb-grid">';
    html += '<div class="bb-field"><label for="room_id">Room Number</label><select id="room_id" name="room_id" required>' + roomOptions(roomId) + '</select>' + fieldError(errors, 'room_id') + '</div>';
    html += '<div class="bb-field"><label for="status">Booking Status</label><select id="status" name="status">' + optionList(STATUS_LABELS, resv.status || 'reserved') + '</select></div>';
    html += field('Check-in Date', 'check_in', resv.check_in, 'date', errors, true);
    html += field('Check-out Date', 'check_out', resv.check_out, 'date', errors, true);
    html += field('Number of Adults', 'num_adults', resv.num_adults || 1, 'number', errors);
    html += field('Number of Children', 'num_children', resv.num_children || 0, 'number', errors);
    html += '</div>';

    // ── Duration and Expected Payment Date ──
    html += '<div class="bb-duration">';
    html += '<label>Stay Duration</label>';
    html += '<div class="bb-duration__display" id="bbStayDurationDisplay">0 Days / 0 Nights</div>';
    html += '</div>';

    html += '<div class="bb-field">';
    html += '<label for="bb_expected_payment_date">Expected Payment Date</label>';
    html += '<input type="date" id="bb_expected_payment_date" name="expected_payment_date" value="' + (resv.expected_payment_date || '') + '">';
    html += '</div>';

    html += '<h3>Payment Information</h3><div class="bb-grid">';
    html += field('Room Rate', 'room_rate', resv.room_rate || 0, 'number', errors);
    html += field('Security Deposit', 'security_deposit', resv.security_deposit || 0, 'number', errors);
    html += field('Total Amount', 'total_amount', resv.total_amount || 0, 'number', errors);
    html += field('Amount Paid', 'amount_paid', resv.amount_paid || 0, 'number', errors);
    html += '<div class="bb-field"><label for="payment_method">Payment Method</label><select id="payment_method" name="payment_method"><option value="">— Select —</option>' + optionList(PAYMENT_LABELS, resv.payment_method || '') + '</select></div>';
    html += '</div>';
    html += '<div class="bb-balance"><span>Remaining Balance</span><strong id="bbResvBalance">₱0.00</strong></div>';

    html += '<h3>Additional Information</h3><div class="bb-grid">';
    html += '<div class="bb-field bb-grid--full"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="2">' + (resv.notes || '') + '</textarea></div>';
    html += '<div class="bb-field bb-grid--full"><label for="special_requests">Special Requests</label><textarea id="special_requests" name="special_requests" rows="2">' + (resv.special_requests || '') + '</textarea></div>';
    html += '</div>';

    if (errors && errors._general) {
      html += '<p class="bb-form-error--general">' + errors._general + '</p>';
    }

    html += '<div class="bb-actions">';
    html += '<button type="button" id="bbResvCancelBtn" class="bb-btn bb-btn--secondary">Cancel</button>';
    html += '<button type="submit" class="bb-btn bb-btn--primary">' + (isEdit ? 'Save Changes' : 'Create Reservation') + '</button>';
    html += '</div></form>';

    inner.innerHTML = html;
    modal.appendChild(inner);
    document.body.appendChild(modal);

    const form = document.getElementById('bbResvForm');
    const balanceEl = document.getElementById('bbResvBalance');
    function recalcBalance() {
      const total = parseFloat(form.total_amount.value) || 0;
      const paid = parseFloat(form.amount_paid.value) || 0;
      const remaining = total - paid;
      balanceEl.textContent = '₱' + remaining.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      balanceEl.style.color = remaining > 0 ? '#b3433f' : 'inherit';
    }
    form.total_amount.addEventListener('input', recalcBalance);
    form.amount_paid.addEventListener('input', recalcBalance);
    recalcBalance();

    // ── Wire duration and expected payment date ──
    function wireBbDateCalculations() {
      const checkIn = form.querySelector('[name="check_in"]');
      const checkOut = form.querySelector('[name="check_out"]');
      const expectedPayment = form.querySelector('[name="expected_payment_date"]');
      const durationDisplay = document.getElementById('bbStayDurationDisplay');

      function updateDurationAndPayment() {
        const inVal = checkIn.value;
        const outVal = checkOut.value;
        if (inVal && outVal) {
          const start = new Date(inVal + 'T00:00:00');
          const end = new Date(outVal + 'T00:00:00');
          const nights = Math.round((end - start) / 86400000);
          const days = nights + 1;
          durationDisplay.textContent = days + ' Days / ' + nights + ' Nights';

          if (!expectedPayment.dataset.userEdited) {
            expectedPayment.value = outVal;
          }
        } else {
          durationDisplay.textContent = '0 Days / 0 Nights';
        }
      }

      checkIn.addEventListener('input', updateDurationAndPayment);
      checkOut.addEventListener('input', updateDurationAndPayment);
      expectedPayment.addEventListener('input', function() {
        this.dataset.userEdited = 'true';
      });
      updateDurationAndPayment();
    }
    wireBbDateCalculations();

    function closeFormModal() { modal.remove(); }
    document.getElementById('bbResvCancelBtn').addEventListener('click', closeFormModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeFormModal(); });

    const roomSel = form.querySelector('[name="room_id"]');
    if (roomSel) {
      roomSel.addEventListener('change', function () {
        if (isDirtyLayoutRoom(this.value)) {
          alert('This room is Vacant Dirty. Please mark it as clean before creating a reservation.');
          this.value = '';
        }
      });
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!isEdit && roomSel && isDirtyLayoutRoom(roomSel.value)) {
        alert('This room is Vacant Dirty. Please mark it as clean before creating a reservation.');
        return;
      }
      const confirmMsg = isEdit ? 'Save these changes to this reservation?' : 'Create this reservation?';
      showConfirmDialog(confirmMsg, 'Confirm Changes').then(function (confirmed) {
        if (!confirmed) return;
        const fd = new FormData(form);
        fd.append('action', isEdit ? 'update' : 'create');
        if (!fd.has('expected_payment_date')) {
          const ep = form.querySelector('[name="expected_payment_date"]');
          if (ep) fd.append('expected_payment_date', ep.value);
        }
        fetch('/process_reservation.php', { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (res.success) {
              if (Array.isArray(res.rooms)) res.rooms.forEach(updateRoomCard);
              const formData = formToObject(new FormData(form));
              const resvStatus = formData.status || (isEdit ? (resv.status || 'reserved') : 'reserved');
              updateRoomCardReservation(Object.assign({}, formData, {
                room_id: formData.room_id || roomId,
                status: resvStatus,
                expected_payment_date: formData.expected_payment_date || formData.check_out || null,
              }));
              closeFormModal();
            } else {
              const resvData = isEdit ? Object.assign({ id: resv.id }, formToObject(fd)) : formToObject(fd);
              renderReservationForm(resvData, roomId, Object.assign({ _general: res.message }, res.errors || {}));
            }
          })
          .catch(function (err) {
            console.error('[layout] Reservation save error:', err);
            alert('Error: ' + (err.message || 'Something went wrong. Please try again.'));
          });
      });
    });
  }

  // ─── Edit Room Details ────────────────────────────────────────────
  function openEditRoomModal(card) {
    const roomId = card.dataset.roomId;
    const currentNumber = card.dataset.roomNumber || '';
    const currentType = card.dataset.typeMain || '';
    const currentPrice = card.dataset.price || '';

    const modal = document.createElement('div');
    modal.className = 'bb-modal-overlay';
    const inner = document.createElement('div');
    inner.className = 'bb-modal-card bb-modal-card--sm';
    inner.innerHTML =
      '<h2 style="font-size:1.2rem;">Edit Room Details</h2>' +
      '<div style="display:flex;flex-direction:column;gap:14px;margin:16px 0 4px;">' +
        '<div class="bb-field"><label>Room Number</label>' +
        '<input type="text" id="editRoomNumber" value="' + currentNumber.replace(/"/g, '&quot;') + '"></div>' +
        '<div class="bb-field"><label>Room Type</label>' +
        '<input type="text" id="editRoomType" value="' + currentType.replace(/"/g, '&quot;') + '"></div>' +
        '<div class="bb-field"><label>Price per Night (₱)</label>' +
        '<input type="number" id="editRoomPrice" min="0" step="1" value="' + (currentPrice || 0) + '"></div>' +
        '<p id="editRoomError" class="bb-form-error" style="display:none;"></p>' +
      '</div>' +
      '<div class="bb-actions">' +
        '<button id="editRoomCancelBtn" class="bb-btn bb-btn--secondary">Cancel</button>' +
        '<button id="editRoomSaveBtn" class="bb-btn bb-btn--primary">Save</button>' +
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

      fetch('/process_room_action.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.success) {
            if (data.room) updateRoomCard(data.room);
            modal.remove();
          } else {
            errorEl.textContent = data.message || 'Could not save room details.';
            errorEl.style.display = 'block';
          }
        })
        .catch(function (err) {
          console.error('[layout] Edit room details error:', err);
          errorEl.textContent = 'Network error.';
          errorEl.style.display = 'block';
        });
    });
  }

  // ─── Reservation actions ──────────────────────────────────────────
  const RESV_TO_ROOM_STATUS = {
    reserved: 'reserved',
    checked_in: 'occupied',
    checked_out: 'available',
    cancelled: 'available',
  };

  function applyRoomCardStatus(roomId, resvStatus) {
    const card = document.querySelector('.room-card[data-room-id="' + roomId + '"]');
    if (!card) return;
    const roomStatus = RESV_TO_ROOM_STATUS[resvStatus] || 'available';
    const willBeDirty = resvStatus === 'checked_out';

    card.className = card.className
      .split(' ')
      .filter(function (c) { return c.indexOf('status-') !== 0 && c !== 'room-card--dirty'; })
      .join(' ');
    card.classList.add('status-' + roomStatus);
    if (roomStatus === 'available' && willBeDirty) {
      card.classList.add('room-card--dirty');
    }
    card.dataset.status = roomStatus;
    if (willBeDirty) card.dataset.cleaning = 'Pending';

    const datesEl = card.querySelector('.rc-dates');
    if (datesEl && roomStatus === 'available') {
      datesEl.textContent = willBeDirty ? 'Vacant Dirty' : 'Vacant Clean';
    }
  }

  function updateReservationStatusFor(r, newStatus) {
    const fd = new FormData();
    fd.append('action', 'update');
    fd.append('id', r.id);
    fd.append('status', newStatus);
    Object.keys(r).forEach(function (key) {
      if (key !== 'status' && key !== 'id') fd.append(key, r[key] !== undefined && r[key] !== null ? r[key] : '');
    });

    applyRoomCardStatus(r.room_id, newStatus);

    fetch('/process_reservation.php', { method: 'POST', body: fd })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.success) {
          if (Array.isArray(data.rooms)) data.rooms.forEach(updateRoomCard);
          updateRoomCardReservation(Object.assign({}, r, { status: newStatus }));
        } else {
          applyRoomCardStatus(r.room_id, r.status);
          alert('Error: ' + (data.message || 'Could not update reservation.'));
        }
      })
      .catch(function (err) {
        console.error('[layout] Status update error:', err);
        applyRoomCardStatus(r.room_id, r.status);
        alert('Network error.');
      });
  }

  function updateReservationDatesFor(r, checkIn, checkOut) {
    const fd = new FormData();
    fd.append('action', 'update');
    fd.append('id', r.id);
    fd.append('check_in', checkIn);
    fd.append('check_out', checkOut);
    fd.append('expected_payment_date', checkOut);
    Object.keys(r).forEach(function (key) {
      if (key !== 'check_in' && key !== 'check_out' && key !== 'id' && key !== 'expected_payment_date') {
        fd.append(key, r[key] !== undefined && r[key] !== null ? r[key] : '');
      }
    });
    fetch('/process_reservation.php', { method: 'POST', body: fd })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.success) {
          if (Array.isArray(data.rooms)) data.rooms.forEach(updateRoomCard);
          updateRoomCardReservation(Object.assign({}, r, { check_in: checkIn, check_out: checkOut, expected_payment_date: checkOut }));
        } else {
          alert('Error: ' + (data.message || 'Could not update dates.'));
        }
      })
      .catch(function (err) {
        console.error('[layout] Dates update error:', err);
        alert('Network error.');
      });
  }

  function formatLocalDate(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  function showGuestProfile(r) {
    const modal = document.createElement('div');
    modal.className = 'bb-modal-overlay';
    const inner = document.createElement('div');
    inner.className = 'bb-modal-card bb-modal-card--sm';
    inner.innerHTML = '<h2 style="font-size:1.2rem;">Guest Profile</h2>' +
      '<div style="margin:16px 0 4px;">' +
      '<div class="bb-info-row"><span class="bb-label">Name</span><span class="bb-value">' + (r.guest_full_name || 'N/A') + '</span></div>' +
      '<div class="bb-info-row"><span class="bb-label">Contact</span><span class="bb-value">' + (r.contact_number || 'N/A') + '</span></div>' +
      '<div class="bb-info-row"><span class="bb-label">Email</span><span class="bb-value">' + (r.email || 'N/A') + '</span></div>' +
      '<div class="bb-info-row"><span class="bb-label">Address</span><span class="bb-value">' + (r.address || 'N/A') + '</span></div>' +
      '<div class="bb-info-row"><span class="bb-label">Valid ID</span><span class="bb-value">' + (r.valid_id_type || 'N/A') + ' #' + (r.valid_id_number || 'N/A') + '</span></div>' +
      '</div>' +
      '<div class="bb-actions" style="justify-content:flex-start;"><button id="bbProfileCloseBtn" class="bb-btn bb-btn--secondary">Close</button></div>';
    modal.appendChild(inner);
    document.body.appendChild(modal);
    modal.querySelector('#bbProfileCloseBtn').addEventListener('click', function () { modal.remove(); });
    modal.addEventListener('click', function (e) { if (e.target === modal) modal.remove(); });
  }

  function showReservationHistory(roomId) {
    fetch('/process_room_action.php?action=get_history&room_id=' + roomId)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        const entries = (data.success && Array.isArray(data.history)) ? data.history : [];
        const modal = document.createElement('div');
        modal.className = 'bb-modal-overlay';
        const inner = document.createElement('div');
        inner.className = 'bb-modal-card bb-modal-card--md';
        let html = '<h2 style="font-size:1.2rem;">Reservation History</h2><ul class="bb-history-list" style="margin-top:14px;">';
        if (entries.length) {
          entries.forEach(function (entry) {
            const when = new Date(entry.created_at.replace(' ', 'T')).toLocaleString();
            const who = entry.full_name || entry.username || 'Unknown';
            html += '<li>' +
              '<strong>' + entry.action.charAt(0).toUpperCase() + entry.action.slice(1) + '</strong> <span class="bb-who">by ' + who + ' — ' + when + '</span>' +
              (entry.details ? '<span class="bb-detail">' + entry.details + '</span>' : '') +
              '</li>';
          });
        } else {
          html += '<li>No activity recorded.</li>';
        }
        html += '</ul><div class="bb-actions" style="justify-content:flex-start;"><button id="bbHistoryCloseBtn" class="bb-btn bb-btn--secondary">Close</button></div>';
        inner.innerHTML = html;
        modal.appendChild(inner);
        document.body.appendChild(modal);
        modal.querySelector('#bbHistoryCloseBtn').addEventListener('click', function () { modal.remove(); });
        modal.addEventListener('click', function (e) { if (e.target === modal) modal.remove(); });
      })
      .catch(function (err) {
        console.error('[layout] History fetch error:', err);
        alert('Could not load history.');
      });
  }

  function printRegCard(r) {
    const printWindow = window.open('', '_blank', 'width=800,height=600');
    if (!printWindow) { alert('Please allow pop-ups to print.'); return; }
    const nights = Math.round((new Date(r.check_out) - new Date(r.check_in)) / 86400000);
    printWindow.document.write(
      '<html><head><title>Registration Card</title>' +
      '<style>body{font-family:sans-serif;padding:40px;max-width:600px;margin:0 auto;}' +
      'h1{color:#16324f;border-bottom:2px solid #3b7dd8;padding-bottom:10px;}' +
      '.row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #eee;}' +
      '.label{font-weight:600;color:#5b7693;}.value{font-weight:500;}.print-btn{display:none;}' +
      '@media print{.print-btn{display:none;}}</style></head><body>' +
      '<h1>Registration Card</h1>' +
      '<div class="row"><span class="label">Guest Name</span><span class="value">' + (r.guest_full_name || 'N/A') + '</span></div>' +
      '<div class="row"><span class="label">Room</span><span class="value">RM' + (r.room_number || '?') + '</span></div>' +
      '<div class="row"><span class="label">Check-in</span><span class="value">' + r.check_in + '</span></div>' +
      '<div class="row"><span class="label">Check-out</span><span class="value">' + r.check_out + '</span></div>' +
      '<div class="row"><span class="label">Nights</span><span class="value">' + nights + '</span></div>' +
      '<div class="row"><span class="label">Adults</span><span class="value">' + (r.num_adults || 1) + '</span></div>' +
      '<div class="row"><span class="label">Children</span><span class="value">' + (r.num_children || 0) + '</span></div>' +
      '<div class="row"><span class="label">Contact</span><span class="value">' + (r.contact_number || 'N/A') + '</span></div>' +
      '<div class="row"><span class="label">Email</span><span class="value">' + (r.email || 'N/A') + '</span></div>' +
      '<div class="row"><span class="label">Valid ID</span><span class="value">' + (r.valid_id_type || 'N/A') + ' #' + (r.valid_id_number || 'N/A') + '</span></div>' +
      '<div class="row"><span class="label">Rate/Night</span><span class="value">₱' + parseFloat(r.room_rate || 0).toFixed(2) + '</span></div>' +
      '<div class="row"><span class="label">Total Amount</span><span class="value">₱' + parseFloat(r.total_amount || 0).toFixed(2) + '</span></div>' +
      '<div class="row"><span class="label">Amount Paid</span><span class="value">₱' + parseFloat(r.amount_paid || 0).toFixed(2) + '</span></div>' +
      '<div class="row"><span class="label">Payment Method</span><span class="value">' + (r.payment_method || 'N/A') + '</span></div>' +
      '<div style="margin-top:30px;text-align:center;color:#5b7693;font-size:0.8rem;">Generated by Bluebookers PMS</div>' +
      '<button class="print-btn" onclick="window.print()">Print</button></body></html>'
    );
    printWindow.document.close();
    setTimeout(function () { printWindow.print(); }, 500);
  }

  function printFolio(r) {
    const printWindow = window.open('', '_blank', 'width=800,height=600');
    if (!printWindow) { alert('Please allow pop-ups to print.'); return; }
    const nights = Math.round((new Date(r.check_out) - new Date(r.check_in)) / 86400000);
    const balance = (parseFloat(r.total_amount) || 0) - (parseFloat(r.amount_paid) || 0);
    printWindow.document.write(
      '<html><head><title>Folio</title>' +
      '<style>body{font-family:sans-serif;padding:40px;max-width:600px;margin:0 auto;}' +
      'h1{color:#16324f;border-bottom:2px solid #3b7dd8;padding-bottom:10px;}' +
      '.row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #eee;}' +
      '.label{font-weight:600;color:#5b7693;}.value{font-weight:500;}' +
      '.total{font-weight:700;border-top:2px solid #16324f;margin-top:10px;padding-top:10px;}.print-btn{display:none;}' +
      '@media print{.print-btn{display:none;}}</style></head><body>' +
      '<h1>Guest Folio</h1>' +
      '<div class="row"><span class="label">Guest Name</span><span class="value">' + (r.guest_full_name || 'N/A') + '</span></div>' +
      '<div class="row"><span class="label">Room</span><span class="value">RM' + (r.room_number || '?') + '</span></div>' +
      '<div class="row"><span class="label">Check-in</span><span class="value">' + r.check_in + '</span></div>' +
      '<div class="row"><span class="label">Check-out</span><span class="value">' + r.check_out + '</span></div>' +
      '<div class="row"><span class="label">Nights</span><span class="value">' + nights + '</span></div>' +
      '<div class="row"><span class="label">Rate/Night</span><span class="value">₱' + parseFloat(r.room_rate || 0).toFixed(2) + '</span></div>' +
      '<div class="row total"><span class="label">Total Amount</span><span class="value">₱' + parseFloat(r.total_amount || 0).toFixed(2) + '</span></div>' +
      '<div class="row"><span class="label">Amount Paid</span><span class="value">₱' + parseFloat(r.amount_paid || 0).toFixed(2) + '</span></div>' +
      '<div class="row"><span class="label">Balance Due</span><span class="value" style="' + (balance > 0 ? 'color:#b3433f;' : '') + '">₱' + balance.toFixed(2) + '</span></div>' +
      '<div class="row"><span class="label">Payment Method</span><span class="value">' + (r.payment_method || 'N/A') + '</span></div>' +
      (r.special_requests ? '<div class="row"><span class="label">Special Requests</span><span class="value">' + r.special_requests + '</span></div>' : '') +
      '<div style="margin-top:30px;text-align:center;color:#5b7693;font-size:0.8rem;">Generated by Bluebookers PMS</div>' +
      '<button class="print-btn" onclick="window.print()">Print</button></body></html>'
    );
    printWindow.document.close();
    setTimeout(function () { printWindow.print(); }, 500);
  }

  function openMoveRoomModal(r) {
    if (!LAYOUT_ROOMS.length) { alert('No rooms available to move to.'); return; }

    const moveOptions = LAYOUT_ROOMS
      .filter(function (room) { return String(room.id) !== String(r.room_id); })
      .map(function (room) {
        const dirty = isDirtyLayoutRoom(room.id);
        const label = 'RM' + room.room_number + ' — ' + room.room_type +
          ' (₱' + Number(room.price_per_night).toLocaleString() + '/night)' +
          (dirty ? ' — Vacant Dirty' : '');
        return '<option value="' + room.id + '"' + (dirty ? ' disabled' : '') + '>' + label + '</option>';
      }).join('');

    if (!moveOptions) {
      alert('No other rooms are available to move to.');
      return;
    }
    const modal = document.createElement('div');
    modal.className = 'bb-modal-overlay';
    const inner = document.createElement('div');
    inner.className = 'bb-modal-card bb-modal-card--sm';
    inner.innerHTML = '<h2 style="font-size:1.2rem;">Select New Room</h2>' +
      '<div class="bb-field" style="margin:16px 0 4px;"><select id="bbMoveRoomSelect">' + moveOptions + '</select></div>' +
      '<div class="bb-actions">' +
      '<button id="bbMoveCancelBtn" class="bb-btn bb-btn--secondary">Cancel</button>' +
      '<button id="bbMoveConfirmBtn" class="bb-btn bb-btn--primary">Move</button>' +
      '</div>';
    modal.appendChild(inner);
    document.body.appendChild(modal);
    modal.querySelector('#bbMoveCancelBtn').addEventListener('click', function () { modal.remove(); });
    modal.querySelector('#bbMoveConfirmBtn').addEventListener('click', function () {
      const newRoomId = parseInt(document.getElementById('bbMoveRoomSelect').value, 10);
      if (isDirtyLayoutRoom(newRoomId)) {
        alert('This room is Vacant Dirty. Please mark it as clean before creating a new reservation.');
        return;
      }
      modal.remove();
      const fd = new FormData();
      fd.append('action', 'update');
      fd.append('id', r.id);
      fd.append('room_id', newRoomId);
      const fields = [
        'guest_full_name', 'contact_number', 'email', 'address',
        'valid_id_type', 'valid_id_number', 'check_in', 'check_out',
        'num_adults', 'num_children', 'status',
        'room_rate', 'security_deposit', 'total_amount', 'amount_paid',
        'payment_method', 'notes', 'special_requests', 'expected_payment_date'
      ];
      fields.forEach(function (key) {
        fd.append(key, r[key] !== undefined && r[key] !== null ? r[key] : '');
      });
      fetch('/process_reservation.php', { method: 'POST', body: fd })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.success) {
            if (Array.isArray(data.rooms)) data.rooms.forEach(updateRoomCard);
          } else {
            alert('Error: ' + (data.message || 'Could not move reservation.'));
          }
        })
        .catch(function (err) {
          console.error('[layout] Move room error:', err);
          alert('Network error.');
        });
    });
  }

  function handleReservationAction(action, roomId) {
    fetchActiveReservation(roomId).then(function (r) {
      if (!r && action !== 'history') {
        alert('No active reservation found for this room — it may have just changed elsewhere. Try right-clicking again.');
        return;
      }
      switch (action) {
        case 'edit':
          renderReservationForm(r, roomId);
          break;
        case 'cancel':
          showConfirmDialog('Cancel this reservation?', 'Confirm Cancellation').then(function (ok) {
            if (ok) updateReservationStatusFor(r, 'cancelled');
          });
          break;
        case 'checkin':
          showConfirmDialog('Check in this guest?', 'Confirm Check-In').then(function (ok) {
            if (ok) updateReservationStatusFor(r, 'checked_in');
          });
          break;
        case 'checkout':
          showConfirmDialog('Check out this guest?', 'Confirm Check-Out').then(function (ok) {
            if (ok) updateReservationStatusFor(r, 'checked_out');
          });
          break;
        case 'extend': {
          const days = prompt('Extend stay by how many days?', '1');
          if (days !== null && !isNaN(days) && parseInt(days, 10) > 0) {
            const newOut = new Date(r.check_out);
            newOut.setDate(newOut.getDate() + parseInt(days, 10));
            updateReservationDatesFor(r, r.check_in, formatLocalDate(newOut));
          }
          break;
        }
        case 'early_checkout': {
          const newDate = prompt('Enter new check-out date (YYYY-MM-DD):', r.check_out);
          if (newDate && /^\d{4}-\d{2}-\d{2}$/.test(newDate)) {
            updateReservationDatesFor(r, r.check_in, newDate);
          }
          break;
        }
        case 'move':
          openMoveRoomModal(r);
          break;
        case 'profile':
          showGuestProfile(r);
          break;
        case 'history':
          showReservationHistory(roomId);
          break;
        case 'print_regcard':
          printRegCard(r);
          break;
        case 'print_folio':
          printFolio(r);
          break;
        default:
          console.warn('[layout] Unknown reservation action:', action);
      }
    }).catch(function (err) {
      console.error('[layout] Reservation fetch error:', err);
      alert('Could not load reservation data: ' + err.message);
    });
  }

  // ─── Room context menu ──────────────────────────────────────────────
  let activeMenu = null;
  function hideActiveMenu() {
    if (activeMenu) { activeMenu.remove(); activeMenu = null; }
  }
  document.addEventListener('click', function (e) {
    if (activeMenu && !activeMenu.contains(e.target)) hideActiveMenu();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') hideActiveMenu();
  });

  function showRoomMenu(x, y, card) {
    hideActiveMenu();
    const roomId = card.dataset.roomId;
    const status = card.dataset.status;
    const hasActiveBooking = status === 'occupied' || status === 'reserved';

    const items = [];
    if (status === 'available') {
      items.push({ label: 'Create Reservation', action: 'create' });
      items.push({ divider: true });
      const isDirty = card.dataset.cleaning !== 'Clean';
      if (isDirty) {
        items.push({ label: 'Mark as Clean', action: 'mark_clean' });
      } else {
        items.push({ label: 'Mark as Dirty', action: 'mark_dirty' });
      }
      items.push({ divider: true });
    } else if (hasActiveBooking) {
      items.push({ label: 'Edit Reservation', action: 'edit' });
      items.push({ label: 'Check In', action: 'checkin' });
      items.push({ label: 'Check Out', action: 'checkout' });
      items.push({ label: 'Cancel Reservation', action: 'cancel' });
      items.push({ divider: true });
      items.push({ label: 'Extend Stay', action: 'extend' });
      items.push({ label: 'Early Check-Out', action: 'early_checkout' });
      items.push({ label: 'Move to Another Room', action: 'move' });
      items.push({ divider: true });
      items.push({ label: 'Guest Profile', action: 'profile' });
      items.push({ label: 'Reservation History', action: 'history' });
      items.push({ label: 'Print Registration Card', action: 'print_regcard' });
      items.push({ label: 'Print Folio', action: 'print_folio' });
      items.push({ divider: true });
    }
    items.push({ label: 'Edit Room Details', action: 'edit_room_details' });

    const menu = document.createElement('div');
    menu.className = 'bb-context-menu';
    const ul = document.createElement('ul');
    items.forEach(function (item) {
      if (item.divider) {
        const li = document.createElement('li');
        li.className = 'bb-divider';
        ul.appendChild(li);
        return;
      }
      const li = document.createElement('li');
      li.className = 'bb-item';
      li.textContent = item.label;
      li.addEventListener('click', function (e) {
        e.stopPropagation();
        hideActiveMenu();
        if (item.action === 'create') {
          if (card.dataset.cleaning && card.dataset.cleaning !== 'Clean') {
            alert('This room is Vacant Dirty. Please mark it as clean before creating a new reservation.');
            return;
          }
          renderReservationForm({}, roomId);
        } else if (item.action === 'edit_room_details') {
          openEditRoomModal(card);
        } else if (item.action === 'mark_clean' || item.action === 'mark_dirty') {
          const newStatus = item.action === 'mark_clean' ? 'available' : 'needs_cleaning';
          const fd = new FormData();
          fd.append('action', 'update_status');
          fd.append('room_id', roomId);
          fd.append('new_status', newStatus);
          card.classList.remove('room-card--dirty');
          if (newStatus === 'needs_cleaning') card.classList.add('room-card--dirty');
          card.dataset.cleaning = newStatus === 'needs_cleaning' ? 'Pending' : 'Clean';
          const datesEl = card.querySelector('.rc-dates');
          if (datesEl) datesEl.textContent = newStatus === 'needs_cleaning' ? 'Vacant Dirty' : 'Vacant Clean';
          fetch('/process_room_action.php', { method: 'POST', body: fd })
            .then(function (r) { return r.text(); })
            .then(function (text) {
              let data;
              try { data = JSON.parse(text); } catch (e) {
                console.error('[layout] mark clean/dirty: server returned non-JSON:', text.slice(0, 400));
                card.dataset.cleaning = newStatus === 'needs_cleaning' ? 'Clean' : 'Pending';
                card.classList.toggle('room-card--dirty', newStatus !== 'needs_cleaning');
                if (datesEl) datesEl.textContent = newStatus === 'needs_cleaning' ? 'Vacant Clean' : 'Vacant Dirty';
                alert('Server error — check the browser console for details.');
                return;
              }
              if (data.success) {
                if (data.room) updateRoomCard(data.room);
              } else {
                alert(data.message || 'Could not update cleaning status.');
              }
            })
            .catch(function (err) {
              console.error('[layout] mark clean/dirty fetch error:', err);
              alert('Network error — could not reach the server.');
            });
        } else {
          handleReservationAction(item.action, roomId);
        }
      });
      ul.appendChild(li);
    });
    menu.appendChild(ul);
    document.body.appendChild(menu);
    activeMenu = menu;

    const menuWidth = menu.offsetWidth || 220;
    const menuHeight = menu.offsetHeight || 100;
    let left = x, top = y;
    const margin = 8;
    if (left + menuWidth > window.innerWidth - margin) left = window.innerWidth - menuWidth - margin;
    if (left < margin) left = margin;
    if (top + menuHeight > window.innerHeight - margin) top = window.innerHeight - menuHeight - margin;
    if (top < margin) top = margin;
    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
  }

  document.addEventListener('contextmenu', function (e) {
    const card = e.target.closest('.room-card[data-room-id]');
    if (!card) return;
    e.preventDefault();
    e.stopPropagation();
    showRoomMenu(e.clientX, e.clientY, card);
  }, true);

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
      console.warn('[layout] Room sync WebSocket unavailable:', err.message);
      return;
    }

    socket.addEventListener('open', function () {
      reconnectAttempts = 0;
      console.log('[layout] Live room sync connected.');
    });

    socket.addEventListener('message', function (e) {
      let msg;
      try {
        msg = JSON.parse(e.data);
      } catch (err) {
        return;
      }
      if (msg && msg.type === 'rooms_changed' && Array.isArray(msg.rooms)) {
        msg.rooms.forEach(updateRoomCard);
      }
      if (msg && msg.type === 'reservations_changed' && Array.isArray(msg.reservations)) {
        msg.reservations.forEach(updateRoomCardReservation);
      }
    });

    socket.addEventListener('close', scheduleReconnect);
    socket.addEventListener('error', function () {});
  }

  connect();

  (function wireLayoutLegend() {
    const legendItems = document.querySelectorAll('#layoutLegend .layout-legend__item');
    let activeFilter = null; // { status: 'available'|'dirty'|'occupied'|'reserved'|'maintenance'|'all' }

    function clearLegendHighlights() {
        legendItems.forEach(el => el.classList.remove('active-filter'));
    }

    function filterRooms(status) {
        const cards = document.querySelectorAll('.room-card');
        if (status === 'all') {
            cards.forEach(card => card.style.display = '');
            clearLegendHighlights();
            activeFilter = null;
            return;
        }

        // Map status to card classes
        let showClass = '';
        let extraCondition = null;
        switch (status) {
            case 'available':
                showClass = 'status-available';
                extraCondition = (card) => !card.classList.contains('room-card--dirty');
                break;
            case 'dirty':
                showClass = 'status-available';
                extraCondition = (card) => card.classList.contains('room-card--dirty');
                break;
            case 'occupied':
                showClass = 'status-occupied';
                break;
            case 'reserved':
                showClass = 'status-reserved';
                break;
            case 'maintenance':
                showClass = 'status-maintenance';
                break;
            default:
                return;
        }

        cards.forEach(card => {
            const hasClass = card.classList.contains(showClass);
            let show = hasClass;
            if (extraCondition) {
                show = show && extraCondition(card);
            }
            card.style.display = show ? '' : 'none';
        });

        // Highlight the active legend item
        clearLegendHighlights();
        const target = document.querySelector(`#layoutLegend .layout-legend__item[data-status="${status}"]`);
        if (target) target.classList.add('active-filter');
        activeFilter = { status };
    }

    // Add click listeners
    legendItems.forEach(item => {
        item.addEventListener('click', function(e) {
            const status = this.dataset.status;
            if (!status) return;

            // If already active, reset to show all
            if (activeFilter && activeFilter.status === status) {
                filterRooms('all');
                return;
            }

            filterRooms(status);
        });
    });

    // Expose reset function globally (used by other parts if needed)
    window.resetLayoutLegend = function() { filterRooms('all'); };

    // Also, when a room card is updated (via WebSocket), we might want to re-apply filter
    // if active. We'll override the updateRoomCard to re-filter after update.
    const originalUpdateRoomCard = window.updateRoomCard;
    if (originalUpdateRoomCard) {
        window.updateRoomCard = function(room) {
            originalUpdateRoomCard(room);
            // If a filter is active, re-apply it to maintain filtering
            if (activeFilter) {
                filterRooms(activeFilter.status);
            }
        };
    }

})();

})();
