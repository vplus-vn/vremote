# DSG-VREMOTE-001 — Thiết kế kiến trúc: VRemote (điều khiển edge box từ xa)

- **Mã:** DSG-VREMOTE-001
- **Ngày:** 2026-09-11
- **Trạng thái:** DRAFT để bàn giao hiện thực.
- **Phương án:** **T1 — tự viết engine trên WebRTC**; không dùng RustDesk và không tự viết NAT-traversal từ số 0.
- **Nguồn:** chuyển từ `sab-com-vn/edge-agent/docs/design/DSG-VREMOTE-001-remote-admin-architecture.md`.

## 1. Tổng quan

VRemote nối **VRemote Console (web)** với **VRemote Agent (box)** qua **WebRTC**, sử dụng signaling + STUN + TURN self-host. VRemote là domain service; Platform cung cấp identity/authentication và platform contract. VRemote quản lý device runtime, enrollment, session, command, WebRTC/signaling/TURN và audit.

Nguyên tắc:
- WebRTC đảm nhiệm ICE/STUN/TURN, DTLS-SRTP và vận chuyển media/data.
- Backend VRemote là nơi duy nhất cấp phép mở phiên, ký HMAC và cấp TTL.
- Box mặc định không chia sẻ màn hình; chỉ bật khi có phiên hợp lệ.
- Box chỉ outbound tới signaling/TURN, không mở inbound.
- Device thuộc **một Owner Tenant tại một thời điểm**, không gắn ownership trực tiếp với một User. Một Device có thể được nhiều User/Principal truy cập theo Role/Permission/Scope hoặc Access Grant.
- `VREMOTE_PLATFORM_URL` là điểm tích hợp Platform duy nhất; không có `VREMOTE_PLATFORM_MODE` và không dựng silo account/auth riêng cho VRemote.

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
- Sau enrollment, Agent giữ **Device Identity + credential lâu dài** trong secure storage của OS; enrollment credential không được dùng cho các lần kết nối thông thường.

Artifact Android: `vremote-agent.apk`, device-owner/foreground service/BOOT_COMPLETED. Linux triển khai sau bằng JVM/native + systemd.

### 3.2 Signaling
WebSocket trung chuyển SDP offer/answer và ICE candidate; xác thực bằng session token do backend cấp; không giữ media.

### 3.3 STUN/TURN
STUN dùng để dò địa chỉ công khai. TURN/coturn relay khi P2P thất bại; credential TURN là ephemeral, TTL ngắn theo phiên.

### 3.4 Backend
- Platform identity/authentication adapter.
- Device enrollment, durable identity và runtime registry.
- Session/token: token phạm vi hẹp theo device/capability/TTL.
- Device runtime state và map `deviceId ↔ machineCode`.
- Fleet-ops API.
- Device access/transfer/support-access policy.
- Audit.
- Integration API cho sản phẩm khác.

### 3.5 Console
Web app độc lập, đề xuất Vite + React + TypeScript; tích hợp Platform SSO; danh sách box; enrollment; mở phiên; `<video>`; input; signaling/WebRTC; hiển thị P2P/relay, latency và TTL.

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
Sản phẩm khác gọi `POST /integrations/sessions` với `machineCode/deviceId` và capability. VRemote map device, kiểm scope và trả embed token/viewer URL TTL; sản phẩm tích hợp không nói trực tiếp với box.

## 5. Bảo mật

- Không inbound vào box.
- HMAC-SHA256 với canonical string + golden vector; áp dụng cho `session.enable` và command.
- `nonce` + `ts`, skew mục tiêu ±300s để chống replay.
- Session token giới hạn 1 device/1 capability và có `exp`.
- TURN credential ephemeral.
- Quyền tách `edge.admin.view`, `edge.admin.control` và gated.
- Gated mặc định TẮT + xác nhận + quyền cao + audit.
- Secret/provisioning nạp runtime; không log secret/PIN/OTP/credential/key.
- Enrollment credential là credential **tạm thời của quá trình enroll**, không phải password tài khoản người dùng và không phải credential dùng cho session thường ngày.
- Device credential dài hạn phải được lưu trong secure storage của Agent, có lifecycle rotation/revocation/recovery.
- QR không chứa secret dài hạn; nếu chứa enrollment token thì token phải one-time/TTL ngắn và được invalidated sau khi bind thành công.

