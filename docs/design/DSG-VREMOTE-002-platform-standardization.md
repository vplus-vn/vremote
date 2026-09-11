# DSG-VREMOTE-002 — Thiết kế chuẩn hóa nền tảng (Platform Standardization v0.2.0)

- **Mã:** DSG-VREMOTE-002
- **Ngày:** 2026-09-11
- **Trạng thái:** DRAFT để hiện thực.
- **Nguồn giao việc:** `vplus-vn/vremote#7` — *HANDOFF: VRemote Platform Standardization v0.2.0*.
- **Baseline vào:** `vremote_v0.1.0` — `vplus-vn/vremote@438cdd9` (tham chiếu bất biến tại `vplus-vn/VSpace/baseline/vremote/`).
- **Baseline ra (khi DONE):** `vremote_v0.2.0_platform-standardized`.
- **Đọc kèm:** `DSG-VREMOTE-001` (kiến trúc domain đích — *CÁI GÌ*), `REQ-VREMOTE-001` (yêu cầu), `api/vremote/*` (contract khóa v0.0.1).

## 0. Quan hệ với DSG-VREMOTE-001

`DSG-VREMOTE-001` mô tả **kiến trúc domain đích** của VRemote (ownership, enrollment, transfer, reset, platform integration). Tài liệu này (`DSG-VREMOTE-002`) mô tả **cách refactor code từ baseline `v0.1.0` sang `v0.2.0` chuẩn hóa** để hiện thực đúng kiến trúc đó: phân tầng, persistence, platform adapter, authorization và device lifecycle. DSG-002 không định nghĩa lại domain — nó cụ thể hóa cấu trúc code, interface và migration.

## 1. Mục tiêu & phạm vi

Chuẩn hóa VRemote thành **Domain Service** trên VPLUS Platform, thay các implementation chuyển tiếp/prototype của baseline bằng kiến trúc production:

1. Phân tách **Domain / Application / Infrastructure / Platform Adapter**.
2. Thay local JSON persistence bằng **DB (MongoDB) + Repository abstraction**.
3. Nhận identity context từ Platform qua **`VREMOTE_PLATFORM_URL`** (integration point duy nhất); **không** dựng auth silo, **không** `VREMOTE_PLATFORM_MODE`.
4. Authorization theo **Tenant + Role + Permission + Scope + Access Grant**.
5. Device lifecycle end-to-end: enrollment (QR + manual), device credential, reset/unassign, transfer.
6. Giữ contract `api/vremote` v0.0.1 làm source of truth; không breaking change ngoài approval.
7. Giữ nguyên mọi security invariant; golden vectors tiếp tục pass.

**Ngoài phạm vi đợt này:** P2 input/control (chờ PoC R1), P3 fleet-scale/dashboard nâng cao. Signaling giữ kiến trúc hiện tại, chỉ bổ sung khi cần cho revocation.

## 2. Kiến trúc code đích

### 2.1 Phân tầng (backend Laravel)

Baseline hiện gộp toàn bộ vào `app/Http/Controllers` + `app/Services` với logic + persistence trộn lẫn. Chuẩn hóa theo 4 tầng, hiện thực bằng namespace rõ ràng (tận dụng `nwidart/laravel-modules` đã khai báo trong `composer.json`):

```text
app/
├── Domain/                      # Thuần nghiệp vụ, không phụ thuộc framework
│   ├── Device/                  # Entity, ValueObject, DeviceState, EnrollmentState...
│   ├── Session/
│   ├── Command/
│   ├── Enrollment/
│   ├── AccessGrant/
│   ├── Transfer/
│   ├── Audit/
│   └── Contracts/               # Repository interfaces, PlatformClient interface
├── Application/                 # Use-case/service orchestration
│   ├── Session/                 # OpenSessionService, CloseSessionService...
│   ├── Command/                 # DispatchCommandService
│   ├── Enrollment/              # CreateEnrollment, ConsumeEnrollment...
│   ├── Transfer/                # RequestTransfer, ApproveTransfer...
│   └── Authorization/           # AuthorizationService (policy engine)
├── Infrastructure/              # Adapter cụ thể
│   ├── Persistence/Mongo/       # *MongoRepository implements Domain\Contracts\*Repository
│   ├── Platform/                # HttpPlatformClient (VREMOTE_PLATFORM_URL) + FakePlatformClient
│   ├── Signaling/               # SignalingSender (internal /agent/send)
│   └── Signing/                 # VRemoteSigningService (giữ nguyên canonical/golden vector)
└── Http/                        # Controllers mỏng: chỉ validate + gọi Application service
    ├── Controllers/
    └── Middleware/PlatformContext.php   # Thay VRemoteAuth: nạp PlatformContext
```

