# RouterOS v7 Startup Configuration for Carrier RADIUS AAA Lab
/interface ethernet set [ find default-name=ether1 ] name=ether1-access
/interface ethernet set [ find default-name=ether2 ] name=ether2-radius

/ip address add address=172.20.20.10/24 interface=ether2-radius

# Configure FreeRADIUS Client
/radius add service=ppp,hotspot address=172.20.20.20 secret=secure_radius_secret_key_2026 timeout=300ms authentication-port=1812 accounting-port=1813

# Enable RFC 3576 / RFC 5176 Change of Authorization (CoA) & Packet of Disconnect (PoD)
/radius incoming set accept=yes port=3799

# Configure PPPoE Server with RADIUS Authentication & Accounting
/ppp profile add name=profile-carrier-radius use-encryption=yes only-one=yes
/interface pppoe-server server add service-name=pppoe-carrier interface=ether1-access default-profile=profile-carrier-radius one-session-per-host=yes disabled=no
/ppp aaa set use-radius=yes accounting=yes interim-update=300s
