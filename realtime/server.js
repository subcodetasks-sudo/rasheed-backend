'use strict';

require('dotenv').config();

const express = require('express');
const http = require('http');
const cors = require('cors');
const { Server } = require('socket.io');

const PORT = Number(process.env.PORT || 3001);
const HOST = process.env.HOST || '0.0.0.0';
const PATH = process.env.SOCKET_IO_PATH || '/socket.io';
const SECRET = process.env.SOCKET_IO_SECRET || '';
const LARAVEL_URL = (process.env.LARAVEL_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const CORS_ORIGIN = process.env.CORS_ORIGIN || '*';

const ALLOWED_ROOM_PREFIXES = [
  'notifications',
  'daily-journals.',
  'cash-station.',
  'administrative-debt-settlements.',
  'monthly-summary.',
  'inventory',
  'projects.',
];

function isAllowedRoom(room) {
  if (typeof room !== 'string' || room.length === 0 || room.length > 120) {
    return false;
  }

  return ALLOWED_ROOM_PREFIXES.some(
    (prefix) => room === prefix.replace(/\.$/, '') || room.startsWith(prefix),
  );
}

async function authenticateWithLaravel(token) {
  const response = await fetch(`${LARAVEL_URL}/api/v1/realtime/auth`, {
    method: 'GET',
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
      // Avoid ngrok free browser interstitial breaking JSON auth
      'ngrok-skip-browser-warning': 'true',
    },
  });

  if (!response.ok) {
    const text = await response.text();
    throw new Error(`Laravel auth failed (${response.status}): ${text}`);
  }

  const body = await response.json();
  if (!body?.success || !body?.data) {
    throw new Error('Laravel auth returned an unexpected payload');
  }

  return body.data;
}

const app = express();
app.use(cors({ origin: CORS_ORIGIN === '*' ? true : CORS_ORIGIN.split(',') }));
app.get('/health', (_req, res) => {
  res.json({ ok: true, service: 'rasheed-realtime' });
});

const server = http.createServer(app);
const io = new Server(server, {
  path: PATH,
  cors: {
    origin: CORS_ORIGIN === '*' ? true : CORS_ORIGIN.split(','),
    methods: ['GET', 'POST'],
  },
});

io.use(async (socket, next) => {
  try {
    const auth = socket.handshake.auth || {};
    const query = socket.handshake.query || {};

    if (auth.server_secret && SECRET && auth.server_secret === SECRET) {
      socket.data.isServer = true;
      return next();
    }

    let token = auth.token || query.token || socket.handshake.headers?.authorization;
    if (Array.isArray(token)) {
      token = token[0];
    }
    if (typeof token === 'string') {
      token = token.replace(/^Bearer\s+/i, '').trim();
    }

    // eslint-disable-next-line no-console
    console.log('Handshake attempt', {
      hasAuthToken: Boolean(auth.token),
      hasQueryToken: Boolean(query.token),
      authKeys: Object.keys(auth),
      queryKeys: Object.keys(query),
    });

    if (!token) {
      // eslint-disable-next-line no-console
      console.error('Socket auth failed: missing token');
      return next(new Error('Unauthorized'));
    }

    const user = await authenticateWithLaravel(token);
    socket.data.user = user;
    socket.data.isServer = false;
    // eslint-disable-next-line no-console
    console.log('Socket connected as', user.uuid || user.full_name);

    return next();
  } catch (err) {
    // eslint-disable-next-line no-console
    console.error('Socket auth failed:', err?.message || err);
    return next(new Error('Unauthorized'));
  }
});

io.on('connection', (socket) => {
  if (socket.data.isServer) {
    socket.on('server:broadcast', (message = {}) => {
      const rooms = Array.isArray(message.rooms) ? message.rooms : [];
      const event = message.event;
      const payload = message.payload ?? {};

      if (!event || typeof event !== 'string') {
        return;
      }

      for (const room of rooms) {
        if (!isAllowedRoom(room)) {
          continue;
        }
        io.to(room).emit(event, payload);
      }
    });

    return;
  }

  socket.on('join', (room, ack) => {
    if (!isAllowedRoom(room)) {
      if (typeof ack === 'function') {
        ack({ success: false, message: 'Room not allowed' });
      }
      return;
    }

    socket.join(room);

    if (typeof ack === 'function') {
      ack({ success: true, room });
    }
  });

  socket.on('leave', (room, ack) => {
    socket.leave(room);

    if (typeof ack === 'function') {
      ack({ success: true, room });
    }
  });
});

server.listen(PORT, HOST, () => {
  // eslint-disable-next-line no-console
  console.log(`Rashid realtime listening on http://${HOST}:${PORT} (path ${PATH})`);
});
