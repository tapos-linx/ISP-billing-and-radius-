# ==============================================================================
# MikroTik RouterOS v7 - Single-Subscriber Isolated Pilot Configuration
# Target: Test MikroTik CHR / Physical Router (Canary / Sandbox Isolation)
# Dedicated to pilot subscriber: tapos_pilot on isolated test VLAN 999
# Safe: Completely isolated from production traffic and live subscribers.
# ==============================================================================

/interface vlan
add interface=ether2 name=vlan999-pilot vlan-id=999 comment="PILOT_TEST_ONLY"

/ip pool
add name=pppoe_active_pool ranges=100.64.0.10-100.64.15.254 comment="PILOT_TEST_ONLY"
add name=walled_garden_pool ranges=10.250.0.10-10.250.0.254 comment="PILOT_TEST_ONLY"

/ppp profile
add local-address=100.64.0.1 name=pppoe-pilot-profile use-encryption=no comment="PILOT_TEST_ONLY"

/interface pppoe-server server
add authentication=pap,chap,mschap2 default-profile=pppoe-pilot-profile disabled=no \
    interface=vlan999-pilot service-name=ISP_PILOT_TEST comment="PILOT_TEST_ONLY"

# Enable RADIUS AAA with 3000ms timeout pointing to FreeRADIUS (Update address & secret)
/radius
add address=10.10.0.10 secret="REPLACE_WITH_RADIUS_SECRET" service=ppp timeout=3000ms \
    authentication-port=1812 accounting-port=1813 comment="PILOT_TEST_ONLY"

# Enable RFC 5176 Change of Authorization (CoA) & Disconnect-Request (PoD)
/radius incoming
set accept=yes port=3799

# Configure PPP AAA with 5-minute Interim Accounting
/ppp aaa
set accounting=yes interim-update=5m use-radius=yes

# Walled Garden Captive Portal NAT Redirect (Port 80/tcp -> Self-Care Portal)
/ip firewall nat
add action=dst-nat chain=dstnat dst-port=80 protocol=tcp src-address=10.250.0.0/24 \
    to-addresses=10.10.0.20 to-ports=8080 comment="PILOT_TEST_ONLY: Walled Garden Bill-Pay Redirect"

# --- Teardown / Rollback Command (One-Liner): ---
# /interface pppoe-server server remove [find comment~"PILOT_TEST_ONLY"]
# /ppp profile remove [find comment~"PILOT_TEST_ONLY"]
# /ip pool remove [find comment~"PILOT_TEST_ONLY"]
# /interface vlan remove [find comment~"PILOT_TEST_ONLY"]
# /radius remove [find comment~"PILOT_TEST_ONLY"]
# /ip firewall nat remove [find comment~"PILOT_TEST_ONLY"]
