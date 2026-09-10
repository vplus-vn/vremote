# REQ-VREMOTE-001 — Yêu cầu: VRemote (quản trị & điều khiển edge box từ xa)

- **Mã:** REQ-VREMOTE-001
- **Ngày:** 2026-09-10
- **Trạng thái:** DRAFT để bàn giao ChatGPT hiện thực.
- **Sản phẩm:** **VRemote** — hệ thống độc lập trong bộ **vplus**, chung nền **vplus-platform** + tài khoản **vplus.vn** (SSO). **Repo:** `vplus-vn/vremote` (mới, xây trên vplus-platform) — gồm **agent trên thiết bị + phần web control**. Cầu nối web↔phần cứng (khoá/đầu đọc…) tách ở `sab-com-vn/edge-agent` (VBridge).
- **Liên quan:** `DSG-VREMOTE-001` (thiết kế kiến trúc — đọc kèm); `edge-agent`/VBridge (device control, INT-VIS-LOCK-001); `INT-VIS-EDGE-001` (app edge box). Bản quyết định gốc: workspace `changes/vremote-design-20260910-quan-tri-edge-tu-xa.md`.
- **Cho ai đọc:** đội hiện thực (ChatGPT). Tài liệu này nói **CÁI GÌ & VÌ SAO**; `DSG-VREMOTE-001` nói **LÀM THẾ NÀO**.

---

## 1. Bối cảnh & mục tiêu

Cần quản trị đội **edge box (Android, có thể mở rộng Linux)** đặt tại cửa hàng **từ xa**, thay AnyDesk/TeamViewer (vướng bản quyền, đắt khi nhân đội). Box nằm **sau NAT**, là thiết bị vật lý ngoài cửa hàng → phải outbound + relay, **không mở cổng inbound**, bảo mật chặt.

**Mục tiêu chính:**
- **G1** — Xem + điều khiển màn hình box từ xa qua **web**, 0 phí bản quyền, không giới hạn số máy.
- **G2** — Fleet ops: health/log/restart/config/reboot/cập nhật cho đội box.
- **G3** — Là **sản phẩm độc lập** nhưng **tích hợp được** vào Smart Retail/vplus-ai để điều khiển thiết bị trong ngữ cảnh của chúng.
- **G4** — Dùng chung **SSO vplus**, phân quyền theo **gói dịch vụ** đã đăng ký.
- **G5** — Bảo mật: không inbound, phiên ký + TTL + audit, tách quyền xem/điều khiển.

## 2. Phạm vi

**Trong phạm vi:** đầu box (VRemote Agent), signaling, tích hợp TURN, backend phiên/quyền/audit/registry, console web, API tích hợp + viewer nhúng.
**Ngoài phạm vi (đợt này):** viết lại engine screen từ số 0 (dùng WebRTC); MDM/COSU đầy đủ; đổi contract lock-control của VBridge; nhồi tính năng vào admin-portal.

## 3. Tác nhân (Actors)

| Tác nhân | Mô tả |
|---|---|
| **Operator/Kỹ thuật** | Người dùng VRemote (qua console hoặc sản phẩm tích hợp). Quyền `edge.admin.view` / `.control` / gated. |
| **Sản phẩm tích hợp** | Smart Retail, vplus-ai… gọi VRemote để điều khiển 1 thiết bị. |
| **VRemote Agent** | Phần mềm trên box (bên bị điều khiển). |
| **VBridge** | Lớp cầu nối **web ↔ phần cứng** (khoá/đầu đọc…): web không chạm phần cứng trực tiếp nên mọi chức năng web cần điều khiển phần cứng đi qua VBridge. Điều khiển **phần cứng ngoại vi** (khác VRemote — điều khiển **bản thân box**). VRemote **supervise** nhưng KHÔNG phụ thuộc code. |
| **vplus-platform / vplus.vn** | Nền identity + SSO + quản lý gói dịch vụ. |

## 4. Yêu cầu chức năng (Functional Requirements)

### 4.1 Kết nối & phiên (FR-VR-CONN)
- **FR-VR-CONN-01** Box chỉ **outbound** tới signaling + TURN; **không** mở cổng inbound.
- **FR-VR-CONN-02** Kết nối xuyên NAT qua WebRTC (STUN dò địa chỉ; TURN relay khi P2P fail).
- **FR-VR-CONN-03** Mở phiên remote phải được **backend uỷ quyền** (lệnh ký HMAC) kèm **TTL**; hết TTL tự đóng.
- **FR-VR-CONN-04** Box **mặc định TẮT** chia sẻ màn hình; chỉ bật trong phiên hợp lệ.
- **FR-VR-CONN-05** Đóng phiên chủ động (`disable`) hoặc tự đóng khi hết TTL / mất kết nối; agent dọn tài nguyên + xoá credential TURN tạm.
- **FR-VR-CONN-06** Hỗ trợ reconnect khi rớt mạng tạm; phiên còn TTL thì nối lại được.

