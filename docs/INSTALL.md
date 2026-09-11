# VRemote — Hướng dẫn cài đặt & triển khai thủ công

Tài liệu này mô tả cách cài đặt VRemote lên **production** (`vremote.vplus.vn`) **thủ công qua SSH**,
áp dụng khi CI/CD (GitHub Actions) chưa dùng được. Nó bổ sung cho `deploy/DEPLOY.md`
(bản CI/CD) và ghi lại đầy đủ các "bẫy" thực tế đã gặp: xác thực repo private, PHP 8.3 vs
`composer.lock`, tải `vplus-platform/core-lib` qua SSH, tạo user MongoDB, nginx/php-fpm/systemd/TLS.

> **Chuẩn fleet:** VRemote chạy cùng chuẩn với các service `cab.sab.com.vn`
> (payment/platform/commerce/coupon/channel): **PHP 8.3 · Laravel 13.8 · MongoDB · nwidart/laravel-modules**.
> Không nâng/hạ khác fleet.

---

## 0. Kiến trúc triển khai (tóm tắt)

Một domain duy nhất `vremote.vplus.vn`, nginx đứng trước và phân tuyến:

| Path | Phục vụ | Đích |
|---|---|---|
| `/` | Console SPA (React tĩnh) | `current/console/dist` |
| `/api/...` | Backend Laravel | nginx nội bộ `127.0.0.1:8080` → php-fpm 8.3 |
| `/signaling`, `/agent` | Signaling WebSocket (chỉ điều khiển) | `127.0.0.1:8787` (Node) |
| `/downloads/...` | APK agent đã ký | `shared/downloads` |

- **Media + input (điều khiển màn hình) đi thẳng qua WebRTC**, không qua WebSocket.
- Định danh/SSO dùng **chung platform service** với `cab.sab.com.vn` (qua `VREMOTE_PLATFORM_URL` / core-lib), **không** deploy platform ở đây.
- Cổng nội bộ `8080`/`8787`/`8788` chỉ nghe loopback, không lộ ra ngoài.

Bố cục thư mục trên server (release/current/shared, phát hành nguyên tử):

```text
/var/www/vremote.vplus.vn/            # DEPLOY_PATH
├── releases/
│   └── r1/  (hoặc 20260911xxxx-<sha>) # backend/ console/dist signaling/{dist,node_modules}
├── current -> releases/<mới nhất>     # symlink đảo ở cuối mỗi lần deploy
└── shared/                            # tồn tại xuyên các release
    ├── .env                           # env backend (từ backend/.env.example)
    ├── signaling.env                  # env signaling (từ signaling/.env.example)
    ├── backend-storage/               # storage Laravel (log, cache framework)
    └── downloads/                     # vremote-agent.apk
```

---

## 1. Chuẩn bị server (một lần)

### 1.1 Gói phần mềm
```bash
sudo apt-get update
sudo apt-get install -y nginx php8.3-fpm php8.3-mongodb php8.3-mbstring php8.3-xml \
  php8.3-curl php8.3-bcmath php8.3-zip unzip git rsync certbot python3-certbot-nginx
# Node.js (cho signaling) — dùng bản LTS 20+ theo chuẩn fleet
# MongoDB: cài tại chỗ hoặc trỏ tới instance dùng chung
composer --version   # cần Composer 2.x
php -v                # xác nhận PHP 8.3.x
```

### 1.2 Người dùng hệ thống

Có **2 user tách vai trò**:

| User | Vai trò |
|---|---|
| `vdeploy` | Sở hữu mã nguồn, clone/build/deploy (đã có sẵn trên server này) |
| `vremote` | Chạy service signaling qua systemd (system user, không login) |

```bash
sudo adduser --system --group --no-create-home vremote
```

php-fpm chạy bằng `www-data` (mặc định) — cần đọc mã nguồn và ghi được `storage/`. Xem §5.

### 1.3 Thư mục & quyền
```bash
sudo mkdir -p /var/www/vremote.vplus.vn/{releases,shared/downloads,shared/backend-storage}
sudo chown -R vdeploy:vdeploy /var/www/vremote.vplus.vn
```

### 1.4 Xác thực GitHub cho repo private (QUAN TRỌNG)

GitHub **đã bỏ xác thực bằng mật khẩu**. Repo `vplus-vn/vremote` và package
`vplus-platform/core-lib` đều private ⇒ phải dùng **SSH key** (hoặc token). Trên server này
`vdeploy` đã có sẵn SSH key đăng ký với GitHub (giống các service fleet đang chạy), nên
**dùng SSH là gọn nhất, không cần token**.

