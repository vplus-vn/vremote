# VRemote — Đặc tả ký lệnh (HMAC-SHA256, canonical) + golden vector

- **Mã:** contract VRemote `api/` — signing. **contractVersion: 0.0.1**.
- **Đọc kèm:** `openapi.yaml` (REST) · `asyncapi.yaml` (WS) · `DSG-VREMOTE-001` §5–§6.
- **Nguyên tắc:** **Backend VRemote KÝ, VRemote Agent (box) VERIFY.** FE/console/sản phẩm tích hợp **KHÔNG** ký. Áp cho 2 loại thông điệp backend→box: `session.enable` và `command`.
- **Cùng họ với** `edge-agent/api/signing.md` (lock-control): HMAC-SHA256, chuỗi canonical nối bằng `\n`, UTF-8, kết quả **base64**. Khoá = **device-token** cấp cho từng box lúc provisioning (không hardcode).

## 1. Thuật toán chung
```
sig = base64( HMAC_SHA256( deviceToken, canonicalString ) )
```
- `canonicalString`: các trường theo **đúng thứ tự**, nối bằng ký tự `\n` (LF, U+000A), mã hoá UTF-8.
- `deviceToken`: bí mật đối xứng riêng mỗi box (provisioning, rotation được).
- So khớp **constant-time**. Verify kèm **nonce** (chống replay, lưu theo cửa sổ) + **ts** (unix seconds, skew ±300s).

## 2. `session.enable` (mở phiên remote: backend → box)

**Trường canonical (thứ tự cố định):**
| # | Trường | Ghi chú |
|---|---|---|
| 1 | `deviceId` | định danh box trong VRemote (ràng buộc phiên đúng box) |
| 2 | `sessionId` | id phiên remote |
| 3 | `capability` | `view` \| `control` |
| 4 | `ttlSec` | số nguyên (chuỗi thập phân) |
| 5 | `nonce` | chỉ chống replay |
| 6 | `ts` | unix seconds |

```
canonical = deviceId "\n" sessionId "\n" capability "\n" ttlSec "\n" nonce "\n" ts
```

**Golden vector (bắt buộc khớp):**
```
deviceToken = "dev-secret-123"
deviceId    = "DEV-001"
sessionId   = "S-1001"
capability  = "view"
ttlSec      = 1800
nonce       = "nonce-abc"
ts          = 1760000000

canonical   = "DEV-001\nS-1001\nview\n1800\nnonce-abc\n1760000000"
sig(base64) = "+z47Fx1AXfmSNSFdKlyK6xPRnPivKGLFMrKuDIIEtlY="
```

## 3. `command` (lệnh fleet-ops: backend → box)

**Trường canonical (thứ tự cố định):**
| # | Trường | Ghi chú |
|---|---|---|
| 1 | `deviceId` | box đích |
| 2 | `commandId` | duy nhất mỗi lệnh; idempotency; retry → commandId mới |
| 3 | `type` | `health`\|`version`\|`ping`\|`logs`\|`config-read`\|`restart-app`\|`reboot`\|`config-push`\|`apk-update`\|`cache-clear`\|`remote.enable`\|`remote.disable` |
| 4 | `argsHash` | **hex(lowercase) SHA-256** của `argsJson`; nếu **không có** args → **chuỗi rỗng** `""` |
| 5 | `nonce` | chống replay |
| 6 | `ts` | unix seconds |

- `argsJson` = **đúng chuỗi bytes JSON của trường `args`** như được truyền trong thân thông điệp (byte-for-byte). Không có `args` → `argsHash = ""`.

```
canonical = deviceId "\n" commandId "\n" type "\n" argsHash "\n" nonce "\n" ts
```

**Golden vector A — có args:**
```
deviceToken = "dev-secret-123"
deviceId    = "DEV-001"
commandId   = "CMD-0001"
type        = "restart-app"
argsJson    = "{\"target\":\"vbridge\"}"
argsHash    = "c5f85fa5aba702326ec0da5d3cd57b8e98bb2e6634867d7c13451f5c863c668e"
nonce       = "nonce-xyz"
ts          = 1760000000

canonical   = "DEV-001\nCMD-0001\nrestart-app\nc5f85fa5aba702326ec0da5d3cd57b8e98bb2e6634867d7c13451f5c863c668e\nnonce-xyz\n1760000000"
sig(base64) = "/lAXmjDUVdnVSZ4RM+T323imtc0UHthJ8m4159gYX5A="
```

**Golden vector B — không args:**
```
deviceToken = "dev-secret-123"
deviceId    = "DEV-001"
commandId   = "CMD-0002"
type        = "health"
argsHash    = ""      (không có args)
nonce       = "nonce-000"
ts          = 1760000000

canonical   = "DEV-001\nCMD-0002\nhealth\n\nnonce-000\n1760000000"
sig(base64) = "n5obzyY/A5PsQ1mhLHXqUJsmAK0OB9m/vvB+kBUisUY="
```

## 4. Ràng buộc verify (phía box)
1. Kiểm `ts` trong skew ±300s → nếu lệch: `rejected` (stale_ts).
2. Tính lại `sig`, so constant-time → sai: `invalid_signature`.
3. `nonce` đã thấy trong cửa sổ → `replay_detected`.
4. `commandId` đã xử lý → trả lại kết quả cũ (idempotent), không thực thi lại.
5. Với lệnh **GATED** (reboot/config-push/apk-update/cache-clear/remote.enable): mặc định TẮT — chỉ thực thi khi box được cấu hình cho phép + policy đạt; nếu không → `rejected` (gated_off).
6. **Không log** deviceToken/sig/nonce ở mức có thể suy ngược khoá.

## 5. Lưu ý
- `session.enable` cấp quyền **bật pipeline WebRTC** cho phiên (view/control). Việc trao SDP/ICE đi qua **signaling** (`asyncapi.yaml`), không nằm trong chữ ký này.
- **Session token** (dùng để `join` signaling từ console/viewer) là token **backend cấp cho phía web**, ký/kiểm ở backend — KHÁC với `session.enable` (ký cho box). Xem `openapi.yaml`.
