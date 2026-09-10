# P1 code completion plan

The initial implementation is now platform-aligned and contract-locked. The remaining production code is intentionally sequenced as:

1. Backend session/device domain + platform JWT/RBAC/tenant adapters.
2. WebSocket signaling service with strict session-token scope and no media/input payloads.
3. STUN/TURN service integration using short-lived credentials.
4. Browser RTCPeerConnection viewer: offer/answer, ICE, track rendering and teardown.
5. Android MediaProjection -> MediaCodec -> libwebrtc video track, with default OFF state.
6. Device registration/provisioning and heartbeat/telemetry.
7. Audit records for open/close/command/session outcomes.
8. End-to-end P1 test harness: backend ↔ signaling ↔ agent ↔ browser WebRTC.

Each stage must pass automated tests before the next is merged. P2 input injection remains gated on the R1 Android PoC and must use AccessibilityService; it is not being simulated as a P1 shortcut.