Kiểm tra key có quyền đọc cả 2 org:
```bash
sudo -iu vdeploy
git ls-remote git@github.com:vplus-vn/vremote.git        >/dev/null && echo "OK vremote"
git ls-remote git@github.com:vplus-platform/core-lib.git >/dev/null && echo "OK core-lib"
```
Cả hai in `OK` ⇒ dùng SSH được cho toàn bộ bước sau.

> Nếu chưa có key: tạo `ssh-keygen -t ed25519 -f ~/.ssh/vremote_deploy -N ""`,
> thêm public key vào **Deploy keys** của repo (chỉ Read), và cấu hình `~/.ssh/config`.
> `core-lib` khác org nên cần key/deploy-key riêng có quyền đọc `vplus-platform`.

---

## 2. Lấy mã nguồn

Clone **từ nhánh `main`** (đã gộp toàn bộ chuẩn hóa platform + core-lib auth), **bằng SSH**:

```bash
sudo -iu vdeploy
cd /var/www/vremote.vplus.vn
git clone -b main git@github.com:vplus-vn/vremote.git releases/r1
cd releases/r1
```

> ❌ Đừng dùng `https://github.com/...` rồi gõ username/password — GitHub sẽ từ chối
> (`Password authentication is not supported`). Luôn dùng `git@github.com:`.

---

## 3. Build backend (Laravel · PHP 8.3)

### 3.1 Cho Composer tải được `core-lib` (private) qua SSH

`composer.json` khai `core-lib` là VCS repo với URL `https://github.com/vplus-platform/core-lib.git`.
Mặc định Composer tải "dist" = file zip qua **GitHub API** (`api.github.com/.../zipball`), mà API
này với repo private **cần token** ⇒ trả `404`. Cách xử lý **không cần token** — ép đi qua git/SSH:

```bash
# Đổi mọi URL https github → SSH (dùng key vdeploy)
git config --global url."git@github.com:".insteadOf "https://github.com/"
```

Và ép cài `core-lib` từ **source (git clone)** thay vì dist API. Chọn 1 trong 2:

```bash
# (a) Chỉ core-lib cài từ source — ghi luôn vào composer.json (fix vĩnh viễn, khuyến nghị)
composer config preferred-install.vplus-platform/core-lib source

# (b) Hoặc ép source cho toàn bộ lần cài này
#     composer update --prefer-source --no-interaction
```

> **Fix vĩnh viễn tốt nhất:** thêm `"no-api": true` vào khối `repositories` của core-lib trong
> `backend/composer.json`:
> ```json
> "repositories": [
>   { "type": "vcs", "url": "https://github.com/vplus-platform/core-lib.git", "no-api": true }
> ]
> ```
> Khi đó Composer luôn git-clone core-lib (qua SSH), không đụng API, khỏi cần cờ.

### 3.2 Sinh lại `composer.lock` cho đúng PHP 8.3

`composer.lock` trong repo có thể được resolve trên máy **PHP 8.4** ⇒ kéo `symfony 8.1.x`
(bỏ hỗ trợ 8.3) và `ext-mongodb ^2.4`, gây lỗi `Your lock file does not contain a compatible
set of packages` trên server 8.3. **Sinh lại lock ngay trên server 8.3** — Composer sẽ tự chọn
`symfony 7.4.x` hợp Laravel 13.8 (giống các service fleet):

```bash
cd /var/www/vremote.vplus.vn/releases/r1/backend
rm -f composer.lock
composer update --no-interaction        # resolve + cài (kèm dev)
```

Sau khi chạy trơn, cài lại gọn cho production (bỏ dev, tối ưu autoload):
```bash
composer install --no-dev --optimize-autoloader --no-interaction
```

> Nếu vướng `ext-mongodb`: chỉ khi thật cần mới thêm `--ignore-platform-req=ext-mongodb`.
> Bình thường Composer tự chọn `mongodb/mongodb 2.3.x` hợp ext 2.3 đã cài — không cần cờ.

### 3.3 Sinh `composer.lock` chuẩn 8.3 để tái lập
`composer.lock` mới (đã hợp 8.3) nên được **commit về repo** để lần deploy sau không phải
`composer update` lại. Trên máy dev có PHP 8.3 (hoặc chính server), commit `backend/composer.lock`
và (nếu áp dụng) `backend/composer.json` đã thêm `no-api`.

---

## 4. Cấu hình môi trường (`shared/.env`)

Toàn bộ secret **chỉ nằm trong `shared/.env` trên server**, không bao giờ trong Git.

