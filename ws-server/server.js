/**
 * Bluebookers — Room Sync WebSocket Server
 * ------------------------------------------------------------------
 * Pushes live room updates (status, type, price, number, cleaning/
 * maintenance state) AND live reservation updates (guest name, dates,
 * status — including checkout) to every connected browser tab — Layout
 * pages and the Calendar page alike — so a change made in one
 * already-open tab shows up in another without a manual reload.
 *
 * Design: this server does NOT receive "something changed" events from
 * PHP. It polls the `rooms` and `reservations` tables itself, on a
 * short interval, and diffs against its last snapshot of each. Anything
 * that changed gets broadcast. This means it doesn't matter which PHP
 * endpoint made the change, or whether a future endpoint is added later
 * that changes a room or reservation — anything that touches either
 * table is automatically picked up, with zero changes required to any
 * PHP file's write path. The trade-off is the poll interval
 * (POLL_INTERVAL_MS below) is the upper bound on propagation latency,
 * not true instant push from the write itself — for a hotel-staff admin
 * tool, ~1-2s is generally imperceptible as "not live."
 *
 * The reservations table grows forever (bookings are never hard-deleted
 * — see schema.sql's audit-log comment), so unlike rooms it isn't
 * practical to poll/diff every row every cycle. Instead the reservation
 * poll is bounded: status IN ('reserved','checked_in') — i.e. anything
 * currently active, which is what guest/date sync actually needs — OR
 * updated_at within the last minute, which catches a reservation the
 * moment it transitions OUT of active status (checked_out, cancelled)
 * so clients can react (remove the calendar bar, clear the room card's
 * guest info) even though the row itself no longer matches the first
 * condition. After that one-minute window the row simply ages out of
 * the polled set — no separate "removed" message is needed, since the
 * client already reacted when the status change was first broadcast.
 *
 * Run with: node server.js
 * (see README.md for first-time setup and running this as a background
 * service rather than a foreground process you have to keep open)
 */

'use strict';

require('dotenv').config();
const WebSocket = require('ws');
const mysql = require('mysql2/promise');

const PORT = parseInt(process.env.WS_PORT || '8081', 10);
const POLL_INTERVAL_MS = parseInt(process.env.POLL_INTERVAL_MS || '1500', 10);

const DB_CONFIG = {
  host: process.env.DB_HOST || 'localhost',
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASS || '',
  database: process.env.DB_NAME || 'bluebookers',
};

const ROOM_FIELDS = [
  'id', 'branch', 'room_number', 'room_type', 'price_per_night',
  'room_status', 'cleaning_status', 'maintenance_status', 'last_occupancy',
];

const RESERVATION_FIELDS = [
  'r.id', 'r.room_id', 'r.guest_full_name', 'r.check_in', 'r.check_out',
  'r.status', 'r.num_adults', 'r.num_children', 'r.updated_at',
];

let pool;
let lastRoomSnapshot = new Map(); // room id -> JSON string of that room's row
let lastReservationSnapshot = new Map(); // reservation id -> JSON string of that reservation's row
let pollTimer = null;
let pollInFlight = false;

const wss = new WebSocket.Server({ port: PORT });
console.log(`[ws-server] listening on ws://0.0.0.0:${PORT}`);

wss.on('connection', function (ws) {
  console.log('[ws-server] client connected. total clients:', wss.clients.size);
  ws.isAlive = true;
  ws.on('pong', function () { ws.isAlive = true; });

  ws.on('message', function (raw) {
    // No client -> server commands are required for this feature (the
    // server only pushes), but a 'ping' is accepted as a harmless no-op
    // so a client can verify the connection is alive without the
    // browser's native WebSocket ping/pong (which JS can't trigger
    // directly from the page).
    try {
      const msg = JSON.parse(raw.toString());
      if (msg && msg.type === 'ping') {
        ws.send(JSON.stringify({ type: 'pong' }));
      }
    } catch (err) { /* ignore malformed client messages */ }
  });

  ws.on('close', function () {
    console.log('[ws-server] client disconnected. total clients:', wss.clients.size);
  });

  ws.on('error', function (err) {
    console.error('[ws-server] client socket error:', err.message);
  });
});

// Drop dead connections (e.g. laptop went to sleep, network dropped
// without a clean close) so broadcasts don't keep trying to write to a
// socket nobody's listening on anymore.
const heartbeat = setInterval(function () {
  wss.clients.forEach(function (ws) {
    if (ws.isAlive === false) return ws.terminate();
    ws.isAlive = false;
    ws.ping();
  });
}, 30000);
wss.on('close', function () { clearInterval(heartbeat); });

function broadcast(payload) {
  const json = JSON.stringify(payload);
  wss.clients.forEach(function (client) {
    if (client.readyState === WebSocket.OPEN) {
      client.send(json);
    }
  });
}

function roomRowToPayload(row) {
  return {
    id: row.id,
    branch: row.branch,
    room_number: row.room_number,
    room_type: row.room_type,
    price_per_night: row.price_per_night,
    room_status: row.room_status,
    cleaning_status: row.cleaning_status,
    maintenance_status: row.maintenance_status,
    last_occupancy: row.last_occupancy,
  };
}

