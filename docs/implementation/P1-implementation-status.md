# VRemote P1 implementation status

> **SUPERSEDED — snapshot lịch sử.** Tài liệu trạng thái canonical là [`STATUS.md`](STATUS.md). File này viết ở thời điểm sớm hơn nên liệt kê WebRTC/MediaProjection/TURN là "còn phải làm"; thực tế các phần đó đã có ở mức prototype trong baseline v0.1.0 (xem `STATUS.md` §1).

## Platform alignment

Verified against `vplus-platform/control` and the current shared `sab-com-vn/platform-service` baseline:

- Backend baseline: PHP 8.3 + Laravel 13, MongoDB, nwidart modules, PHPUnit/Pint.
- Shared platform capabilities to reuse: Identity, RBAC/permissions, tenant context, Audit, Storage.
- Console baseline: Vite + React + TypeScript, matching `sab-com-vn/admin-portal`.

The target architecture explicitly says not to duplicate existing platform capabilities. VRemote therefore keeps domain-specific remote-device/session/signaling logic in this product repository.

## Implemented in repository

- Contract files under `api/vremote/`.
- Laravel backend baseline under `backend/`.
- Contract-compatible HMAC signing service with canonical LF serialization and args hashing.
- Browser console P1 registry shell under `console/`.
- Android Kotlin application baseline under `agent-android/` with MediaProjection foreground-service boundary and screen sharing OFF by default.

## Validation gates

1. Contract signing golden vectors are represented in backend tests and must remain byte-for-byte compatible with `api/vremote/signing.md`.
2. Android signer has the same canonicalization/HMAC algorithm and is intended to share the same vectors in CI once the Android test source is admitted by the repository checks.
3. No WebSocket message carries media or input; those belong to WebRTC.
4. Backend, not console, owns box-command signing.
5. No VBridge lock-control contract is modified.

## Not falsely marked complete

P1 production functionality is **not yet complete**: WebSocket signaling transport, actual STUN/TURN allocation, WebRTC SDP/ICE pipeline, platform SSO adapter, registry persistence/provisioning, audit persistence, and Android MediaProjection/libwebrtc capture still require implementation. They are deliberately not replaced with fake/mock production behavior.