```bash
cp /var/www/vremote.vplus.vn/releases/r1/backend/.env.example /var/www/vremote.vplus.vn/shared/.env
$EDITOR /var/www/vremote.vplus.vn/shared/.env
```

Các khóa bắt buộc điền (xem đầy đủ trong `backend/.env.example`):

| Nhóm | Khóa | Ghi chú |
|---|---|---|
| App | `APP_KEY` | `php artisan key:generate` (hoặc điền base64 32 byte) |
| App | `APP_ENV=production` `APP_DEBUG=false` | |
| Auth | `VREMOTE_DEV_AUTH=false` | **BẮT BUỘC false** ở production |
| Auth | `VREMOTE_PLATFORM_URL=https://cab.sab.com.vn` | SSO dùng chung platform |
| Auth | `CORE_JWT_PUBLIC_KEY(_PATH)` | Khóa công khai issuer để verify JWT (nếu dùng core-lib) |
| DB | `SERVICES=vremote` | DB/user/auth-db suy ra từ đây |
| DB | `MONGODB_HOST/PORT` | vd `127.0.0.1` / cổng thật |
| DB | `MONGODB_PASSWORD` | mật khẩu thật của `vremote_user` |
| Token | `VREMOTE_SESSION_SECRET` | 32+ byte ngẫu nhiên; **phải trùng** signaling |
| Signaling | `VREMOTE_SIGNALING_URL=wss://vremote.vplus.vn/signaling` | trả về cho console |
| Signaling | `VREMOTE_SIGNALING_INTERNAL_URL=http://127.0.0.1:8788` | loopback |
| Signaling | `VREMOTE_SIGNALING_INTERNAL_SECRET` | **phải trùng** signaling |
| TURN | `VREMOTE_TURN_URLS` `VREMOTE_TURN_SHARED_SECRET` | trùng coturn + agent |
| Fleet | `VREMOTE_GATED_COMMANDS=false` | lệnh nguy hiểm mặc định tắt |

Sinh secret nhanh: `openssl rand -base64 32`.

### 4.1 Tạo user MongoDB
```bash
mongosh "mongodb://127.0.0.1:<MONGODB_PORT>"
```
```javascript
use vremote
db.createUser({
  user: "vremote_user",
  pwd:  "<MONGODB_PASSWORD>",           // trùng .env, KHÔNG commit
  roles: [ { role: "readWrite", db: "vremote" }, { role: "dbAdmin", db: "vremote" } ]
})
// kiểm tra:
db.getUsers()
```

### 4.2 Liên kết shared vào release + migrate
```bash
cd /var/www/vremote.vplus.vn
ln -sfn "$PWD/shared/.env"            releases/r1/backend/.env
rm -rf releases/r1/backend/storage && ln -sfn "$PWD/shared/backend-storage" releases/r1/backend/storage

cd releases/r1/backend
php artisan key:generate --force        # nếu APP_KEY còn trống
php artisan optimize:clear
php artisan migrate --force             # tạo index MongoDB (tolerant nếu chưa có migration)
```

> **Không** bật `config:cache` ở giai đoạn này — còn vài chỗ đọc `env()` ở request-path
> (khóa VPlus, TURN secret) cần chuyển vào config trước (DSG-VREMOTE-002 §4).

---

## 5. Quyền cho php-fpm

php-fpm (`www-data`) cần đọc mã nguồn và ghi `storage/`, `bootstrap/cache/`. Chọn 1:

**Cách A (khuyến nghị)** — tạo php-fpm pool chạy bằng `vdeploy`, khỏi lệch quyền:
`/etc/php/8.3/fpm/pool.d/vremote.conf` với `user = vdeploy`, `group = vdeploy`,
`listen = /run/php/php8.3-fpm-vremote.sock` (rồi trỏ nginx `/api` tới socket này).

**Cách B** — giữ `www-data`, mở quyền group:
```bash
sudo chown -R vdeploy:www-data /var/www/vremote.vplus.vn/releases/r1/backend
sudo chmod -R g+rwX /var/www/vremote.vplus.vn/shared/backend-storage
```

---

## 6. Build console (React/Vite)

```bash
cd /var/www/vremote.vplus.vn/releases/r1/console
cp .env.example .env       # chỉnh VITE_AGENT_DOWNLOAD_URL nếu cần (mặc định /downloads/vremote-agent.apk)
npm ci
npm run build              # ra console/dist  (nginx phục vụ tĩnh ở gốc domain)
```
> `VITE_*` được biên dịch vào bundle tĩnh — **không đặt secret** ở đây. Production gọi cùng-origin `/api`.

---

## 7. Build & chạy signaling (Node)

