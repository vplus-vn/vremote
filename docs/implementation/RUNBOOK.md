# VRemote Runbook

## Local

1. `docker compose up --build`
2. Open `http://localhost:5173`.
3. Build/install `agent-android` on the Android box.
4. In the agent enter the device ID/token, signaling URL and TURN shared secret.
5. Approve MediaProjection.
6. Wait for the agent WebSocket to authenticate.
7. Press **View** for the online device.

## Production

- Set `VREMOTE_DEV_AUTH=false`.
- Configure `VPLUS_USER_PUBLIC_KEY` with the Vplus identity issuer public key and keep `VPLUS_JWT_ALG=RS256` unless the approved issuer uses another algorithm.
- Replace JSON device registry with the shared platform registry.
- Set unique high-entropy session, internal signaling and TURN secrets in a secret manager.
- Use HTTPS/WSS and a public TURN hostname.
- Do not commit real device tokens or secrets.
- Keep the Android agent outbound-only.

## Troubleshooting

- `503 Agent channel unavailable`: agent is not authenticated to `/agent`, or the backend cannot reach signaling `:8788`.
- Device is offline: check the registry status and agent authentication.
- No video: check MediaProjection consent, WebRTC/ICE connectivity and TURN credentials.
- `401`: production bearer token did not verify with the configured Vplus public key.
