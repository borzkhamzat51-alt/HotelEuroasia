/**
 * Bluebookers – Front Desk Management (Admin Layout)
 * Fully interactive inline-editing dashboard with clean guest dossier.
 * Now with auto‑save draft – your data stays even if you close the modal.
 */
(function() {
  'use strict';

  const roomModal = document.getElementById('roomModal');
  const modalContent = document.getElementById('modalContent');
  const modalClose = document.getElementById('modalClose');
  
  const isAdmin = document.body.dataset.admin === 'true'; 

  // ─── SVG Icons ──────────────────────────────────────────────────
  const ICONS = {
    save: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;display:inline-block;vertical-align:middle;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>`,
    walkin: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;display:inline-block;vertical-align:middle;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><line x1="12" y1="11" x2="12" y2="15"/><line x1="10" y1="13" x2="14" y2="13"/></svg>`,
    outOfOrder: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;display:inline-block;vertical-align:middle;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="15" x2="15" y2="15"/><line x1="9" y1="11" x2="15" y2="11"/><line x1="9" y1="19" x2="15" y2="19"/></svg>`,
    checkin: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;display:inline-block;vertical-align:middle;"><path d="M3 12h4l2-4 2 8 2-6 2 6 2-4 2 4h4"/><path d="M3 12v4"/><path d="M21 12v4"/></svg>`,
    checkout: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;display:inline-block;vertical-align:middle;"><path d="M13 4h6a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-6"/><path d="M9 16l4-4-4-4"/><path d="M13 12H3"/></svg>`,
    clear: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;display:inline-block;vertical-align:middle;"><path d="M20 6L9 17l-5-5"/></svg>`,
    history: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;display:inline-block;vertical-align:middle;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>`,
  };

  // ─── DRAFT SAVING ──────────────────────────────────────────────
  function getDraftKey(roomId) {
    return 'layout_draft_' + roomId;
  }

  function saveDraft(roomId) {
    const form = document.getElementById('adminRoomForm');
    if (!form) return;
    
    const formData = new FormData(form);
    const draft = {};
    for (let [key, value] of formData.entries()) {
      draft[key] = value;
    }
    const statusSelect = document.getElementById('directStatusOverride');
    if (statusSelect) {
      draft.status = statusSelect.value;
    }
    try {
      sessionStorage.setItem(getDraftKey(roomId), JSON.stringify(draft));
    } catch (e) {
      // Silently fail if storage is full
    }
  }

  function loadDraft(roomId) {
    try {
      const raw = sessionStorage.getItem(getDraftKey(roomId));
      if (!raw) return null;
      return JSON.parse(raw);
    } catch (e) {
      return null;
    }
  }

  function clearDraft(roomId) {
    try {
      sessionStorage.removeItem(getDraftKey(roomId));
    } catch (e) {
      // Silently fail
    }
  }

  // ─── Debounce helper ─────────────────────────────────────────────
  function debounce(fn, delay) {
    let timer = null;
    return function(...args) {
      clearTimeout(timer);
      timer = setTimeout(() => fn.apply(this, args), delay);
    };
  }

  // ─── Helper: format date display ────────────────────────────────
  function formatDateDisplay(checkIn, checkOut, status) {
    if (status === 'available' || status === 'maintenance') return '';
    if (!checkIn || !checkOut) return '';
    return new Date(checkIn).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) +
           ' - ' +
           new Date(checkOut).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  }

  // ─── Render card from its dataset ────────────────────────────────
  function renderCard(card) {
    const status = card.dataset.status || 'available';
    const guestName = card.dataset.guestName || '';
    const checkIn = card.dataset.checkIn || '';
    const checkOut = card.dataset.checkOut || '';
    const cleaning = card.dataset.cleaning || 'Clean';
    const isDirty = status === 'available' && cleaning !== 'Clean';

    card.className = card.className.split(' ').filter(c => !c.startsWith('status-')).join(' ');
    card.classList.add('status-' + status);
    if (isDirty) card.classList.add('room-card--dirty');
    else card.classList.remove('room-card--dirty');

    const datesEl = card.querySelector('.rc-dates');
    if (datesEl) {
      if (status === 'available') {
        datesEl.textContent = isDirty ? 'Needs Cleaning' : 'Vacant - Ready';
      } else if (status === 'maintenance') {
        datesEl.textContent = 'Out of Order';
      } else if (checkIn && checkOut) {
        datesEl.textContent = formatDateDisplay(checkIn, checkOut, status);
      } else {
        datesEl.textContent = '—';
      }
    }

    let guestEl = card.querySelector('.rc-guest');
    if (guestName && (status === 'occupied' || status === 'reserved')) {
      if (!guestEl) {
        const content = card.querySelector('.rc-content');
        const newGuest = document.createElement('div');
        newGuest.className = 'rc-guest';
        newGuest.style.cssText = 'font-weight:600; font-size:0.9rem; color:var(--blue-700); margin-top:4px;';
        content.appendChild(newGuest);
        guestEl = newGuest;
      }
      guestEl.textContent = guestName;
    } else {
      if (guestEl) guestEl.remove();
    }
  }

  // ─── Update card with fresh data from server ─────────────────────
  function updateCardFromData(card, data) {
    const room = data.room;
    const res = data.reservation;

    card.dataset.status = room.room_status;
    card.dataset.cleaning = room.cleaning_status;
    card.dataset.maintenance = room.maintenance_status;
    card.dataset.lastOccupancy = room.last_occupancy || '';
    card.dataset.notes = room.staff_notes || '';

    if (res) {
      card.dataset.guestName = res.guest_full_name || '';
      card.dataset.checkIn = res.check_in || '';
      card.dataset.checkOut = res.check_out || '';
      card.dataset.phone = res.contact_number || '';
      card.dataset.email = res.email || '';
      card.dataset.pax = res.num_adults || 1;
      card.dataset.address = res.address || '';
      card.dataset.validIdType = res.valid_id_type || '';
      card.dataset.validIdNumber = res.valid_id_number || '';
      card.dataset.paymentMethod = res.payment_method || '';
      card.dataset.amountPaid = res.amount_paid || 0;
      card.dataset.totalAmount = res.total_amount || 0;
      card.dataset.securityDeposit = res.security_deposit || 0;
      card.dataset.roomRate = res.room_rate || 0;
      card.dataset.specialRequests = res.special_requests || '';
    } else {
      card.dataset.guestName = '';
      card.dataset.checkIn = '';
      card.dataset.checkOut = '';
      card.dataset.phone = '';
      card.dataset.email = '';
      card.dataset.pax = '';
      card.dataset.address = '';
      card.dataset.validIdType = '';
      card.dataset.validIdNumber = '';
      card.dataset.paymentMethod = '';
      card.dataset.amountPaid = 0;
      card.dataset.totalAmount = 0;
      card.dataset.securityDeposit = 0;
      card.dataset.roomRate = 0;
      card.dataset.specialRequests = '';
    }

    renderCard(card);
    clearDraft(card.dataset.roomId);
  }

  // ─── Live Balance Calculator ──────────────────────────────────────
  function wireBalance() {
    const form = document.getElementById('adminRoomForm');
    if (!form) return;
    
    const totalInput = form.querySelector('input[name="total_amount"]');
    const paidInput = form.querySelector('input[name="amount_paid"]');
    const balanceEl = document.getElementById('liveBalance');
    
    if (!totalInput || !paidInput || !balanceEl) return;

    function recalcBalance() {
      const total = parseFloat(totalInput.value) || 0;
      const paid = parseFloat(paidInput.value) || 0;
      const remaining = total - paid;
      
      balanceEl.textContent = '₱' + remaining.toLocaleString(undefined, { 
        minimumFractionDigits: 2, 
        maximumFractionDigits: 2 
      });
      
      if (remaining > 0) {
        balanceEl.style.color = '#d93025';
      } else if (remaining === 0 && total > 0) {
        balanceEl.style.color = '#1e8e3e';
      } else {
        balanceEl.style.color = 'var(--ink-500)';
      }
    }

    totalInput.addEventListener('input', recalcBalance);
    paidInput.addEventListener('input', recalcBalance);
    recalcBalance();
  }

  // ─── Open Admin Console ──────────────────────────────────────────
  function openAdminConsole(card, overrideStatus = null) {
    if (!isAdmin) return;

    const roomId = card.dataset.roomId;
    const number = card.dataset.roomNumber;
    const type = (card.dataset.typeMain || '') + ' ' + (card.dataset.typeSub || '');
    const status = overrideStatus || card.dataset.status || 'available';
    
    const draft = loadDraft(roomId);
    
    const guestName = draft?.guest_name || card.dataset.guestName || '';
    const phone = draft?.phone || card.dataset.phone || '';
    const email = draft?.email || card.dataset.email || '';
    const address = draft?.address || card.dataset.address || '';
    const validIdType = draft?.valid_id_type || card.dataset.validIdType || '';
    const validIdNumber = draft?.valid_id_number || card.dataset.validIdNumber || '';
    const pax = draft?.pax || card.dataset.pax || 1;
    const ref = draft?.ref || card.dataset.ref || '';
    const paymentMethod = draft?.payment_method || card.dataset.paymentMethod || '';
    const amountPaid = draft?.amount_paid || card.dataset.amountPaid || 0;
    const totalAmount = draft?.total_amount || card.dataset.totalAmount || 0;
    const securityDeposit = draft?.security_deposit || card.dataset.securityDeposit || 0;
    const roomRate = draft?.room_rate || card.dataset.roomRate || 0;
    const specialRequests = draft?.special_requests || card.dataset.specialRequests || '';
    const checkIn = draft?.check_in || card.dataset.checkIn || '';
    const checkOut = draft?.check_out || card.dataset.checkOut || '';
    const lastOccupancy = draft?.last_occupancy || card.dataset.lastOccupancy || '';
    const cleaning = draft?.cleaning || card.dataset.cleaning || 'Clean';
    const maintenance = draft?.maintenance_status || card.dataset.maintenance || 'Cleared';
    const notes = draft?.notes || card.dataset.notes || '';
    
    const draftStatus = draft?.status || null;
    const effectiveStatus = draftStatus || overrideStatus || card.dataset.status || 'available';

    const displayStatus = (effectiveStatus === 'available' && cleaning !== 'Clean') ? 'needs_cleaning' : effectiveStatus;
    const initialBalance = parseFloat(totalAmount) - parseFloat(amountPaid);

    let html = `
      <form id="adminRoomForm">
        <div class="pms-header">
          <div>
            <p class="pms-eyebrow">Management Console ${draft ? '📝 Draft' : ''}</p>
            <h2 style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
              <span>Room ${number}</span>
              <select id="directStatusOverride" name="status" class="pms-status-dropdown status-${displayStatus}">
                <option value="available" ${displayStatus === 'available' ? 'selected' : ''}>Available</option>
                <option value="needs_cleaning" ${displayStatus === 'needs_cleaning' ? 'selected' : ''}>Needs Cleaning</option>
                <option value="occupied" ${effectiveStatus === 'occupied' ? 'selected' : ''}>Occupied</option>
                <option value="reserved" ${effectiveStatus === 'reserved' ? 'selected' : ''}>Reserved</option>
                <option value="maintenance" ${effectiveStatus === 'maintenance' ? 'selected' : ''}>Out of Order</option>
              </select>
            </h2>
          </div>
        </div>
        <div class="pms-body">
          <input type="hidden" name="action" value="save_room_data">
          <input type="hidden" name="room_id" value="${roomId}">
    `;

    html += `
      <div class="pms-section">
        <h3>Guest Dossier</h3>
        <div class="pms-grid">
          <div class="pms-data">
            <label>Full Name *</label>
            <input type="text" name="guest_name" class="pms-input" value="${guestName}" placeholder="Enter guest name">
          </div>
          <div class="pms-data">
            <label>Contact Number</label>
            <input type="text" name="phone" class="pms-input" value="${phone}" placeholder="Phone number">
          </div>
          <div class="pms-data">
            <label>Email Address</label>
            <input type="email" name="email" class="pms-input" value="${email}" placeholder="Email address">
          </div>
          <div class="pms-data">
            <label>Address</label>
            <input type="text" name="address" class="pms-input" value="${address}" placeholder="Address">
          </div>
          <div class="pms-data">
            <label>Valid ID Type</label>
            <input type="text" name="valid_id_type" class="pms-input" value="${validIdType}" placeholder="e.g. Passport, Driver's License">
          </div>
          <div class="pms-data">
            <label>Valid ID Number</label>
            <input type="text" name="valid_id_number" class="pms-input" value="${validIdNumber}" placeholder="ID number">
          </div>
          <div class="pms-data">
            <label>Number of Guests</label>
            <input type="number" name="pax" class="pms-input" value="${pax}" min="1" placeholder="1">
          </div>
          <div class="pms-data">
            <label>Booking Reference</label>
            <input type="text" name="ref" class="pms-input" value="${ref}" placeholder="Auto-generated if empty">
          </div>
        </div>
      </div>
    `;

    html += `
      <div class="pms-section">
        <h3>Reservation Details</h3>
        <div class="pms-grid">
          <div class="pms-data">
            <label>Check-in Date</label>
            <input type="date" name="check_in" class="pms-input" value="${checkIn}">
          </div>
          <div class="pms-data">
            <label>Check-out Date</label>
            <input type="date" name="check_out" class="pms-input" value="${checkOut}">
          </div>
          <div class="pms-data">
            <label>Room Rate (₱)</label>
            <input type="number" step="0.01" name="room_rate" class="pms-input" value="${roomRate}">
          </div>
          <div class="pms-data">
            <label>Security Deposit (₱)</label>
            <input type="number" step="0.01" name="security_deposit" class="pms-input" value="${securityDeposit}">
          </div>
          <div class="pms-data">
            <label>Total Amount (₱)</label>
            <input type="number" step="0.01" name="total_amount" class="pms-input" value="${totalAmount}">
          </div>
          <div class="pms-data">
            <label>Amount Paid (₱)</label>
            <input type="number" step="0.01" name="amount_paid" class="pms-input" value="${amountPaid}">
          </div>
          <div class="pms-data">
            <label>Payment Method</label>
            <select name="payment_method" class="pms-select">
              <option value="">— Select —</option>
              <option value="cash" ${paymentMethod === 'cash' ? 'selected' : ''}>Cash</option>
              <option value="gcash" ${paymentMethod === 'gcash' ? 'selected' : ''}>GCash</option>
              <option value="bank_transfer" ${paymentMethod === 'bank_transfer' ? 'selected' : ''}>Bank Transfer</option>
              <option value="card" ${paymentMethod === 'card' ? 'selected' : ''}>Credit/Debit Card</option>
            </select>
          </div>
        </div>
        
        <div class="pms-balance">
          <span style="font-weight:600;">Amount Due:</span>
          <strong id="liveBalance" style="font-size:1.1rem; margin-left:4px;">
            ₱${initialBalance.toFixed(2)}
          </strong>
          <span style="font-size:0.75rem; color:var(--ink-500); margin-left:8px;">
            (auto-calculated)
          </span>
        </div>
      </div>
    `;

    html += `
      <div class="pms-section">
        <h3>Additional Information</h3>
        <div class="pms-grid">
          <div class="pms-data" style="grid-column: 1 / -1;">
            <label>Special Requests</label>
            <input type="text" name="special_requests" class="pms-input" value="${specialRequests}" placeholder="Any special requests?">
          </div>
          <div class="pms-data" style="grid-column: 1 / -1;">
            <label>Staff Notes</label>
            <input type="text" name="notes" class="pms-input" value="${notes}" placeholder="Enter any operational notes here...">
          </div>
        </div>
      </div>
    `;

    html += `</div><div class="pms-footer">`; 
    html += `<button type="submit" class="btn btn--primary" id="saveRoomBtn">${ICONS.save} Save</button>`;

    if (effectiveStatus === 'available') {
      html += `<button type="button" class="btn btn--secondary action-walkin">${ICONS.walkin} Walk-In</button>`;
      html += `<button type="button" class="btn btn--outline action-maintenance">${ICONS.outOfOrder} Out of Order</button>`;
    } else if (effectiveStatus === 'reserved') {
      html += `<button type="button" class="btn btn--outline action-checkin" style="border-color:#1e8e3e; color:#1e8e3e;">${ICONS.checkin} Check In</button>`;
    } else if (effectiveStatus === 'occupied') {
      html += `<button type="button" class="btn btn--outline action-checkout" style="border-color:#d93025; color:#d93025;">${ICONS.checkout} Check Out</button>`;
    } else if (effectiveStatus === 'maintenance') {
      html += `<button type="button" class="btn btn--outline action-clear-maint">${ICONS.clear} Clear Out of Order</button>`;
    }

    html += `<button type="button" class="btn btn--text action-history">${ICONS.history} History</button></div></form>`;

    modalContent.innerHTML = html;
    roomModal.classList.add('is-pms-dashboard');
    roomModal.hidden = false;

    // ─── Wire up live balance ──────────────────────────────────────
    wireBalance();

    // ─── Bind Interactivity ──────────────────────────────────────────
    const form = modalContent.querySelector('#adminRoomForm');
    const statusSelect = modalContent.querySelector('#directStatusOverride');

    // ─── Auto-save draft ────────────────────────────────────────────
    const saveDraftDebounced = debounce(() => saveDraft(roomId), 300);

    form.querySelectorAll('input, select, textarea').forEach(field => {
      field.addEventListener('input', saveDraftDebounced);
      field.addEventListener('change', saveDraftDebounced);
    });

    // ─── Status dropdown handler ─────────────────────────────────────
    if (statusSelect) {
      statusSelect.addEventListener('change', function(e) {
        const newStatus = e.target.value;
        saveDraft(roomId);
        
        const originalText = statusSelect.value;
        statusSelect.disabled = true;

        const formData = new FormData();
        formData.append('action', 'update_status');
        formData.append('room_id', roomId);
        formData.append('new_status', newStatus);

        fetch('/process_room_action.php', { method: 'POST', body: formData })
          .then(res => res.json())
          .then(data => {
            statusSelect.disabled = false;
            if (data.success) {
              clearDraft(roomId);
              if (data.data) {
                updateCardFromData(card, data.data);
              } else {
                const roomStatus = newStatus === 'needs_cleaning' ? 'available' : newStatus;
                card.dataset.status = roomStatus;
                if (newStatus === 'needs_cleaning') {
                  card.dataset.cleaning = 'Pending';
                } else if (newStatus === 'available') {
                  card.dataset.cleaning = 'Clean';
                }
                renderCard(card);
              }
              openAdminConsole(card, newStatus === 'needs_cleaning' ? 'available' : newStatus);
            } else {
              alert('Error: ' + data.message);
              statusSelect.value = originalText;
            }
          })
          .catch(err => {
            statusSelect.disabled = false;
            console.error('Status update error:', err);
            alert('Network error. Please try again.');
            statusSelect.value = originalText;
          });
      });
    }

    // ─── Form submit (Save) ──────────────────────────────────────────
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      const submitBtn = form.querySelector('#saveRoomBtn');
      const originalHTML = submitBtn.innerHTML;
      submitBtn.innerHTML = ICONS.save + ' Saving...';
      submitBtn.disabled = true;

      const formData = new FormData(form);

      fetch('/process_room_action.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            clearDraft(roomId);
            if (data.data) {
              updateCardFromData(card, data.data);
            } else {
              renderCard(card);
            }
            closeModal();
          } else {
            alert('Error: ' + data.message);
            submitBtn.innerHTML = originalHTML;
            submitBtn.disabled = false;
          }
        })
        .catch(err => {
          alert('Network error: ' + err.message);
          console.error(err);
          submitBtn.innerHTML = originalHTML;
          submitBtn.disabled = false;
        });
    });

    // ─── Quick Actions ──────────────────────────────────────────────
    function attachQuickAction(selector, actionType, confirmMsg, targetStatus) {
      const btn = modalContent.querySelector(selector);
      if (!btn) return;
      btn.addEventListener('click', () => {
        if (!confirm(confirmMsg)) return;
        const originalHTML = btn.innerHTML;
        btn.innerHTML = '⏳ Processing...';
        btn.disabled = true;
        const formData = new FormData();
        formData.append('action', actionType);
        formData.append('room_id', roomId);
        fetch('/process_room_action.php', { method: 'POST', body: formData })
          .then(res => res.json())
          .then(data => {
            if (data.success) {
              clearDraft(roomId);
              if (data.data) {
                updateCardFromData(card, data.data);
              } else {
                card.dataset.status = targetStatus;
                if (actionType === 'check_out') {
                  card.dataset.cleaning = 'Pending';
                  card.dataset.guestName = '';
                  card.dataset.checkIn = '';
                  card.dataset.checkOut = '';
                  card.dataset.phone = '';
                  card.dataset.email = '';
                  card.dataset.address = '';
                  card.dataset.validIdType = '';
                  card.dataset.validIdNumber = '';
                  card.dataset.paymentMethod = '';
                  card.dataset.amountPaid = 0;
                  card.dataset.totalAmount = 0;
                  card.dataset.securityDeposit = 0;
                  card.dataset.roomRate = 0;
                  card.dataset.specialRequests = '';
                }
                renderCard(card);
              }
              openAdminConsole(card, targetStatus);
            } else {
              alert('Error: ' + data.message);
              btn.innerHTML = originalHTML;
              btn.disabled = false;
            }
          })
          .catch(err => {
            alert('Network error: ' + err.message);
            console.error(err);
            btn.innerHTML = originalHTML;
            btn.disabled = false;
          });
      });
    }

    attachQuickAction('.action-checkin', 'check_in', 'Confirm check-in for this guest?', 'occupied');
    attachQuickAction('.action-checkout', 'check_out', 'Check out this guest?', 'available');
    attachQuickAction('.action-maintenance', 'set_maintenance', 'Mark this room out of order?', 'maintenance');
    attachQuickAction('.action-clear-maint', 'clear_maintenance', 'Mark room available?', 'available');

    const walkinBtn = modalContent.querySelector('.action-walkin');
    if (walkinBtn) {
      walkinBtn.addEventListener('click', () => {
        clearDraft(roomId);
        openAdminConsole(card, 'occupied');
      });
    }

    const historyBtn = modalContent.querySelector('.action-history');
    if (historyBtn) {
      historyBtn.addEventListener('click', function() {
        fetch('/process_room_action.php?action=get_history&room_id=' + roomId)
          .then(res => res.json())
          .then(data => {
            if (data.success) {
              showHistoryModal(roomId, data.history);
            } else {
              alert('Error loading history: ' + data.message);
            }
          })
          .catch(err => {
            alert('Network error: ' + err.message);
            console.error(err);
          });
      });
    }

    // ─── Auto‑checkout (30 days) ─────────────────────────────────────
    const checkInInput = form.querySelector('input[name="check_in"]');
    const checkOutInput = form.querySelector('input[name="check_out"]');
    if (checkInInput && checkOutInput) {
      function setCheckout() {
        const dateVal = checkInInput.value;
        if (!dateVal) return;
        const d = new Date(dateVal);
        d.setDate(d.getDate() + 30);
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        checkOutInput.value = year + '-' + month + '-' + day;
        saveDraft(roomId);
      }
      if (checkInInput.value) setCheckout();
      checkInInput.addEventListener('change', setCheckout);
    }
  }

  // ─── Show History Modal ──────────────────────────────────────────
  function showHistoryModal(roomId, history) {
    const existing = document.getElementById('historyModal');
    if (existing) existing.remove();

    const overlay = document.createElement('div');
    overlay.id = 'historyModal';
    overlay.className = 'modal-overlay';
    overlay.style.display = 'flex';
    overlay.style.alignItems = 'center';
    overlay.style.justifyContent = 'center';
    overlay.style.position = 'fixed';
    overlay.style.inset = '0';
    overlay.style.background = 'rgba(22, 50, 79, 0.4)';
    overlay.style.backdropFilter = 'blur(8px)';
    overlay.style.zIndex = '10000';

    let historyHtml = '<div class="modal" style="max-width:600px; max-height:80vh; overflow-y:auto; background:white; border-radius:24px; padding:30px;">';
    historyHtml += '<h2 style="font-family:Playfair Display,serif; margin-bottom:16px;">Room ' + roomId + ' History</h2>';
    if (history.length === 0) {
      historyHtml += '<p>No activity recorded for this room.</p>';
    } else {
      historyHtml += '<ul style="list-style:none; padding:0; margin:0;">';
      history.forEach(function(entry) {
        const who = entry.full_name || entry.username || 'Unknown';
        const when = new Date(entry.created_at.replace(' ', 'T')).toLocaleString();
        historyHtml += '<li style="padding:10px 0; border-bottom:1px solid #eee;">';
        historyHtml += '<strong>' + entry.action.charAt(0).toUpperCase() + entry.action.slice(1) + '</strong> by ' + who + ' — ' + when;
        if (entry.details) historyHtml += '<br><span style="color:#666; font-size:0.9rem;">' + entry.details + '</span>';
        historyHtml += '</li>';
      });
      historyHtml += '</ul>';
    }
    historyHtml += '<button class="btn btn--secondary" style="margin-top:16px;" onclick="this.closest(\'.modal-overlay\').remove()">Close</button>';
    historyHtml += '</div>';

    overlay.innerHTML = historyHtml;
    document.body.appendChild(overlay);
  }

  // ─── Attach Click Listeners to Room Cards ──────────────────────
  document.querySelectorAll('.room-card').forEach(card => {
    card.addEventListener('click', function() {
      openAdminConsole(this);
    });
  });

  // ─── Close modal logic ──────────────────────────────────────────
  function closeModal() {
    roomModal.hidden = true;
    setTimeout(() => roomModal.classList.remove('is-pms-dashboard'), 300);
  }
  if(modalClose) modalClose.addEventListener('click', closeModal);
  if(roomModal) { roomModal.addEventListener('click', function(e) { if (e.target === this) closeModal(); }); }
  document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });

})();