```bash
cd /var/www/vremote.vplus.vn/releases/r1/signaling
npm ci
npm run build              # ra signaling/dist

cp .env.example /var/www/vremote.vplus.vn/shared/signaling.env
$EDITOR /var/www/vremote.vplus.vn/shared/signaling.env
```
`shared/signaling.env` phải **trùng secret** với backend:
- `VREMOTE_SESSION_SECRET` = `VREMOTE_SESSION_SECRET` của backend
- `VREMOTE_SIGNALING_INTERNAL_SECRET` = của backend
- `PORT=8787`, `INTERNAL_PORT=8788` (chỉ loopback)

Cài systemd unit:
```bash
sudo cp /var/www/vremote.vplus.vn/releases/r1/deploy/systemd/vremote-signaling.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now vremote-signaling
sudo systemctl status vremote-signaling
```

---

## 8. nginx + TLS

```bash
sudo cp /var/www/vremote.vplus.vn/releases/r1/deploy/nginx/vremote.vplus.vn.conf /etc/nginx/sites-available/
sudo ln -sfn /etc/nginx/sites-available/vremote.vplus.vn.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

# TLS Let's Encrypt
sudo certbot --nginx -d vremote.vplus.vn
```
> Đảm bảo DNS `A`/`AAAA` của `vremote.vplus.vn` trỏ về server; mở `443` và TURN `3478`.
> Nếu dùng php-fpm pool riêng (Cách A §5), sửa `fastcgi_pass` trong conf trỏ đúng socket.

---

## 9. Đảo `current` & kiểm tra

```bash
cd /var/www/vremote.vplus.vn
ln -sfn "$PWD/releases/r1" current
sudo systemctl reload php8.3-fpm nginx

# Smoke test
curl -fsS https://vremote.vplus.vn/api/vremote/health          # {"status":"ok","service":"vremote-backend"}
curl -fsS -o /dev/null -w '%{http_code}\n' https://vremote.vplus.vn/   # 200 (console)
```
- Đặt APK đã ký tại `shared/downloads/vremote-agent.apk` (agent tải qua `/downloads/`).
- Agent Android kết nối WSS: `wss://vremote.vplus.vn/agent`.

---

## 10. Phát hành bản mới & rollback

**Bản mới:** clone/`rsync` vào `releases/<mới>`, lặp §3–§7, rồi đảo `current`:
```bash
ln -sfn /var/www/vremote.vplus.vn/releases/<mới> /var/www/vremote.vplus.vn/current
sudo systemctl restart vremote-signaling && sudo systemctl reload php8.3-fpm nginx
```
**Rollback:** trỏ `current` về release trước và reload (câu lệnh như trên với release cũ).

---

## 11. Sự cố thường gặp

| Triệu chứng | Nguyên nhân | Xử lý |
|---|---|---|
| `Password authentication is not supported` khi clone | Dùng URL `https://` + mật khẩu | Clone bằng `git@github.com:` (SSH) — §1.4/§2 |
| `zipball ... 404 Not Found` khi `composer install` | Composer tải core-lib qua GitHub API (cần token) | `insteadOf` SSH + `preferred-install source`/`no-api` — §3.1 |
| `lock file does not contain a compatible set of packages` | Lock resolve trên PHP 8.4 | `rm composer.lock && composer update` trên PHP 8.3 — §3.2 |
| `symfony/... requires php >=8.4.1` | Như trên | Như trên (resolve lại trên 8.3) |
| 500 sau deploy, log `permission denied` `storage/` | php-fpm không ghi được storage | Quyền php-fpm — §5 |
| `/api/vremote/health` 502 | php-fpm/pool sai socket | Đối chiếu `fastcgi_pass` với pool — §5/§8 |
| Console mở nhưng gọi API lỗi CORS | Console không cùng-origin | Production phục vụ console ở gốc domain, gọi `/api` cùng-origin — không set VITE proxy |

---

## Bảo mật

- **Không bao giờ** commit `.env`, mật khẩu DB, device token, TURN secret, hay SSH private key.
- Mọi secret chỉ sống trong `shared/.env` và `shared/signaling.env` trên server.
- `VREMOTE_DEV_AUTH` phải `false` ở production (chỉ dùng bypass khi `local`/`testing`).
- Sau khi cấu hình xong, **đổi (rotate)** mọi secret từng lộ ra ngoài server (log, chat, lịch sử shell).

Tham chiếu: `deploy/DEPLOY.md` (bản CI/CD), `docs/USAGE.md` (sử dụng),
`docs/design/DSG-VREMOTE-002-platform-standardization.md` (thiết kế chuẩn hóa).
