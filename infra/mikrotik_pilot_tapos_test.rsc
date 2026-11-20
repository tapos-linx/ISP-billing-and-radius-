# ==============================================================================
# MikroTik RouterOS v7 - Isolated Single-Subscriber Pilot Configuration
# Project: Carrier-Scale 1M-10M ISP Billing & FreeRADIUS Automation
# Target: Test MikroTik CHR / Physical Router (Canary / Sandbox Isolation)
# Dedicated Pilot Subscriber: tapos_pilot (20M/20M, Test VLAN 999)
# Reference: docs/STAGE_12_CUTOVER_RUNBOOK.md & infra/mikrotik_chr_lab_setup.rsc
# Strictly isolated: Zero impact on live production traffic or active subscribers.
# ==============================================================================

# 1. Isolated Test VLAN Interface
/interface vlan
add interface=ether2 name=vlan999-pilot vlan-id=999 comment="PILOT_TAPOS_TEST"

# 2. IP Pools for Active and Walled Garden Profiles
/ip pool
add name=pppoe_active_pool ranges=100.64.0.10-100.64.15.254 comment="PILOT_TAPOS_TEST"
add name=walled_garden_pool ranges=10.250.0.10-10.250.0.254 comment="PILOT_TAPOS_TEST"

# 3. Dedicated Sandbox PPP Profile
/ppp profile
add local-address=100.64.0.1 name=pppoe-pilot-profile use-encryption=no comment="PILOT_TAPOS_TEST"

# 4. PPPoE Server Bound Exclusively to Isolated Test VLAN
/interface pppoe-server server
add authentication=pap,chap,mschap2 default-profile=pppoe-pilot-profile disabled=no \
    interface=vlan999-pilot service-name=ISP_PILOT_TAPOS comment="PILOT_TAPOS_TEST"

# 5. RADIUS Client pointing to Ubuntu 24.04 FreeRADIUS (Over WireGuard / Management VIP)
/radius
add address=10.10.0.10 secret="REPLACE_WITH_RADIUS_SECRET" service=ppp timeout=3000ms \
    authentication-port=1812 accounting-port=1813 comment="PILOT_TAPOS_TEST"

# 6. Incoming RFC 5176 CoA / PoD Disconnect (UDP 3799)
/radius incoming
set accept=yes port=3799

# 7. AAA Accounting with 5-Minute Interim Updates (BTRC 2-Year Retention Stream)
/ppp aaa
set accounting=yes interim-update=5m use-radius=yes

# 8. Walled Garden Bill-Pay Captive Portal Redirect (Port 80/tcp -> Self-Care Portal)
/ip firewall nat
add action=dst-nat chain=dstnat dst-port=80 protocol=tcp src-address=10.250.0.0/24 \
    to-addresses=10.10.0.20 to-ports=8080 comment="PILOT_TAPOS_TEST: Walled Garden Bill-Pay Redirect"

# ==============================================================================
# Complete Teardown & Rollback Commands (Safe One-Liner Cleanup):
# ------------------------------------------------------------------------------
# /interface pppoe-server server remove [find comment~"PILOT_TAPOS_TEST"]
# /ppp profile remove [find comment~"PILOT_TAPOS_TEST"]
# /ip pool remove [find comment~"PILOT_TAPOS_TEST"]
# /interface vlan remove [find comment~"PILOT_TAPOS_TEST"]
# /radius remove [find comment~"PILOT_TAPOS_TEST"]
# /ip firewall nat remove [find comment~"PILOT_TAPOS_TEST"]
# ==============================================================================
