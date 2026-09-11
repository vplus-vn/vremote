# VRemote Project Contract

## Mission

Deliver VRemote as the Vplus remote monitoring/control product for Android edge boxes. The current release target is a production-deployable P1 Monitor stack: authenticated web console, device registry, scoped sessions, backend signing, authenticated signaling, STUN/TURN, WebRTC screen viewing, Android Agent, audit, and safe commands.

## Engineering mode

- Work contract-first. `api/vremote` v0.0.1 is locked unless an explicit contract change is approved.
- Prefer the existing vplus-platform capabilities over duplicated infrastructure.
- Continue implementation, testing, CI repair, documentation, and deployment hardening without unnecessary confirmation loops.
- Never claim a test, deployment, CI gate, or runtime verification that was not actually observed.
- Never expose, print, commit, or paste secrets, private keys, or tokens.
- Never weaken repository/security protections or merge with failed required gates.

## Security invariants

- Android Agent uses an outbound connection; no inbound device port.
- Screen capture is disabled until a valid backend-signed session is accepted.
- Backend is the only signer for session/command messages.
- `edge.admin.view` and `edge.admin.control` are separate permissions.
- GATED operations are disabled by default.
- Session tokens are scoped and short-lived.
- Timestamp skew and nonce replay protection are mandatory.
- Device secrets must never be returned by device APIs.

## Runtime topology

`vremote.vplus.vn` serves the console over HTTPS. `/api/` proxies to Laravel, `/signaling` and `/agent` proxy to the signaling service over WSS, and TURN is deployed separately. Media flows directly between browser and Android through WebRTC; signaling is not the media path.

## Device onboarding

The current Android Agent supports manual first-time configuration of device ID, device token, Agent WSS URL, TURN URL and TURN shared secret. The console exposes a configurable APK download link. QR enrollment and automated credential rotation are follow-up production capabilities.

## Release boundary

P1 screen monitoring is the immediate usable release. P2 remote input/control requires the documented Android R1 proof-of-concept and separate security review. Fleet-scale provisioning/integration is P3.
