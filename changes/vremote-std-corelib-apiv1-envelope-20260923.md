# VRemote — Chuẩn hoá core-lib + API v1 + response envelope (2026-09-23)

- **Nhãn version:** `[v0.1.1]` (console `package.json` = 0.1.0; không bump version).
- **Nhánh / PR:** `claude/std-corelib-apiv1-envelope` → `develop`.
- **Tracking:** https://github.com/sab-com-vn/workspace/issues/287
- **Chuẩn:** https://github.com/vplus-platform/control/blob/main/docs/references/api-standard.md ·
  https://github.com/vplus-platform/control/blob/main/changes/corelib-adoption-autorun-runbook-20260923.md

## 1. Ma trận AS-IS / TARGET / GAP

| Hạng mục | AS-IS (develop `0ad9e99`) | TARGET (api-standard) | Sau PR này |
|---|---|---|---|
| core-lib | `dev-main` @ `7493257`, chỉ dùng `CheckAuthMiddleware` (qua `CoreLibPlatformClient`) | dùng core-lib cho auth, envelope, correlation | `dev-main` @ `a0a984d`; dùng thêm `ApiResponse`/`ApiResponder` (envelope), `CorrelationIdMiddleware` |
| Route nghiệp vụ | 28 route dưới `/api/vremote/v1/...` | `/api/<svc>/v1/...` | không đổi (đã đạt) |
| Health | `/api/vremote/health` (closure) | `/api/<svc>/health` Mongo-free | `OpsController@health`, shape giữ nguyên |
| Ready | không có | `/api/<svc>/ready` | **mới** `/api/vremote/ready` (Mongo ping khi `DB_CONNECTION=mongodb`, signaling internal) — 200/503 |
| Envelope thành công | body trần | `{data}` | `{data}` khi `X-Api-Envelope: v1`; mặc định vẫn `legacy` (xem §2) |
| Envelope lỗi (controller) | `{error:{code,message}}` | như nhau | giữ; helper dùng `ApiResponder::fail` |
| Envelope lỗi (framework) | Laravel mặc định `{message}` / `{message,errors}` | `{error:{code,message}}` | **luôn** chuẩn: 422 `VALIDATION_FAILED`+`details`, 404 `NOT_FOUND`, 405 `METHOD_NOT_ALLOWED`, 400 `BAD_REQUEST`, 500 `INTERNAL_ERROR` (không lộ chi tiết) |
| `DELETE /sessions/{id}` 404 | body `null` | error envelope | `{error:{code:NOT_FOUND}}` |
| `POST /devices/{id}/commands` 409 | `{commandId,accepted:false,result:replay_detected}` | error envelope | legacy giữ nguyên; `v1` → `{error:{code:REPLAY_DETECTED,details:{...}}}` |
| Correlation | không | `X-Correlation-Id` nhận/sinh + trả lại | có (core-lib, mọi route API) |
| Dead code | `VRemoteAuth` (JWT silo, alias `vremote.auth` không dùng) | — | **xoá** |
| Test | `develop` đỏ âm thầm (fake repo thiếu `findByPairingId`/`delete`), không CI | test xanh + CI | fake sửa; +15 test HTTP; workflow `test-backend.yml` (self-hosted) |

## 2. Contract & tương thích ngược

`api/vremote` v0.0.1 đang **khoá** (PROJECT.md). PR này **không sửa** `api/vremote/*`:

- Envelope thành công là **opt-in** theo request (`X-Api-Envelope: v1`), mặc định `legacy` =
  body v0.0.1 → agent Android đã cài + sản phẩm tích hợp (`/integrations/sessions`) không vỡ.
- Console (deploy cùng backend) opt-in `v1` và bóc `data`.
- Agent Android: parse chịu được cả 2 shape (`data ?? body`) — chuẩn bị lật mặc định.
- Lỗi do framework sinh trước đây không thuộc contract (shape mặc định Laravel) → chuẩn hoá luôn;
  client hiện có đều đọc `error.message` (agent `parseErr`, console) nên được lợi, không vỡ.
- Response có header `X-Api-Envelope: legacy|v1` cho biết shape đã trả.

**Cần duyệt (Contract Change Gate):** lật `VREMOTE_API_ENVELOPE_DEFAULT=v1` (chỉ đổi env) sau khi
fleet agent lên bản có `unwrap` và các sản phẩm tích hợp gửi/đọc envelope; khi đó cập nhật
`api/vremote/openapi.yaml` lên contractVersion mới (schema bọc `data`).

## 3. Chưa làm (cần duyệt / pha sau)

- [ ] Hợp nhất auth về core-lib `SabAuthOptions` (referenceMode + checkRef) thay `CheckRefPlatformClient`
      tự viết và 3 fallback `Oidc/Jwt/HttpPlatformClient` — đụng auth prod, cần key S2S + duyệt.
- [ ] Phân quyền route bằng `core.permission:edge.admin.*` thay `requirePermission()` lặp trong controller.
- [ ] Pagination cursor thật (`cursor`/`nextCursor` hiện luôn `null`), `meta` trong envelope.
- [ ] `composer.lock` đang khoá symfony 8.x (PHP ≥ 8.4.1) trong khi runner/prod PHP 8.3 → deploy rơi
      về `composer update` (tồn tại từ trước PR này). Cần quyết: nâng PHP 8.4 hay re-lock cho 8.3.
- [ ] `console/package-lock.json` lệch `package.json` (`npm ci` lỗi; từ trước PR này).
- [ ] VRemote chưa có môi trường Dev / workflow deploy-dev (chỉ `main` → Production). Chưa deploy.

## 4. Kiểm chứng (local sandbox, PHP 8.4.19)

- `vendor/bin/phpunit`: **55 tests, 163 assertions — OK** (baseline 40 sau khi sửa fake).
- `php -l` toàn bộ app/config/routes/bootstrap/tests: OK · `php artisan route:list`: 28 route v1 + health + ready.
- `php artisan config:cache`: OK · `console`: `npm run build` (tsc + vite) OK.
- Smoke `php artisan serve`: health 200; ready 503 (`signaling: fail` — không chạy signaling local);
  `GET /devices` legacy `{items,nextCursor}` + header `X-Api-Envelope: legacy`; `X-Api-Envelope: v1` →
  `{data:{items,nextCursor}}`; 422 `VALIDATION_FAILED`; 404 `NOT_FOUND`; `X-Correlation-Id` có trên mọi response.
