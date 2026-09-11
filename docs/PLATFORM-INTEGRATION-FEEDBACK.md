# Góp ý tích hợp `vplus-platform/core-lib` — từ trải nghiệm onboard VRemote

> Tài liệu này ghi lại **các quy ước ngầm** phải tự phát hiện khi tích hợp `vplus-platform/core-lib`
> vào VRemote (auth qua Platform), kèm **đề xuất cải thiện** gửi team `vplus-platform`.
> Vừa là bài học nội bộ cho service VRemote, vừa là bản góp ý để chuyển lên `vplus-platform/core-lib`.
>
> Ngày: 2026-09-12 · Bối cảnh: đưa VRemote lên production `vremote.vplus.vn`, auth dùng chung platform như fleet `cab.sab.com.vn`.
>
> **Đã được platform tiếp nhận:** feedback này đã trở thành backlog **DX-1** của core-lib
> (`vplus-platform/control → projects/vplus-platform/core-lib-consumer-dx.md`), khớp finding **S8**
> trong audit (ADR-0008). VRemote được ghi nhận là **consumer thật đầu tiên** của core-lib → đủ DoD
> để platform **tag `0.1.0`**. Tài liệu này là **case study nguồn** của DX-1, không phải nguồn sự thật
> song song — mọi quyết định DX thuộc về DX-1.

---

## 1. Vấn đề: tích hợp phải "đọc mã vendor", không có tài liệu cho consumer

Để bật xác thực Platform cho một service mới, mọi thứ cần biết đều là **quy ước ngầm** — chỉ lộ ra khi
đọc mã trong `vendor/` hoặc copy cấu hình từ một service fleet khác. Cụ thể đã phải tự mò:

| Cần biết | Thực tế phải làm để phát hiện | Đúng ra nên |
|---|---|---|
| Provider nạp thế nào | `grep` `extra.laravel.providers` trong `vendor/.../composer.json` | Ghi trong README |
| Vị trí public key | Mò file `platform_public_key.pem` ở `coupon-service/shared/storage/keys/services/platform/` | Doc nêu rõ path mặc định |
| `config/core.php` default path | `cat vendor/.../config/core.php` | Doc + bảng override |
| Vì sao verify "chết" | `tinker` in `config('core.jwt.public_key_path')` = rỗng | Command tự kiểm + log rõ |
| Vì sao provider không nạp | `package:discover` chưa chạy (fail âm thầm ở deploy trước) | Fail phải "nói to" |

Triệu chứng cuối cùng người tích hợp thấy chỉ là `401 {"code":"UNAUTHENTICATED"}` — **giống hệt** lỗi
token sai, khiến mất nhiều thời gian mới nhận ra gốc là **config/provider chưa nạp**, không phải token.

---

## 2. Đề xuất cải thiện (ưu tiên cao → thấp)

### 2.1 `INTEGRATION.md` cho service tiêu thụ (consumer)
Một trang checklist ngắn để bật auth cho service mới:
1. `composer require vplus-platform/core-lib` (provider tự nạp qua auto-discovery).
2. Đặt `platform_public_key.pem` vào `storage/keys/services/platform/`.
3. `composer install` → `php artisan package:discover` → `php artisan optimize:clear`.
4. Dùng `Platform\Core\Http\Middleware\CheckAuthMiddleware` (hoặc adapter tương đương).
5. Kiểm bằng `php artisan core:doctor`.

Kèm **bảng biến môi trường override**: `CORE_JWT_PUBLIC_KEY`, `CORE_JWT_PUBLIC_KEY_PATH`,
`CORE_JWT_ISSUER`, `CORE_JWT_AUDIENCE` — nêu rõ cái nào tùy chọn, default là gì.

### 2.2 Lệnh tự kiểm `php artisan core:doctor`
In PASS/FAIL cho từng điều kiện — đúng những thứ chúng tôi phải tự chế bằng `tinker`:
- CoreServiceProvider đã nạp chưa?
- `config('core.jwt.public_key_path')` trỏ tới đâu, file tồn tại + đọc được không?
- issuer/audience đang kỳ vọng gì?
- (tùy chọn) decode thử một token mẫu để xác nhận verify chạy.