## 6. API / Contract surface

REST:
- `POST /sessions`
- `DELETE /sessions/{id}`
- `GET /devices`
- `GET /devices/{id}`
- `POST /devices/{id}/commands`
- `GET /audit`
- `POST /integrations/sessions`

Enrollment API là domain API của VRemote và được thiết kế riêng, không thay đổi `api/vremote` v0.0.1 nếu chưa có contract change được phê duyệt.

Signaling WebSocket: `join(sessionToken)`, `offer`, `answer`, `candidate`, `leave`, `error`.

Kênh box↔backend: `session.enable/disable`, `command`, canonical HMAC, nonce/ts và `commandId` idempotency.

Embeddable viewer: `<vremote-viewer token=... capability=...>` hoặc iframe với viewer URL và token hẹp.

## 7. Identity và Tenant model

VRemote không dựng silo account. Platform chịu trách nhiệm identity/authentication, User, Tenant, Role/Permission và scope. VRemote nhận identity context từ Platform Contract, tối thiểu gồm `userId`, `tenantId` và permissions/scopes cần thiết để quyết định truy cập Device.

### 7.1 Ownership chuẩn

Một Device/Terminal thuộc **đúng một Owner Tenant tại một thời điểm**. Đây là ownership/security boundary của resource. Không mô hình hóa `Device → User` như ownership trực tiếp và không mặc định cho một Device đồng thời thuộc nhiều Tenant.

```text
Tenant A
├── Sites
│   └── Areas / Lines
│       └── Devices
└── Users
    └── Roles / Permissions / Scope
```

Một Device có thể được nhiều User/Principal trong Owner Tenant truy cập:

```text
Tenant A
├── User A1 → DEV-001 (view + control)
├── User A2 → DEV-001 (view)
└── User A3 → DEV-001 (admin)
```

### 7.2 Delegated / Support Access

Nếu một Tenant khác, VPLUS Support hoặc đơn vị vận hành cần hỗ trợ Device, sử dụng **Access Grant / Delegated Access**, không tạo ownership Tenant thứ hai.

```text
DEV-001
Owner Tenant: A

Access Grants:
├── Tenant A / Users A1,A2 → theo Role/Scope
└── VPLUS Support → view/control, TTL giới hạn
```

Support access phải có actor, reason/ticket nếu áp dụng, capability, scope, `grantedAt`, `expiresAt`, revoke và audit. Hết TTL thì quyền tự mất. Tenant B/Support không được xem dữ liệu của Tenant A ngoài phạm vi grant.

### 7.3 Transfer giữa Tenant

Chuyển Device từ Tenant A sang Tenant B là **Transfer/Re-assignment**, không phải enrollment lại theo mặc định.

```text
DEV-001
   │
   ▼
Tenant A
   │
   │ Transfer request
   ▼
TRANSFER_PENDING
   │
   ├── A xác nhận release
   └── B xác nhận accept (nếu policy yêu cầu)
   │
   ▼
Tenant B
```

Transfer phải:
- Giữ nguyên `deviceId`/Device Identity và lịch sử kỹ thuật của Device.
- Thay đổi `ownerTenantId` theo transaction/audit nguyên tử.
- Revoke các Access Grant không còn hợp lệ của Tenant A.
- Không tự động cho Tenant B xem dữ liệu lịch sử thuộc Tenant A nếu policy/data isolation không cho phép.
- Có audit `DEVICE_TRANSFER` với fromTenant, toTenant, actor, approver và timestamp.
- Có thể yêu cầu credential rotation sau transfer theo policy bảo mật.