### 4.2 Giám sát màn hình — Monitor (FR-VR-VIEW) — **P1**
- **FR-VR-VIEW-01** Xem realtime màn hình box trên console web (`<video>`).
- **FR-VR-VIEW-02** Nhiều người có quyền `view` cùng xem một box (read-only) — nếu khả thi kỹ thuật; tối thiểu 1 người.
- **FR-VR-VIEW-03** Hiển thị trạng thái phiên (đang kết nối/relay/P2P, độ trễ, TTL còn lại).
- **FR-VR-VIEW-04** Cho chọn độ phân giải/FPS (mặc định tiết kiệm băng thông).

### 4.3 Điều khiển — Control (FR-VR-CTRL) — **P2**
- **FR-VR-CTRL-01** Gửi thao tác chuột/chạm + bàn phím từ console → box (qua data channel).
- **FR-VR-CTRL-02** Điều khiển yêu cầu quyền **`edge.admin.control`** (cao hơn `view`).
- **FR-VR-CTRL-03** Bơm input trên Android qua **AccessibilityService**/device-owner (cần PoC — xem R1).
- **FR-VR-CTRL-04** Chỉ một người **control** tại một thời điểm (khoá quyền điều khiển); người khác chỉ `view`.

### 4.4 Fleet ops (FR-VR-FLEET) — **P3**
- **FR-VR-FLEET-01** Lệnh **an toàn**: health, version, ping, tail-log, đọc config, **restart-app (VBridge)**.
- **FR-VR-FLEET-02** Lệnh **GATED** (mặc định TẮT + xác nhận + quyền cao): reboot box, config-push, **apk-update (VBridge)**, cache-clear.
- **FR-VR-FLEET-03** VRemote Agent **supervise VBridge**: restart/cập nhật VBridge mà không tự chết (tiến trình riêng).
- **FR-VR-FLEET-04** Cập nhật VBridge phải **có rollback** nếu bản mới lỗi (health-check sau update rồi mới commit).
- **FR-VR-FLEET-05** Heartbeat/telemetry định kỳ về backend (online, phiên bản, tài nguyên).
- **FR-VR-FLEET-06** Dashboard nhiều box: trạng thái online/offline, phiên bản, phiên đang mở.

### 4.5 Danh tính & phân quyền (FR-VR-AUTH)
- **FR-VR-AUTH-01** Đăng nhập qua **identity chuẩn vplus** (không dựng silo tài khoản riêng).
- **FR-VR-AUTH-02** Quyền vào VRemote = có **gói dịch vụ VRemote** (entitlement) + role phù hợp.
- **FR-VR-AUTH-03** Tách quyền **`edge.admin.view`** / **`.control`** / gated ops.
- **FR-VR-AUTH-04** Sẵn sàng **federate/SSO** qua **vplus.vn** về sau (đăng nhập một lần cho cả bộ) — thiết kế theo interface, không viết lại khi ghép.
- **FR-VR-AUTH-05** FE/console **không** tự ký lệnh; ký do backend (giữ khoá).

### 4.6 Audit & nhật ký (FR-VR-AUDIT)
- **FR-VR-AUDIT-01** Ghi mọi phiên: ai, box nào, bắt đầu/kết thúc, view hay control, qua sản phẩm nào.
- **FR-VR-AUDIT-02** Ghi mọi lệnh fleet (đặc biệt gated) + kết quả.
- **FR-VR-AUDIT-03** Không log secret/PIN/OTP/credential/khoá.
- **FR-VR-AUDIT-04** Audit truy vấn được (theo box, theo người, theo thời gian).

### 4.7 Tích hợp (FR-VR-INTEG)
- **FR-VR-INTEG-01** API cho sản phẩm khác **mở phiên cho một deviceId** → trả **viewer token phạm vi hẹp + TTL** (chỉ 1 device, 1 khả năng view/control).
- **FR-VR-INTEG-02** **Embeddable viewer**: component/iframe nhận token để nhúng màn hình vào UI sản phẩm khác (không copy code WebRTC).
- **FR-VR-INTEG-03** API fleet-ops cho một thiết bị (giới hạn theo quyền).
- **FR-VR-INTEG-04** Sản phẩm khác **không** nói trực tiếp với box — mọi thứ qua VRemote (một cửa + audit).
- **FR-VR-INTEG-05** Danh tính xuyên sản phẩm qua SSO vplus; không nâng quyền vượt tài khoản.

### 4.8 Thiết bị & provisioning (FR-VR-DEVICE)
- **FR-VR-DEVICE-01** Đăng ký box vào VRemote (deviceId) + **map `deviceId ↔ machineCode`** (định danh của Smart Retail).
- **FR-VR-DEVICE-02** Cấp **khoá ký + endpoint + config TURN** cho box lúc lắp máy (provisioning), nạp runtime, **không hardcode**.
- **FR-VR-DEVICE-03** Thu hồi/đổi khoá của một box (rotation) khi cần.
- **FR-VR-DEVICE-04** Đa nền: Android trước; Linux sau (kiến trúc không khoá cứng Android).

