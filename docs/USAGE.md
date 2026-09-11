# VRemote — Hướng dẫn sử dụng

Tài liệu này mô tả cách **sử dụng** VRemote sau khi đã cài đặt (xem `docs/INSTALL.md`):
đăng nhập console, xem/điều khiển thiết bị Android từ xa, gửi lệnh quản trị, và cách gọi
REST API. Hợp đồng máy-đọc chi tiết nằm ở `api/vremote/` (`openapi.yaml`, `asyncapi.yaml`, `signing.md`).

---

## 1. Tổng quan

VRemote cho phép quản trị viên **giám sát và điều khiển màn hình** các thiết bị/box Android
từ xa qua trình duyệt. Thành phần:

| Thành phần | Vai trò |
|---|---|
| **Console** (React SPA) | Giao diện quản trị: danh sách thiết bị, mở phiên xem/điều khiển, gửi lệnh, xem audit |
| **Backend** (Laravel) | REST API, xác thực qua platform, ký `session.enable`/`command`, cấp `sessionToken` |
| **Signaling** (Node WS) | Đàm phán WebRTC (`/signaling`) + kênh lệnh tới agent (`/agent`) |
| **Agent** (Kotlin/Android) | Chạy trên box: chia sẻ màn hình, nhận input, thực thi lệnh đã ký |

**Ranh giới quan trọng:**
- **Hình ảnh màn hình và thao tác điều khiển đi thẳng qua WebRTC** (media track + data channel), **không** qua WebSocket.
- `/signaling` chỉ trao đổi SDP/ICE và xác thực phiên bằng `sessionToken`.
- **Backend là bên DUY NHẤT ký** `session.enable` và `command`; console/sản phẩm tích hợp **không** ký.

---

## 2. Xác thực & phân quyền

### 2.1 Đăng nhập
Auth được **gói sẵn trong VRemote** (như S&B admin portal). Console có **màn Login riêng**:
người dùng nhập tài khoản VPlus Platform → console gọi **`POST /api/vremote/v1/auth/login`**
(backend proxy sang platform, nên cùng-origin, không CORS) → nhận token → lưu localStorage →
gắn `Authorization: Bearer <token>` vào mọi request API. Gặp `401` → xoá token, quay về Login.

Backend xác thực token theo 1 trong 2 chế độ (chọn qua env):
- **Introspection (khuyến nghị, mặc định)** — `VREMOTE_PLATFORM_INTROSPECT=true`: backend
  chuyển bearer token tới endpoint "who am I" của platform (`VREMOTE_PLATFORM_URL` +
  `VREMOTE_PLATFORM_ME_PATH`, mặc định `/v1/me`), tin danh tính 2xx trả về, cache ngắn theo
  token. **Chạy được với token opaque** (không cần JWT/public key).
- **JWT verify tại chỗ** — khi introspection tắt: verify RS256 bằng `vplus-platform/core-lib`
  + public key (`CORE_JWT_PUBLIC_KEY[_PATH]`). Chỉ dùng khi platform phát JWT.

Cả hai đều dựng ra `PlatformContext` (userId, tenantId, roles, permissions, scopes) ở middleware
`vremote.platform`.

> **Chế độ dev:** khi `VREMOTE_DEV_AUTH=true` (chỉ `local`/`testing`), backend bỏ qua verify và
> dùng actor cố định `VREMOTE_DEV_ACTOR`. **Tuyệt đối không bật ở production.**

### 2.2 Quyền (permissions)
Hai quyền điều khiển truy cập thao tác:

| Quyền | Cho phép |
|---|---|
| `edge.admin.view` | Xem thông tin thiết bị, mở phiên **view**, gửi lệnh **an toàn** (health/version/ping/logs/config-read), xem audit |
| `edge.admin.control` | Mở phiên **control**, gửi lệnh **thay đổi** (restart-app…) và lệnh **GATED** (reboot…) |

Khi bật `enforce_tenant` (mặc định tắt trong giai đoạn di trú), người gọi còn phải **sở hữu
tenant của thiết bị** (hoặc có access-grant) mới thao tác được — chống truy cập chéo tenant (IDOR).

---

## 3. Sử dụng Console

Mở `https://vremote.vplus.vn/` và đăng nhập.

