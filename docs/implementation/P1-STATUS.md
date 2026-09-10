# VRemote P1 implementation status

## Implemented

- Contract v0.0.1 retained under `api/vremote`.
- Platform baseline documented from `vplus-platform/control`, `sab-com-vn/platform-service`, and `sab-com-vn/admin-portal`.
- Laravel backend baseline and REST route boundary.
- Contract-compatible HMAC signer with golden-vector unit tests.
- React/Vite console registry shell.
- Kotlin Android agent baseline with foreground service and safe screen-off default.
- WebSocket signaling relay: join + peer relay; media/input are never transported by this service.
- Local coturn compose service.

## Security gates

- Backend-only signing remains an invariant.
- Nonce/timestamp validation is specified by `api/vremote/signing.md`; production session issuance must enforce it at the agent boundary.
- No inbound listener is added to the Android agent.
- GATED operations remain disabled until explicit authorization policy exists.

## Verification

The signing implementation is covered by the three contract golden vectors and tamper tests. Full application build/test requires dependency installation in CI/runner; repository CI should execute backend PHPUnit, console TypeScript/Vite build, signaling TypeScript build, and Android Gradle tests.

## Remaining production work

P1 still requires real platform identity middleware, persistent registry/session/audit repositories, authenticated signaling tokens, ephemeral TURN credentials, actual browser WebRTC media pipeline, and Android MediaProjection/libwebrtc capture. These are implementation milestones, not contract changes. R1 input injection remains P2.