### 4.9 Console web (FR-VR-CONSOLE)
- **FR-VR-CONSOLE-01** App web độc lập (ngang hàng admin-portal), URL riêng.
- **FR-VR-CONSOLE-02** Danh sách box + trạng thái + mở phiên xem/điều khiển.
- **FR-VR-CONSOLE-03** i18n (vi/en) tối thiểu.
- **FR-VR-CONSOLE-04** Hiển thị rõ đang **view** hay **control**, TTL, và nút kết thúc phiên.

## 5. Yêu cầu phi chức năng (NFR)

| Mã | Yêu cầu |
|---|---|
| **NFR-SEC** | Không inbound vào box; DTLS-SRTP (WebRTC) mã hoá media; lệnh ký HMAC + nonce/ts chống replay; token phiên phạm vi hẹp + TTL; secret nạp runtime; least-privilege cho tài khoản chạy trên box. |
| **NFR-PERF** | Độ trễ tương tác mục tiêu **< 300 ms** trong điều kiện mạng bình thường (P2P); relay chấp nhận cao hơn. |
| **NFR-BW** | Ưu tiên P2P; khi relay, giới hạn độ phân giải/FPS mặc định để kiểm soát băng thông TURN. |
| **NFR-AVAIL** | Signaling/TURN có thể chạy nhiều instance; box tự reconnect; mất relay không làm hỏng box. |
| **NFR-PORT** | Đầu box đa nền (Android/Linux); backend/console theo chuẩn stack vplus. |
| **NFR-SCALE** | Hỗ trợ đội box tăng dần (hàng trăm+); registry + heartbeat chịu tải. |
| **NFR-OBS** | Log/metric/health cho signaling, TURN, backend; trạng thái box quan sát được. |
| **NFR-COMPAT** | Không phá contract lock-control của VBridge; tái dụng chuẩn ký (golden vector). |
| **NFR-LICENSE** | Chỉ dùng thư viện license thân thiện (WebRTC BSD…); tránh AGPL nếu sẽ thương mại hoá. |

## 6. Ràng buộc & giả định

- Dùng **WebRTC** (không tự viết NAT-traversal/mã hoá).
- **Bootstrap từ vplus-platform** (identity/module-registry/security baseline/CI/contract-first).
- Cần **VPS** đặt signaling + TURN.
- Android bị-điều-khiển: **xem** khả thi (MediaProjection); **điều khiển** cần Accessibility/device-owner (PoC).

## 7. Phân pha (map yêu cầu)

| Pha | Yêu cầu chính | Tiêu chí xong |
|---|---|---|
| **P1 — Monitor** | CONN, VIEW, AUTH(view), AUDIT(session), DEVICE(register/provision), CONSOLE(view) | Xem được màn hình box thật qua relay, có phiên ký + TTL + audit + đăng nhập |
| **P2 — Control** | CTRL, AUTH(control) | Điều khiển được box thật (sau PoC Accessibility), khoá 1-người-control |
| **P3 — Fleet + Integ** | FLEET, INTEG, dashboard, supervisor + rollback | Vận hành đội box + tích hợp Smart Retail/vplus-ai (API + viewer nhúng) |

## 8. Tiêu chí nghiệm thu (Acceptance — tóm tắt)

- **AC-P1:** Từ console, đăng nhập (quyền view) → chọn box thật → thấy màn hình realtime; phiên tự đóng khi hết TTL; audit ghi đủ; box từ chối khi không có phiên ký.
- **AC-P2:** Với quyền control → điều khiển được chuột/phím trên box thật; người chỉ có view không điều khiển được; chỉ 1 người control cùng lúc.
- **AC-P3:** Gọi được lệnh fleet an toàn/gated (gated cần xác nhận + quyền cao); Smart Retail mở được phiên xem 1 tủ qua API + viewer nhúng, không nói trực tiếp với box; cập nhật VBridge có rollback khi bản lỗi.

## 9. Rủi ro & câu hỏi mở (cần chủ dự án)

- **R1** — Android **điều khiển tay** (input injection) cần Accessibility/device-owner → **PoC bắt buộc** trước P2.
- ~~**D1** — Tổ chức repo VRemote~~ → **ĐÃ CHỐT**: 2 repo — `sab-com-vn/edge-agent` (VBridge/device) + `vplus-vn/vremote` (VRemote: agent thiết bị + web control, trên vplus-platform).
- **D2** — VPS đặt signaling + TURN; số phiên đồng thời dự kiến.
- **D3** — vplus.vn SSO + đăng ký gói tới đâu → chốt interface identity cho P1.
- **D4** — Định danh box: dùng lại `machineCode` của Smart Retail hay deviceId riêng của VRemote (đề xuất: deviceId riêng + bảng map).

---

*Xem chi tiết kiến trúc, luồng, contract, data model, stack trong `DSG-VREMOTE-001`.*
