# TURN (coturn) setup for VRemote — required for cross-internet remote sessions

Remote support works across the internet only with a TURN relay: when the phone (4G/CGNAT)
and the operator's PC can't reach each other directly, media is relayed through coturn.
STUN alone only works on the same/simple network.

Both the **viewer** and the **agent** get credentialed TURN from the server
(session response + `session.enable`), so this one coturn instance serves both ends.

## One-time server setup

```bash
sudo apt-get update && sudo apt-get install -y coturn

# Enable the daemon
echo 'TURNSERVER_ENABLED=1' | sudo tee /etc/default/coturn

# Install the config, then edit the two placeholders:
sudo cp deploy/coturn/turnserver.conf /etc/turnserver.conf
#  - static-auth-secret  -> a strong random secret (keep it; you'll reuse it below)
#  - external-ip         -> the server's PUBLIC IPv4  (curl -s https://api.ipify.org)
sudoedit /etc/turnserver.conf

sudo systemctl enable --now coturn
sudo systemctl restart coturn
sudo ss -lunp | grep 3478   # expect coturn listening on udp/3478
```

Open the firewall / cloud security group:
- **3478/udp and 3478/tcp** (TURN control)
- **49152–65535/udp** (relay range from turnserver.conf)
- (TLS optional) 5349/tcp for `turns:`

## Backend wiring (shared/.env — the single source of truth)

Set these in `/var/www/vremote.vplus.vn/shared/.env` (never commit real secrets):

```
VREMOTE_TURN_URLS=turn:vremote.vplus.vn:3478
# add ,turns:vremote.vplus.vn:5349 once TLS is enabled
VREMOTE_TURN_SHARED_SECRET=<the SAME static-auth-secret you put in /etc/turnserver.conf>
```

Then reload PHP so the new env is read:

```bash
sudo systemctl reload php8.3-fpm
```

The backend `TurnCredentialService` issues time-limited credentials
(`username = "<expiry>:<deviceId>"`, `password = base64(HMAC-SHA1(username, secret))`) that
coturn validates via `use-auth-secret` — so the secret MUST match on both sides.

## Verify

- `turnutils_uclient -v -y -u ignored -w ignored vremote.vplus.vn` (basic reachability), or
- open a session and check the browser's `chrome://webrtc-internals` shows a **relay** candidate pair
  when the two ends are on different networks.
