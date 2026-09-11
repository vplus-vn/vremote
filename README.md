# VRemote

VRemote is the Vplus remote screen monitoring/control product for Android edge boxes.

## Architecture

VRemote is built on the shared **vplus-platform** baseline. P1 is Monitor-first: registry, session/token, signaling, STUN/TURN, browser WebRTC receive, and Android screen capture. P2 adds input/control; P3 adds fleet/integrations.

## Contract-first

`api/vremote` version `0.0.1` is the source of truth. Do not change it unilaterally.

- `api/vremote/openapi.yaml` — REST
- `api/vremote/asyncapi.yaml` — realtime channels
- `api/vremote/signing.md` — HMAC and golden vectors

Media/input travel directly over WebRTC; WebSocket is control/signaling only. Backend is the only component that signs `session.enable`/`command`.

## Runnable stack

Prerequisites: Docker + Docker Compose.

```bash
docker compose up --build
```

Open `http://localhost:5173`. The compose profile includes Laravel backend (`:8080`), browser console (`:5173`), signaling (`:8787`) and coturn (`:3478`). The bundled `DEV-001` is a development registry entry; a real Android agent must be online before opening a session.

For the Android agent, build `agent-android` with Android Studio/Gradle, install the APK, and configure:

- Device ID
- per-device device token (must match backend registry)
- Agent WebSocket URL, e.g. `ws://<vremote-host>:8787/agent`
- TURN URL and the same TURN shared secret used by coturn/backend

Press **Enable VRemote Agent** and approve Android MediaProjection once. Screen capture remains OFF until the backend sends a valid signed `session.enable`; the agent verifies timestamp, nonce and HMAC before starting capture.

## Production hardening

Set `VREMOTE_DEV_AUTH=false` and integrate the backend bearer middleware with the vplus Identity/SSO gateway. Replace all example secrets. Use TLS/WSS, a managed secret store, persistent platform registry/audit, and a real production TURN hostname. The development JSON registry is intentionally a small bootstrap boundary; production device registry must be backed by the shared platform capability.

## Security invariants

- No inbound port is opened on the box.
- Screen sharing is OFF by default.
- Backend alone signs session commands.
- `edge.admin.view` and `edge.admin.control` are separate.
- GATED operations are OFF by default.
- Device tokens are never returned by the device API.
- Timestamp skew and nonce replay protection follow `signing.md`.
- VBridge lock-control is out of scope.