Device chỉ trở về enrollment/re-provisioning khi mất hoặc reset Device Identity/credential, factory reset, thiết bị bị retired/revoked hoặc policy bảo mật yêu cầu. **Thêm User, đổi Role, cấp Support Access hoặc Transfer Tenant không mặc định yêu cầu re-enrollment.**

VRemote chỉ kiểm tra quyền/scope đối với Device khi thực hiện enrollment, session, command hoặc các thao tác domain tương ứng; Platform không phải proxy cho toàn bộ VRemote API.

## 8. Data model (phác)

### Platform-owned context
- `user`: userId, tenantId, roles/permissions, scopes.
- `tenant`: tenantId và tenant metadata.
- Device ownership/scope ở cấp Platform Contract/Registry khi cần cho việc phân quyền.

### VRemote-owned domain
- `device`: deviceId, **ownerTenantId**, machineCode, name, platform, status, agentVersion, publicKeyId, enrollmentState, identityState, lastSeenAt, createdAt.
- `session`: sessionId, deviceId, userId, tenantId, capability, source, startedAt, endedAt, endReason, ttl.
- `audit`: id, ts, actor, tenantId, action, deviceId, sessionId, commandType, result, meta(no-secret).
- `command`: commandId, deviceId, type, args(no-secret), signedAt, nonce, result, status.
- `enrollment`: enrollmentId, deviceId, targetTenantId, method, status, createdAt, expiresAt, consumedAt, createdBy; không lưu secret/token plaintext dài hạn.
- `device_access_grant`: grantId, deviceId, granteePrincipal/tenant, capabilities, scope, reason/reference, grantedAt, expiresAt, revokedAt, createdBy; không lưu credential secret.
- `device_transfer`: transferId, deviceId, fromTenantId, toTenantId, status, initiatedBy, approvedBy, createdAt, completedAt, reason; audit đầy đủ.

## 9. Enrollment

Enrollment là quy trình **bind một VRemote Agent/Device vào một Owner Tenant và Device Registry của VRemote**, sau đó thiết lập **Device Identity + long-lived Device Credential** để Device có thể kết nối outbound tới VRemote. Enrollment không phải đăng nhập User vào Agent và **không phải thao tác lặp lại cho mỗi lần sử dụng/quản lý**.

Mục tiêu vận hành:

```text
ENROLL 1 LẦN
     │
     ▼
DEVICE ACTIVE
     │
     ├── reconnect
     ├── reboot
     ├── session mới
     ├── user mới / role mới
     ├── support access
     └── transfer tenant
          │
          └── KHÔNG re-enroll mặc định
```

VRemote hỗ trợ **đồng thời hai phương án enrollment**, để phù hợp cả triển khai tại chỗ và triển khai từ xa:

### 9.1 Phương án A — QR Code

Trên màn hình Device/Box hiển thị QR Code enrollment. Người quản trị dùng Console/mobile enrollment flow để quét QR.

```text
Device/Box
   │
   │ hiển thị QR enrollment
   ▼
Admin/Operator → Console/Mobile → scan QR
                                │
                                ▼
                         VRemote Backend
                                │
                    kiểm Owner Tenant + quyền + TTL
                                │
                                ▼
                         Enrollment approve
                                │
                                ▼
                         Device bound Tenant
                                │
                                ▼
                   generate/bind Device Identity
                                │
                                ▼
                     store durable credential
                                │
                                ▼
                         Agent reconnect
```

QR nên chứa một enrollment reference/token ngắn hạn, ví dụ về mặt logic:

```text
vremote://enroll?enrollmentId=<id>&token=<one-time-token>
```

Yêu cầu bảo mật:
- Token QR one-time, TTL ngắn.
- Không chứa private key hoặc credential dài hạn.
- Token bị consume/invalidate ngay sau khi enrollment thành công.
- Nếu enrollment chưa hoàn tất, token tự hết hạn.
- Có thể revoke enrollment đang pending.
- Audit toàn bộ create/scan/approve/consume/revoke.

### 9.2 Phương án B — nhập mã thiết bị + token/password

Dùng khi không tiện quét QR hoặc Device không có màn hình/camera phù hợp.

