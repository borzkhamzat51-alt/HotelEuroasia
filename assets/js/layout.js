/**
 * Bluebookers – Front Desk Management (Admin Layout)
 * Fully interactive inline-editing dashboard with all operational workflows wired.
 */
(function() {
  'use strict';

  const roomModal = document.getElementById('roomModal');
  const modalContent = document.getElementById('modalContent');
  const modalClose = document.getElementById('modalClose');
  
  const isAdmin = document.body.dataset.admin === 'true'; 

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

    // Update status class
    card.className = card.className.split(' ').filter(c => !c.startsWith('status-')).join(' ');
    card.classList.add('status-' + status);
    if (isDirty) card.classList.add('room-card--dirty');
    else card.classList.remove('room-card--dirty');

    // Update rc-dates
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

    // Update rc-guest
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

    // Update all dataset attributes
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
    } else {
      card.dataset.guestName = '';
      card.dataset.checkIn = '';
      card.dataset.checkOut = '';
      card.dataset.phone = '';
      card.dataset.email = '';
      card.dataset.pax = '';
    }

    renderCard(card);
  }

  // ─── Open Admin Console ──────────────────────────────────────────
  function openAdminConsole(card, overrideStatus = null) {
    if (!isAdmin) return;

    const roomId = card.dataset.roomId;
    const number = card.dataset.roomNumber;
    const type = (card.dataset.typeMain || '') + ' ' + (card.dataset.typeSub || '');
    const status = overrideStatus || card.dataset.status || 'available';
    
    const guestName = card.dataset.guestName || '';
    const phone = card.dataset.phone || '';
    const email = card.dataset.email || '';
    const govId = card.dataset.govId || '';
    const pax = card.dataset.pax || '';
    const history = card.dataset.history || 'No previous stays';
    const ref = card.dataset.ref || '';
    const payment = card.dataset.payment || 'Unpaid';
    const checkIn = card.dataset.checkIn || '';
    const checkOut = card.dataset.checkOut || '';
    const lastOccupancy = card.dataset.lastOccupancy || '';
    const cleaning = card.dataset.cleaning || 'Clean';
    const maintenance = card.dataset.maintenance || 'Cleared';
    const notes = card.dataset.notes || '';

    const displayStatus = (status === 'available' && cleaning !== 'Clean') ? 'needs_cleaning' : status;

    let html = `
      <form id="adminRoomForm">
        <div class="pms-header">
            <div>
                <p class="pms-eyebrow">Management Console</p>
                <h2 style="display:flex; align-items:center; gap:12px;">
                  Room ${number} 
                  <select id="directStatusOverride" name="status" class="pms-status-dropdown status-${displayStatus}">
                    <option value="available" ${displayStatus === 'available' ? 'selected' : ''}>Available</option>
                    <option value="needs_cleaning" ${displayStatus === 'needs_cleaning' ? 'selected' : ''}>Needs Cleaning</option>
                    <option value="occupied" ${status === 'occupied' ? 'selected' : ''}>Occupied</option>
                    <option value="reserved" ${status === 'reserved' ? 'selected' : ''}>Reserved</option>
                    <option value="maintenance" ${status === 'maintenance' ? 'selected' : ''}>Out of Order</option>
                  </select>
                </h2>
            </div>
        </div>
        <div class="pms-body">
          <input type="hidden" name="action" value="save_room_data">
          <input type="hidden" name="room_id" value="${roomId}">
    `;

    if (status === 'occupied' || status === 'reserved') {
      html += `
        <div class="pms-section">
          <h3>Guest Dossier</h3>
          <div class="pms-grid">
            <div class="pms-data"><label>Primary Guest</label><input type="text" name="guest_name" class="pms-input" value="${guestName}" placeholder="Full Name"></div>
            <div class="pms-data"><label>Contact</label><input type="text" name="phone" class="pms-input" value="${phone}" placeholder="Phone Number"></div>
            <div class="pms-data"><label>Email</label><input type="email" name="email" class="pms-input" value="${email}" placeholder="Email Address"></div>
            <div class="pms-data"><label>Gov ID Info</label><input type="text" name="gov_id" class="pms-input" value="${govId}" placeholder="ID Number"></div>
            <div class="pms-data"><label>Number of Guests</label><input type="number" name="pax" class="pms-input" value="${pax}" placeholder="Pax"></div>
            <div class="pms-data"><label>Booking History</label><input type="text" name="history" class="pms-input" value="${history}" disabled></div>
          </div>
        </div>
        <div class="pms-section">
          <h3>Reservation Details</h3>
          <div class="pms-grid">
            <div class="pms-data"><label>Ref Number</label><input type="text" name="ref" class="pms-input" value="${ref}" placeholder="Auto-generated if empty"></div>
            <div class="pms-data"><label>Payment Status</label>
              <select name="payment" class="pms-select">
                <option value="Unpaid" ${payment === 'Unpaid' ? 'selected' : ''}>Unpaid</option>
                <option value="50% Deposit" ${payment === '50% Deposit' ? 'selected' : ''}>50% Deposit</option>
                <option value="Fully Paid" ${payment === 'Fully Paid' ? 'selected' : ''}>Fully Paid</option>
              </select>
            </div>
            <div class="pms-data"><label>Check-in Date</label><input type="date" name="check_in" class="pms-input" value="${checkIn}"></div>
            <div class="pms-data"><label>Check-out Date</label><input type="date" name="check_out" class="pms-input" value="${checkOut}"></div>
          </div>
        </div>
      `;
    } else {
      html += `
        <div class="pms-section">
          <h3>Room Status & Operations</h3>
          <div class="pms-grid">
            <div class="pms-data"><label>Room Type</label><input type="text" class="pms-input" value="${type}" disabled></div>
            <div class="pms-data"><label>Last Occupancy</label><input type="date" name="last_occupancy" class="pms-input" value="${lastOccupancy}"></div>
            <div class="pms-data"><label>Cleaning Status</label>
              <select name="cleaning" class="pms-select">
                <option value="Clean" ${cleaning === 'Clean' ? 'selected' : ''}>Clean</option>
                <option value="Pending" ${cleaning === 'Pending' ? 'selected' : ''}>Pending Cleaning</option>
                <option value="Requires Deep Clean" ${cleaning === 'Requires Deep Clean' ? 'selected' : ''}>Requires Deep Clean</option>
              </select>
            </div>
            <div class="pms-data"><label>Maintenance</label>
              <select name="maintenance_status" class="pms-select">
                <option value="Cleared" ${maintenance === 'Cleared' ? 'selected' : ''}>Cleared</option>
                <option value="Pending Repair" ${maintenance === 'Pending Repair' ? 'selected' : ''}>Pending Repair</option>
              </select>
            </div>
            <div class="pms-data" style="grid-column: 1 / -1;"><label>Staff Notes</label><input type="text" name="notes" class="pms-input" value="${notes}" placeholder="Enter any operational notes here..."></div>
          </div>
        </div>
      `;
    }

    html += `</div><div class="pms-footer">`; 
    html += `<button type="submit" class="btn btn--primary" id="saveRoomBtn">Save</button>`;

    if (status === 'available') {
      html += `<button type="button" class="btn btn--secondary action-walkin">Create Walk-In</button>`;
      html += `<button type="button" class="btn btn--outline action-maintenance">Mark Out of Order</button>`;
    } else if (status === 'reserved') {
      html += `<button type="button" class="btn btn--outline action-checkin" style="border-color:#1e8e3e; color:#1e8e3e;">Check In Guest</button>`;
    } else if (status === 'occupied') {
      html += `<button type="button" class="btn btn--outline action-checkout">Process Check-out</button>`;
    } else if (status === 'maintenance') {
      html += `<button type="button" class="btn btn--outline action-clear-maint">Clear Out of Order</button>`;
    }

    html += `<button type="button" class="btn btn--text action-history">View Full History</button></div></form>`;

    modalContent.innerHTML = html;
    roomModal.classList.add('is-pms-dashboard');
    roomModal.hidden = false;

    // ─── Bind Interactivity ──────────────────────────────────────────
    const form = modalContent.querySelector('#adminRoomForm');
    const statusSelect = modalContent.querySelector('#directStatusOverride');

    // ─── CRITICAL: Dropdown change handler ──────────────────────────
    if (statusSelect) {
      statusSelect.addEventListener('change', function(e) {
        const newStatus = e.target.value;
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
              // Update card with fresh data from server
              if (data.data) {
                updateCardFromData(card, data.data);
              } else {
                // Fallback: just update status and re-render
                const roomStatus = newStatus === 'needs_cleaning' ? 'available' : newStatus;
                card.dataset.status = roomStatus;
                if (newStatus === 'needs_cleaning') {
                  card.dataset.cleaning = 'Pending';
                } else if (newStatus === 'available') {
                  card.dataset.cleaning = 'Clean';
                }
                renderCard(card);
              }
              // Re-open console with updated data
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

    // ─── Form submit (Save) ─────────────────────────────────────────
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      const submitBtn = form.querySelector('#saveRoomBtn');
      const originalText = submitBtn.textContent;
      submitBtn.textContent = 'Saving...';
      submitBtn.disabled = true;

      const formData = new FormData(form);

      fetch('/process_room_action.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            if (data.data) {
              updateCardFromData(card, data.data);
            } else {
              renderCard(card);
            }
            closeModal();
          } else {
            alert('Error: ' + data.message);
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
          }
        })
        .catch(err => {
          alert('Network error: ' + err.message);
          console.error(err);
          submitBtn.textContent = originalText;
          submitBtn.disabled = false;
        });
    });

    // ─── Quick Actions ──────────────────────────────────────────────
    function attachQuickAction(selector, actionType, confirmMsg, targetStatus) {
      const btn = modalContent.querySelector(selector);
      if (!btn) return;
      btn.addEventListener('click', () => {
        if (!confirm(confirmMsg)) return;
        const originalText = btn.textContent;
        btn.textContent = 'Processing...';
        btn.disabled = true;
        const formData = new FormData();
        formData.append('action', actionType);
        formData.append('room_id', roomId);
        fetch('/process_room_action.php', { method: 'POST', body: formData })
          .then(res => res.json())
          .then(data => {
            if (data.success) {
              if (data.data) {
                updateCardFromData(card, data.data);
              } else {
                card.dataset.status = targetStatus;
                if (actionType === 'check_out') {
                  card.dataset.cleaning = 'Pending';
                  card.dataset.guestName = '';
                  card.dataset.checkIn = '';
                  card.dataset.checkOut = '';
                }
                renderCard(card);
              }
              openAdminConsole(card, targetStatus);
            } else {
              alert('Error: ' + data.message);
              btn.textContent = originalText;
              btn.disabled = false;
            }
          })
          .catch(err => {
            alert('Network error: ' + err.message);
            console.error(err);
            btn.textContent = originalText;
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

    const checkInInput = form.querySelector('input[name="check_in"]');
    const checkOutInput = form.querySelector('input[name="check_out"]');
    if (checkInInput && checkOutInput && !form.querySelector('input[name="id"]')) {
      function setCheckout() {
        const dateVal = checkInInput.value;
        if (!dateVal) return;
        const d = new Date(dateVal);
        d.setDate(d.getDate() + 30);
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        checkOutInput.value = year + '-' + month + '-' + day;
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