# VRemote

VRemote is the Vplus remote screen monitoring/control product for Android edge boxes.

## Architecture

VRemote is built on the shared **vplus-platform** baseline. The platform reference identifies `sab-com-vn/platform-service` as the existing shared capability layer for Identity, RBAC, tenant context, Audit, Storage and related middleware; VRemote must reuse those capabilities rather than duplicate them.

P1 is Monitor-first: registry/provisioning, SSO/RBAC, session/token, WebSocket signaling, STUN/TURN, browser WebRTC receive pipeline, audit, and Android screen capture.

P2 adds input/control; P3 adds fleet and product integrations.

## Contract-first

`api/vremote` version `0.0.1` is the source of truth. Contract changes require an explicit project decision before implementation.

- `api/vremote/openapi.yaml` — REST contract
- `api/vremote/asyncapi.yaml` — signaling/agent WebSocket contract
- `api/vremote/signing.md` — HMAC signing and golden vectors

Media and input travel directly over WebRTC. WebSocket is signaling/control-plane only.

## Platform baseline

- Backend: Laravel/PHP baseline from `sab-com-vn/platform-service` (PHP 8.3, Laravel 13, MongoDB, nwidart modules, PHPUnit/Pint).
- Identity/RBAC/tenant/audit: reuse platform capabilities.
- Console: Vite + React + TypeScript, matching `sab-com-vn/admin-portal`.
- Signaling: keep the first implementation small and platform-compatible; WebSocket signaling must not carry media/input.
- Android agent: Kotlin + MediaProjection + MediaCodec + libwebrtc; P2 input via AccessibilityService.
- TURN: coturn with short-lived credentials.

## Security invariants

- No inbound port is opened on the box.
- Screen sharing is OFF by default.
- Backend alone signs `session.enable` and `command`.
- `edge.admin.view` and `edge.admin.control` are separate permissions.
- GATED operations are OFF by default.
- Device tokens are scoped per box and never logged.
- Timestamp skew and nonce replay protection follow `signing.md`.
- VBridge lock-control contract is out of scope.

## Development

Follow the platform repository governance: work on a branch, validate with automated tests, and merge through Pull Request. Never change `api/vremote` unilaterally.
