# VRemote P1 implementation status

## Usable P1 implemented

- Contract `api/vremote` v0.0.1 retained unchanged.
- Laravel 13 backend runtime with scoped session tokens and REST device/session/command/audit/integration endpoints.
- Vplus bearer JWT verification boundary; development auth is explicitly local-only.
- Backend-only HMAC signing with the contract golden vectors.
- Session lifecycle: authorization → signed `session.enable` → scoped browser token → TTL expiry → audit.
- Authenticated signaling: viewer session-token verification and outbound agent device-token authentication.
- Browser WebRTC viewer: SDP/ICE exchange and remote video rendering.
- Android agent: MediaProjection screen capture, libwebrtc peer connection, outbound-only WebSocket, signed session-enable verification, timestamp skew and nonce replay protection.
- TURN REST ephemeral credentials using coturn shared-secret authentication; Android can derive the same per-session credential when provisioned with the TURN shared secret.
- Safe fleet commands: health/version/ping/logs/config-read; GATED operations remain disabled by default.
- Command signing and commandId idempotency boundary.
- Docker Compose runnable stack: backend, console, signaling and TURN.
- CI covers contract, backend, console, signaling and Android build/test.

## Security gates

- No inbound listener is added to the Android agent; it initiates the WebSocket connection.
- Screen capture is OFF until a valid backend-signed `session.enable` is accepted.
- Backend is the only signer for box commands.
- `edge.admin.view` and `edge.admin.control` remain separate.
- GATED operations require explicit policy + confirmation and remain off by default.
- Device secrets are not returned by the device API.

## Local run path

1. Copy `backend/.env.example` to `backend/.env` and set unique secrets.
2. Configure the demo device token consistently in backend and Android Agent.
3. Run `docker compose up --build`.
4. Open the console at `http://localhost:5173`.
5. Install the Android debug APK, configure the device/signaling/TURN settings, grant MediaProjection permission, and leave the agent running.
6. The device should become available for `View`; opening a session establishes WebRTC and displays the screen.

## Production hardening still required

- Replace the development JSON registry with the shared vplus-platform registry/storage capability.
- Complete exact deployed Vplus SSO issuer/claim mapping and production key rotation.
- Move audit/session/command storage to durable shared platform storage and persist agent command results.
- Deploy WSS/HTTPS behind Vplus ingress and managed secrets.
- Complete P2 input injection only after the required Android R1 PoC is approved.
- Add production fleet scale, device provisioning/rotation and P3 integration UX.

These are deployment/product hardening items. They do not require changing the locked VRemote contract unless a new capability cannot be represented by v0.0.1.
