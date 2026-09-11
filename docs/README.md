# VRemote Documentation

Tài liệu canonical của **VRemote** — hệ thống quản trị và điều khiển edge box từ xa trong bộ vplus.

## Tài liệu chính

| Nhóm | Tài liệu |
|---|---|
| Yêu cầu | [`requirements/REQ-VREMOTE-001-remote-admin.md`](requirements/REQ-VREMOTE-001-remote-admin.md) |
| Kiến trúc domain | [`design/DSG-VREMOTE-001-remote-admin-architecture.md`](design/DSG-VREMOTE-001-remote-admin-architecture.md) |
| Chuẩn hóa v0.2.0 | [`design/DSG-VREMOTE-002-platform-standardization.md`](design/DSG-VREMOTE-002-platform-standardization.md) |
| Trạng thái (canonical) | [`implementation/STATUS.md`](implementation/STATUS.md) |
| Runbook | [`implementation/RUNBOOK.md`](implementation/RUNBOOK.md) |

> `implementation/P1-STATUS.md` và `implementation/P1-implementation-status.md` là snapshot lịch sử của P1, đã *superseded* bởi `STATUS.md`.

## Nguồn chuyển

Các tài liệu VRemote được chuyển từ `sab-com-vn/edge-agent/docs` sang repo sản phẩm `vplus-vn/vremote/docs`, theo quyết định tổ chức repo trong DSG-VREMOTE-001.

- `sab-com-vn/edge-agent` tiếp tục là repo **VBridge** (web ↔ phần cứng).
- `vplus-vn/vremote` là repo **VRemote** (remote operator ↔ màn hình/OS của box), xây trên `vplus-platform`.

## Trạng thái

Tài liệu REQ/DSG-001 là **DRAFT** kiến trúc domain đích. Baseline hiện tại là `vremote_v0.1.0`; giai đoạn đang triển khai là **Platform Standardization v0.2.0** theo `DSG-VREMOTE-002` (handoff `#7`). Trạng thái triển khai canonical: `implementation/STATUS.md`.