Device hiển thị hoặc được cấp:

```text
Device ID: DEV-001
Enrollment Code: 7H4K-92QM
Token/Password: ********
```

Operator mở Console, chọn **Add/Enroll Device**, sau đó nhập:

```text
Device ID / Enrollment Code
+
Enrollment Token / Password
```

Backend xác thực enrollment credential, kiểm tra Owner Tenant + quyền + TTL và bind Device.

Nguyên tắc:
- `Device ID` chỉ là identifier, không phải secret.
- `Enrollment Code` có thể dùng để tìm đúng enrollment transaction nhưng không đủ quyền nếu thiếu credential.
- `Enrollment Token/Password` là secret tạm thời, one-time hoặc TTL ngắn.
- Không sử dụng password này làm password đăng nhập User.
- Không log token/password.
- Sau khi bind thành công, enrollment credential phải bị vô hiệu hóa.
- Sau bind, Agent sử dụng Device Identity/Credential lâu dài đã được provision; **không yêu cầu operator nhập lại enrollment token/password cho mỗi session**.

### 9.3 Device Identity và long-lived credential

Enrollment phải tạo/bind một Device Identity ổn định và credential dùng cho runtime reconnect. Ưu tiên Agent tự tạo key pair trên thiết bị và giữ private key trong secure storage/keystore; backend chỉ lưu public key hoặc key reference cần thiết.

```text
First Enrollment
      │
      ├── Device ID / machineCode
      ├── one-time enrollment credential
      └── Device key pair / identity
              │
              ▼
       BOUND / ACTIVE
              │
              ▼
       durable runtime auth
              │
      ┌───────┼────────┐
      ▼       ▼        ▼
   reconnect session   command
```

Credential runtime phải:
- Không phụ thuộc User cụ thể.
- Không phải password của OS.
- Không gửi/lưu private key ở backend nếu Agent có thể tự giữ key.
- Có rotation, revocation và recovery/re-provisioning.
- Có thể bị revoke khi Device bị retire, mất kiểm soát hoặc vi phạm policy.
- Sau rotation, Agent reconnect bằng credential mới mà không cần enrollment transaction mới nếu rotation thành công.

### 9.4 UX chung cho hai phương án

Console nên cung cấp một entry point duy nhất:

```text
Add / Enroll Device
        │
        ├── Scan QR Code
        │
        └── Enter Device Code + Enrollment Token/Password
```

Hai phương án phải tạo ra **cùng một enrollment transaction và cùng một trạng thái cuối**; khác nhau chỉ ở phương thức trao credential.

Trạng thái enrollment đề xuất:

```text
PENDING
  ↓
VERIFIED
  ↓
BOUND
  ↓
ACTIVE

PENDING → EXPIRED / REVOKED
VERIFIED → REJECTED
```

`ACTIVE` nghĩa là Device đã bind Tenant và có Device Identity/Credential runtime hợp lệ; không có nghĩa phải giữ một enrollment token sống lâu dài.

### 9.5 Enrollment ownership

Enrollment phải xác định rõ Owner Tenant trước khi Device trở thành ACTIVE:

```text
Platform Identity
      │
      ▼
 User + Tenant + Permission/Scope
      │
      ▼
VRemote Enrollment
      │
      ├── Device ID
      ├── Machine Code (nếu có)
      ├── Enrollment method
      ├── Owner Tenant
      └── Device Identity/Credential binding
      │
      ▼
VRemote Device Registry
```

Một Device sau khi bind thuộc đúng **một Owner Tenant tại một thời điểm**; nhiều User/Principal có thể được cấp quyền truy cập theo Role/Scope/Access Grant. Việc chuyển Device sang Tenant khác là **Transfer/Re-assignment**; không mặc định re-enroll.

### 9.6 OS password và VRemote credential

Nếu Device/OS yêu cầu password/PIN để mở khóa hoặc cho phép thao tác cục bộ, đây là **OS credential**, không phải enrollment credential và không phải VRemote Device Credential.

