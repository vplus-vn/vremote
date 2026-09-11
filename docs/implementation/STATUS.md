# VRemote — Trạng thái triển khai (canonical)

> Đây là tài liệu **trạng thái canonical duy nhất**. Hai file `P1-STATUS.md` và `P1-implementation-status.md` là **snapshot lịch sử của giai đoạn P1** (viết ở hai thời điểm khác nhau nên mâu thuẫn nhau) và đã được đánh dấu *superseded*; đừng dùng chúng làm nguồn sự thật.

- **Baseline hiện tại:** `vremote_v0.1.0` — `vplus-vn/vremote@438cdd9` (đóng băng tại `vplus-vn/VSpace/baseline/vremote/`).
- **Giai đoạn đang làm:** Platform Standardization `v0.2.0` — xem `docs/design/DSG-VREMOTE-002-platform-standardization.md`, giao việc tại `vplus-vn/vremote#7`.

## 1. Thực trạng baseline v0.1.0 (đã kiểm chứng từ code)

**Đã có (P1 monitor prototype chạy được với docker-compose):**

- Contract `api/vremote` v0.0.1 (OpenAPI + AsyncAPI + `signing.md` + golden vectors).
- Backend Laravel: REST device/session/command/audit/integration; HMAC signing khớp golden vector (có unit test).
- Signaling Node/TS: xác thực session-token (viewer) và device-token (agent); trung chuyển SDP/ICE; kênh internal có shared secret.
- Console React/Vite: `RTCPeerConnection` + WebSocket join + offer/answer/ICE + render video.
- Android agent: MediaProjection capture (OFF mặc định), verify `session.enable` (chữ ký + skew + nonce), outbound-only WebSocket.
- TURN ephemeral (coturn shared-secret), STUN.
- Docker-compose + CI (contract/backend/console/signaling/android) + nginx production template.

**Chưa đạt production (chuyển tiếp/prototype — là phạm vi v0.2.0):**

- Persistence bằng **JSON file** (`storage/app/vremote-*.json`) không khóa đồng bộ → race, mất audit, replay TOCTOU.
- Device registry đọc từ **env JSON** (`VREMOTE_DEVICES_JSON`), không DB.
- Auth bằng **JWT trực tiếp trong VRemote** (silo) thay vì Platform adapter.
- **Không có tenant isolation** (claim `tenantId` không được dùng lại).
- `DELETE /sessions` **không** báo agent dừng capture; **không** revoke session token.
- Console **chưa nối SSO** thật.
- Device lifecycle (enrollment/credential/reset/transfer) **mới ở mức thiết kế**, chưa hiện thực end-to-end.

> Danh sách lỗi/độ ưu tiên đầy đủ: báo cáo review kèm `#7`.

## 2. Đích v0.2.0

Xem `DSG-VREMOTE-002`. Tóm tắt DoD: DDD layering · DB + repository · Platform adapter qua `VREMOTE_PLATFORM_URL` · authorization tenant/role/permission/scope/access-grant · device lifecycle · security-invariant tests · CI xanh · docs cập nhật.

## 3. Nguyên tắc báo cáo

Không claim test/deploy/CI/runtime nếu chưa **thực sự quan sát được**. Không đánh dấu hoàn thành khi mới có skeleton.
