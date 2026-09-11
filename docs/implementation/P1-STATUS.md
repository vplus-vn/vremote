# VRemote P1 implementation status

## Implemented

- Contract v0.0.1 retained under `api/vremote`.
- Laravel 13 backend runtime with scoped session tokens and REST session/device/command endpoints.
- Vplus bearer JWT verification boundary; development auth is explicitly local-only.
- Backend-only HMAC signing with contract golden vectors.
- Backend → signaling internal control channel for signed `session.enable`/`session.disable`/commands.
- Authenticated signaling: viewer session-token verification and outbound agent device-token authentication.
- Browser WebRTC viewer: SDP/ICE exchange and remote video rendering.
- Android agent: MediaProjection screen capture, libwebrtc peer connection, outbound-only WebSocket, signed session-enable verification, timestamp skew and nonce replay protection.
- Ephemeral TURN credentials using coturn shared-secret authentication.
- Docker Compose runnable stack: backend, console, signaling and TURN.
- CI covers backend, console, signaling and Android build/test.

## Security gates

- No inbound listener is added to the Android agent; it initiates the WebSocket connection.
- Screen capture is OFF until a valid backend-signed `session.enable` is accepted.
- Backend is the only signer for box commands.
- `edge.admin.view` and `edge.admin.control` remain separate.
- GATED operations require explicit confirmation and remain off by default.
- Device secrets are not returned by the device API.

## Verification

Signing golden vectors are unit-tested. CI is configured to build the full stack and Android APK. The runnable development path is `docker compose up --build` followed by Android agent configuration and MediaProjection consent.

## Remaining production hardening

- Replace the development JSON registry with the shared platform registry/storage capability.
- Complete the exact vplus SSO/permission claim mapping with the deployed identity issuer/public key.
- Add durable audit/event storage and fleet command result persistence.
- Deploy WSS/HTTPS behind the Vplus ingress and use a managed secret store.
- Complete P2 input injection only after the required Android R1 PoC is approved.
- Add production multi-device/fleet operations and integration embed flow in P3.

These are deployment/product hardening items; `api/vremote` 0.0.1 remains unchanged.
