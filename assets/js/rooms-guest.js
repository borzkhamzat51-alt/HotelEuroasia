/**
 * Bluebookers — Guest Room Browser
 * Read-only: floor filtering + a simple details panel with a "Reserve"
 * call to action. No guest dossiers, no status overrides, no editing —
 * that's all admin-only (see admin/layout.js).
 */
(function () {
  'use strict';

  const tabs   = document.querySelectorAll('#floorTabs button');
  const cards  = document.querySelectorAll('#roomGrid .room-card');
  const modal  = document.getElementById('roomDetailModal');
  const content = document.getElementById('roomDetailContent');
  const closeBtn = document.getElementById('roomDetailClose');

  const statusLabels = {
    available: 'Available',
    occupied: 'Booked',
    reserved: 'Reserved',
    maintenance: 'Unavailable',
  };

  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      tabs.forEach((t) => t.classList.remove('is-active'));
      tab.classList.add('is-active');
      const floor = tab.dataset.floor;
      cards.forEach((card) => {
        card.style.display = (floor === 'all' || card.dataset.floor === floor) ? '' : 'none';
      });
    });
  });

  function openModal(card) {
    const status = card.dataset.status;
    const isBookable = status === 'available';

    content.innerHTML = `
      <h2>Room ${card.dataset.roomNumber}</h2>
      <p style="color:var(--ink-500); margin-top:4px;">${card.dataset.type} &middot; Floor ${card.dataset.floor}</p>
      <p style="font-size:1.4rem; font-weight:700; margin:14px 0; color:var(--ink-900);">&#8369;${Number(card.dataset.price).toLocaleString()} <span style="font-size:0.85rem; font-weight:400; color:var(--ink-500);">/ night</span></p>
      <p style="margin-bottom:18px;">Status: <strong>${statusLabels[status] || status}</strong></p>
      ${isBookable
        ? `<a class="btn btn--primary" style="display:block; text-align:center; text-decoration:none;" href="book.php?room=${encodeURIComponent(card.dataset.roomNumber)}">Reserve This Room</a>`
        : `<p style="color:#d93025;">This room isn't available to book right now.</p>`
      }
    `;
    modal.hidden = false;
  }

  cards.forEach((card) => {
    if (!card.classList.contains('is-bookable') && card.dataset.status !== undefined) {
      // Still viewable, just not bookable — clicking shows the same panel either way.
    }
    card.addEventListener('click', () => openModal(card));
  });

  function close() { modal.hidden = true; }
  closeBtn.addEventListener('click', close);
  modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });

})();
