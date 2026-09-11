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

## Production user flow

Target public URL: `https://vremote.vplus.vn`.

1. User signs in through Vplus SSO.
2. User downloads the approved **VRemote Android Agent** from the Console.
3. Agent is installed on the Android edge box.
4. On first run, the operator configures the device ID, unique device token, Agent WSS URL, TURN URL and TURN shared secret, then grants Android MediaProjection.
5. Agent connects outbound to `wss://vremote.vplus.vn/agent` and waits with screen capture OFF.
6. Operator selects an online device and clicks **View**.
7. Backend authorizes the session, creates a scoped TTL token, signs `session.enable`, and sends it to signaling.
8. Android verifies the signature and starts screen capture.
9. Browser and Android establish WebRTC; video does not pass through the backend.
10. Closing the session or TTL expiry stops capture.

The Console download URL is controlled by `VITE_AGENT_DOWNLOAD_URL` and defaults to `/downloads/vremote-agent.apk`. The signed APK is deployed outside the Git source tree (see `deploy/PRODUCTION.md`).

## Local runnable stack

Prerequisites: Docker + Docker Compose.

```bash
docker compose up --build
```

Open `http://localhost:5173`. The compose profile includes Laravel backend (`:8080`), browser console (`:5173`), signaling (`:8787`) and coturn (`:3478`). The bundled `DEV-001` is a development registry entry; a real Android agent must be online before opening a session.

## Android agent

Build `agent-android` with Android Studio/Gradle and install the APK. The first-run screen supports:

- Device ID
- per-device device token
- Agent WSS URL (production default: `wss://vremote.vplus.vn/agent`)
- TURN URL
- TURN shared secret

Press **Enable VRemote Agent** and approve Android MediaProjection once. Screen capture remains OFF until the backend sends a valid signed `session.enable`; the agent verifies timestamp, nonce and HMAC before starting capture.

Build the debug APK locally with Gradle (`gradle :app:assembleDebug`). Production use requires a separately signed release APK.

## Production deployment

See `deploy/PRODUCTION.md` and `deploy/nginx/vremote.vplus.vn.conf` for DNS, TLS/WSS, reverse proxy, TURN, secret handling and smoke-test steps.

Set `VREMOTE_DEV_AUTH=false`, replace all example secrets, integrate the deployed Vplus SSO/JWKS configuration, use managed persistent registry/audit/session storage, and deploy a production TURN service. Do not expose signaling internal port `8788` or backend port `8080` directly.

## Security invariants

- No inbound port is opened on the box.
- Screen sharing is OFF by default.
- Backend alone signs session commands.
- `edge.admin.view` and `edge.admin.control` are separate.
- GATED operations are OFF by default.
- Device tokens are never returned by the device API.
- Timestamp skew and nonce replay protection follow `signing.md`.
- VBridge lock-control is out of scope.
