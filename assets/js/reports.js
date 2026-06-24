/**
 * reports.js — Bluebookers Reports
 * Guest list + activity card + deep-link to Calendar.
 * Live updates via polling (5s). Date changes update counts & table instantly.
 */
(function () {
  'use strict';

  var branch = window.RPT_BRANCH || 'mtv';
  var today  = window.RPT_TODAY  || new Date().toISOString().split('T')[0];
  var counts = window.RPT_COUNTS || { arrivals: 0, moveouts: 0, inhouse: 0 };
  var selectedDate = today;

  // DOM refs
  var activitySelect  = document.getElementById('activitySelect');
  var activityValue   = document.getElementById('activityValue');
  var activityUnit    = document.getElementById('activityUnit');
  var activitySub     = document.getElementById('activitySub');
  var activityCountEl = document.getElementById('activityCount');
  var guestPanelTitle = document.getElementById('guestPanelTitle');
  var guestDate       = document.getElementById('guestDate');
  var guestLoading    = document.getElementById('guestLoading');
  var guestTable      = document.getElementById('guestTable');
  var guestThead      = document.getElementById('guestThead');
  var guestTbody      = document.getElementById('guestTbody');
  var guestCount      = document.getElementById('guestCount');
  var liveLabel       = document.getElementById('rptLiveLabel');
  var liveDot         = document.getElementById('rptLiveDot');

  var labels = {
    arrivals : { title: 'Expected Arrivals',    sub: 'Checking in today'         },
    moveouts : { title: 'Move-Out Guests',      sub: 'Departing today'           },
    inhouse  : { title: 'In-House Guests',      sub: 'Currently staying in-house'},
  };

  // Helpers
  function money(n) {
    return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function currentType() { return activitySelect ? activitySelect.value : 'inhouse'; }
  function currentDate() { return selectedDate; }

  // Flash animation for KPI cards
  function flashEl(el) {
    if (!el) return;
    el.classList.remove('rpt-kpi--flash');
    void el.offsetWidth;
    el.classList.add('rpt-kpi--flash');
  }

  // Update activity card UI with cached counts
  function updateActivityCard(type) {
    var info  = labels[type] || labels.inhouse;
    var count = counts[type] !== undefined ? counts[type] : 0;

    if (activityCountEl) {
      activityCountEl.classList.remove('rpt-activity-count--animate');
      void activityCountEl.offsetWidth;
      activityCountEl.classList.add('rpt-activity-count--animate');
    }
    if (activityValue)   activityValue.textContent  = count;
    if (activityUnit)    activityUnit.textContent   = count === 1 ? 'Guest' : 'Guests';
    if (activitySub)     activitySub.textContent    = info.sub;
    if (guestPanelTitle) guestPanelTitle.textContent = info.title;

    document.querySelectorAll('.rpt-dot').forEach(function (dot) {
      dot.classList.toggle('rpt-dot--active', dot.dataset.type === type);
    });
  }

  // Fetch fresh counts for a given date
  function fetchActivityCounts(date) {
    return fetch('process_reports_counts.php?branch=' + encodeURIComponent(branch) + '&date=' + encodeURIComponent(date))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) throw new Error(d.message || 'Failed to fetch counts');
        return d.counts;
      });
  }

  // Update counts and UI for a given date
  function updateActivityCounts(date) {
    fetchActivityCounts(date)
      .then(function (newCounts) {
        counts = newCounts;
        updateActivityCard(currentType());
        // Update dot tooltips
        document.querySelectorAll('.rpt-dot').forEach(function (dot) {
          var type = dot.dataset.type;
          var cnt = counts[type] !== undefined ? counts[type] : 0;
          dot.title = type.charAt(0).toUpperCase() + type.slice(1) + ': ' + cnt;
        });
      })
      .catch(function (err) {
        console.warn('[reports] Failed to refresh activity counts:', err);
      });
  }

  // Load guest table for a given type and date
  function loadGuests(type, date, opts) {
    opts = opts || {};
    var silent = !!opts.silent;

    if (!silent) {
      if (guestLoading) guestLoading.style.display = 'flex';
      if (guestTable)   guestTable.style.display   = 'none';
      if (guestCount)   guestCount.textContent      = '';
    }

    fetch('process_reports_guests.php'
      + '?branch=' + encodeURIComponent(branch)
      + '&type='   + encodeURIComponent(type)
      + '&date='   + encodeURIComponent(date))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!silent && guestLoading) guestLoading.style.display = 'none';
        if (!d.success) {
          if (!silent) {
            if (guestTbody) guestTbody.innerHTML = '<tr><td colspan="8" class="rpt-empty-row">Error loading data.</td></tr>';
            if (guestTable) guestTable.style.display = 'table';
          }
          return;
        }
        // Diff to avoid flicker
        if (guestThead && guestThead.innerHTML !== d.thead) guestThead.innerHTML = d.thead;
        if (guestTbody && guestTbody.innerHTML !== d.tbody) guestTbody.innerHTML = d.tbody;
        if (guestTable) guestTable.style.display = 'table';
        if (guestCount) guestCount.textContent = d.count;
      })
      .catch(function () {
        if (!silent) {
          if (guestLoading) guestLoading.style.display = 'none';
          if (guestTbody)   guestTbody.innerHTML = '<tr><td colspan="8" class="rpt-empty-row">Could not load guests.</td></tr>';
          if (guestTable)   guestTable.style.display = 'table';
        }
      });
  }

  // Refresh money KPIs (sales, expected, overdue) — always for today
  var refreshPending = false;
  function refreshKpis(silent) {
    if (refreshPending) return;
    refreshPending = true;

    fetch('process_reports_kpis.php?branch=' + encodeURIComponent(branch))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        refreshPending = false;
        if (!d.success) return;

        var map = {
          sales_today:      money(d.sales_today),
          expected_revenue: money(d.expected_revenue),
          overdue:          money(d.overdue),
        };
        Object.keys(map).forEach(function (key) {
          var el = document.querySelector('[data-kpi="' + key + '"]');
          if (!el) return;
          if (el.textContent !== map[key]) {
            el.textContent = map[key];
            if (!silent) flashEl(el);
          }
        });

        if (liveLabel && d.updated_at) {
          liveLabel.textContent = 'Updated ' + d.updated_at;
        }
      })
      .catch(function () { refreshPending = false; });
  }

  // Deep-link to Calendar
  window.rptGoToCalendar = function (e, el) {
    e.preventDefault();
    var rId  = el.dataset.reservationId;
    var br   = el.dataset.branch;
    var key  = 'bb_cal_state_' + (br || 'default');
    var href = el.getAttribute('href');
    try { localStorage.setItem(key, JSON.stringify({ barId: String(rId) })); } catch (_) {}
    window.location.href = href;
  };

  // ── Event wiring ───────────────────────────────────────

  // Activity type dropdown
  if (activitySelect) {
    activitySelect.addEventListener('change', function () {
      updateActivityCard(this.value);
      loadGuests(this.value, currentDate());
    });
  }

  // Date input change – the core of the fix
  if (guestDate) {
    guestDate.addEventListener('change', function () {
      var newDate = this.value;
      selectedDate = newDate;
      // Update counts for the new date
      updateActivityCounts(newDate);
      // Update guest list for the new date
      loadGuests(currentType(), newDate);
    });
  }

  // Dot clicks
  document.querySelectorAll('.rpt-dot').forEach(function (dot) {
    dot.addEventListener('click', function () {
      var type = this.dataset.type;
      if (activitySelect) activitySelect.value = type;
      updateActivityCard(type);
      loadGuests(type, currentDate());
    });
  });

  // ── Initialisation ─────────────────────────────────────

  // Set selectedDate from the input value (or today)
  if (guestDate) {
    selectedDate = guestDate.value || today;
  }

  // Initial display
  updateActivityCard(currentType());
  loadGuests(currentType(), selectedDate);

  // Set dot tooltips with initial counts
  document.querySelectorAll('.rpt-dot').forEach(function (dot) {
    var type = dot.dataset.type;
    var cnt = counts[type] !== undefined ? counts[type] : 0;
    dot.title = type.charAt(0).toUpperCase() + type.slice(1) + ': ' + cnt;
  });

  // ── Live polling (every 5 seconds) ─────────────────────
  if (liveDot)   liveDot.className   = 'rpt-live-dot rpt-live-dot--live';
  if (liveLabel) liveLabel.textContent = 'Auto-refresh';

  setInterval(function () {
    // Refresh money KPIs (always for today)
    refreshKpis(false);
    // Refresh activity counts for the currently selected date
    updateActivityCounts(selectedDate);
    // Silently refresh the guest table for the same date
    loadGuests(currentType(), selectedDate, { silent: true });
  }, 5000);

}());