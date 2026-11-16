-- ====================================================================
-- Seed Script: Test NAS and PPPoE Subscriber (tapos_test)
-- Project: ISP Billing Software (by Tapos)
-- ====================================================================

-- 1. Register Test NAS Devices in `nas` table
-- 127.0.0.1 for local radtest verification bench
INSERT INTO nas (nasname, shortname, type, ports, secret, description)
VALUES ('127.0.0.1', 'localhost_bench', 'other', 1812, 'testing123', 'Local Verification Testbench')
ON CONFLICT (nasname) DO UPDATE SET secret = EXCLUDED.secret;

-- Localhost IPV6 & Host loopback
INSERT INTO nas (nasname, shortname, type, ports, secret, description)
VALUES ('localhost', 'localhost_named', 'other', 1812, 'testing123', 'Local named host')
ON CONFLICT (nasname) DO UPDATE SET secret = EXCLUDED.secret;

-- Mikrotik CCR2004 Gateway NAS (Production Stage 2)
INSERT INTO nas (nasname, shortname, type, ports, secret, description)
VALUES ('10.10.10.1', 'mikrotik_ccr2004', 'mikrotik', 3799, 'RadiusSecretKey2026', 'Mikrotik CCR2004 Production NAS')
ON CONFLICT (nasname) DO UPDATE SET secret = EXCLUDED.secret;

-- Docker Internal Subnet NAS
INSERT INTO nas (nasname, shortname, type, ports, secret, description)
VALUES ('172.20.0.0/16', 'docker_subnet', 'other', 1812, 'testing123', 'Docker Internal Bridge Network')
ON CONFLICT (nasname) DO UPDATE SET secret = EXCLUDED.secret;


-- 2. Seed PPPoE Test User: `tapos_test`
-- Authentication in `radcheck` (Cleartext-Password)
DELETE FROM radcheck WHERE username = 'tapos_test';
INSERT INTO radcheck (username, attribute, op, value)
VALUES ('tapos_test', 'Cleartext-Password', ':=', 'tapos_pass_2026');

-- Authorization in `radreply` (Mikrotik-Rate-Limit := 20M/20M)
DELETE FROM radreply WHERE username = 'tapos_test';
INSERT INTO radreply (username, attribute, op, value)
VALUES 
    ('tapos_test', 'Mikrotik-Rate-Limit', ':=', '20M/20M'),
    ('tapos_test', 'Framed-IP-Address', ':=', '10.100.0.50'),
    ('tapos_test', 'Framed-Pool', ':=', 'pool-subscribers'),
    ('tapos_test', 'Service-Type', ':=', 'Framed-User'),
    ('tapos_test', 'Framed-Protocol', ':=', 'PPP');


-- 3. Verify Inserted Records
SELECT 'NAS Registration Verified:' AS status, nasname, secret FROM nas WHERE nasname = '127.0.0.1';
SELECT 'radcheck Credentials Verified:' AS status, username, attribute, value FROM radcheck WHERE username = 'tapos_test';
SELECT 'radreply Attributes Verified:' AS status, username, attribute, value FROM radreply WHERE username = 'tapos_test';
