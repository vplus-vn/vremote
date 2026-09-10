# DSG-VREMOTE-001 — Thiết kế kiến trúc: VRemote (điều khiển edge box từ xa)

- **Mã:** DSG-VREMOTE-001
- **Ngày:** 2026-09-10
- **Trạng thái:** DRAFT để bàn giao hiện thực.
- **Phương án:** **T1 — tự viết engine trên WebRTC**; không dùng RustDesk và không tự viết NAT-traversal từ số 0.
- **Nguồn:** chuyển từ `sab-com-vn/edge-agent/docs/design/DSG-VREMOTE-001-remote-admin-architecture.md`.

## 1. Tổng quan

VRemote nối **VRemote Console (web)** với **VRemote Agent (box)** qua **WebRTC**, sử dụng signaling + STUN + TURN self-host. Backend VRemote quản lý phiên, quyền, device registry, fleet-ops và audit; backend là cửa duy nhất để cấp phép mở phiên.

Nguyên tắc:
- WebRTC đảm nhiệm ICE/STUN/TURN, DTLS-SRTP và vận chuyển media/data.
- Backend là nơi duy nhất cấp phép mở phiên, ký HMAC và cấp TTL.
- Box mặc định không chia sẻ màn hình; chỉ bật khi có phiên hợp lệ.
- Box chỉ outbound tới signaling/TURN, không mở inbound.

## 2. Tổ chức repo

| Repo | Vai trò |
|---|---|
| `sab-com-vn/edge-agent` | **VBridge** — web ↔ phần cứng ngoại vi (khóa, đầu đọc…). Giữ nguyên contract lock-control. |
| `vplus-vn/vremote` | **VRemote** — agent trên box + console web + backend + signaling + deploy + docs; xây trên `vplus-platform`. |

Hai repo tách code. VRemote Agent và VBridge chỉ liên hệ qua OS supervisor/IPC contract; chuẩn ký được dùng chung qua contract + golden vector, không qua shared code library.

## 3. Thành phần

### 3.1 VRemote Agent
- Android: MediaProjection, MediaCodec, WebRTC peer, AccessibilityService/device-owner cho input.
- Fleet exec cho các lệnh an toàn/gated.
- Supervisor VBridge với restart/update và rollback.
- Provisioning runtime cho key, endpoint và config; không hardcode secret.
- Mặc định TẮT screen, chỉ bật khi backend ủy quyền.

Artifact Android: `vremote-agent.apk`, device-owner/foreground service/BOOT_COMPLETED. Linux triển khai sau bằng JVM/native + systemd.

### 3.2 Signaling
WebSocket trung chuyển SDP offer/answer và ICE candidate; xác thực bằng session token do backend cấp; không giữ media.

### 3.3 STUN/TURN
STUN dùng để dò địa chỉ công khai. TURN/coturn relay khi P2P thất bại; credential TURN là ephemeral, TTL ngắn theo phiên.

### 3.4 Backend
- Auth/entitlement theo identity vplus.
- Session/token: token phạm vi hẹp theo device/capability/TTL.
- Device registry và map `deviceId ↔ machineCode`.
- Fleet-ops API.
- Audit.
- Integration API cho sản phẩm khác.

### 3.5 Console
Web app độc lập, đề xuất Vite + React + TypeScript; SSO vplus; danh sách box; mở phiên; `<video>`; input; signaling/WebRTC; hiển thị P2P/relay, latency và TTL.

## 4. Luồng chính

### Monitor (P1)
```text
Console → Backend: POST /sessions {deviceId, capability:"view"}
Backend → Console: sessionId + signalingUrl + sessionToken + TURN/ICE
Backend → Box: session.enable (HMAC + TTL)
Box: capture + encode + WebRTC peer
Console ↔ Signaling ↔ Box: SDP/ICE
Console ↔ Box: media P2P hoặc TURN
Hết TTL/disable → Box tắt capture, xóa credential tạm; Backend audit và đóng session
```

### Control (P2)
Giống Monitor nhưng `capability:"control"`; yêu cầu `edge.admin.control`, bật AccessibilityService và gửi input qua data channel. Chỉ một người control tại một thời điểm.

### Fleet ops (P3)
```text
Console/SP → Backend → kiểm quyền/gated → ký HMAC → Box
Box → verify HMAC + nonce/ts + policy → thực thi → result
Backend → audit → Console/SP
```

