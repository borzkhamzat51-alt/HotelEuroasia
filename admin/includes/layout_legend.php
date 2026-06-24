(function () {
  'use strict';

  var sidebar = document.getElementById('rlSidebar');
  var countEl = document.getElementById('rlCount');
  if (!sidebar) return;

  var statusMap = {
    available:     function(c){ return c.classList.contains('status-available') && !c.classList.contains('room-card--dirty'); },
    needs_cleaning:function(c){ return c.classList.contains('room-card--dirty'); },
    occupied:      function(c){ return c.classList.contains('status-occupied'); },
    reserved:      function(c){ return c.classList.contains('status-reserved'); },
    maintenance:   function(c){ return c.classList.contains('status-maintenance'); },
  };

  function cards(){ return Array.from(document.querySelectorAll('.room-card')); }

  function applyFilter(filter) {
    var all = cards(), shown = 0;
    all.forEach(function(card){
      var show = filter === 'all' || (statusMap[filter] && statusMap[filter](card));
      card.style.opacity       = show ? '' : '0.18';
      card.style.filter        = show ? '' : 'grayscale(70%)';
      card.style.pointerEvents = show ? '' : 'none';
      if (show) shown++;
    });
    if (countEl) countEl.textContent = filter === 'all' ? all.length + ' rooms' : shown + ' of ' + all.length + ' shown';

    document.querySelectorAll('.rl-legend-item').forEach(function(item){
      var f = item.dataset.rlFilter;
      item.classList.toggle('is-active', f === filter);
      item.classList.toggle('is-dimmed', filter !== 'all' && f !== filter && f !== 'all');
    });
  }

  sidebar.addEventListener('click', function(e){
    var item = e.target.closest('[data-rl-filter]');
    if (item) applyFilter(item.dataset.rlFilter || 'all');
  });

  applyFilter('all');
}());