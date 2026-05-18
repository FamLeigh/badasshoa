-- Migration 069: Import Bellair rental agents from "Bellair Agent by Unit v2.csv"
-- Source: Bellair Agent by Unit v2.csv, 2026-05-18
-- Association: Bellair Condominiums (association_id = 1)
-- All entries treated as rental_agent kind (they all manage tenant relationships)
--
-- Skipped / noted:
--   "Karen (self/owner contact)" unit 321 — no contact info, owner self-managing
--   Tom McPherson unit 704 — no contact info on that row; DB owner (Tresa Mila) doesn't
--     match CSV owner note (Habib) — needs manual review before linking

-- ─── AGENT CONTACTS ────────────────────────────────────────────────────────────

INSERT INTO association_contacts (association_id, kind, label, phone, email, notes) VALUES
  (1, 'rental_agent', 'Billie Snyder',                  '407-448-7847', NULL,                          NULL),
  (1, 'rental_agent', 'Brinkerhoff Property Mgmt',      '386-258-3802', 'eric@brinkerhoff.info',        NULL),
  (1, 'rental_agent', 'Croce',                          '386-212-0120', NULL,                          'Also: Alfred 856-649-5223'),
  (1, 'rental_agent', 'Jay (Bric Realty)',               '407-810-3991', 'jay@bricrealty.com',           NULL),
  (1, 'rental_agent', 'Laura Morrow',                   '386-290-8864', 'isellthebeachside@gmail.com',  NULL),
  (1, 'rental_agent', 'Patricia Taylor',                '386-405-5867', 'patricia@rentmevf.com',        NULL),
  (1, 'rental_agent', 'Tom McPherson',                  '812-756-8861', 'tommcpherson2001@hotmail.com', NULL),
  (1, 'rental_agent', 'Tom Ventura (Cassandra Realty)', '386-682-1970', NULL,                          'Owner/manager — units 115 and 303');

-- ─── LINK AGENTS TO UNITS ──────────────────────────────────────────────────────
-- Using label subqueries so the script is safe to inspect / re-examine without
-- hard-coding IDs that could drift between environments.

-- Billie Snyder → 105, 119, 211, 420
UPDATE units
   SET rental_agent_contact_id = (SELECT id FROM association_contacts
                                   WHERE association_id = 1 AND label = 'Billie Snyder'
                                     AND kind = 'rental_agent' LIMIT 1)
 WHERE association_id = 1 AND unit_number IN ('105','119','211','420');

-- Brinkerhoff Property Mgmt → 214, 521
UPDATE units
   SET rental_agent_contact_id = (SELECT id FROM association_contacts
                                   WHERE association_id = 1 AND label = 'Brinkerhoff Property Mgmt'
                                     AND kind = 'rental_agent' LIMIT 1)
 WHERE association_id = 1 AND unit_number IN ('214','521');

-- Croce → 614
-- NOTE: CSV lists owner as "Vincent" but DB has Cocoros for unit 614 — verify
UPDATE units
   SET rental_agent_contact_id = (SELECT id FROM association_contacts
                                   WHERE association_id = 1 AND label = 'Croce'
                                     AND kind = 'rental_agent' LIMIT 1)
 WHERE association_id = 1 AND unit_number = '614';

-- Jay (Bric Realty) → 610
UPDATE units
   SET rental_agent_contact_id = (SELECT id FROM association_contacts
                                   WHERE association_id = 1 AND label = 'Jay (Bric Realty)'
                                     AND kind = 'rental_agent' LIMIT 1)
 WHERE association_id = 1 AND unit_number = '610';

-- Laura Morrow → 108, 503
-- NOTE: unit 108 CSV note says tenant "moved to 704"
UPDATE units
   SET rental_agent_contact_id = (SELECT id FROM association_contacts
                                   WHERE association_id = 1 AND label = 'Laura Morrow'
                                     AND kind = 'rental_agent' LIMIT 1)
 WHERE association_id = 1 AND unit_number IN ('108','503');

-- Patricia Taylor → 504
UPDATE units
   SET rental_agent_contact_id = (SELECT id FROM association_contacts
                                   WHERE association_id = 1 AND label = 'Patricia Taylor'
                                     AND kind = 'rental_agent' LIMIT 1)
 WHERE association_id = 1 AND unit_number = '504';

-- Tom McPherson → 321
UPDATE units
   SET rental_agent_contact_id = (SELECT id FROM association_contacts
                                   WHERE association_id = 1 AND label = 'Tom McPherson'
                                     AND kind = 'rental_agent' LIMIT 1)
 WHERE association_id = 1 AND unit_number = '321';

-- Tom Ventura (Cassandra Realty) → 115, 303
UPDATE units
   SET rental_agent_contact_id = (SELECT id FROM association_contacts
                                   WHERE association_id = 1 AND label = 'Tom Ventura (Cassandra Realty)'
                                     AND kind = 'rental_agent' LIMIT 1)
 WHERE association_id = 1 AND unit_number IN ('115','303');
