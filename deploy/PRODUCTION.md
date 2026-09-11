# VRemote production deployment

Target public host: `vremote.vplus.vn`.

## Runtime

Expose only the reverse proxy publicly:

- Nginx: `80/443`
- Backend: `127.0.0.1:8080`
- Console: `127.0.0.1:5173`
- Signaling: `127.0.0.1:8787`
- TURN: `3478` UDP/TCP and a production relay range

Do not expose the signaling internal port (`8788`) or backend directly to the Internet.

## DNS

Create an `A`/`AAAA` record for `vremote.vplus.vn` pointing to the VRemote ingress host.

## TLS

Provision a trusted certificate for `vremote.vplus.vn` and enable the certificate/key lines in `deploy/nginx/vremote.vplus.vn.conf`. The browser console must be served over HTTPS and signaling/agent traffic must use WSS.

## Secrets

Generate unique production values for:

- `VREMOTE_SESSION_SECRET`
- `VREMOTE_SIGNING_SECRET` (or the configured signing key material)
- `VREMOTE_SIGNALING_INTERNAL_SECRET`
- `VREMOTE_TURN_SHARED_SECRET`
- production Vplus JWT verification configuration

Store them in the deployment secret manager/environment, never in Git. Do not reuse the compose demo values.

Set `VREMOTE_DEV_AUTH=false`.

## Backend

Build/install the Laravel backend with production dependencies, configure the deployed Vplus SSO issuer/algorithm/public-key or JWKS integration, and run the application behind the Nginx `/api/` proxy.

Health endpoint: `GET /api/vremote/health`.

## Signaling

Run the signaling service on `127.0.0.1:8787`. The public endpoints are:

- `wss://vremote.vplus.vn/signaling`
- `wss://vremote.vplus.vn/agent`

The internal command dispatch endpoint stays on `127.0.0.1:8788` and is protected by `X-VRemote-Internal-Secret`.

## TURN

Use a public TURN hostname and configure the same `VREMOTE_TURN_SHARED_SECRET` in coturn, backend, and provisioned Android agents. For production, enable TLS/DTLS as required by the network policy and configure a bounded relay port range/firewall rules.

## Android agent

Build a signed release APK from `agent-android`. Place the approved APK outside the source tree, for example:

`/srv/vremote/downloads/vremote-agent.apk`

The console download link is configured by `VITE_AGENT_DOWNLOAD_URL` and defaults to `/downloads/vremote-agent.apk`.

On first install, configure:

- device ID
- unique device token
- `wss://vremote.vplus.vn/agent`
- production TURN URL
- production TURN shared secret

Grant MediaProjection when requested. The agent opens an outbound connection; no inbound box port is required.

## First smoke test

1. Login through Vplus SSO.
2. Verify the device appears `online`.
3. Open `View`.
4. Verify a session is created and audited.
5. Verify the Android agent accepts the signed `session.enable`.
6. Verify the browser receives the screen over WebRTC.
7. Close the session and verify the screen capture stops after session close/TTL expiry.
8. Verify safe commands only; gated commands must remain disabled.

## Production boundary

This runbook does not silently enable P2 input control, reboot/config-push/apk-update/cache-clear, or fleet automation. Those remain separate capabilities and require their documented security gates.