function reservationRowToPayload(row) {
  return {
    id: row.id,
    room_id: row.room_id,
    room_number: row.room_number,
    guest_full_name: row.guest_full_name,
    check_in: row.check_in,
    check_out: row.check_out,
    status: row.status,
    num_adults: row.num_adults,
    num_children: row.num_children,
  };
}

async function pollRoomsOnce() {
  const [rows] = await pool.query(
    'SELECT ' + ROOM_FIELDS.join(', ') + ' FROM rooms ORDER BY id ASC'
  );

  const changed = [];
  const seenIds = new Set();

  for (const row of rows) {
    seenIds.add(row.id);
    const serialized = JSON.stringify(row);
    if (lastRoomSnapshot.get(row.id) !== serialized) {
      changed.push(roomRowToPayload(row));
    }
    lastRoomSnapshot.set(row.id, serialized);
  }

  // Rooms that existed last poll but not this one (deleted) — not
  // expected in normal operation (no room-delete feature exists in
  // either module currently) but handled so the snapshot map doesn't
  // silently grow stale entries forever if it ever does happen.
  for (const id of Array.from(lastRoomSnapshot.keys())) {
    if (!seenIds.has(id)) lastRoomSnapshot.delete(id);
  }

  if (changed.length > 0 && wss.clients.size > 0) {
    broadcast({ type: 'rooms_changed', rooms: changed });
    console.log('[ws-server] broadcast', changed.length, 'changed room(s) to', wss.clients.size, 'client(s)');
  }
}

async function pollReservationsOnce() {
  const [rows] = await pool.query(
    `SELECT ${RESERVATION_FIELDS.join(', ')}, ro.room_number
     FROM reservations r
     JOIN rooms ro ON ro.id = r.room_id
     WHERE r.status IN ('reserved', 'checked_in')
        OR r.updated_at >= NOW() - INTERVAL 1 MINUTE
     ORDER BY r.id ASC`
  );

  const changed = [];
  const seenIds = new Set();

  for (const row of rows) {
    seenIds.add(row.id);
    const serialized = JSON.stringify(row);
    if (lastReservationSnapshot.get(row.id) !== serialized) {
      changed.push(reservationRowToPayload(row));
    }
    lastReservationSnapshot.set(row.id, serialized);
  }

  // Reservations that aged out of the bounded query (no longer active,
  // and it's been over a minute since they last changed) — quietly drop
  // them from the snapshot. No broadcast needed here: clients already
  // reacted to the status change itself when it was first picked up
  // above, in an earlier poll cycle.
  for (const id of Array.from(lastReservationSnapshot.keys())) {
    if (!seenIds.has(id)) lastReservationSnapshot.delete(id);
  }

  if (changed.length > 0 && wss.clients.size > 0) {
    broadcast({ type: 'reservations_changed', reservations: changed });
    console.log('[ws-server] broadcast', changed.length, 'changed reservation(s) to', wss.clients.size, 'client(s)');
  }
}

async function pollOnce() {
  if (pollInFlight) return; // a previous poll is still running (slow DB) — skip this tick rather than overlap
  pollInFlight = true;
  try {
    await pollRoomsOnce();
    await pollReservationsOnce();
  } catch (err) {
    console.error('[ws-server] poll error:', err.message);
  } finally {
    pollInFlight = false;
  }
}

async function main() {
  pool = mysql.createPool({
    host: DB_CONFIG.host,
    user: DB_CONFIG.user,
    password: DB_CONFIG.password,
    database: DB_CONFIG.database,
    waitForConnections: true,
    connectionLimit: 3,
  });

  // Prime both snapshots from current state so the very first poll
  // cycle doesn't broadcast every room/reservation as "changed."
  try {
    const [roomRows] = await pool.query('SELECT ' + ROOM_FIELDS.join(', ') + ' FROM rooms');
    roomRows.forEach(function (row) { lastRoomSnapshot.set(row.id, JSON.stringify(row)); });

    const [resvRows] = await pool.query(
      `SELECT ${RESERVATION_FIELDS.join(', ')}, ro.room_number
       FROM reservations r
       JOIN rooms ro ON ro.id = r.room_id
       WHERE r.status IN ('reserved', 'checked_in')
          OR r.updated_at >= NOW() - INTERVAL 1 MINUTE`
    );
    resvRows.forEach(function (row) { lastReservationSnapshot.set(row.id, JSON.stringify(row)); });

    console.log('[ws-server] primed snapshot with', roomRows.length, 'room(s) and', resvRows.length, 'active reservation(s)');
  } catch (err) {
    console.error('[ws-server] FATAL: could not connect to database on startup:', err.message);
    console.error('[ws-server] check DB_HOST/DB_USER/DB_PASS/DB_NAME in .env — see .env.example');
    process.exit(1);
  }

  pollTimer = setInterval(pollOnce, POLL_INTERVAL_MS);
}

main();

process.on('SIGINT', function () {
  console.log('\n[ws-server] shutting down...');
  if (pollTimer) clearInterval(pollTimer);
  wss.close(function () { process.exit(0); });
});