### 2.3 Fail phải "nói to", đừng im lặng
Khi thiếu/không đọc được public key, `CheckAuthMiddleware` nên **log cảnh báo rõ ràng**
(vd `core-lib: public key not found at <path>`), thay vì chỉ trả `401 UNAUTHENTICATED` —
để người tích hợp phân biệt được "token sai" với "server chưa cấu hình key".

### 2.4 Command bootstrap key `php artisan core:install-key`
Tự tạo `storage/keys/services/platform/` đúng quyền và hướng dẫn đặt `.pem`, để mỗi service
khỏi mò path + chmod thủ công (chúng tôi phải `mkdir -p` + `chown vdeploy:www-data` + `chmod 640` bằng tay).

### 2.5 Ghi rõ phụ thuộc `package:discover` trong quy trình deploy
Provider chỉ nạp khi manifest (`bootstrap/cache/packages.php`) được sinh. Nếu `package:discover`
fail (ví dụ do một lỗi config khác ở service), auth "chết" mà không rõ nguyên nhân. README nên
khuyến nghị chạy `optimize:clear` + `package:discover` ở cuối script deploy, và `core:doctor` để chốt.

### 2.6 Versioning + CHANGELOG thay cho `dev-main`
Các service đang pin `vplus-platform/core-lib: dev-main` (moving target). Nên phát hành **tag semver**
+ CHANGELOG, để service biết khi contract auth thay đổi (claim mapping, path, env) mà chủ động nâng.

---

## 3. Phụ lục — Quy ước ngầm đã phát hiện (tham chiếu cho service VRemote)

Ghi lại để dev VRemote sau này khỏi phải mò lại:

- **Provider**: `Platform\Core\CoreServiceProvider`, tự nạp qua `extra.laravel.providers`
  trong `composer.json` của core-lib (Laravel package auto-discovery). Không cần khai tay ở
  `bootstrap/providers.php` — nhưng **phải** để `package:discover` chạy được.
- **Config**: `vendor/vplus-platform/core-lib/config/core.php`, merge vào key `core.*`.
  Đọc bằng `config('core.jwt.public_key_path')`.
- **Public key mặc định**: `storage/keys/services/platform/platform_public_key.pem`
  (tức `storage_path('keys/services/platform/platform_public_key.pem')`). Là **khóa công khai**,
  copy giữa các service an toàn. Fleet **không** set env path — chỉ đặt file đúng chỗ.
- **Verify**: `CheckAuthMiddleware` xác thực RS256 **tại chỗ** bằng public key (không gọi ra platform);
  set claim đã verify vào `request->attributes['auth']`. VRemote map claim đó sang `PlatformContext`
  qua `CoreLibPlatformClient` (xem `backend/app/Infrastructure/Platform/CoreLibPlatformClient.php`).
- **URL platform**: theo chuẩn `/api/{service}` → `PLATFORM_SERVICE_URL = <base>/api/platform`.
  VRemote fallback `VREMOTE_PLATFORM_URL=https://cab.sab.com.vn/api/platform` (chỉ dùng cho OIDC/HTTP
  fallback; verify chính bằng public key nên URL không tham gia).
- **Bẫy thường gặp**: `config('core.*')` rỗng ⇒ manifest chưa gồm core-lib ⇒ chạy
  `php artisan package:discover` + `optimize:clear`. `401 UNAUTHENTICATED` với token hợp lệ ⇒ kiểm key file,
  không phải token.

## 4. Việc cần làm tiếp cho VRemote (theo DX-1)

Rút từ bảng env chuẩn hoá của DX-1 (`core-lib-consumer-dx.md`):

- **`.env` VRemote nên set khớp default platform**:
  `CORE_JWT_ISSUER=platform-service` (default DX-1) và `CORE_JWT_AUDIENCE` theo service; key path
  mặc định `storage/keys/services/platform/platform_public_key.pem` (đã đặt) — không cần override nếu
  đặt đúng chỗ.
- **Pin phiên bản khi core-lib có tag**: hiện `composer.json` dùng `vplus-platform/core-lib: dev-main`
  (moving target). Khi platform tag **`0.1.0`** (mà VRemote chính là consumer kích hoạt), đổi sang
  `^0.1.0` để ổn định + biết khi contract auth đổi.
- **Deploy**: thêm `optimize:clear` + `package:discover` (+ `core:doctor` khi DX-1 ra) vào cuối quy
  trình deploy — chính là bẫy đã làm auth "chết câm" ở lần deploy này (DX-1 mục 5).
