/**
 * Bluebookers — Calendar (Reservations)
 * ... (all previous comments)
 * Fixed drag: moving a bar preserves its duration (width) exactly.
 */
(function () {
  'use strict';

  const cfg = window.BB_CALENDAR;
  const STORAGE_KEY     = 'bb_selected_date';
  const STORAGE_BAR_KEY = 'bb_selected_bar';

  // ─── PERSISTENT CALENDAR STATE ─────────────────────────────────────
  // Stores bar ID, selected date, and scroll position in localStorage so
  // the calendar returns to exactly where the user was after any refresh.
  // localStorage is used (not sessionStorage) so state survives across
  // browser sessions, not just page reloads.
  const CAL_STATE_KEY = 'bb_cal_state_' + (cfg.branch || 'default');

  // Raised during programmatic smooth scrolling so the topScroll↔wrap
  // bidirectional sync doesn't hard-set wrap.scrollLeft and cancel the
  // smooth animation mid-flight (see wireTopScroll below).
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

  // Throttled scroll-position saver so we don't thrash localStorage on
  // every pixel of scroll movement.
  let _scrollSaveTimer = null;
  function saveScrollPosition() {
    if (_scrollSaveTimer) return;
    _scrollSaveTimer = setTimeout(function() {
      _scrollSaveTimer = null;
      const wrap = document.querySelector('.cal-grid-wrap');
      if (wrap) saveCalState({ scrollLeft: wrap.scrollLeft, scrollTop: window.scrollY });
    }, 200);
  }

  // Also save vertical scroll when the window scrolls
  window.addEventListener('scroll', saveScrollPosition, { passive: true });

  // ─── SCROLL POSITION CALCULATORS ──────────────────────────────────
  //
  // WHY NOT getBoundingClientRect():
  //   When an element is scrolled out of view inside overflow:auto,
  //   browsers clip getBoundingClientRect() to the container's visible
  //   bounds — the reported left/top are clamped, not the true layout
  //   position. This makes the horizontal target come out as ~0, so
  //   wrap.scrollTo() does nothing visible.
  //
  // THE FIX — walk offsetParent to .cal-grid:
  //   .cal-grid has position:relative, so it IS in the offsetParent
  //   chain. A slot's offsetLeft summed up to .cal-grid equals its
  //   true content position within the scroll container, completely
  //   independent of viewport clipping or current scroll state.

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

  // Absolute page-level top (for window.scrollTo vertical centering).
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

  // Mirrors cal_bar_icon() in reservations.php exactly, so a bar's icon
  // stays correct after an in-place status change (no reload).
  const BAR_ICONS = {
    checked_in: '<svg viewBox="0 0 24 24" class="cal-bar__icon" fill="none" aria-hidden="true"><path d="M5 4h6a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M19 12H9m0 0 3.5-3.5M9 12l3.5 3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    checked_out: '<svg viewBox="0 0 24 24" class="cal-bar__icon" fill="none" aria-hidden="true"><path d="M13 4H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 12h10m0 0-3.5-3.5M19 12l-3.5 3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    cancelled: '<svg viewBox="0 0 24 24" class="cal-bar__icon" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path d="m9 9 6 6m0-6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    reserved: '<svg viewBox="0 0 24 24" class="cal-bar__icon" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
  };

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

  // --- Form rendering ----------------------------------
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

  // ─── FORM SUBMIT – Auto‑close modal on success ────────────────────
  function wireFormSubmit(isEdit, id) {
    const form = document.getElementById('resvForm');
    // ── Dirty room guard ──────────────────────────────────────────
    // Warn immediately when the room dropdown changes to a dirty room,
    // and block submission if one is somehow selected.
    const roomSel = form.querySelector('[name="room_id"]');
    function isDirtyRoom(roomId) {
      const room = cfg.rooms.find(function(r) { return String(r.id) === String(roomId); });
      return room && room.room_status === 'available' && room.cleaning_status !== 'Clean';
    }
    if (roomSel) {
      roomSel.addEventListener('change', function() {
        if (isDirtyRoom(this.value)) {
          alert('This room is Vacant Dirty. Please mark it as clean before creating a reservation.');
          // Reset to blank so the user must pick a different room
          this.value = '';
        }
      });
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      // Block new reservations on dirty rooms (edit of existing resv is OK)
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
            // Remove the bar first, then refresh — updateRoomSidebar
            // recomputes "has bookings this month" by counting bars
            // still present in the track, so ordering matters here.
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

  // ─── UI Update Helpers ─────────────────────────────────────────────
  function updateUIFromServer(serverResv) {
    if (!serverResv || !serverResv.id) return;

    const id = serverResv.id;
    let bar = document.querySelector('.cal-bar[data-reservation-id="' + id + '"]');

    // A checked-out stay is over — remove its bar rather than just
    // restyling it, so the grid only shows active/upcoming bookings.
    // This is deliberately specific to checked_out: cancelled keeps its
    // existing muted-grey/strikethrough treatment and stays visible,
    // that distinction was an earlier, separate design decision and
    // isn't being changed here. If no bar exists yet (e.g. this arrived
    // via WS before any bar was ever created for it), there's nothing
    // to do either way.
    if (serverResv.status === 'checked_out') {
      if (bar) bar.remove();
      return;
    }

    if (!bar) {
      // No bar exists for this reservation yet — almost always because
      // it was just created. Previously this function only ever updated
      // an existing bar, so a brand-new reservation simply never
      // appeared on the grid until the next full page reload. Build the
      // element now so "Created" actually shows up live, matching how
      // drag/resize/status updates already do.
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

    // A freshly-created bar has no icon/name spans yet — build the full
    // inner structure once rather than trying to patch pieces that don't
    // exist. Updating an existing bar still goes through the same path
    // afterward (icon swap, class list, position), so the two cases stay
    // in lockstep instead of slowly drifting apart as two code paths.
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
    // Brief confirmation flash so an in-place update (no reload) still
    // gives clear visual feedback that the save landed.
    bar.classList.remove('cal-bar--just-updated');
    void bar.offsetWidth; // restart the animation if it's already mid-flash
    bar.classList.add('cal-bar--just-updated');

    // If the room changed — via the edit form's dropdown or a context-menu
    // action, as opposed to a drag (which already reparents live during
    // the gesture itself) — move the bar into the correct row's track so
    // it doesn't end up sitting under the wrong room with the right dates
    // but the wrong vertical position.
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
        // Deliberately unclamped and symmetric — matching dateOffset() in
        // wireBarInteractions exactly. Clamping only startOffset while
        // leaving endOffset free (the previous behavior) could produce a
        // mismatched, collapsed, or even negative-width bar whenever the
        // two ended up on opposite sides of that asymmetry.
        const startOffset = Math.round((checkIn - monthDate) / 86400000);
        const endOffset = Math.round((checkOut - monthDate) / 86400000);
        const duration = endOffset - startOffset;
        const totalDays = slots.length;
        bar.style.left = (startOffset / totalDays * 100) + '%';
        bar.style.width = (duration / totalDays * 100) + '%';
        bar.title = serverResv.guest_full_name + ' • ' + serverResv.check_in + ' to ' + serverResv.check_out + ' • ' + (cfg.statusLabels[serverResv.status] || serverResv.status);
      }
    }

    // Drag/resize interactions are still wired per-element (a gesture
    // needs to own its own state for its whole lifecycle), so a
    // freshly-created bar needs this exactly once. Click-to-select and
    // right-click are handled separately via delegation further down, so
    // they need nothing here regardless of when this bar was created.
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

  /**
   * Mirrors cal_room_status_label() / cal_room_status_key() / cal_room_code()
   * / cal_room_floor() in reservations.php exactly, so the sidebar can be
   * refreshed in place with the same labels/keys/derived values the
   * server would have rendered.
   */
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

  /**
   * Refreshes one room's entire sidebar panel in place — number, code,
   * type, floor, price, status pill, availability dot, maintenance
   * badge, and the row's filterable data attributes. Previously nothing
   * ever touched this panel after page load, so it silently went stale
   * not just on reservation changes but on Layout-side room edits too
   * (renumbering, retyping, repricing, marking out of order). This is
   * the single function both the AJAX success paths and the live
   * WebSocket room-sync layer funnel through, so the two stay
   * consistent rather than drifting into two separate update code paths.
   */
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

    row.classList.toggle('maintenance', isMaintenance);
    row.dataset.statusKey = statusKey;
    row.dataset.available = isAvailableNow ? '1' : '0';
    if (hasFullDetails) {
      row.dataset.roomType = room.room_type;
      row.dataset.floor = floor;
    }

    // Occupancy ("has bookings this month") is recomputed straight from
    // the DOM rather than carried as separate server state — by this
    // point in the flow every bar in this room's track is already
    // current (updateUIFromServer/deleteReservation's bar.remove() both
    // run before this is called), so counting bars actually present is
    // always accurate without a second round-trip.
    const track = row.querySelector('.cal-row__track');
    const hasBookings = !!(track && track.querySelector('.cal-bar'));
    row.dataset.hasBookings = hasBookings ? '1' : '0';

    const labelCol = row.querySelector('.cal-label-col');
    if (labelCol && hasFullDetails) {
      labelCol.dataset.roomNumber = room.room_number;
      const numberEl = labelCol.querySelector('.cal-room-id strong');
      if (numberEl) numberEl.textContent = 'RM' + room.room_number;
      const codeEl = labelCol.querySelector('.cal-room-code');
      if (codeEl) codeEl.textContent = roomCode(room.room_type);
      const typeEl = labelCol.querySelector('.cal-room-type');
      if (typeEl) typeEl.textContent = room.room_type;
      const floorEl = labelCol.querySelector('.cal-room-floor-chip');
      if (floorEl) floorEl.textContent = 'Floor ' + floor;
      const rateEl = labelCol.querySelector('.cal-room-rate');
      if (rateEl && room.price_per_night !== undefined) {
        rateEl.textContent = '₱' + Number(room.price_per_night).toLocaleString();
      }
    }

    const pill = row.querySelector('.cal-status-pill');
    if (pill) {
      pill.className = 'cal-status-pill cal-status-pill--' + statusKey;
      pill.textContent = statusLabel;
    }
    const dot = row.querySelector('.cal-room-avail-dot');
    if (dot) {
      dot.className = 'cal-room-avail-dot ' + (isAvailableNow ? 'cal-room-avail-dot--available' : 'cal-room-avail-dot--unavailable');
      dot.title = isAvailableNow ? 'Available now' : 'Not available now';
    }

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

    // The row's filterable attributes (status/availability/occupancy/
    // type/floor) may have just changed under an active filter
    // selection — re-apply filters so a room doesn't linger visible (or
    // hidden) against its own current state.
    if (typeof window.__bbApplyCalFilters === 'function') {
      window.__bbApplyCalFilters();
    }
  }

  /**
   * Single entry point for "a reservation create/update AJAX call
   * succeeded" — updates the bar itself and every room sidebar the
   * server flagged as affected (the reservation's current room, and its
   * previous room too if it just moved). Every create/update success
   * handler in this file should call this instead of updateUIFromServer
   * directly, so the sidebar-sync requirement can't be accidentally
   * skipped by a future call site.
   */
  function handleReservationSaveSuccess(res) {
    updateUIFromServer(res.reservation);
    if (Array.isArray(res.rooms)) {
      res.rooms.forEach(updateRoomSidebar);
    }
    // Persist the just-saved reservation so that if the page reloads
    // (e.g. from a manual refresh after saving) the calendar scrolls
    // back to it automatically.
    if (res.reservation && res.reservation.id) {
      saveCalState({ barId: String(res.reservation.id), date: null });
    }
  }

  // ─── Reload with cache-buster (kept as a manual fallback / for actions
  // that don't yet return fresh data) ────────────────────────────────
  function reloadWithCacheBuster() {
    const url = new URL(window.location.href);
    url.searchParams.set('t', Date.now());
    window.location.href = url.toString();
  }

  // ─── Update room status on the left label ─────────────────────────
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
          // process_room_action.php now returns fresh room/reservation
          // data on every status change (it used to return nothing but
          // {success:true}, which is why this previously had no choice
          // but to force a full page reload to show the result).
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

  // ─── DATE SLOT HIGHLIGHTING ────────────────────────────────────────
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
      // Save both date AND room_id so restore lands on the exact cell,
      // not just the first cell on that date across all rooms.
      saveCalState({ date: selectedDate, roomId: slotEl.dataset.roomId || null, barId: null });
    } else {
      selectedSlotEl = null;
      selectedDate = null;
      clearCalState('date');
      clearCalState('roomId');
    }
  }

  // ─── SMOOTH CENTERING ─────────────────────────────────────────────
  // Horizontal: wrap.scrollTo on .cal-grid-wrap (overflow-x: auto).
  // Vertical:   window.scrollTo (.cal-grid-wrap has no overflow-y).
  // Both fire simultaneously; onDone fires when both have settled.
  function smoothCenterIn(wrap, el, onDone) {
    const pos = offsetFromGrid(el);

    // Center horizontally within the scroll container
    const targetLeft = Math.max(0, pos.left - wrap.clientWidth  / 2 + el.offsetWidth  / 2);

    // Center vertically in the page (wrap has no vertical scroll)
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
      }, 1200); // safety timeout
    }

    makeSettleWatcher(wrap,   wrap.scrollLeft, targetLeft, function() { hDone = true; checkBothDone(); });
    makeSettleWatcher(window, window.scrollY,  targetTop,  function() { vDone = true; checkBothDone(); });

    // Raise flag BEFORE scrollTo so the topScroll→wrap sync handler
    // (wireTopScroll) doesn't cancel the animation mid-flight.
    _smoothScrollActive = true;
    wrap.scrollTo({ left: targetLeft, behavior: 'smooth' });
    window.scrollTo({ top:  targetTop,  behavior: 'smooth' });

    // Clear flag once both animations have settled (handled inside
    // checkBothDone → onDone, but also via the safety timeouts above).
    // We piggyback on the existing safety timeout (1200ms) by resetting
    // after a guaranteed-safe margin.
    setTimeout(function() { _smoothScrollActive = false; }, 1400);
  }

  // ─── RESTORE FULL CALENDAR STATE ON LOAD ──────────────────────────
  // Priority: bar > slot (by room_id + date) > raw scroll position.
  // Highlight + pulse animation are applied AFTER smooth scroll settles.
  function restoreCalendarState() {
    const state = loadCalState();
    const wrap  = document.querySelector('.cal-grid-wrap');
    if (!wrap) return;

    // ── 1. Restore selected reservation bar ───────────────────────
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

    // ── 2. Restore selected date slot (matched by room_id + date) ─
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
          // Highlight first, then trigger the pulse animation on the
          // next frame so both CSS classes are active together.
          highlightSlot(targetSlot);
          requestAnimationFrame(function() {
            targetSlot.classList.remove('cal-day-slot--restored');
            void targetSlot.offsetWidth; // force reflow to restart animation
            targetSlot.classList.add('cal-day-slot--restored');
            // Clean up the animation class once it finishes (2 × 700ms)
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

    // ── 3. Fall back to raw scroll position ───────────────────────
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
    // position:fixed + appended to <body> — coordinates are plain
    // viewport coordinates (clientX/clientY), with no scroll-container
    // offset math needed and no risk of being clipped by an ancestor's
    // overflow:hidden/auto the way an absolutely-positioned menu nested
    // inside .cal-grid-wrap would be.
    contextMenu.style.cssText = 'display:none;position:fixed;z-index:10000;background:#fff;border-radius:8px;box-shadow:0 8px 30px rgba(0,0,0,0.2);padding:6px 0;min-width:200px;font-family:\'Inter\',sans-serif;';
    document.body.appendChild(contextMenu);
  }

  // Registered once, not per right-click — building this fresh inside
  // buildContextMenu() (the previous approach) silently stacked up one
  // duplicate "click outside closes the menu" listener per right-click
  // for the lifetime of the page.
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

    // Render once (off-screen-safe, display:none already set) so
    // offsetWidth/offsetHeight reflect this menu's actual content
    // (item count varies by type/status) rather than a guessed constant.
    contextMenu.style.left = '0px';
    contextMenu.style.top = '0px';
    contextMenu.style.display = 'block';
    const menuWidth = contextMenu.offsetWidth || 200;
    const menuHeight = contextMenu.offsetHeight || 100;

    // Open exactly at the cursor — like a desktop app's right-click menu
    // — then nudge back onto-screen only if it would actually overflow.
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

  // ─── Handle room status actions ───────────────────────────────────
  const ROOM_STATUS_MENU_LABELS = {
    available: 'Available (Vacant Clean)',
    needs_cleaning: 'Vacant Dirty',
    occupied: 'Occupied',
    reserved: 'Reserved',
    maintenance: 'Out of Order / Maintenance'
  };

  function handleRoomStatusAction(action) {
    const roomId = contextRoomId;
    if (!roomId) {
      alert('No room selected.');
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

  // ─── Edit Room Details (number / type / price) ─────────────────────
  // No admin-facing edit path for these fields existed in either module
  // before this — they were effectively fixed at however the room was
  // originally seeded.
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

  // ─── CONTEXT ACTION HANDLERS ─────────────────────────────────────
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

  // ─── Entry points ──────────────────────────────────────────────────
  newBtn.addEventListener('click', function () {
    renderForm(null, {});
  });

  // ─── SLOT CLICK: only highlight, store in sessionStorage ─────────
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

  // ─── ROOM LABEL CLICK: open new reservation form ──────────────────
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

  // Click on empty space to deselect slot highlight and bar selection
  document.addEventListener('click', function(e) {
    if (!e.target.closest('.cal-day-slot')) {
      highlightSlot(null);
    }
    if (!e.target.closest('.cal-bar')) {
      selectBar(null);
    }
  });

  // ─── DRAG LOGIC – Fixed to preserve width on move ────────────────
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

    // Reads the bar's CURRENT state straight from the DOM/dataset, never
    // from a cached value. This bar can be updated in place — by a drag
    // commit, a form save, or a context-menu action — without a page
    // reload (see updateUIFromServer), so resv/origStartIdx/origEndIdx/
    // duration must never be captured once and reused: that's exactly
    // what caused a second consecutive drag to compute against pre-edit
    // data (bars "reverting" or collapsing after a successful save).
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

    // (Re)creates the resize handles and the draggable styling hook based
    // on a fresh state snapshot. Called once at initial wire time, again
    // at the start of every drag, and exposed on the element itself so
    // updateUIFromServer can trigger it after a non-drag edit (status
    // change, room move via the form, etc.) changes what's draggable.
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
      // Ignore right-click (button === 2)
      if (e.button === 2) return;

      // Always start from the bar's TRUE current state — not whatever
      // was true the first time this bar was wired, and not whatever was
      // true the last time it was dragged.
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

      // If not clicking a handle and cannot move, do nothing
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

      // Get the slot index under the mouse in the current track
      const mouseIdx = getSlotUnderMouse(e.clientX, slots);

      if (dragMode === 'move') {
        // Move: keep duration constant
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

      // Check for overlap with other bars in the same room
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

      // Block dropping onto a dirty room — it must be cleaned first.
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

  // ─── Wire bars ─────────────────────────────────────────────────────
  // Drag/resize (wireBarInteractions) is still wired per-element, since a
  // drag gesture genuinely needs to own its own state for its lifetime.
  // Click-to-select and right-click are NOT wired per-element below —
  // see the delegated listeners on .cal-grid further down, which is what
  // makes both of those reliably work for every bar regardless of
  // whether it existed at page load, was reparented by a drag, or was
  // created afterward by updateUIFromServer for a brand-new reservation.
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

  // ─── DELEGATED bar click (select) + right-click (context menu) ────
  // Attached once to the grid container, which exists for the entire
  // page lifetime, rather than to each bar individually. A listener on
  // an individual bar only ever covers that one element — any bar
  // created or replaced afterward (a brand-new reservation, for
  // instance) would silently have no listener at all unless every single
  // code path that touches the DOM remembered to re-wire it.
  // e.target.closest('.cal-bar') instead means this works for any bar
  // present in the DOM at the moment of the click, full stop.
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
      } catch (err) { /* ignore unparsable bar data */ }
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

      // Right-click on a room's sidebar panel — status changes and room
      // detail editing, without leaving the Calendar. This menu type
      // existed in showContextMenu/handleRoomStatusAction already, but
      // had no event wiring anywhere actually triggering it until now.
      const label = e.target.closest('.cal-label-col[data-room-id]');
      if (label) {
        e.preventDefault();
        e.stopPropagation();
        showContextMenu(e.clientX, e.clientY, 'room', { roomId: label.dataset.roomId });
      }
    });
  }

  // ─── AUTO‑SCROLL TO UPDATED RESERVATION ON LOAD ──────────────────
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

    // Two rAF frames inside a 250ms timeout: the timeout lets the browser
    // finish HTML parsing + initial paint; the rAF chain ensures we measure
    // offsetLeft AFTER a full layout/paint cycle so the values are final.
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

  // ─── Today line, Day boundaries, Top scrollbar, Filters ────────────
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
      if (syncing || _smoothScrollActive) return; // don't interrupt smooth scroll
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

  // ─── Live room sync (WebSocket) ────────────────────────────────────
  // Connects to the ws-server/ process (see ws-server/README.md — this
  // is a separate Node.js process that must be running for this to do
  // anything; it's not required for the page to function, just for
  // changes made in OTHER open tabs to show up here without a reload).
  // Update WS_PORT below if ws-server/.env sets a non-default WS_PORT.
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
        // WebSocket constructor itself can throw synchronously for a
        // malformed URL — not expected here, but this keeps a bad
        // config from breaking the rest of the page.
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
          // Partial rows only (id/room_id/room_number/guest name/dates/
          // status/occupant counts — see ws-server's RESERVATION_FIELDS),
          // not the full reservation record. updateUIFromServer merges
          // this onto whatever the bar already has, so an existing bar
          // keeps its other fields (contact info, payment, notes); a
          // brand-new bar created from this alone will be missing those
          // until the page is reloaded — acceptable since the visible
          // bar (name/dates/status/position) is what matters live, and
          // mirrors the same scope tradeoff realtime-room-sync.js
          // already documents for room-only fields.
          msg.reservations.forEach(updateUIFromServer);
        }
      });

      socket.addEventListener('close', function () {
        scheduleReconnect();
      });

      // 'error' is always followed by 'close' per the WebSocket spec, so
      // reconnect scheduling lives in the close handler only — this
      // just keeps the failure out of the browser's uncaught-error
      // surface (it's an expected, recoverable condition: the sync
      // server simply isn't running/reachable yet).
      socket.addEventListener('error', function () {});
    }

    // If ws-server isn't deployed at all, this silently fails to
    // connect and retries with backoff forever in the background — the
    // rest of the page is fully functional either way (AJAX-driven
    // updates from this tab's own actions never depended on this).
    connect();
  })();

})();