/**
 * Bluebookers — Calendar (Reservations)
 * Drag & drop that actually sticks.
 * Now updates its own source‑of‑truth after a successful save.
 */
(function () {
  'use strict';

  const cfg = window.BB_CALENDAR;

  function formatLocalDate(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
  }

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

  // --- Form rendering ------------------------------------------------
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

    // ---- Duration and Expected Payment Date ----
    html += '<div class="resv-duration">';
    html += '<label>Stay Duration</label>';
    html += '<div class="resv-duration__display" id="stayDurationDisplay">0 Days / 0 Nights</div>';
    html += '</div>';

    html += '<div class="field">';
    html += '<label for="expected_payment_date">Expected Payment Date</label>';
    html += '<input type="date" id="expected_payment_date" name="expected_payment_date" value="' + (resv.expected_payment_date || '') + '">';
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
    wireDateCalculations();
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

  function wireDateCalculations() {
    const form = document.getElementById('resvForm');
    const checkIn = form.querySelector('[name="check_in"]');
    const checkOut = form.querySelector('[name="check_out"]');
    const expectedPayment = form.querySelector('[name="expected_payment_date"]');
    const durationDisplay = document.getElementById('stayDurationDisplay');

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

  function wireFormSubmit(isEdit, id) {
    const form = document.getElementById('resvForm');
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      const confirmMsg = isEdit
        ? 'Are you sure you want to save these changes to this reservation?'
        : 'Are you sure you want to create this reservation?';
      showConfirmDialog(confirmMsg, 'Confirm Changes').then(function (confirmed) {
        if (!confirmed) return;
        const fd = new FormData(form);
        fd.append('action', isEdit ? 'update' : 'create');
        fd.append('csrf_token', cfg.csrfToken);
        if (!fd.has('expected_payment_date')) {
          const ep = form.querySelector('[name="expected_payment_date"]');
          if (ep) fd.append('expected_payment_date', ep.value);
        }
        fetch('/process_reservation.php', { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (res.success) {
              window.location.reload();
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
            window.location.reload();
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
      const nextDay = new Date(date + 'T00:00:00');
      nextDay.setDate(nextDay.getDate() + 1);
      renderForm(null, { room_id: roomId, check_in: date, check_out: formatLocalDate(nextDay) });
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

  // ─── DRAG LOGIC ──────────────────────────────────────────────────
  function wireBarInteractions(bar) {
    let originalRow = bar.closest('.cal-row');
    let originalTrack = bar.closest('.cal-row__track');
    const isMaintenanceRow = originalRow && originalRow.classList.contains('maintenance');
    const resv = JSON.parse(bar.dataset.reservation);
    const draggable = !isMaintenanceRow && (resv.status === 'reserved' || resv.status === 'checked_in');

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

    function mouseDayIndex(clientX, track) {
      const rect = track.getBoundingClientRect();
      const slots = getSlotsForTrack(track);
      if (slots.length === 0) return 0;
      const dayWidth = rect.width / slots.length;
      const relX = clientX - rect.left;
      return relX / dayWidth;
    }

    function applyPosition(startIdx, endIdx, track) {
      const slots = getSlotsForTrack(track);
      const total = slots.length;
      bar.style.left = (startIdx / total * 100) + '%';
      bar.style.width = ((endIdx - startIdx) / total * 100) + '%';
    }

    let slots = getSlotsForTrack(originalTrack);
    let origStartIdx = dateOffset(resv.check_in, slots);
    let origEndIdx = dateOffset(resv.check_out, slots);
    const duration = origEndIdx - origStartIdx;
    const totalDays = slots.length;
    const canMove = (totalDays - duration) > 0;

    if (draggable && totalDays > 0 && canMove) {
      bar.classList.add('is-draggable');
      if (origStartIdx >= 0 && origStartIdx <= totalDays) {
        const leftHandle = document.createElement('span');
        leftHandle.className = 'cal-bar__handle cal-bar__handle--left';
        bar.appendChild(leftHandle);
      }
      if (origEndIdx >= 0 && origEndIdx <= totalDays) {
        const rightHandle = document.createElement('span');
        rightHandle.className = 'cal-bar__handle cal-bar__handle--right';
        bar.appendChild(rightHandle);
      }
    }

    let dragMode = null;
    let currentTrack = originalTrack;
    let currentRow = originalRow;
    let offsetDays = 0;
    let liveStartIdx = origStartIdx;
    let liveEndIdx = origEndIdx;
    let moved = false;

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
      applyPosition(origStartIdx, origEndIdx, currentTrack);
    }

    function computeOffset(clientX) {
      const trackRect = currentTrack.getBoundingClientRect();
      const dayWidth = trackRect.width / slots.length;
      const barLeftPx = (parseFloat(bar.style.left) || 0) / 100 * trackRect.width;
      const barLeftIdx = barLeftPx / dayWidth;
      const cursorIdx = (clientX - trackRect.left) / dayWidth;
      return Math.round(cursorIdx - barLeftIdx);
    }

    function recalculateOffset(clientX) {
      offsetDays = computeOffset(clientX);
    }

    function onPointerDown(e) {
      if (!draggable || totalDays === 0) return;
      const handleSide = e.target.classList && e.target.classList.contains('cal-bar__handle--left') ? 'left'
                        : e.target.classList && e.target.classList.contains('cal-bar__handle--right') ? 'right' : null;

      e.preventDefault();

      currentTrack = originalTrack;
      currentRow = originalRow;
      slots = getSlotsForTrack(currentTrack);
      moved = false;
      liveStartIdx = origStartIdx;
      liveEndIdx = origEndIdx;

      if (handleSide === 'left' && origStartIdx >= 0) {
        dragMode = 'resize-left';
      } else if (handleSide === 'right' && origEndIdx <= slots.length) {
        dragMode = 'resize-right';
      } else if (!handleSide && canMove) {
        dragMode = 'move';
        recalculateOffset(e.clientX);
      } else {
        dragMode = null;
      }

      if (dragMode) {
        bar.classList.add('is-dragging');
        if (dragMode !== 'move' && bar.setPointerCapture) {
          try { bar.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
        }
      }

      document.body.style.userSelect = 'none';
      document.addEventListener('pointermove', onPointerMove);
      document.addEventListener('pointerup', onPointerUp);
      e.stopPropagation();
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
        if (hoveredRow && hoveredRow !== currentRow && !hoveredRow.classList.contains('maintenance')) {
          const newTrack = hoveredRow.querySelector('.cal-row__track');
          if (newTrack) {
            newTrack.appendChild(bar);
            currentTrack = newTrack;
            currentRow = hoveredRow;
            slots = getSlotsForTrack(currentTrack);
            clearDropTarget();
            hoveredRow.classList.add('drop-target');
            recalculateOffset(e.clientX);
          }
        }
      }

      const mouseIdx = mouseDayIndex(e.clientX, currentTrack);

      if (dragMode === 'move') {
        let newStart = Math.round(mouseIdx - offsetDays);
        const maxStart = slots.length - duration;
        newStart = Math.max(0, Math.min(newStart, maxStart));
        liveStartIdx = newStart;
        liveEndIdx = liveStartIdx + duration;
      } else if (dragMode === 'resize-left') {
        let newStart = Math.round(mouseIdx);
        newStart = Math.max(0, Math.min(newStart, liveEndIdx - 1));
        liveStartIdx = newStart;
      } else if (dragMode === 'resize-right') {
        let newEnd = Math.round(mouseIdx);
        newEnd = Math.min(slots.length, Math.max(newEnd, liveStartIdx + 1));
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
        renderForm(resv, null);
        return;
      }

      if (!moved) {
        revertToOriginal();
        renderForm(resv, null);
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

        const updatedResv = Object.assign({}, resv, {
          check_in: newCheckIn,
          check_out: newCheckOut,
          room_id: parseInt(newRoomId),
          expected_payment_date: newCheckOut,
        });

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
        fd.append('expected_payment_date', newCheckOut);

        fetch('/process_reservation.php', { method: 'POST', body: fd })
          .then(function (r) {
            return r.json().catch(function () {
              throw new Error('Server returned invalid JSON. Check PHP error log.');
            });
          })
          .then(function (res) {
            if (res.success) {
              Object.assign(resv, updatedResv);
              bar.dataset.reservation = JSON.stringify(resv);
              bar.dataset.checkIn = newCheckIn;
              bar.dataset.checkOut = newCheckOut;
              bar.dataset.roomId = newRoomId;

              originalRow = currentRow;
              originalTrack = currentTrack;
              origStartIdx = liveStartIdx;
              origEndIdx = liveEndIdx;

              applyPosition(origStartIdx, origEndIdx, currentTrack);
              window.location.reload();
            } else {
              alert('Server error: ' + (res.message || 'Unknown error.'));
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

  // Wire all bars
  document.querySelectorAll('.cal-bar').forEach(function (bar) {
    try {
      wireBarInteractions(bar);
    } catch (err) {
      console.error('[calendar.js] failed to wire a bar:', err);
    }
  });

  // ─── Today line ──────────────────────────────────────────────────
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

  // ─── Day‑boundary lines (overlay) ──────────────────────────────
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

  // ─── Synced top scrollbar ──────────────────────────────────────
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
      if (syncing) return;
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

  // ─── Filters ──────────────────────────────────────────────────
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
  })();

  // ─── FLOOR NAVIGATION ──────────────────────────────────────────────
(function wireFloorNav() {
    const navBtns = document.querySelectorAll('.layout-floor-nav__btn');
    const sections = document.querySelectorAll('.layout-floor-section');

    function scrollToFloor(floor) {
        if (floor === 'all') {
            // Smooth scroll to top of room area
            const area = document.querySelector('.layout-room-area');
            if (area) area.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }
        const target = document.getElementById('floor-' + floor);
        if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    navBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const floor = this.dataset.floor;
            // Update active state
            navBtns.forEach(b => b.classList.remove('is-active'));
            this.classList.add('is-active');
            // Scroll
            scrollToFloor(floor);
            // Also filter rooms to that floor if not 'all'
            if (floor !== 'all') {
                sections.forEach(section => {
                    section.style.display = (section.dataset.floor === floor) ? '' : 'none';
                });
            } else {
                sections.forEach(section => section.style.display = '');
            }
        });
    });

    // If there's a floor param in URL, activate it
    const hash = window.location.hash;
    if (hash && hash.startsWith('#floor-')) {
        const floor = hash.replace('#floor-', '');
        const btn = document.querySelector(`.layout-floor-nav__btn[data-floor="${floor}"]`);
        if (btn) btn.click();
    }
})();

// ─── LEGEND FILTERING ───────────────────────────────────────────────
(function wireLegendFilter() {
    const legendItems = document.querySelectorAll('.layout-legend-panel__item');
    const cards = document.querySelectorAll('.room-card');
    let activeFilter = null;

    function clearHighlights() {
        legendItems.forEach(el => el.classList.remove('active-filter'));
    }

    function filterRooms(status) {
        if (status === 'all') {
            cards.forEach(c => c.style.display = '');
            clearHighlights();
            activeFilter = null;
            // Re-apply floor filter if any (we'll re-trigger floor nav)
            const activeFloorBtn = document.querySelector('.layout-floor-nav__btn.is-active');
            if (activeFloorBtn && activeFloorBtn.dataset.floor !== 'all') {
                const floor = activeFloorBtn.dataset.floor;
                document.querySelectorAll('.layout-floor-section').forEach(section => {
                    section.style.display = (section.dataset.floor === floor) ? '' : 'none';
                });
            }
            return;
        }

        let showClass = '';
        let extra = null;
        switch (status) {
            case 'available':
                showClass = 'status-available';
                extra = (card) => !card.classList.contains('room-card--dirty');
                break;
            case 'dirty':
                showClass = 'status-available';
                extra = (card) => card.classList.contains('room-card--dirty');
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
            default: return;
        }

        cards.forEach(card => {
            const hasClass = card.classList.contains(showClass);
            let show = hasClass;
            if (extra) show = show && extra(card);
            card.style.display = show ? '' : 'none';
        });

        clearHighlights();
        const target = document.querySelector(`.layout-legend-panel__item[data-status="${status}"]`);
        if (target) target.classList.add('active-filter');
        activeFilter = { status };
    }

    legendItems.forEach(item => {
        item.addEventListener('click', function() {
            const status = this.dataset.status;
            if (!status) return;
            if (activeFilter && activeFilter.status === status) {
                filterRooms('all');
                return;
            }
            filterRooms(status);
        });
    });

    // Expose to global for sync
    window.filterLayoutRooms = filterRooms;
    window.resetLayoutFilter = function() { filterRooms('all'); };
})();
