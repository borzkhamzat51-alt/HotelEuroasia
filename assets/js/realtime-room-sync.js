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

  const STATUS_LABELS = { pending: 'Pending', reserved: 'Reserved', checked_in: 'Checked In' };
  const SYSTEM_STATUS_LABELS = { checked_out: 'Checked Out', cancelled: 'Cancelled' };
  const PAYMENT_LABELS = { cash: 'Cash', gcash: 'GCash', bank_transfer: 'Bank Transfer', card: 'Credit/Debit Card' };
  const LAYOUT_ROOMS = window.BB_LAYOUT_ROOMS || [];

  // --- Helper: determine card status from room and reservation ---
  function determineCardStatus(room, reservation) {
    if (room.room_status === 'maintenance') {
      return { status: 'maintenance', statusKey: 'maintenance', label: 'Out of Order' };
    }
    if (reservation) {
      if (reservation.status === 'checked_in') {
        return { status: 'occupied', statusKey: 'occupied', label: 'Occupied' };
      } else {
        // reserved (future or today)
        return { status: 'reserved', statusKey: 'reserved', label: 'Reserved' };
      }
    }
    // No reservation
    const isDirty = (room.cleaning_status !== 'Clean');
    return {
      status: 'available',
      statusKey: isDirty ? 'needs_cleaning' : 'available',
      label: isDirty ? 'Vacant Dirty' : 'Vacant Clean'
    };
  }

  // --- Expose updateRoomCard (room-level changes) ---
  window.updateRoomCard = function(room) {
    if (!room || !room.id) return;
    const card = document.querySelector('.room-card[data-room-id="' + room.id + '"]');
    if (!card) return;

    const isDirty = room.cleaning_status !== 'Clean';
    // Update data attributes
    card.dataset.cleaning = room.cleaning_status;
    if (room.room_number !== undefined) card.dataset.roomNumber = room.room_number;
    if (room.room_type !== undefined) card.dataset.typeMain = room.room_type;
    if (room.price_per_night !== undefined) card.dataset.price = room.price_per_night;

    // Update room number display
    const numEl = card.querySelector('.rc-room-num');
    if (numEl && room.room_number !== undefined) numEl.textContent = 'ROOM ' + room.room_number;

    // Update room type
    const typeEl = card.querySelector('.rc-room-type');
    if (typeEl && room.room_type !== undefined) typeEl.textContent = room.room_type;

    // Update rate (if no reservation, use room price)
    const rateEl = card.querySelector('.rc-rate');
    if (rateEl) {
      const currentResId = card.dataset.reservationId;
      if (!currentResId || currentResId === '') {
        // No reservation – use room price
        if (room.price_per_night && parseFloat(room.price_per_night) > 0) {
          rateEl.textContent = 'Rate: ₱' + Number(room.price_per_night).toLocaleString() + '/month';
          rateEl.style.display = '';
        } else {
          rateEl.style.display = 'none';
        }
      }
    }

    // Update status if no reservation – but if there is a reservation, the status will be set by updateRoomCardReservation.
    // We'll check if there is a reservation id on the card; if not, update status now.
    const resId = card.dataset.reservationId;
    if (!resId || resId === '') {
      const statusInfo = determineCardStatus(room, null);
      // Update classes
      card.className = card.className
        .split(' ')
        .filter(function (c) { return c.indexOf('status-') !== 0 && c !== 'room-card--dirty'; })
        .join(' ');
      card.classList.add('status-' + statusInfo.status);
      if (statusInfo.status === 'available' && isDirty) card.classList.add('room-card--dirty');
      card.dataset.status = statusInfo.status;
      card.dataset.statusKey = statusInfo.statusKey;
      // Update dates area to show vacant status
      const datesEl = card.querySelector('.rc-dates');
      if (datesEl) datesEl.textContent = statusInfo.label;
      // Clear guest name
      const guestEl = card.querySelector('.rc-guest-name');
      if (guestEl) {
        guestEl.textContent = 'No Guest Assigned';
        guestEl.className = 'rc-guest-name rc-guest-name--empty';
        guestEl.title = '';
      }
      card.dataset.guestName = '';
      card.dataset.checkIn = '';
      card.dataset.checkOut = '';
    }
  };

  // --- Expose updateRoomCardReservation (reservation changes) ---
  window.updateRoomCardReservation = function(resv) {
    if (!resv || !resv.room_id) return;
    const card = document.querySelector('.room-card[data-room-id="' + resv.room_id + '"]');
    if (!card) return;

    // We need the room object to check maintenance/dirty status.
    const roomId = resv.room_id;
    const room = LAYOUT_ROOMS.find(function(r) { return String(r.id) === String(roomId); }) || {};

    const isActive = (resv.status === 'reserved' || resv.status === 'checked_in');
    const statusInfo = determineCardStatus(room, isActive ? resv : null);

    // Update card classes
    card.className = card.className
      .split(' ')
      .filter(function (c) { return c.indexOf('status-') !== 0 && c !== 'room-card--dirty'; })
      .join(' ');
    card.classList.add('status-' + statusInfo.status);
    const isDirty = (room.cleaning_status !== 'Clean');
    if (statusInfo.status === 'available' && isDirty) card.classList.add('room-card--dirty');

    // Update data attributes
    card.dataset.status = statusInfo.status;
    card.dataset.statusKey = statusInfo.statusKey;

    // FIX: only carry guest/stay data over from resv while the reservation
    // is actually active (reserved/checked_in). A checked_out or cancelled
    // reservation still arrives here with the guest's name and dates intact
    // (only its status field changed) — previously these fields were copied
    // onto the card unconditionally, so a freshly checked-out room kept
    // showing the departed guest's name, dates, rate and duration even
    // though the card's status/colour had already flipped to vacant. When
    // not active, clear back to the room's plain vacant-state values
    // (mirrors window.updateRoomCard's no-reservation branch above).
    if (isActive) {
      card.dataset.guestName = resv.guest_full_name || '';
      card.dataset.checkIn = resv.check_in || '';
      card.dataset.checkOut = resv.check_out || '';
      card.dataset.price = resv.room_rate || room.price_per_night || '';
      card.dataset.reservationId = resv.id || '';
    } else {
      card.dataset.guestName = '';
      card.dataset.checkIn = '';
      card.dataset.checkOut = '';
      card.dataset.price = room.price_per_night || '';
      card.dataset.reservationId = '';
    }

    // Update guest name
    const guestEl = card.querySelector('.rc-guest-name');
    if (guestEl) {
      const name = isActive ? (resv.guest_full_name || '') : '';
      guestEl.textContent = name || 'No Guest Assigned';
      guestEl.className = name ? 'rc-guest-name' : 'rc-guest-name rc-guest-name--empty';
      guestEl.title = name;
    }

    // Update dates
    const datesEl = card.querySelector('.rc-dates');
    if (datesEl) {
      if (isActive && resv.check_in && resv.check_out) {
        const ci = new Date(resv.check_in + 'T00:00:00');
        const co = new Date(resv.check_out + 'T00:00:00');
        const fmt = { month: 'short', day: '2-digit' };
        const fmtY = { month: 'short', day: '2-digit', year: 'numeric' };
        datesEl.textContent = ci.toLocaleDateString('en-US', fmt) + ' - ' + co.toLocaleDateString('en-US', fmtY);
      } else {
        datesEl.textContent = statusInfo.label;
      }
    }

    // Update rate
    const rateEl = card.querySelector('.rc-rate');
    if (rateEl) {
      const rate = isActive ? (resv.room_rate || room.price_per_night || 0) : (room.price_per_night || 0);
      if (rate > 0) {
        rateEl.textContent = 'Rate: ₱' + Number(rate).toLocaleString() + '/month';
        rateEl.style.display = '';
      } else {
        rateEl.style.display = 'none';
      }
    }

    // Update duration
    const durationEl = card.querySelector('.rc-duration');
    if (durationEl) {
      if (isActive && resv.check_in && resv.check_out) {
        const start = new Date(resv.check_in + 'T00:00:00');
        const end = new Date(resv.check_out + 'T00:00:00');
        const diff = end - start;
        if (diff > 0) {
          const totalDays = diff / 86400000;
          const months = Math.floor(totalDays / 30.44);
          const days = Math.round(totalDays - months * 30.44);
          let parts = [];
          if (months > 0) parts.push(months + ' Month' + (months !== 1 ? 's' : ''));
          if (days > 0)   parts.push(days + ' Day' + (days !== 1 ? 's' : ''));
          durationEl.textContent = parts.join(' ');
          durationEl.style.display = '';
        } else {
          durationEl.style.display = 'none';
        }
      } else {
        durationEl.style.display = 'none';
      }
    }
  };

  // --- UI helpers ---
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
      .bb-duration { background:var(--sky-50); border-radius:var(--radius-sm); padding:8px 12px; margin:8px 0 12px; font-size:0.9rem; border:1px solid var(--sky-200); }
      .bb-duration__display { font-weight:700; color:var(--blue-700); font-size:1rem; margin-top:4px; }
    `;
    document.head.appendChild(style);
  }
  injectModalStyles();

  // ── Reservation form helpers ──────────────────────────────────────
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

  function fetchActiveReservation(roomId, reservationId) {
    // Try by reservation ID first (works for any status), then fall back to room_id.
    // Never throw — always resolve with whatever we find (or null).
    function byId(id) {
      return fetch('/process_reservation.php?action=get_reservation_for_payment&id=' + id)
        .then(function(r) { return r.json(); })
        .then(function(d) { return (d && d.reservation && d.reservation.id) ? d.reservation : null; })
        .catch(function() { return null; });
    }
    function byRoom(rId) {
      return fetch('/process_reservation.php?action=get_active_reservation&room_id=' + rId)
        .then(function(r) { return r.json(); })
        .then(function(d) { return (d && d.success && d.reservation) ? d.reservation : null; })
        .catch(function() { return null; });
    }

    if (reservationId) {
      return byId(reservationId).then(function(r) {
        // If fetch-by-id returned something, use it; otherwise try room lookup
        return r || byRoom(roomId);
      });
    }
    return byRoom(roomId);
  }

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
  const VALID_ID_OPTIONS = [
    'National ID (PhilSys)', 'Passport', "Driver's License", 'Barangay ID',
    'Postal ID', 'UMID', 'SSS ID', 'PRC ID', 'Senior Citizen ID',
    'Student ID', "Voter's ID", 'Company ID', 'Other Government ID', 'No ID'
  ];

  function validIdDropdown(name, selected, errors) {
    var opts = VALID_ID_OPTIONS.map(function(v) {
      return '<option value="' + v.replace(/"/g,'&quot;') + '"' + (v === selected ? ' selected' : '') + '>' + v + '</option>';
    }).join('');
    return '<div class="bb-field"><label for="' + name + '">Valid ID Type</label>' +
      '<select id="' + name + '" name="' + name + '" style="width:100%;padding:9px 11px;border:1.5px solid var(--sky-200,#c5deef);border-radius:6px;font-family:inherit;font-size:.86rem;">' +
        '<option value="">— Select —</option>' + opts +
      '</select>' +
      (errors && errors[name] ? '<span class="bb-form-error">' + errors[name] + '</span>' : '') +
    '</div>';
  }

  function manualStatusOptions(selected) {
    var html = '';
    Object.keys(STATUS_LABELS).forEach(function(k) {
      html += '<option value="' + k + '"' + (k === selected ? ' selected' : '') + '>' + STATUS_LABELS[k] + '</option>';
    });
    if (SYSTEM_STATUS_LABELS[selected]) {
      html += '<option value="' + selected + '" selected disabled>' + SYSTEM_STATUS_LABELS[selected] + ' (system-assigned)</option>';
    }
    return html;
  }

  function renderReservationForm(resv, prefillRoomId, errors, successMsg, justCreated) {
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

    // ── Step indicator (both new and edit) ───────────────────────────
    html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:18px;" id="bbSteps">' +
      '<div style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;" id="bbStep1">' +
        '<span style="width:22px;height:22px;border-radius:50%;background:#3b7dd8;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;">1</span>' +
        '<span style="color:#16324f;">Reservation details</span>' +
      '</div>' +
      '<div style="flex:1;height:1px;background:#c5deef;"></div>' +
      '<div style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;">' +
        '<span id="bbStep2Dot" style="width:22px;height:22px;border-radius:50%;background:#e0eaf4;color:#8dafc8;border:1px solid #c5deef;display:flex;align-items:center;justify-content:center;font-size:11px;">2</span>' +
        '<span id="bbStep2Lbl" style="color:#8dafc8;">Payment</span>' +
      '</div>' +
    '</div>';
    // Confirms the save that just happened (create or edit) and explains
    // why the form re-opened instead of closing — the panel below it lets
    // staff log a payment immediately against the just-saved reservation.
    if (successMsg) {
      html += '<p style="color:#1a7a46;background:#eafaf0;border:1px solid #b9ecd2;border-radius:8px;padding:8px 12px;font-size:.84rem;margin:6px 0 2px;">✓ ' + successMsg + '</p>';
    }
    html += '<form id="bbResvForm" autocomplete="off">';
    if (isEdit) html += '<input type="hidden" name="id" value="' + resv.id + '">';

    html += '<h3>Guest Information</h3><div class="bb-grid">';
    html += field('Full Name', 'guest_full_name', resv.guest_full_name, 'text', errors, true);
    html += field('Contact Number', 'contact_number', resv.contact_number, 'text', errors);
    html += field('Emergency Contact Number', 'emergency_contact', resv.emergency_contact, 'text', errors);
    html += field('Email Address', 'email', resv.email, 'email', errors);
    html += field('Address', 'address', resv.address, 'text', errors);
    html += validIdDropdown('valid_id_type', resv.valid_id_type, errors);
    html += field('Valid ID Number', 'valid_id_number', resv.valid_id_number, 'text', errors);
    html += '</div>';

    html += '<h3>Booking Information</h3><div class="bb-grid">';
    html += '<input type="hidden" name="room_id" value="' + roomId + '">';
    html += '<div class="bb-field"><label for="status">Booking Status</label><select id="status" name="status">' + manualStatusOptions(resv.status || 'reserved') + '</select></div>';
    html += field('Check-in Date', 'check_in', resv.check_in, 'date', errors, true);
    html += field('Check-out Date', 'check_out', resv.check_out, 'date', errors, true);
    html += field('Number of Adults', 'num_adults', resv.num_adults || 1, 'number', errors);
    html += field('Number of Children', 'num_children', resv.num_children || 0, 'number', errors);
    html += '</div>';

    // Quick Stay Duration
    html += '<div class="bb-field" style="grid-column:1/-1;">';
    html += '<label>Quick Stay Duration</label>';
    html += '<div id="bbDurationBtns" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;">';
    [1,2,3,4,6,12].forEach(function(m) {
      html += '<button type="button" class="bb-dur-btn" data-months="' + m + '" style="padding:7px 16px;border-radius:999px;border:1.5px solid var(--sky-200,#c5deef);background:var(--white,#fff);color:var(--ink-700,#2c4a68);font-family:Inter,sans-serif;font-size:0.8rem;font-weight:600;cursor:pointer;">' + (m === 1 ? '1 Month' : m + ' Months') + '</button>';
    });
    html += '</div></div>';

    // Duration display
    html += '<div class="bb-duration">';
    html += '<label>Stay Duration</label>';
    html += '<div class="bb-duration__display" id="bbStayDurationDisplay">0 Days</div>';
    html += '</div>';

    html += '<h3>Payment Rates</h3><div class="bb-grid">';
    html += field('Monthly Rent (₱/month)', 'room_rate', resv.room_rate || 0, 'number', errors);
    html += field('Reservation Fee (₱)', 'reservation_fee', resv.reservation_fee || 0, 'number', errors);
    html += field('Garbage Fee (₱)', 'garbage_fee', resv.garbage_fee || 0, 'number', errors);
    html += field('Security Deposit (₱)', 'security_deposit', resv.security_deposit || 0, 'number', errors);
    html += field('Utilities Deposit (₱)', 'utilities_deposit', resv.utilities_deposit || 0, 'number', errors);
    html += field('Total Amount (₱)', 'total_amount', resv.total_amount || 0, 'number', errors);
    html += '<input type="hidden" name="amount_paid" id="bb_amount_paid" value="' + (resv.amount_paid || 0) + '">';
    html += '<input type="hidden" name="expected_payment_date" value="' + (resv.expected_payment_date || '') + '">';
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
    html += '<button type="submit" class="bb-btn bb-btn--primary">Next →</button>';
    html += '</div></form>';

    inner.innerHTML = html;
    modal.appendChild(inner);
    document.body.appendChild(modal);

    const form = document.getElementById('bbResvForm');
    const balanceEl = document.getElementById('bbResvBalance');

    function calcMonths(inVal, outVal) {
      if (!inVal || !outVal) return 0;
      var s = new Date(inVal + 'T00:00:00'), e = new Date(outVal + 'T00:00:00');
      if (e <= s) return 0;
      var months = (e.getFullYear() - s.getFullYear()) * 12 + (e.getMonth() - s.getMonth());
      var ds = s.getDate(), de = e.getDate();
      if (de > ds) months += (de - ds) / new Date(e.getFullYear(), e.getMonth(), 0).getDate();
      else if (de < ds) months -= (ds - de) / new Date(s.getFullYear(), s.getMonth() + 1, 0).getDate();
      return Math.max(0, months);
    }

    function autoCalcTotal() {
      var rate       = parseFloat(form.room_rate         ? form.room_rate.value         : 0) || 0;
      var resvFee    = parseFloat(form.reservation_fee   ? form.reservation_fee.value   : 0) || 0;
      var garbageFee = parseFloat(form.garbage_fee       ? form.garbage_fee.value       : 0) || 0;
      var deposit    = parseFloat(form.security_deposit  ? form.security_deposit.value  : 0) || 0;
      var utilsDep   = parseFloat(form.utilities_deposit ? form.utilities_deposit.value : 0) || 0;
      var ciEl       = form.querySelector('[name="check_in"]');
      var coEl       = form.querySelector('[name="check_out"]');
      var months     = (ciEl && coEl) ? calcMonths(ciEl.value, coEl.value) : 0;
      var total      = (rate * months) + resvFee + garbageFee + deposit + utilsDep;
      if (form.total_amount) form.total_amount.value = total > 0 ? total.toFixed(2) : '';
      return total;
    }

    function recalcBalance() {
      var total     = autoCalcTotal();
      var paid      = parseFloat(form.amount_paid ? form.amount_paid.value : 0) || 0;
      var remaining = total - paid;
      balanceEl.textContent = '₱' + remaining.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      balanceEl.style.color = remaining > 0 ? '#b3433f' : 'inherit';
    }

    ['room_rate', 'reservation_fee', 'garbage_fee', 'security_deposit', 'utilities_deposit'].forEach(function(n) {
      var el = form.querySelector('[name="' + n + '"]');
      if (el) el.addEventListener('input', recalcBalance);
    });
    ['check_in', 'check_out'].forEach(function(n) {
      var el = form.querySelector('[name="' + n + '"]');
      if (el) el.addEventListener('change', recalcBalance);
    });
    if (form.total_amount) form.total_amount.addEventListener('input', recalcBalance);
    if (form.amount_paid)  form.amount_paid.addEventListener('input', recalcBalance);
    form._recalcBalance = recalcBalance;
    recalcBalance();

    // ── Wire Quick Stay Duration buttons ──────────────────────────────────
    function wireBbDateCalculations() {
      const checkIn = form.querySelector('[name="check_in"]');
      const checkOut = form.querySelector('[name="check_out"]');
      const expectedPayment = form.querySelector('[name="expected_payment_date"]');
      const durationDisplay = document.getElementById('bbStayDurationDisplay');
      let selectedMonths = null;

      function formatLocalDate(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
      }

      function updateDurationAndPayment() {
        const inVal = checkIn.value;
        const outVal = checkOut.value;
        if (inVal && outVal) {
          const start = new Date(inVal + 'T00:00:00');
          const end   = new Date(outVal + 'T00:00:00');
          const days  = Math.round((end - start) / 86400000);
          durationDisplay.textContent = days > 0 ? days + ' Day' + (days !== 1 ? 's' : '') : '0 Days';
          if (!expectedPayment.dataset.userEdited) {
            expectedPayment.value = outVal;
          }
        } else {
          durationDisplay.textContent = '0 Days';
        }
        if (form._recalcBalance) form._recalcBalance();
      }

      function applyDuration(months) {
        if (!checkIn.value) { alert('Please select a check-in date first.'); return; }
        const start = new Date(checkIn.value + 'T00:00:00');
        const end = new Date(start);
        end.setMonth(end.getMonth() + months);
        const outVal = formatLocalDate(end);
        checkOut.value = outVal;
        if (!expectedPayment.dataset.userEdited) expectedPayment.value = outVal;
        updateDurationAndPayment();
        selectedMonths = months;
        form.querySelectorAll('.bb-dur-btn').forEach(function(b) { b.classList.remove('is-active'); b.style.background=''; b.style.color=''; b.style.borderColor=''; });
        const activeBtn = form.querySelector('.bb-dur-btn[data-months="' + months + '"]');
        if (activeBtn) { activeBtn.style.background='var(--blue-500,#3b7dd8)'; activeBtn.style.color='#fff'; activeBtn.style.borderColor='var(--blue-500,#3b7dd8)'; }
      }

      form.querySelectorAll('.bb-dur-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
          e.preventDefault();
          applyDuration(parseInt(this.dataset.months, 10));
        });
      });

      checkIn.addEventListener('input', function() {
        if (selectedMonths !== null) applyDuration(selectedMonths);
        updateDurationAndPayment();
      });
      checkOut.addEventListener('input', function() {
        selectedMonths = null;
        form.querySelectorAll('.bb-dur-btn').forEach(function(b) { b.style.background=''; b.style.color=''; b.style.borderColor=''; });
        updateDurationAndPayment();
      });
      expectedPayment.addEventListener('input', function() { this.dataset.userEdited = 'true'; });
      updateDurationAndPayment();
    }
    wireBbDateCalculations();
    // Payment is handled in step 2 after save

    // ── Financial Summary for edit mode ─────────────────────────────────
    if (isEdit && resv.id) {
      fetch('/process_reservation.php?action=get_reservation_for_payment&id=' + resv.id)
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (!data.success) return;
          const payments = data.months || [];
          const totalPaid = payments.reduce(function(s, p) { return s + parseFloat(p.amount || 0); }, 0);
          const outstanding = data.outstanding_balance ?? 0;
          const status = data.payment_status ?? '—';
          const monthlyRate = parseFloat(resv.room_rate || 0);
          function months(ci, co) {
            if (!ci || !co) return 0;
            var s = new Date(ci+'T00:00:00'), e = new Date(co+'T00:00:00');
            var y = e.getFullYear()-s.getFullYear(), m = e.getMonth()-s.getMonth(), d = e.getDate()-s.getDate();
            var mo = y*12+m; if(d>0)mo++; return Math.max(1, mo);
          }
          const mo = months(resv.check_in, resv.check_out);
          const totalRental = monthlyRate * mo;
          const statusColors = {'Fully Paid':'background:#d4f7e7;color:#1a7a46','Overdue':'background:#fde8e8;color:#b91c1c','Partially Paid':'background:#fef3c7;color:#92400e','Unpaid':'background:#f3f4f6;color:#6b7280'};
          const sc = statusColors[status] || 'background:#f3f4f6;color:#6b7280';

          var panel = document.createElement('div');
          panel.style.cssText = 'margin:14px 0 6px;border:1px solid var(--sky-200,#c5deef);border-radius:10px;background:var(--sky-50,#eef5fc);overflow:hidden;';
          panel.innerHTML =
            '<div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--blue-500,#3b7dd8);padding:9px 14px 7px;border-bottom:1px solid var(--sky-200,#c5deef);">Financial Summary</div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;">' +
            '<div style="padding:8px 14px;border-bottom:1px solid var(--sky-100,#dceaf8);border-right:1px solid var(--sky-100,#dceaf8);"><div style="font-size:0.7rem;color:var(--ink-500,#5b7693);font-weight:600;text-transform:uppercase;">Monthly Rate</div><div style="font-weight:700;margin-top:2px;">₱' + monthlyRate.toLocaleString() + '/mo</div></div>' +
            '<div style="padding:8px 14px;border-bottom:1px solid var(--sky-100,#dceaf8);"><div style="font-size:0.7rem;color:var(--ink-500,#5b7693);font-weight:600;text-transform:uppercase;">Rental Duration</div><div style="font-weight:700;margin-top:2px;">' + mo + ' Month' + (mo!==1?'s':'') + '</div></div>' +
            '<div style="padding:8px 14px;border-bottom:1px solid var(--sky-100,#dceaf8);border-right:1px solid var(--sky-100,#dceaf8);"><div style="font-size:0.7rem;color:var(--ink-500,#5b7693);font-weight:600;text-transform:uppercase;">Total Rental</div><div style="font-weight:700;margin-top:2px;">₱' + totalRental.toLocaleString() + '</div></div>' +
            '<div style="padding:8px 14px;border-bottom:1px solid var(--sky-100,#dceaf8);"><div style="font-size:0.7rem;color:var(--ink-500,#5b7693);font-weight:600;text-transform:uppercase;">Amount Paid</div><div style="font-weight:700;color:#1a7a46;margin-top:2px;">₱' + totalPaid.toLocaleString() + '</div></div>' +
            '<div style="padding:8px 14px;border-right:1px solid var(--sky-100,#dceaf8);"><div style="font-size:0.7rem;color:var(--ink-500,#5b7693);font-weight:600;text-transform:uppercase;">Outstanding Balance</div><div style="font-weight:700;color:' + (outstanding>0?'#b91c1c':'#1a7a46') + ';margin-top:2px;">' + (outstanding>0?'₱'+outstanding.toLocaleString():'✓ Fully Paid') + '</div></div>' +
            '<div style="padding:8px 14px;"><div style="font-size:0.7rem;color:var(--ink-500,#5b7693);font-weight:600;text-transform:uppercase;">Payment Status</div><div style="margin-top:4px;"><span style="font-size:0.72rem;font-weight:700;padding:2px 8px;border-radius:999px;' + sc + ';">' + status + '</span></div></div>' +
            '</div>';
          var actionsEl = inner.querySelector('.bb-actions');
          if (actionsEl) inner.insertBefore(panel, actionsEl);
        })
        .catch(function() { /* silent fail */ });
    }

    function closeFormModal() { modal.remove(); }
    document.getElementById('bbResvCancelBtn').addEventListener('click', closeFormModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeFormModal(); });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!isEdit && isDirtyLayoutRoom(roomId)) {
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
              if (Array.isArray(res.rooms)) res.rooms.forEach(window.updateRoomCard);
              const saved = res.reservation || Object.assign({}, formToObject(new FormData(form)), { room_id: roomId });
              const resvStatus = saved.status || (isEdit ? (resv.status || 'reserved') : 'reserved');
              window.updateRoomCardReservation(Object.assign({}, saved, {
                room_id: saved.room_id || roomId,
                status: resvStatus,
                expected_payment_date: saved.expected_payment_date || saved.check_out || null,
              }));
              if (window.triggerLayoutPoll) window.triggerLayoutPoll();

              {
                // Both new and edit: advance to payment step 2
                var resvId   = saved.id;
                var totalAmt = parseFloat(saved.total_amount || 0);

                // Build step-2 payment UI inside the modal
                inner.innerHTML =
                  '<h2>Payment</h2>' +
                  // Step indicator — step 1 done, step 2 active
                  '<div style="display:flex;align-items:center;gap:8px;margin-bottom:18px;">' +
                    '<div style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;">' +
                      '<span style="width:22px;height:22px;border-radius:50%;background:#d4f7e7;color:#1a7a46;display:flex;align-items:center;justify-content:center;font-size:11px;">✓</span>' +
                      '<span style="color:#1a7a46;">Reservation details</span>' +
                    '</div>' +
                    '<div style="flex:1;height:1px;background:#c5deef;"></div>' +
                    '<div style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;">' +
                      '<span style="width:22px;height:22px;border-radius:50%;background:#3b7dd8;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;">2</span>' +
                      '<span style="color:#16324f;">Payment</span>' +
                    '</div>' +
                  '</div>' +
                  '<div style="background:#eef5fc;border:1px solid #c5deef;border-radius:8px;padding:9px 14px;font-size:.84rem;color:#2c4a68;margin-bottom:14px;">' +
                    (saved.guest_full_name || '') + ' &nbsp;·&nbsp; RM' + (saved.room_number || '') +
                    ' &nbsp;·&nbsp; ' + (saved.check_in || '') + ' → ' + (saved.check_out || '') +
                  '</div>' +
                  '<div style="background:#f8fbff;border:1px solid #c5deef;border-radius:8px;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">' +
                    '<span style="font-size:.84rem;color:#5b7693;">Remaining balance</span>' +
                    '<span id="bbStep2Balance" style="font-size:1rem;font-weight:700;color:#b91c1c;">₱' + totalAmt.toLocaleString('en-PH',{minimumFractionDigits:2}) + '</span>' +
                  '</div>' +
                  '<div style="border:1px solid #c5deef;border-radius:8px;overflow:hidden;margin-bottom:14px;">' +
                    '<div style="background:#eef5fc;padding:8px 14px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#3b7dd8;border-bottom:1px solid #c5deef;">Payment History</div>' +
                    '<div id="bbStep2PayList" style="padding:12px 14px;font-size:.84rem;color:#5b7693;">No payments recorded yet.</div>' +
                  '</div>' +
                  '<div style="border:1px solid #c5deef;border-radius:8px;overflow:hidden;">' +
                    '<div style="background:#f8fbff;padding:8px 14px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5b7693;border-bottom:1px solid #c5deef;">Amount</div>' +
                    '<div style="padding:12px 14px;display:flex;flex-direction:column;gap:8px;">' +
                      '<div style="display:grid;grid-template-columns:1fr auto;gap:8px;align-items:end;">' +
                        '<input type="number" id="bbStep2Amt" min="0.01" step="0.01" placeholder="e.g. 10000" style="width:100%;padding:9px 11px;border:1.5px solid #c5deef;border-radius:6px;font-family:inherit;font-size:.86rem;">' +
                        '<button type="button" id="bbStep2AddBtn" style="padding:9px 16px;background:#16a34a;color:#fff;border:none;border-radius:6px;font-family:inherit;font-size:.84rem;font-weight:600;cursor:pointer;white-space:nowrap;">+ Add</button>' +
                      '</div>' +
                      '<input type="text" id="bbStep2Remarks" placeholder="Remarks / ref no. (optional)" style="width:100%;padding:9px 11px;border:1.5px solid #c5deef;border-radius:6px;font-family:inherit;font-size:.84rem;box-sizing:border-box;">' +
                      '<p id="bbStep2Err" style="color:#b91c1c;font-size:.78rem;margin:0;display:none;"></p>' +
                    '</div>' +
                  '</div>' +
                  '<div class="bb-actions" style="margin-top:14px;">' +
                    '<button type="button" id="bbStep2DoneBtn" class="bb-btn bb-btn--primary">Done</button>' +
                    '<button type="button" id="bbStep2BackBtn" class="bb-btn bb-btn--secondary">← Back</button>' +
                  '</div>';

                function fmtBB(n) { return '₱' + parseFloat(n||0).toLocaleString('en-PH',{minimumFractionDigits:2}); }

                function loadStep2Payments() {
                  var listEl = document.getElementById('bbStep2PayList');
                  var balEl  = document.getElementById('bbStep2Balance');
                  var amtEl  = document.getElementById('bbStep2Amt');
                  if (!listEl) return;
                  listEl.textContent = 'Loading…';
                  fetch('/process_reservation.php?action=get_reservation_for_payment&id=' + resvId)
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                      var payments  = (data.success && data.months) ? data.months : [];
                      var totalPaid = payments.reduce(function(s,p){ return s + parseFloat(p.amount||0); }, 0);
                      var balance   = Math.max(0, totalAmt - totalPaid);
                      if (balEl) { balEl.textContent = fmtBB(balance); balEl.style.color = balance > 0 ? '#b91c1c' : '#1a7a46'; }
                      if (amtEl && !amtEl.dataset.userEdited) amtEl.value = balance > 0 ? balance.toFixed(2) : '';
                      if (payments.length === 0) {
                        listEl.innerHTML = '<div style="color:#8a9aa8;font-size:.82rem;padding:4px 0;">No payments recorded yet.</div>';
                        return;
                      }
                      listEl.innerHTML = payments.map(function(p) {
                        var dt = p.payment_date || (p.created_at ? p.created_at.split(' ')[0] : '—');
                        var pm = {cash:'Cash',gcash:'GCash',bank_transfer:'Bank Transfer',card:'Card'}[p.payment_method] || (p.payment_method || '—');
                        return '<div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid #e8f0f8;font-size:.84rem;">' +
                          '<span style="color:#5b7693;">' + dt + ' · ' + pm + (p.remarks ? ' · ' + p.remarks : '') + '</span>' +
                          '<span style="color:#1a7a46;font-weight:600;">' + fmtBB(p.amount) + '</span></div>';
                      }).join('') +
                      '<div style="display:flex;justify-content:space-between;padding:6px 0 2px;font-weight:700;font-size:.84rem;">' +
                        '<span>Total Paid</span><span style="color:#1a7a46;">' + fmtBB(totalPaid) + '</span>' +
                      '</div>';
                    })
                    .catch(function() { if (listEl) listEl.textContent = 'Could not load payments.'; });
                }

                loadStep2Payments();

                document.getElementById('bbStep2AddBtn').addEventListener('click', function() {
                  var errEl  = document.getElementById('bbStep2Err');
                  var amount = parseFloat(document.getElementById('bbStep2Amt').value);
                  var remarks = document.getElementById('bbStep2Remarks').value.trim();
                  if (errEl) errEl.style.display = 'none';
                  if (!amount || amount <= 0) { if (errEl) { errEl.textContent = 'Enter a valid amount.'; errEl.style.display = 'block'; } return; }
                  var btn = document.getElementById('bbStep2AddBtn');
                  btn.disabled = true; btn.textContent = '…';
                  var fd2 = new FormData();
                  fd2.append('action', 'record_payment');
                  fd2.append('reservation_id', resvId);
                  fd2.append('amount', amount);
                  fd2.append('payment_date', new Date().toISOString().split('T')[0]);
                  fd2.append('payment_method', 'cash');
                  fd2.append('remarks', remarks);
                  fetch('/process_reservation.php', { method: 'POST', body: fd2 })
                    .then(function(r2) { return r2.json(); })
                    .then(function(d) {
                      btn.disabled = false; btn.textContent = '+ Add';
                      if (!d.success) { if (errEl) { errEl.textContent = d.message || 'Could not save.'; errEl.style.display = 'block'; } return; }
                      document.getElementById('bbStep2Amt').value = '';
                      document.getElementById('bbStep2Remarks').value = '';
                      delete document.getElementById('bbStep2Amt').dataset.userEdited;
                      loadStep2Payments();
                    })
                    .catch(function() { btn.disabled = false; btn.textContent = '+ Add'; if (errEl) { errEl.textContent = 'Network error.'; errEl.style.display = 'block'; } });
                });

                document.getElementById('bbStep2DoneBtn').addEventListener('click', closeFormModal);

                var backBtn2 = document.getElementById('bbStep2BackBtn');
                if (backBtn2) {
                  backBtn2.addEventListener('click', function() {
                    fetchActiveReservation(roomId, resvId)
                      .then(function(full) {
                        renderReservationForm(Object.assign({}, saved, full || {}), roomId, null, null, false);
                      })
                      .catch(function() { renderReservationForm(saved, roomId, null, null, false); });
                  });
                }
              }
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
            if (data.room) window.updateRoomCard(data.room);
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
          if (Array.isArray(data.rooms)) data.rooms.forEach(window.updateRoomCard);
          window.updateRoomCardReservation(Object.assign({}, r, { status: newStatus }));
          if (window.triggerLayoutPoll) window.triggerLayoutPoll();
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
          if (Array.isArray(data.rooms)) data.rooms.forEach(window.updateRoomCard);
          window.updateRoomCardReservation(Object.assign({}, r, { check_in: checkIn, check_out: checkOut, expected_payment_date: checkOut }));
          if (window.triggerLayoutPoll) window.triggerLayoutPoll();
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
      '<div class="bb-info-row"><span class="bb-label">Emergency Contact</span><span class="bb-value">' + (r.emergency_contact || 'N/A') + '</span></div>' +
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
      '<div class="row"><span class="label">Monthly Rent</span><span class="value">₱' + parseFloat(r.room_rate || 0).toFixed(2) + '/mo</span></div>' +
      '<div class="row"><span class="label">Reservation Fee</span><span class="value">₱' + parseFloat(r.reservation_fee || 0).toFixed(2) + '</span></div>' +
      '<div class="row"><span class="label">Garbage Fee</span><span class="value">₱' + parseFloat(r.garbage_fee || 0).toFixed(2) + '</span></div>' +
      '<div class="row"><span class="label">Security Deposit</span><span class="value">₱' + parseFloat(r.security_deposit || 0).toFixed(2) + '</span></div>' +
      '<div class="row"><span class="label">Utilities Deposit</span><span class="value">₱' + parseFloat(r.utilities_deposit || 0).toFixed(2) + '</span></div>' +
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
      '<div class="row"><span class="label">Monthly Rent</span><span class="value">₱' + parseFloat(r.room_rate || 0).toFixed(2) + '/mo</span></div>' +
      '<div class="row"><span class="label">Reservation Fee</span><span class="value">₱' + parseFloat(r.reservation_fee || 0).toFixed(2) + '</span></div>' +
      '<div class="row"><span class="label">Garbage Fee</span><span class="value">₱' + parseFloat(r.garbage_fee || 0).toFixed(2) + '</span></div>' +
      '<div class="row"><span class="label">Security Deposit</span><span class="value">₱' + parseFloat(r.security_deposit || 0).toFixed(2) + '</span></div>' +
      '<div class="row"><span class="label">Utilities Deposit</span><span class="value">₱' + parseFloat(r.utilities_deposit || 0).toFixed(2) + '</span></div>' +
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
          ' (₱' + Number(room.price_per_night).toLocaleString() + '/Month)' +
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
        'guest_full_name', 'contact_number', 'emergency_contact', 'email', 'address',
        'valid_id_type', 'valid_id_number', 'check_in', 'check_out',
        'num_adults', 'num_children', 'status',
        'room_rate', 'reservation_fee', 'garbage_fee', 'security_deposit', 'utilities_deposit', 'total_amount', 'amount_paid',
        'payment_method', 'notes', 'special_requests', 'expected_payment_date'
      ];
      fields.forEach(function (key) {
        fd.append(key, r[key] !== undefined && r[key] !== null ? r[key] : '');
      });
      fetch('/process_reservation.php', { method: 'POST', body: fd })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.success) {
            if (Array.isArray(data.rooms)) data.rooms.forEach(window.updateRoomCard);
            if (window.triggerLayoutPoll) window.triggerLayoutPoll();
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

  function handleReservationAction(action, roomId, reservationId) {
    fetchActiveReservation(roomId, reservationId).then(function (r) {
      if (!r) {
        // Could not fetch fresh data — nothing to act on
        console.warn('[layout] No reservation found for room', roomId, 'id', reservationId);
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
      // Don't block the action — proceed with whatever data we have cached on the card
      alert('Could not refresh reservation data from server. Please try right-clicking again.');
    });
  }

  // ── Inline payment panel wiring (layout form) ───────────────────────────
  function wireBbPayPanel(resvId, justCreated) {
    function fmtMoney(n) {
      return '₱' + parseFloat(n||0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    }
    var listEl = document.getElementById('bbPayList');
    var amtEl  = document.getElementById('bbPayAmt');
    var mthEl  = document.getElementById('bbPayMethod');
    var rmkEl  = document.getElementById('bbPayRemarks');
    var btnEl  = document.getElementById('bbPayBtn');
    var errEl  = document.getElementById('bbPayErr');
    if (!listEl || !btnEl) return;

    var PM_LABELS = {cash:'Cash',gcash:'GCash',bank_transfer:'Bank Transfer',card:'Card'};

    function loadPayments() {
      fetch('/process_reservation.php?action=get_reservation_for_payment&id=' + resvId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (!data.success) { listEl.textContent = 'Could not load payments.'; return; }
          var payments = data.months || [];
          var totalPaid = payments.reduce(function(s,p){ return s+parseFloat(p.amount||0); }, 0);
          var totalDue  = parseFloat(document.querySelector('[name="total_amount"]')?.value || 0);
          var balance   = totalDue - totalPaid;

          // Keep hidden field in sync
          var hiddenAmt = document.getElementById('bb_amount_paid');
          if (hiddenAmt) hiddenAmt.value = totalPaid.toFixed(2);

          // Update balance display
          var balEl = document.getElementById('bbResvBalance');
          if (balEl) {
            balEl.textContent = fmtMoney(balance);
            balEl.style.color = balance > 0 ? '#b3433f' : '#1a7a46';
          }

          // Pre-fill amount with outstanding
          if (amtEl && !amtEl.dataset.userEdited) {
            amtEl.value = balance > 0 ? balance.toFixed(2) : '';
          }

          if (payments.length === 0) {
            listEl.innerHTML = '<div style="color:#8a9aa8;font-size:.82rem;padding:4px 0;">No payments recorded yet.</div>';
            return;
          }
          listEl.innerHTML = payments.map(function(p) {
            var pm = PM_LABELS[p.payment_method] || (p.payment_method || '—');
            var dt = p.payment_date || (p.created_at ? p.created_at.split(' ')[0] : '—');
            return '<div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid var(--sky-100,#dceaf8);">' +
              '<span style="color:var(--ink-500,#5b7693);font-size:.8rem;">' + dt + ' &middot; ' + pm + (p.remarks ? ' &middot; ' + p.remarks : '') + '</span>' +
              '<span style="font-weight:700;color:#1a7a46;">' + fmtMoney(p.amount) + '</span>' +
            '</div>';
          }).join('') +
          '<div style="display:flex;justify-content:space-between;padding:7px 0 2px;font-weight:700;font-size:.84rem;">' +
            '<span>Total Paid</span><span style="color:#1a7a46;">' + fmtMoney(totalPaid) + '</span>' +
          '</div>';
        })
        .catch(function() { listEl.textContent = 'Could not load payments.'; });
    }

    loadPayments();
    if (amtEl) amtEl.addEventListener('input', function() { this.dataset.userEdited = 'true'; });

    btnEl.addEventListener('click', function() {
      errEl.style.display = 'none';
      var amount  = parseFloat(amtEl.value);
      // mthEl only exists when this panel was rendered with justCreated —
      // method is required in that case, but isn't asked for at all when
      // logging a payment against an already-existing reservation.
      var method  = mthEl ? mthEl.value : '';
      var remarks = rmkEl ? rmkEl.value.trim() : '';
      if (!amount || amount <= 0) { errEl.textContent='Enter an amount.'; errEl.style.display='block'; return; }
      if (mthEl && !method)       { errEl.textContent='Select a method.'; errEl.style.display='block'; return; }

      btnEl.disabled = true;
      btnEl.textContent = '…';

      var fd = new FormData();
      fd.append('action',         'record_payment');
      fd.append('reservation_id', resvId);
      fd.append('amount',         amount);
      fd.append('payment_date',   new Date().toISOString().split('T')[0]);
      fd.append('payment_method', method);
      fd.append('remarks',        remarks);

      fetch('/process_reservation.php', { method:'POST', body:fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          btnEl.disabled    = false;
          btnEl.textContent = '+ Add';
          if (!data.success) { errEl.textContent=data.message||'Error.'; errEl.style.display='block'; return; }
          amtEl.value = '';
          if (rmkEl) rmkEl.value = '';
          amtEl.dataset.userEdited = '';
          loadPayments();
        })
        .catch(function() {
          btnEl.disabled    = false;
          btnEl.textContent = '+ Add';
          errEl.textContent = 'Network error.';
          errEl.style.display = 'block';
        });
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
  window.addEventListener('scroll', function () { hideActiveMenu(); }, { passive: true, capture: true });
  document.addEventListener('scroll', function () { hideActiveMenu(); }, { passive: true, capture: true });

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
                if (data.room) window.updateRoomCard(data.room);
              } else {
                alert(data.message || 'Could not update cleaning status.');
              }
            })
            .catch(function (err) {
              console.error('[layout] mark clean/dirty fetch error:', err);
              alert('Network error — could not reach the server.');
            });
        } else {
          handleReservationAction(item.action, roomId, card.dataset.reservationId || '');
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

  // ─── WebSocket / Polling ────────────────────────────────────────────
  let socket = null;
  let reconnectAttempts = 0;
  let reconnectTimer = null;
  const MAX_RECONNECT_DELAY_MS = 30000;
  let wsEnabled = true;

  function scheduleReconnect() {
    if (!wsEnabled) return;
    if (reconnectTimer) return;
    const delay = Math.min(1000 * Math.pow(1.5, reconnectAttempts), MAX_RECONNECT_DELAY_MS);
    reconnectAttempts++;
    reconnectTimer = setTimeout(function () {
      reconnectTimer = null;
      connect();
    }, delay);
  }

  function connect() {
    if (!wsEnabled) return;
    try {
      socket = new WebSocket(WS_URL);
    } catch (err) {
      console.warn('[layout] Room sync WebSocket unavailable, falling back to polling.');
      wsEnabled = false;
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
        msg.rooms.forEach(window.updateRoomCard);
      }
      if (msg && msg.type === 'reservations_changed' && Array.isArray(msg.reservations)) {
        msg.reservations.forEach(window.updateRoomCardReservation);
      }
    });

    socket.addEventListener('close', function () {
      if (wsEnabled) scheduleReconnect();
    });
    socket.addEventListener('error', function () {
      console.warn('[layout] WebSocket error, falling back to polling.');
      wsEnabled = false;
      if (socket) socket.close();
    });
  }

  connect();

  // ─── AJAX polling — syncs Layout with Calendar, Reports & Reservations ──
  var branchFromUrl = (function() {
    var m = window.location.search.match(/[?&]branch=([^&]+)/);
    return m ? decodeURIComponent(m[1]) : 'mtv';
  })();

  var lastHash = '';
  var pollTimer = null;

  window.pollNow = function() {
    fetch('/admin/process_layout_poll.php?branch=' + encodeURIComponent(branchFromUrl))
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data.success) return;

        var hash = JSON.stringify(data.rooms) + JSON.stringify(data.reservations);
        if (hash === lastHash) return;
        lastHash = hash;

        if (Array.isArray(data.rooms)) {
          data.rooms.forEach(function(room) { window.updateRoomCard(room); });
        }

        if (Array.isArray(data.reservations)) {
          data.reservations.forEach(function(resv) { window.updateRoomCardReservation(resv); });
        }

        var countEl = document.getElementById('rlCount');
        if (countEl && data.rooms) countEl.textContent = data.rooms.length + ' rooms';
      })
      .catch(function() { /* silent */ });
  };

  window.triggerLayoutPoll = function() {
    if (pollTimer) {
      clearTimeout(pollTimer);
      pollTimer = null;
    }
    window.pollNow();
    pollTimer = setTimeout(function() {
      pollTimer = null;
      window.pollNow();
    }, 1000);
  };

  setInterval(window.pollNow, 10000);
  setTimeout(window.pollNow, 3000);

  // ─── Legend filtering (if present) ──────────────────────────────────
  (function wireLayoutLegend() {
    const legendItems = document.querySelectorAll('#layoutLegend .layout-legend__item');
    let activeFilter = null;

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

      clearLegendHighlights();
      const target = document.querySelector(`#layoutLegend .layout-legend__item[data-status="${status}"]`);
      if (target) target.classList.add('active-filter');
      activeFilter = { status };
    }

    legendItems.forEach(item => {
      item.addEventListener('click', function(e) {
        const status = this.dataset.status;
        if (!status) return;
        if (activeFilter && activeFilter.status === status) {
          filterRooms('all');
          return;
        }
        filterRooms(status);
      });
    });

    window.resetLayoutLegend = function() { filterRooms('all'); };
  })();

})();