**Nguyên tắc phụ thuộc:** `Http → Application → Domain`; `Infrastructure → Domain (Contracts)`. Domain không import Laravel/HTTP. Controllers không chạm persistence trực tiếp.

### 2.2 Sơ đồ luồng chuẩn hóa

```text
HTTP request
   │  bearer/platform token
   ▼
PlatformContext middleware ── verify qua PlatformClient(VREMOTE_PLATFORM_URL) ─► PlatformContext{userId,tenantId,roles,permissions,scopes}
   ▼
Controller (validate DTO)
   ▼
Application Service ── AuthorizationService.authorize(context, action, device) ──► allow/deny (Tenant ownership + Role/Perm/Scope + AccessGrant)
   ▼
Domain (entity/state machine) + Repository (Mongo, transaction/idempotency)
   ▼
Infrastructure: Signing → SignalingSender → Agent
   ▼
Audit append (durable)
```

## 3. Persistence (Phase 1)

### 3.1 Repository abstraction

Định nghĩa interface trong `Domain/Contracts`, hiện thực Mongo trong `Infrastructure/Persistence/Mongo`. Bind trong container (`AppServiceProvider`), cho phép thay `Fake*Repository` khi test.

| Interface | Thay thế baseline | Collection |
|---|---|---|
| `DeviceRepository` | `DeviceRegistry` (JSON env) | `devices` |
| `SessionRepository` | `SessionStore` (JSON file) | `sessions` |
| `CommandRepository` | `CommandStore` (JSON file) | `commands` |
| `AuditRepository` | `AuditStore` (JSON file) | `audit_events` |
| `EnrollmentRepository` | *(mới)* | `enrollments` |
| `AccessGrantRepository` | *(mới)* | `device_access_grants` |
| `DeviceTransferRepository` | *(mới)* | `device_transfers` |
| `RevokedSessionRepository` | *(mới, cho H1/H2)* | `revoked_sessions` |
| `NonceRepository` | *(mới, cho H4)* | `signing_nonces` (TTL index) |

### 3.2 Schema/collection (theo DSG-001 §8)

- `devices`: `_id=deviceId`, `ownerTenantId`, `machineCode`, `name`, `platform`, `status`, `agentVersion`, `enrollmentState`, `credentialState`, `credentialId`, `lastSeenAt`, `createdAt`. **Index:** `ownerTenantId`, `machineCode` (unique khi có).
- `sessions`: `_id=sessionId`, `deviceId`, `userId`, `tenantId`, `capability`, `source`, `startedAt`, `endedAt`, `endReason`, `ttlSec`, `tokenJti`. **Index:** `deviceId`, `tenantId`, `tokenJti`.
- `commands`: `_id=commandId`, `deviceId`, `tenantId`, `type`, `argsHash`, `actor`, `nonce`, `signedAt`, `status`, `result`. **Index:** unique `_id` (idempotency), `deviceId`.
- `audit_events`: `_id`, `ts`, `actor`, `tenantId`, `action`, `deviceId`, `sessionId`, `commandType`, `result`, `meta` (no-secret). **Index:** `tenantId`, `deviceId`, `ts`. **Append-only**, không giới hạn cứng 5000 (dùng TTL/retention policy nếu cần).
- `enrollments`: `_id=enrollmentId`, `deviceId`, `tenantId`, `method`(qr|manual), `status`, `codeHash`, `tokenHash`, `createdAt`, `expiresAt`, `consumedAt`, `createdBy`. **KHÔNG lưu token/code plaintext.** **Index:** TTL trên `expiresAt`, `deviceId`.
- `device_access_grants`: `_id=grantId`, `deviceId`, `principal`/`tenantId`, `permissions[]`, `scope`, `expiresAt`, `revokedAt`, `createdBy`. **Index:** `deviceId`, TTL `expiresAt`.
- `device_transfers`: `_id=transferId`, `deviceId`, `fromTenantId`, `toTenantId`, `status`, `requestedBy`, `approvedBy`, `createdAt`, `completedAt`, `reason`.
- `signing_nonces`: `_id=nonce`, `scope`(session|command), `deviceId`, `seenAt`. **TTL index** = skew window (600s) để tự dọn.
- `revoked_sessions`: `_id=tokenJti` (hoặc `sessionId`), `revokedAt`. **TTL index** = session TTL max.

### 3.3 Transaction / concurrency / idempotency (sửa H5)