1. **Danh sách thiết bị** — hiển thị các box: mã máy, tên, trạng thái (`online`/`offline`), phiên bản agent.
2. **Mở phiên xem (view)** — chọn thiết bị `online` → *Xem màn hình*. Console gọi `POST /api/vremote/v1/sessions`
   với `capability=view`, nhận `sessionToken` + `signalingUrl` + `iceServers`, rồi thiết lập WebRTC
   để nhận luồng hình ảnh trực tiếp từ box.
3. **Điều khiển (control)** — cần quyền `edge.admin.control`. Mở phiên `capability=control` để gửi
   thao tác chạm/bàn phím qua WebRTC data channel.
4. **Gửi lệnh quản trị** — chọn thiết bị → gửi lệnh (xem §4.4). Lệnh **GATED** cần bật chính sách và xác nhận.
5. **Nhật ký (audit)** — xem lịch sử mở/đóng phiên và lệnh đã gửi.
6. **Kết thúc phiên** — *Đóng* để gửi `session.disable` tới agent (dừng chia sẻ màn hình ngay, không đợi hết TTL).

---

## 4. REST API

Base URL: `https://vremote.vplus.vn` — mọi path dưới `/api/vremote/`. Endpoint `v1` yêu cầu header
`Authorization: Bearer <token>`. Lỗi trả về dạng `{"error":{"code":"...","message":"..."}}`.

### 4.0 Health (không cần auth)
```http
GET /api/vremote/health
→ 200 {"status":"ok","service":"vremote-backend"}
```

### 4.1 Thiết bị
```http
GET /api/vremote/v1/devices                 # danh sách thiết bị
GET /api/vremote/v1/devices/{deviceId}      # chi tiết một thiết bị
```
Yêu cầu quyền `edge.admin.view`.

### 4.2 Phiên (view/control)
```http
POST /api/vremote/v1/sessions
Authorization: Bearer <token>
Content-Type: application/json

{ "deviceId": "DEV-001", "capability": "view", "preferredMaxFps": 30 }
```
- `capability`: `view` (cần `edge.admin.view`) hoặc `control` (cần `edge.admin.control`).
- Phản hồi `201`:
  ```json
  {
    "sessionId": "S-XXXXXXXXXXXX",
    "capability": "view",
    "signalingUrl": "wss://vremote.vplus.vn/signaling",
    "sessionToken": "<token phạm vi hẹp, TTL>",
    "iceServers": [ ... ],
    "ttlSec": 1800
  }
  ```
- Lỗi: `404 NOT_FOUND` (không có thiết bị), `503 DEVICE_OFFLINE`, `503 DEVICE_NOT_PROVISIONED`
  (thiếu khóa ký), `503 DEVICE_UNREACHABLE` (agent không nối), `403 FORBIDDEN` (thiếu quyền/không thuộc tenant).

Đóng phiên:
```http
DELETE /api/vremote/v1/sessions/{sessionId}      → 204
```

### 4.3 Lệnh quản trị
```http
POST /api/vremote/v1/devices/{deviceId}/commands
{ "type": "health", "args": {}, "commandId": "CMD-optional", "confirm": false }
```
Phản hồi `202`: `{"commandId":"CMD-...","accepted":true,"result":"accepted"}`.
`409` nếu `commandId` trùng (replay).

### 4.4 Danh mục lệnh

| Loại lệnh | Nhóm | Quyền | Ghi chú |
|---|---|---|---|
| `health` `version` `ping` `logs` `config-read` | An toàn | `edge.admin.view` | Chỉ đọc |
| `restart-app` | Thay đổi | `edge.admin.control` | |
| `reboot` `config-push` `apk-update` `cache-clear` `remote.enable` `remote.disable` | **GATED** | `edge.admin.control` | Cần `VREMOTE_GATED_COMMANDS=true` **và** `confirm=true` |

Lệnh GATED khi tắt chính sách trả `403 GATED_OFF`. Mọi lệnh backend gửi tới box đều được
**ký HMAC-SHA256** (nonce + timestamp chống replay) theo `api/vremote/signing.md`.

### 4.5 Audit
```http
GET /api/vremote/v1/audit        # nhật ký mở/đóng phiên, lệnh đã gửi
```

