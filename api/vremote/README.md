# VRemote — Contract (`api/`)

**contractVersion: 0.0.1** — nguồn sự thật máy-đọc cho VRemote.

Nguồn gốc: `sab-com-vn/edge-agent/api/vremote/`.

## Tệp
| Tệp | Nội dung |
|---|---|
| `openapi.yaml` | REST Backend: `/sessions`, `/devices`, `/devices/{id}/commands`, `/audit`, `/integrations/sessions` |
| `asyncapi.yaml` | WS `/signaling` (đàm phán WebRTC) + `/agent` (kênh lệnh box) |
| `signing.md` | HMAC-SHA256 canonical + golden vector cho `session.enable` và `command` |

## Boundary bắt buộc
- Media + input đi **trực tiếp qua WebRTC** (media track + data channel), không đi qua WS.
- `/signaling` chỉ dùng cho SDP/ICE và xác thực phiên bằng `sessionToken`.
- `/agent` là kênh điều khiển box; thông điệp backend → box phải tuân `signing.md`.
- **Backend là bên duy nhất ký** `session.enable`/`command`; FE/console/sản phẩm tích hợp không ký.
- Sản phẩm tích hợp chỉ gọi `/integrations/sessions`, nhận token phạm vi hẹp + TTL và không nói trực tiếp với box.

## Conformance
Mọi implementation phải khớp `signing.md` byte-for-byte, gồm 3 golden vectors. Bắt buộc có test cross-language giữa backend và agent Kotlin.

## Phân pha
- P1: `/sessions` + signaling + `session.enable` + WebRTC video + `/agent` hello/telemetry.
- P2: `control` + input WebRTC data channel.
- P3: fleet commands + integrations/viewer.

> Không tự sửa contract v0.0.1. Thay đổi contract phải được review/chốt trước khi implementation.
