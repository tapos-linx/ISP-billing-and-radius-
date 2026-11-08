# App Flow: ISP Billing System (Carrier Edition)

## 1. Subscriber Onboarding (TR-069)
1. ONT connects to GPON OLT.
2. ONT triggers CWMP Inform to GenieACS.
3. Billing System detects new Serial, assigns to Shard (`crc32(id) % 128`).
4. GenieACS NBI pushes PPPoE credentials and Wi-Fi SSID.

## 2. Authentication Flow (RADIUS Fast-Path)
1. Subscriber attempts PPPoE session.
2. BNG sends Access-Request to FreeRADIUS cluster.
3. FreeRADIUS checks Redis 7 Fast-Path (<0.4ms).
4. If miss, queries Citus shard.
5. Access-Accept sent with Vendor-Specific Attributes (VSAs).

## 3. Billing & Expiry (MFS Webhook)
1. Midnight Cron identifies expired subscribers.
2. Citus updates status to `EXPIRED` + `WALLED_GARDEN`.
3. System sends RFC 3576 CoA/PoD to BNG via UDP 3799.
4. Subscriber pays via bKash (HMAC SHA256 verified).
5. Webhook triggers auto-unblock: Citus update -> CoA -> Restore.

## 4. CGNAT Compliance (BTRC)
1. ClickHouse logs `radacct` with Public IP + Port Range.
2. Admin uses CGNAT Inspector to map external activity to internal subscriber profile using RFC 6888 math.