- Thay read-modify-write file bằng thao tác atomic của DB: `insertOne`/`updateOne`/`findOneAndUpdate`.
- **Idempotency `commandId`:** dựa trên **unique index `_id`** + `insert` — trùng `commandId` → duplicate-key → trả `replay_detected` (thay guard check-then-act đang race).
- **Nonce replay backend:** `insert` vào `signing_nonces`; trùng → reject. (Backend hiện chỉ *sinh* nonce; xem §6 để bàn ai là authority.)
- Audit ghi bằng `insertOne` độc lập → không mất bản ghi khi tải cao.

## 4. Platform Adapter (Phase 2)

### 4.1 PlatformClient

```text
Domain\Contracts\PlatformClient
  verifyContext(string $bearerToken): ?PlatformContext
  # (tuỳ contract) resolveDevices/tenant nếu Platform giữ ownership ở tầng registry
```

`PlatformContext` (DTO thuần): `userId`, `tenantId`, `roles[]`, `permissions[]`, `scopes[]`.

- **`HttpPlatformClient`**: gọi Platform qua `VREMOTE_PLATFORM_URL` theo Platform Contract (tận dụng `vplus-platform/core-lib` nếu core-lib cung cấp client/verifier chuẩn). Xác thực token/context theo contract.
- **`FakePlatformClient`**: cho unit/integration test độc lập, trả context cấu hình sẵn — thay cơ chế `VREMOTE_DEV_AUTH` hiện tại nhưng vẫn chỉ bật ở `local/testing`.

### 4.2 Thay đổi so với baseline

- `VRemoteAuth` middleware (JWT trực tiếp) → `PlatformContext` middleware gọi `PlatformClient`.
- Bỏ đọc `VPLUS_USER_PUBLIC_KEY`/`VPLUS_JWT_ALG` trực tiếp bằng `env()` trong request path; verification do PlatformClient theo contract. (Sửa luôn lỗi `env()` ngoài config khi `config:cache`.)
- **`VREMOTE_PLATFORM_URL` là selector duy nhất.** Không `VREMOTE_PLATFORM_MODE=local/external`. Một binary chạy được với Platform ở Cloud / Smart Retail / On-prem.
- Không duplicate DB User/Tenant/Auth trong VRemote.

## 5. Authorization (Phase 4)

`Application/Authorization/AuthorizationService` quyết định dựa trên:

```text
allow = deviceOwnedByContextTenant(device, ctx.tenantId)
        AND hasPermission(ctx, requiredPermission)      # edge.admin.view | edge.admin.control
        AND scopeSatisfied(ctx.scopes, action)
     OR validAccessGrant(device, ctx.principal, requiredPermission, now)   # delegated/support
```

- **Tenant ownership invariant** (sửa H3): mọi truy cập device/session/command/audit phải kiểm `device.ownerTenantId == ctx.tenantId` **hoặc** có Access Grant hợp lệ. Loại bỏ `tenantId` dead-code hiện tại.
- **View/Control separation**: giữ `edge.admin.view` vs `edge.admin.control`.
- **Gated commands** (reboot/config-push/apk-update/cache-clear/remote.enable): mặc định OFF + quyền cao + `confirm=true` + audit.
- **Access Grant**: `principal/tenant`, `permissions`, `scope`, `TTL`, `revoke`, `audit` — cho VPLUS Support / cross-tenant, không đổi ownership.
- Audit endpoints & device list **phải scope theo tenant** (sửa các finding review M1/H3).

## 6. Session lifecycle & signing hardening

Các sửa lỗi bảo mật từ review baseline, gắn vào chuẩn hóa:

- **H1/H2 — kill switch:** `DELETE /sessions/{id}` phải (a) kiểm authorization + ownership; (b) gửi `session.disable` xuống agent qua signaling; (c) thêm `tokenJti`/`sessionId` vào `revoked_sessions`. `SessionTokenService.verify` và signaling `verifyViewer` kiểm revocation ngoài `exp`.
- **H4 — nonce/skew authority:** chốt rõ **agent là authority chống replay** (đúng `signing.md` §4) và **backend bổ sung** `signing_nonces` + enforce `skew_seconds` (đang là dead config) cho lớp phòng thủ chiều sâu. Ghi rõ ràng trong `signing.md`/doc để không mập mờ trách nhiệm.
- **Rate limiting:** thêm `throttle` cho `POST /sessions`, `POST /devices/{id}/commands`, integration endpoint.
- Contract `session.enable`/`command` canonical + golden vector **giữ nguyên** — chỉ thay tầng lưu trữ/authorization quanh nó.