### Integration (P3)
Sản phẩm khác gọi `POST /integrations/sessions` với `machineCode/deviceId` và capability. VRemote map device, kiểm phạm vi và trả embed token/viewer URL TTL; sản phẩm tích hợp không nói trực tiếp với box.

## 5. Bảo mật

- Không inbound vào box.
- HMAC-SHA256 với canonical string + golden vector; áp dụng cho `session.enable` và command.
- `nonce` + `ts`, skew mục tiêu ±300s để chống replay.
- Session token giới hạn 1 device/1 capability và có `exp`.
- TURN credential ephemeral.
- Quyền tách `edge.admin.view`, `edge.admin.control` và gated.
- Gated mặc định TẮT + xác nhận + quyền cao + audit.
- Secret/provisioning nạp runtime; không log secret/PIN/OTP/credential/key.

## 6. API / Contract surface

REST:
- `POST /sessions`
- `DELETE /sessions/{id}`
- `GET /devices`
- `GET /devices/{id}`
- `POST /devices/{id}/commands`
- `GET /audit`
- `POST /integrations/sessions`

Signaling WebSocket: `join(sessionToken)`, `offer`, `answer`, `candidate`, `leave`, `error`.

Kênh box↔backend: `session.enable/disable`, `command`, canonical HMAC, nonce/ts và `commandId` idempotency.

Embeddable viewer: `<vremote-viewer token=... capability=...>` hoặc iframe với viewer URL và token hẹp.

## 7. Identity

VRemote dùng identity/SSO chuẩn vplus, không dựng silo account. Quyền vào phụ thuộc entitlement gói VRemote + role `edge.admin.*`. Thiết kế `IdentityProvider` để federate với vplus.vn SSO mà không viết lại P1.

## 8. Data model (phác)

- `device`: deviceId, machineCode, name, platform, status, agentVersion, publicKeyId, createdAt.
- `session`: sessionId, deviceId, userId, capability, source, startedAt, endedAt, endReason, ttl.
- `audit`: id, ts, actor, action, deviceId, sessionId, commandType, result, meta(no-secret).
- `command`: commandId, deviceId, type, args(no-secret), signedAt, nonce, result, status.
- `entitlement`: userId/tenant, package=VRemote, roles.

## 9. Isolation VBridge / VRemote Agent

**VBridge** điều khiển phần cứng ngoại vi. **VRemote Agent** giám sát/điều khiển bản thân box và quản trị box. Không kéo SDK vendor từ VBridge vào VRemote.

Luật isolation: cấm phụ thuộc code hai chiều; chỉ OS supervisor/IPC contract. HMAC dùng chung qua contract + golden vector.

## 10. Stack

| Phần | Đề xuất |
|---|---|
| Android Agent | Kotlin + MediaProjection + MediaCodec + WebRTC + AccessibilityService |
| Linux Agent | Kotlin/JVM + GStreamer `webrtcbin` hoặc Pion/Go |
| Signaling | Node hoặc Kotlin/Ktor WebSocket |
| TURN | coturn |
| Backend | Stack `vplus-platform` |
| Console | Vite + React + TypeScript |
| Ký | HMAC-SHA256 + canonical + golden vector |

## 11. Deploy

- Box: APK Android; Linux jar/native + systemd sau.
- Hạ tầng: `deploy/`, docker-compose cho signaling + coturn, TURN ephemeral secret, systemd và provisioning.
- Console/backend theo pipeline vplus.

## 12. Phân pha

- **P1 Monitor:** signaling + STUN + TURN + WebRTC + backend session/token + console view + registry/provision + audit.
- **P2 Control:** Accessibility PoC + quyền control + single-controller lock.
- **P3 Fleet + Integration:** fleet-ops, supervisor/rollback, dashboard, Integration API, embeddable viewer.

## 13. Việc cần chốt trước khi code

- R1: PoC Android input-injection trước P2.
- D2: VPS signaling + TURN và ước lượng tải.
- D3: interface SSO vplus.vn.
- D4: deviceId riêng + map machineCode (đề xuất).

## Trạng thái

**DRAFT** — bắt đầu từ contract-first + P1 Monitor. Không phá contract lock-control của VBridge; tái dụng chuẩn ký/golden vector.