### 4.6 Tích hợp (sản phẩm khác)
```http
POST /api/vremote/v1/integrations/sessions
```
Sản phẩm tích hợp **chỉ** gọi endpoint này để nhận token phạm vi hẹp + TTL cho một phiên xem;
**không** nói trực tiếp với box và **không** ký lệnh.

---

## 5. Vòng đời thiết bị (device lifecycle)

Các endpoint chuẩn hóa v0.1.2 (DSG-VREMOTE-002 §7), nằm ngoài hợp đồng `api/vremote` v0.0.1
đã khóa. Đều sau middleware `vremote.platform`.

### 5.1 Đăng ký (enrollment)
Trạng thái: `UNENROLLED → PENDING → VERIFIED → BOUND → ACTIVE`.
```http
POST /api/vremote/v1/devices/{deviceId}/enrollments      # tạo yêu cầu đăng ký (sinh mã/enrollmentId)
POST /api/vremote/v1/enrollments/{enrollmentId}/consume  # box dùng mã để hoàn tất đăng ký
POST /api/vremote/v1/devices/{deviceId}/enrollment/reset  # reset về trạng thái đăng ký ban đầu
```

### 5.2 Chuyển giao (transfer)
Chuyển thiết bị giữa các tenant/chủ sở hữu, có duyệt:
```http
POST /api/vremote/v1/devices/{deviceId}/transfers   # mở yêu cầu chuyển
POST /api/vremote/v1/transfers/{transferId}/approve # bên nhận/duyệt chấp thuận
POST /api/vremote/v1/transfers/{transferId}/reject  # từ chối
POST /api/vremote/v1/transfers/{transferId}/complete# hoàn tất chuyển
```

---

## 6. Agent Android

- Tải APK đã ký tại `https://vremote.vplus.vn/downloads/vremote-agent.apk`.
- Agent kết nối kênh điều khiển qua WSS: `wss://vremote.vplus.vn/agent`, xác thực bằng `deviceToken`.
- Agent **chỉ** thực thi `session.enable`/`command` khi chữ ký HMAC hợp lệ (khớp `signing.md` byte-for-byte).
- Luồng màn hình và input đi qua **WebRTC** trực tiếp giữa console và agent (P2P/qua TURN).

---

## 7. Luồng điển hình: xem màn hình một box

1. Admin đăng nhập console (SSO platform) → có Bearer token.
2. Chọn box `online` → console `POST /api/vremote/v1/sessions {capability:"view"}`.
3. Backend kiểm quyền `edge.admin.view` (+ tenant nếu bật), ký `session.enable`, gửi qua signaling
   `/agent` tới box, trả `sessionToken` + `signalingUrl` + `iceServers`.
4. Console mở WSS `/signaling`, xác thực bằng `sessionToken`, đàm phán SDP/ICE.
5. WebRTC thiết lập → **hình ảnh màn hình chảy trực tiếp** từ box về console.
6. Đóng: console `DELETE /api/vremote/v1/sessions/{id}` → backend gửi `session.disable` → box dừng chia sẻ.

---

## 8. Mã lỗi thường gặp

| HTTP | code | Ý nghĩa |
|---|---|---|
| 401 | — | Thiếu/hỏng Bearer token |
| 403 | `FORBIDDEN` | Thiếu quyền, hoặc không thuộc tenant của thiết bị |
| 403 | `GATED_OFF` | Lệnh GATED bị tắt chính sách hoặc thiếu `confirm=true` |
| 404 | `NOT_FOUND` | Không có thiết bị/phiên |
| 409 | `replay_detected` | `commandId` đã tồn tại |
| 503 | `DEVICE_OFFLINE` | Thiết bị offline |
| 503 | `DEVICE_NOT_PROVISIONED` | Thiếu khóa ký của thiết bị |
| 503 | `DEVICE_UNREACHABLE` / `AGENT ...` | Kênh signaling/agent không sẵn sàng |

---

Tham chiếu:
- `api/vremote/openapi.yaml` · `asyncapi.yaml` · `signing.md` — hợp đồng máy-đọc (v0.0.1, không tự sửa).
- `docs/INSTALL.md` — cài đặt & triển khai.
- `docs/design/DSG-VREMOTE-001-remote-admin-architecture.md`, `DSG-VREMOTE-002-platform-standardization.md` — thiết kế.