> Chi tiết từng finding và mức độ: xem báo cáo review kèm theo issue #7.

## 7. Device lifecycle (Phase 3)

Hiện thực theo DSG-001 §9. Các domain endpoint enrollment/transfer/reset là **domain API riêng của VRemote**, thiết kế mà **không sửa** `api/vremote` v0.0.1 (nếu cần contract mới → đề xuất version mới, chờ approval).

- **Enrollment state machine:** `UNENROLLED → PENDING → VERIFIED → BOUND → ACTIVE`; `PENDING → EXPIRED/REVOKED`; `VERIFIED → REJECTED`.
- **QR:** `vremote://enroll?enrollmentId=<id>&token=<one-time-token>`; **Manual:** `Device ID + Enrollment Code + Enrollment Token/Password`. Cả hai → cùng một enrollment transaction. Credential one-time/TTL ngắn, hash khi lưu, consume/invalidate sau bind, audit đầy đủ, **không log secret**.
- **Device credential:** long-lived, lưu trong Android Keystore/EncryptedSharedPreferences (sửa finding H8 phía agent); state `ACTIVE/ROTATION_REQUIRED/REVOKED/RECOVERY_REQUIRED`; rotate không đổi `deviceId`; reconnect/session/command **không re-enroll**.
- **Reset/Unassign:** local protected authorization tại Device; revoke credential cũ trước khi enroll mới; audit `ENROLLMENT_RESET`; `edge.admin.control` thường **không** được tự reset.
- **Transfer:** workflow `fromTenantId→toTenantId` với pending/approve/reject/complete; giữ Device Identity; không tự chuyển historical domain data; audit đầy đủ.

## 8. Cấu hình / môi trường

| Env | Vai trò | Ghi chú |
|---|---|---|
| `VREMOTE_PLATFORM_URL` | Integration point Platform **duy nhất** | Trỏ Cloud/Smart Retail/On-prem conforming contract |
| `VREMOTE_DB_*` (Mongo DSN) | Persistence | Thay JSON stores |
| `VREMOTE_SESSION_SECRET` | Ký session token (web) | Giữ |
| `VREMOTE_SIGNALING_*` | Kênh internal tới signaling | Giữ |
| `VREMOTE_TURN_*` | TURN ephemeral | Giữ |
| ~~`VPLUS_USER_PUBLIC_KEY`/`VPLUS_JWT_ALG`~~ | (bỏ khỏi request path) | Verification chuyển sang PlatformClient |
| ~~`VREMOTE_DEVICES_JSON`~~ | (bỏ) | Device chuyển vào DB registry |
| ~~`VREMOTE_PLATFORM_MODE`~~ | **KHÔNG tạo** | Vi phạm nguyên tắc single integration point |

## 9. Map sang các Phase của issue #7 & Definition of Done

| Phase issue #7 | Deliverable DSG-002 | DoD chính |
|---|---|---|
| P1 Production foundation | §2 layering, §3 persistence | Repository abstraction; Mongo migrations/index; transaction/idempotency; contract v0.0.1 giữ nguyên |
| P2 Platform integration | §4 platform adapter | `PlatformClient` thật + `FakePlatformClient`; `VREMOTE_PLATFORM_URL` selector duy nhất; không auth silo |
| P3 Device lifecycle | §7 | QR + manual enrollment; credential lifecycle; reset/unassign; transfer; audit không secret |
| P4 Authorization | §5 | Tenant ownership; role/perm/scope; access grant; view/control; gated controls |
| Contract/security | §6 | golden vectors pass; replay protection; security invariants có automated tests |
| CI/operational | — | unit + integration + contract tests; CI xanh; docs cập nhật; không claim gate chưa quan sát |

## 10. Security invariants (không được phá)

Không inbound port vào box · screen capture OFF mặc định · backend là signer duy nhất · HMAC-SHA256 canonical LF UTF-8 base64 · golden vectors pass · nonce+timestamp replay protection · session token scope hẹp (device+capability+TTL) · TURN ephemeral · không trả device secret qua API · không log password/token/key · không bypass authorization để demo · không tắt security control để CI pass.

## 11. Baseline delta

Khi hoàn tất, tạo `VSpace/baseline/vremote/vremote_v0.2.0_platform-standardized.md` (**không sửa baseline v0.1.0**) với migration notes:

```text
v0.1.0  →  Platform standardization  →  v0.2.0_platform-standardized
```

gồm: platform contract integration · durable persistence/repositories · device lifecycle · authorization/access policy · enrollment & credential lifecycle · transfer/reset · production CI & operational hardening · next baseline commit SHA.
