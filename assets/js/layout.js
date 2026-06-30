/**
   * Bluebookers — Layout (Room Cards & Modal)
   * Corrected modal element IDs + removed room selector + Quick Stay Duration (identical to Calendar).
   * Updated for monthly rental: rate labels changed to "/month", duration display added.
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

    // Helper: format duration accurately using calendar arithmetic (matches PHP)
    function formatDuration(checkIn, checkOut) {
      var start = new Date(checkIn + 'T00:00:00');
      var end   = new Date(checkOut + 'T00:00:00');
      var ms    = end - start;
      if (ms <= 0) return '0 Days';
      var y = end.getFullYear() - start.getFullYear();
      var m = end.getMonth()    - start.getMonth();
      var d = end.getDate()     - start.getDate();
      if (d < 0) { m--; var prev = new Date(end.getFullYear(), end.getMonth(), 0); d += prev.getDate(); }
      if (m < 0) { y--; m += 12; }
      var months = y * 12 + m;
      var parts  = [];
      if (months > 0) parts.push(months + ' Month' + (months > 1 ? 's' : ''));
      if (d > 0)      parts.push(d + ' Day'   + (d > 1 ? 's' : ''));
      return parts.join(' ') || '0 Days';
    }

    // ─── Modal Elements ──────────────────────────────────────────────
    const overlay = document.getElementById('roomModal');
    const content = document.getElementById('modalContent');
    const closeBtn = document.getElementById('modalClose');

    if (!overlay || !content || !closeBtn) {
      console.warn('Layout modal elements not found – skipping modal setup.');
    } else {

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

      // ─── VALID ID OPTIONS ─────────────────────────────────────────────
      const VALID_ID_OPTIONS = [
        'National ID (PhilSys)',
        'Passport',
        "Driver's License",
        'Barangay ID',
        'Postal ID',
        'UMID',
        'SSS ID',
        'PRC ID',
        'Senior Citizen ID',
        'Student ID',
        "Voter's ID",
        'Company ID',
        'Other Government ID',
        'No ID',
      ];

      function validIdDropdown(name, selected, errors) {
        const id = name;
        const opts = VALID_ID_OPTIONS.map(function(v) {
          const sel = (v === selected) ? ' selected' : '';
          return '<option value="' + v.replace(/"/g, '&quot;') + '"' + sel + '>' + v + '</option>';
        }).join('');
        return '<div><label for="' + id + '">Valid ID Type</label>' +
          '<select id="' + id + '" name="' + name + '">' +
            '<option value="">— Select —</option>' + opts +
          '</select>' +
          (errors && errors[name] ? '<span class="form-error">' + errors[name] + '</span>' : '') +
          '</div>';
      }

      // ─── MANUAL STATUS OPTIONS (Pending, Reserved, Checked In only) ─────
      const MANUAL_STATUS_OPTIONS = {
        pending:    'Pending',
        reserved:   'Reserved',
        checked_in: 'Checked In',
      };
      const SYSTEM_ONLY_STATUS = { cancelled: 'Cancelled', checked_out: 'Checked Out' };

      function manualStatusOptions(selectedStatus) {
        let html = '';
        Object.keys(MANUAL_STATUS_OPTIONS).forEach(function(key) {
          const sel = key === selectedStatus ? ' selected' : '';
          html += '<option value="' + key + '"' + sel + '>' + MANUAL_STATUS_OPTIONS[key] + '</option>';
        });
        if (SYSTEM_ONLY_STATUS[selectedStatus]) {
          html += '<option value="' + selectedStatus + '" selected disabled>' + SYSTEM_ONLY_STATUS[selectedStatus] + ' (system-assigned)</option>';
        }
        return html;
      }

      // ─── Form rendering ─────────────────────────────────────────────
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
        html += '<p style="color:var(--ink-500); font-size:0.85rem; margin-bottom:6px;">' + (cfg.branchLabel || '') + '</p>';

        if (!isEdit) {
          html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:18px;" id="bbSteps">' +
            '<div style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;" id="bbStep1">' +
              '<span style="width:22px;height:22px;border-radius:50%;background:#3b7dd8;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;">1</span>' +
              '<span style="color:#16324f;">Reservation details</span>' +
            '</div>' +
            '<div style="flex:1;height:1px;background:#c5deef;"></div>' +
            '<div style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;" id="bbStep2">' +
              '<span id="bbStep2Dot" style="width:22px;height:22px;border-radius:50%;background:#e0eaf4;color:#8dafc8;border:1px solid #c5deef;display:flex;align-items:center;justify-content:center;font-size:11px;">2</span>' +
              '<span id="bbStep2Lbl" style="color:#8dafc8;">Payment</span>' +
            '</div>' +
          '</div>';
        }

        html += '<div id="bbFormStep1">';
        html += '<form id="resvForm" class="resv-form" autocomplete="off">';
        if (isEdit) html += '<input type="hidden" name="id" value="' + resv.id + '">';
        html += '<input type="hidden" name="room_id" value="' + roomId + '">';

        html += '<h3>Guest Information</h3><div class="resv-grid">';
        html += field('Full Name', 'guest_full_name', resv.guest_full_name, 'text', errors, true);
        html += field('Contact Number', 'contact_number', resv.contact_number, 'text', errors);
        html += field('Email Address', 'email', resv.email, 'email', errors);
        html += field('Address', 'address', resv.address, 'text', errors);
        html += validIdDropdown('valid_id_type', resv.valid_id_type, errors);
        html += field('Valid ID Number', 'valid_id_number', resv.valid_id_number, 'text', errors);
        html += '</div>';

        html += '<h3>Booking Information</h3><div class="resv-grid">';
        html += '<div><label for="status">Booking Status</label><select id="status" name="status">' + manualStatusOptions(resv.status || 'reserved') + '</select></div>';
        html += field('Check-in Date', 'check_in', checkIn, 'date', errors, true);
        html += field('Check-out Date', 'check_out', checkOut, 'date', errors, true);
        html += field('Number of Adults', 'num_adults', resv.num_adults || 1, 'number', errors);
        html += field('Number of Children', 'num_children', resv.num_children || 0, 'number', errors);
        html += '<div class="bb-quick-duration"><label>Quick Stay Duration</label><div class="bb-duration-buttons">';
        html += '<button type="button" data-months="1">1 Month</button>';
        html += '<button type="button" data-months="2">2 Months</button>';
        html += '<button type="button" data-months="3">3 Months</button>';
        html += '<button type="button" data-months="4">4 Months</button>';
        html += '<button type="button" data-months="6">6 Months</button>';
        html += '<button type="button" data-months="12">12 Months</button>';
        html += '</div></div>';
        html += '</div>';

        html += '<div class="resv-duration"><label>Stay Duration</label>';
        html += '<div class="resv-duration__display" id="stayDurationDisplay">0 Days</div></div>';
        html += '<input type="hidden" name="expected_payment_date" id="expected_payment_date" value="' + (resv.expected_payment_date || '') + '">';

        html += '<h3>Payment Rates</h3><div class="resv-grid">';
        html += field('Monthly Rent (₱/month)', 'room_rate', resv.room_rate || 0, 'number', errors);
        html += field('Reservation Fee (₱)', 'reservation_fee', resv.reservation_fee || 0, 'number', errors);
        html += field('Garbage Fee (₱)', 'garbage_fee', resv.garbage_fee || 0, 'number', errors);
        html += field('Security Deposit (₱)', 'security_deposit', resv.security_deposit || 0, 'number', errors);
        html += field('Utilities Deposit (₱)', 'utilities_deposit', resv.utilities_deposit || 0, 'number', errors);
        html += field('Total Amount (₱)', 'total_amount', resv.total_amount || 0, 'number', errors);
        html += '<input type="hidden" name="amount_paid" id="amount_paid" value="' + (resv.amount_paid || 0) + '">';
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
        if (!isEdit) {
          html += '<button type="submit" class="btn btn--primary">Next →</button>';
        } else {
          html += '<button type="submit" class="btn btn--primary">Save Changes</button>';
          if (cfg.canDelete) {
            html += '<button type="button" class="btn btn--danger" id="resvDeleteBtn">Delete</button>';
          }
        }
        html += '<button type="button" class="btn btn--secondary" id="resvCancelBtn">Cancel</button>';
        html += '</div>';
        html += '</form>';
        if (isEdit) {
          html += '<details class="resv-log"><summary>Activity Log</summary><ul id="resvLogList"><li>Loading…</li></ul></details>';
        }
        html += '</div>';

        if (!isEdit) {
          html += '<div id="bbFormStep2" style="display:none;">';
          html += '<div id="bbResvSummaryBar" style="background:#eef5fc;border:1px solid #c5deef;border-radius:8px;padding:9px 14px;font-size:.84rem;color:#2c4a68;margin-bottom:14px;"></div>';
          html += '<div style="background:#f8fbff;border:1px solid #c5deef;border-radius:8px;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">' +
            '<span style="font-size:.84rem;color:#5b7693;">Remaining balance</span>' +
            '<span id="bbPayBalance" style="font-size:1rem;font-weight:700;color:#b91c1c;">₱0.00</span>' +
          '</div>';
          html += '<div style="border:1px solid #c5deef;border-radius:8px;overflow:hidden;margin-bottom:14px;">' +
            '<div style="background:#eef5fc;padding:8px 14px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#3b7dd8;border-bottom:1px solid #c5deef;">Payment History</div>' +
            '<div id="bbPayList" style="padding:12px 14px;font-size:.84rem;color:#5b7693;">No payments recorded yet.</div>' +
          '</div>';
          html += '<div style="border:1px solid #c5deef;border-radius:8px;overflow:hidden;">' +
            '<div style="background:#f8fbff;padding:8px 14px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5b7693;border-bottom:1px solid #c5deef;">Amount</div>' +
            '<div style="padding:12px 14px;display:flex;flex-direction:column;gap:8px;">' +
              '<div style="display:grid;grid-template-columns:1fr auto;gap:8px;align-items:end;">' +
                '<input type="number" id="bbPayAmt" min="0.01" step="0.01" placeholder="e.g. 10000" style="width:100%;padding:8px 10px;border:1.5px solid #c5deef;border-radius:6px;font-family:inherit;font-size:.86rem;">' +
                '<button type="button" id="bbPayAddBtn" style="padding:8px 16px;background:#16a34a;color:#fff;border:none;border-radius:6px;font-family:inherit;font-size:.84rem;font-weight:600;cursor:pointer;white-space:nowrap;">+ Add</button>' +
              '</div>' +
              '<input type="text" id="bbPayRemarks" placeholder="Remarks / ref no. (optional)" style="width:100%;padding:8px 10px;border:1.5px solid #c5deef;border-radius:6px;font-family:inherit;font-size:.84rem;box-sizing:border-box;">' +
              '<p id="bbPayErr" style="color:#b91c1c;font-size:.78rem;margin:0;display:none;"></p>' +
            '</div>' +
          '</div>';
          html += '<div class="resv-actions" style="margin-top:14px;">' +
            '<button type="button" class="btn btn--primary" id="bbPayDoneBtn">Done</button>' +
            '<button type="button" class="btn btn--secondary" id="bbPayBackBtn">← Back</button>' +
          '</div>';
          html += '</div>';
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

      function wireBalance() {
        const form = document.getElementById('resvForm');
        const balanceEl = document.getElementById('resvBalance');

        function calcMonths(inVal, outVal) {
          if (!inVal || !outVal) return 0;
          var start = new Date(inVal + 'T00:00:00');
          var end   = new Date(outVal + 'T00:00:00');
          if (end <= start) return 0;
          var months = (end.getFullYear() - start.getFullYear()) * 12 + (end.getMonth() - start.getMonth());
          var dayStart = start.getDate(), dayEnd = end.getDate();
          if (dayEnd > dayStart) {
            months += (dayEnd - dayStart) / new Date(end.getFullYear(), end.getMonth(), 0).getDate();
          } else if (dayEnd < dayStart) {
            months -= (dayStart - dayEnd) / new Date(start.getFullYear(), start.getMonth() + 1, 0).getDate();
          }
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
          if (form.total_amount) {
            form.total_amount.value = total > 0 ? total.toFixed(2) : '';
          }
          return total;
        }

        function recalc() {
          var total     = autoCalcTotal();
          var paid      = parseFloat(form.amount_paid ? form.amount_paid.value : 0) || 0;
          var remaining = total - paid;
          balanceEl.textContent = '₱' + remaining.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
          balanceEl.style.color = remaining > 0 ? '#b3433f' : 'inherit';
        }

        ['room_rate', 'reservation_fee', 'garbage_fee', 'security_deposit', 'utilities_deposit'].forEach(function(name) {
          var el = form.querySelector('[name="' + name + '"]');
          if (el) el.addEventListener('input', recalc);
        });
        ['check_in', 'check_out'].forEach(function(name) {
          var el = form.querySelector('[name="' + name + '"]');
          if (el) el.addEventListener('change', recalc);
        });
        if (form.total_amount) form.total_amount.addEventListener('input', recalc);
        if (form.amount_paid)  form.amount_paid.addEventListener('input', recalc);
        form._recalcBalance = recalc;
        recalc();
      }

      function wireDateCalculations() {
        var form            = document.getElementById('resvForm');
        var checkIn         = form.querySelector('[name="check_in"]');
        var checkOut        = form.querySelector('[name="check_out"]');
        var expectedPayment = form.querySelector('[name="expected_payment_date"]');
        var durationDisplay = document.getElementById('stayDurationDisplay');
        var selectedDuration = null;

        function calcDays(inVal, outVal) {
          if (!inVal || !outVal) return 0;
          var s = new Date(inVal + 'T00:00:00'), e = new Date(outVal + 'T00:00:00');
          return Math.round((e - s) / 86400000);
        }

        function formatLocalDate(d) {
          var y = d.getFullYear(), m = String(d.getMonth()+1).padStart(2,'0'), day = String(d.getDate()).padStart(2,'0');
          return y + '-' + m + '-' + day;
        }

        function updateDurationAndPayment() {
          var inVal = checkIn.value, outVal = checkOut.value;
          if (inVal && outVal) {
            var days = calcDays(inVal, outVal);
            durationDisplay.textContent = days > 0 ? days + ' Day' + (days !== 1 ? 's' : '') : '0 Days';
            if (!expectedPayment.dataset.userEdited) expectedPayment.value = outVal;
          } else {
            durationDisplay.textContent = '0 Days';
          }
          if (form._recalcBalance) form._recalcBalance();
        }

        function applyDuration(months) {
          if (!checkIn.value) { alert('Please select a check-in date first.'); return; }
          var start = new Date(checkIn.value + 'T00:00:00');
          var end = new Date(start);
          end.setMonth(end.getMonth() + months);
          checkOut.value = formatLocalDate(end);
          if (!expectedPayment.dataset.userEdited) expectedPayment.value = checkOut.value;
          selectedDuration = months;
          updateDurationAndPayment();
          form.querySelectorAll('.bb-duration-buttons button').forEach(function(b) { b.classList.remove('is-active'); });
          var active = form.querySelector('.bb-duration-buttons button[data-months="' + months + '"]');
          if (active) active.classList.add('is-active');
        }

        form.querySelectorAll('.bb-duration-buttons button').forEach(function(btn) {
          btn.addEventListener('click', function(e) {
            e.preventDefault();
            applyDuration(parseInt(this.dataset.months, 10));
          });
        });

        checkIn.addEventListener('input', function() {
          if (selectedDuration !== null) applyDuration(selectedDuration);
          else updateDurationAndPayment();
        });
        checkOut.addEventListener('input', function() {
          selectedDuration = null;
          form.querySelectorAll('.bb-duration-buttons button').forEach(function(b) { b.classList.remove('is-active'); });
          updateDurationAndPayment();
        });
        expectedPayment.addEventListener('input', function() { this.dataset.userEdited = 'true'; });
        updateDurationAndPayment();
      }

      function fetchAndEditReservation(resv) {
        var resvId = resv.id;
        if (!resvId) { renderForm(resv, null); return; }
        fetch('/process_reservation.php?action=get_reservation_for_payment&id=' + resvId)
          .then(function(r) { return r.json(); })
          .then(function(data) {
            if (data && data.success && data.reservation) {
              renderForm(Object.assign({}, resv, data.reservation), null);
            } else {
              // Fallback: try by room_id
              return fetch('/process_reservation.php?action=get_active_reservation&room_id=' + resv.room_id)
                .then(function(r2) { return r2.json(); })
                .then(function(data2) {
                  if (data2 && data2.success && data2.reservation) {
                    renderForm(Object.assign({}, resv, data2.reservation), null);
                  } else {
                    renderForm(resv, null);
                  }
                });
            }
          })
          .catch(function() { renderForm(resv, null); });
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
                  if (isEdit) {
                    window.location.reload();
                  } else {
                    var savedResv = res.reservation || {};
                    var resvId = savedResv.id;
                    var totalAmt = parseFloat(savedResv.total_amount || 0);

                    var dot1 = document.querySelector('#bbStep1 span:first-child');
                    var lbl1 = document.querySelector('#bbStep1 span:last-child');
                    if (dot1) { dot1.style.background = '#d4f7e7'; dot1.style.color = '#1a7a46'; dot1.textContent = '✓'; }
                    if (lbl1) lbl1.style.color = '#1a7a46';
                    var dot2 = document.getElementById('bbStep2Dot');
                    var lbl2 = document.getElementById('bbStep2Lbl');
                    if (dot2) { dot2.style.background = '#3b7dd8'; dot2.style.color = '#fff'; dot2.style.border = 'none'; }
                    if (lbl2) lbl2.style.color = '#16324f';

                    var bar = document.getElementById('bbResvSummaryBar');
                    if (bar) {
                      bar.textContent = (savedResv.guest_full_name || '') + '  ·  RM' + (savedResv.room_number || '') +
                        '  ·  ' + (savedResv.check_in || '') + ' → ' + (savedResv.check_out || '');
                    }

                    var balEl = document.getElementById('bbPayBalance');
                    if (balEl) balEl.textContent = '₱' + totalAmt.toLocaleString('en-PH', {minimumFractionDigits:2});

                    document.getElementById('bbFormStep1').style.display = 'none';
                    document.getElementById('bbFormStep2').style.display = 'block';

                    var bbPayments = [];
                    var bbBalance = totalAmt;

                    function fmtBB(n) { return '₱' + parseFloat(n||0).toLocaleString('en-PH', {minimumFractionDigits:2}); }

                    function refreshBBPayList() {
                      var listEl = document.getElementById('bbPayList');
                      var balElInner = document.getElementById('bbPayBalance');
                      if (!listEl) return;
                      if (bbPayments.length === 0) { listEl.textContent = 'No payments recorded yet.'; }
                      else {
                        listEl.innerHTML = bbPayments.map(function(p) {
                          return '<div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #e8f0f8;font-size:.84rem;">' +
                            '<span style="color:#5b7693;">' + (p.remarks || 'Payment') + '</span>' +
                            '<span style="color:#1a7a46;font-weight:600;">' + fmtBB(p.amount) + '</span>' +
                          '</div>';
                        }).join('');
                      }
                      if (balElInner) {
                        balElInner.textContent = fmtBB(bbBalance);
                        balElInner.style.color = bbBalance > 0 ? '#b91c1c' : '#1a7a46';
                      }
                    }

                    var addBtn = document.getElementById('bbPayAddBtn');
                    if (addBtn) {
                      addBtn.addEventListener('click', function() {
                        var errEl = document.getElementById('bbPayErr');
                        var amount = parseFloat(document.getElementById('bbPayAmt').value);
                        var remarks = document.getElementById('bbPayRemarks').value.trim();
                        if (errEl) errEl.style.display = 'none';
                        if (!amount || amount <= 0) {
                          if (errEl) { errEl.textContent = 'Enter a valid amount.'; errEl.style.display = 'block'; }
                          return;
                        }
                        addBtn.disabled = true; addBtn.textContent = '…';
                        var fd2 = new FormData();
                        fd2.append('action', 'record_payment');
                        fd2.append('reservation_id', resvId);
                        fd2.append('amount', amount);
                        fd2.append('payment_date', new Date().toISOString().split('T')[0]);
                        fd2.append('payment_method', 'cash');
                        fd2.append('remarks', remarks);
                        fetch('/process_reservation.php', { method: 'POST', body: fd2 })
                          .then(function(r) { return r.json(); })
                          .then(function(data) {
                            addBtn.disabled = false; addBtn.textContent = '+ Add';
                            if (!data.success) {
                              if (errEl) { errEl.textContent = data.message || 'Could not save.'; errEl.style.display = 'block'; }
                              return;
                            }
                            bbPayments.push({ amount: amount, remarks: remarks });
                            bbBalance = Math.max(0, bbBalance - amount);
                            document.getElementById('bbPayAmt').value = '';
                            document.getElementById('bbPayRemarks').value = '';
                            refreshBBPayList();
                          })
                          .catch(function() {
                            addBtn.disabled = false; addBtn.textContent = '+ Add';
                            if (errEl) { errEl.textContent = 'Network error.'; errEl.style.display = 'block'; }
                          });
                      });
                    }

                    var doneBtn = document.getElementById('bbPayDoneBtn');
                    if (doneBtn) doneBtn.addEventListener('click', function() { window.location.reload(); });

                    var backBtn = document.getElementById('bbPayBackBtn');
                    if (backBtn) {
                      backBtn.addEventListener('click', function() {
                        renderForm(savedResv, null, null);
                      });
                    }
                  }
                } else {
                  var resv2 = isEdit ? Object.assign({ id: id }, formToObject(fd)) : formToObject(fd);
                  renderForm(resv2, null, Object.assign({ _general: res.message }, res.errors || {}));
                }
              })
              .catch(function (err) {
                console.error('[layout.js] AJAX error:', err);
                alert('Error: ' + (err.message || 'Something went wrong.'));
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

      // ─── Entry points (click handlers) ─────────────────────────────
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
            fetchAndEditReservation(resv);
            return;
          }

          if (!moved) {
            revertToOriginal();
            fetchAndEditReservation(resv);
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
            navBtns.forEach(b => b.classList.remove('is-active'));
            this.classList.add('is-active');
            scrollToFloor(floor);
            if (floor !== 'all') {
              sections.forEach(section => {
                section.style.display = (section.dataset.floor === floor) ? '' : 'none';
              });
            } else {
              sections.forEach(section => section.style.display = '');
            }
          });
        });

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

        window.filterLayoutRooms = filterRooms;
        window.resetLayoutFilter = function() { filterRooms('all'); };
      })();

      // ─── Edit Room Details modal (monthly label) ──────────────────
      // Override the default modal with monthly rate label
      const originalEdit = window.openEditRoomModal;
      if (originalEdit) {
        window.openEditRoomModal = function(roomId) {
          // Use the same logic but with updated labels
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
              '<div><label style="display:block;font-size:0.78rem;font-weight:600;color:#2c4a68;margin-bottom:4px;">Monthly Rate (₱)</label>' +
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
                  if (data.room) {
                    // Update the card via the sync function
                    if (typeof updateRoomCard === 'function') updateRoomCard(data.room);
                  }
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
        };
      }

    } // end if(modal elements exist)

  })();