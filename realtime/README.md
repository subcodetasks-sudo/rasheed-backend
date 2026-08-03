# Rashid realtime (Socket.IO)

Node sidecar that fans out Laravel broadcasts to browser clients.

```bash
cp .env.example .env   # set SOCKET_IO_SECRET to match Laravel
npm install
npm start
```

Laravel needs `BROADCAST_CONNECTION=socketio`, `SOCKET_IO_URL`, and the same `SOCKET_IO_SECRET`.
