# ==============================================================================
# MikroTik RouterOS v7 / Virtual CHR Lab Configuration (Zero-Risk Sandbox)
# Configures VLAN 100 PPPoE, Walled Garden Pool, WireGuard, & RADIUS CoA (3799)
# ==============================================================================

/interface vlan
add interface=ether2 name=vlan100-pppoe vlan-id=100

/ip pool
add name=pppoe_active_pool ranges=100.64.0.10-100.64.15.254
add name=walled_garden_pool ranges=10.250.0.10-10.250.0.254

/ppp profile
add local-address=100.64.0.1 name=pppoe-isp-profile use-encryption=no

/interface pppoe-server server
add authentication=pap,chap,mschap2 default-profile=pppoe-isp-profile disabled=no interface=vlan100-pppoe service-name=ISP_FTTH

# RADIUS AAA + CoA/PoD (UDP 3799) pointing to GCE / Docker FreeRADIUS over WireGuard
/radius
add address=10.10.0.10 secret="REPLACE_WITH_WIREGUARD_RADIUS_SECRET" service=ppp timeout=3000ms

/radius incoming
set accept=yes port=3799

/ppp aaa
set accounting=yes interim-update=5m use-radius=yes

# Walled Garden HTTP Redirect for Expired Subscribers
/ip firewall nat
add action=dst-nat chain=dstnat dst-port=80 protocol=tcp src-address=10.250.0.0/24 to-addresses=10.10.0.20 to-ports=8080 comment="Walled Garden Bill-Pay Redirect"
