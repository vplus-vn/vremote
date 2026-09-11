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
- Backend and signaling health endpoints.
- Production ingress template for `vremote.vplus.vn` with HTTPS and WSS routing.
- Console entry for downloading the approved Android Agent APK.
- CI publishes the Android debug APK as a workflow artifact.

## Security gates

- No inbound listener is added to the Android agent; it initiates the WebSocket connection.
- Screen capture is OFF until a valid backend-signed `session.enable` is accepted.
- Backend is the only signer for session/command messages.
- `edge.admin.view` and `edge.admin.control` remain separate.
- GATED operations require explicit policy + confirmation and remain off by default.
- Device secrets are not returned by the device API.

## Production deployment

Target public URL: `https://vremote.vplus.vn`.

1. Point DNS to the VRemote ingress host.
2. Provision a trusted TLS certificate.
3. Deploy Nginx using `deploy/nginx/vremote.vplus.vn.conf`.
4. Run backend, console and signaling on private loopback ports.
5. Deploy production coturn and keep the shared TURN secret consistent across backend, coturn and agents.
6. Set `VREMOTE_DEV_AUTH=false` and configure the deployed Vplus SSO/JWKS boundary.
7. Deploy a signed Android release APK to `/srv/vremote/downloads/vremote-agent.apk` or set `VITE_AGENT_DOWNLOAD_URL` to its approved location.
8. Enroll/configure an Android box and verify the end-to-end WebRTC smoke test in `deploy/PRODUCTION.md`.

## Remaining product/platform work

- Replace the development JSON registry with the shared vplus-platform registry/storage capability.
- Complete exact deployed Vplus SSO issuer/claim mapping and production key rotation.
- Move audit/session/command storage to durable shared platform storage and persist agent command results.
- Production TURN hardening (TLS/DTLS, relay range, firewall and monitoring).
- QR/device enrollment and automated credential rotation.
- Complete P2 input injection only after the required Android R1 PoC is approved.
- Add production fleet scale and P3 integration UX.

These are production platform/product items. The locked VRemote contract remains unchanged.