VRemote không lưu hoặc log OS password/PIN. Có thể yêu cầu xác thực cục bộ trên box cho các thao tác nhạy cảm theo policy, nhưng việc Device reconnect/quản lý từ xa không được phụ thuộc vào việc operator nhập lại OS password.

### 9.7 Enrollment và Platform Contract

VRemote sử dụng `VREMOTE_PLATFORM_URL` để kết nối Platform. Platform cung cấp identity context và các primitive cần thiết để xác định Tenant/User/Permission/Scope. VRemote thực hiện enrollment domain và lưu trạng thái runtime/domain của Device.

Không tạo:

```text
VREMOTE_PLATFORM_MODE=local
VREMOTE_PLATFORM_MODE=external
```

và không tạo local-user fallback trong kiến trúc production.

## 10. Isolation VBridge / VRemote Agent

**VBridge** điều khiển phần cứng ngoại vi. **VRemote Agent** giám sát/điều khiển bản thân box và quản trị box. Không kéo SDK vendor từ VBridge vào VRemote.

Luật isolation: cấm phụ thuộc code hai chiều; chỉ OS supervisor/IPC contract. HMAC dùng chung qua contract + golden vector.

## 11. Stack

| Phần | Đề xuất |
|---|---|
| Android Agent | Kotlin + MediaProjection + MediaCodec + WebRTC + AccessibilityService |
| Linux Agent | Kotlin/JVM + GStreamer `webrtcbin` hoặc Pion/Go |
| Signaling | Node hoặc Kotlin/Ktor WebSocket |
| TURN | coturn |
| Backend | VRemote domain service + Platform Contract adapter |
| Console | Vite + React + TypeScript |
| Ký | HMAC-SHA256 + canonical + golden vector |

## 12. Deploy

- Box: APK Android; Linux jar/native + systemd sau.
- Hạ tầng: `deploy/`, docker-compose cho signaling + coturn, TURN ephemeral secret, systemd và provisioning.
- `VREMOTE_PLATFORM_URL` trỏ tới VPLUS Cloud, Smart Retail hoặc Customer Platform conforming với Platform Contract; có thể dùng URL service nội bộ như `http://platform-service:8000`.
- Console/backend theo pipeline VPLUS.

## 13. Phân pha

- **P1 Monitor:** signaling + STUN + TURN + WebRTC + backend session/token + console view + registry/provision + audit + **durable device identity/credential + enrollment QR/manual**.
- **P2 Control:** Accessibility PoC + quyền control + single-controller lock.
- **P3 Fleet + Integration:** fleet-ops, supervisor/rollback, dashboard, Integration API, embeddable viewer.

## 14. Việc cần chốt trước khi code

- R1: PoC Android input-injection trước P2.
- D2: VPS signaling + TURN và ước lượng tải.
- D3: Platform Contract cho SSO/identity/tenant/permission/scope.
- D4: deviceId riêng + map machineCode.
- D5: **Enrollment Contract/API + durable Device Identity/Credential + state machine cho QR/manual.**
- D6: **Device Transfer/Re-assignment giữa Tenant, không mặc định re-enrollment.**
- D7: **Device Access Grant / Delegated Support Access với TTL, revoke và audit.**
- D8: Credential rotation/revocation/recovery và policy sau factory reset.

## Trạng thái

**DRAFT** — bắt đầu từ contract-first + P1 Monitor. Enrollment hỗ trợ song song **QR Code** và **Device Code + Enrollment Token/Password**, sau đó cấp/bind **Device Identity + long-lived runtime credential** để thiết bị được quản lý lâu dài mà không phải enroll lại cho mỗi lần đăng nhập/session. Device thuộc **một Owner Tenant tại một thời điểm**; nhiều User/Principal có thể truy cập theo quyền; hỗ trợ liên Tenant dùng **Access Grant/Delegated Support**, còn chuyển ownership dùng **Transfer/Re-assignment**. Không phá contract lock-control của VBridge; tái dụng chuẩn ký/golden vector.