-- Migration 070: Import Bellair tenants from "Bellair Tenant Only List v2- Cleaned.csv"
-- Source: Bellair Tenant Only List v2- Cleaned.csv, 2026-05-18
-- Association: Bellair Condominiums (association_id = 1)
-- All inserted with role='renter', is_owner=0, status='active', password='!' (locked until reset)
--
-- Name cleanup:
--   "Taseu Oct 31"          → last_name='Taseu'        (Oct 31 is a lease-end note, not a name)
--   "Tammy Paine  back ground" → last_name='Paine'     (background check note stripped)
--   "Zanolini Mrs"          → last_name='Zanolini'     (title stripped)
--   "La casse"              → last_name='Lacasse'
--   "Mc Donald"             → last_name='McDonald'
--   "Campbell, Meyer" / "Kevin Sandra" → couple row
--
-- Email conflicts / resolutions:
--   kallyp61@gmail.com already belongs to owner Natalie DeLuca (unit 119, id=28).
--     Unit 119 tenants given placeholder r119x1@noemail.bellair.com.
--   Kevmcamp42@gmail.com appears for both unit 108 AND unit 704 (same people, moved).
--     Unit 704 (current) gets the real email; unit 108 gets placeholder.
--   Kortbanks@yahoo.com appears for unit 214 (Mikel Scott) AND unit 521 (Kori Banks).
--     "Kortbanks" = Kori Banks — unit 521 gets the real email; unit 214 gets placeholder.

INSERT INTO users
  (association_id, first_name, last_name, email, password_hash, role, unit_number, phone, is_owner, status)
VALUES
  -- 105
  (1,'Daniel','Marshall',               'r105x1@noemail.bellair.com','!','renter','105','(317) 509-6107',0,'active'),
  -- 107
  (1,'Mark & Jamie','Bussey',           'r107x1@noemail.bellair.com','!','renter','107','(386) 299-1050',0,'active'),
  -- 108 (Kevin & Sandra moved to 704 — real email on that row)
  (1,'Kevin & Sandra','Campbell / Meyer','r108x1@noemail.bellair.com','!','renter','108','(262) 266-0117',0,'active'),
  -- 110
  (1,'Carrie','Zoeliner',               'r110x1@noemail.bellair.com','!','renter','110','(386) 265-3265',0,'active'),
  -- 115
  (1,'Sandra','Karry',                  'r115x1@noemail.bellair.com','!','renter','115','(386) 675-9583',0,'active'),
  -- 116
  (1,'Shane','McDonald',                'r116x1@noemail.bellair.com','!','renter','116','(386) 212-7704',0,'active'),
  -- 119 (kallyp61@gmail.com already on owner id=28 Natalie DeLuca)
  (1,'Dennis & Dena','Muldrow / Day / Plakaris','r119x1@noemail.bellair.com','!','renter','119',NULL,0,'active'),
  -- 201
  (1,'Bill & Tina','Flowers',           'tina52167@aol.com',          '!','renter','201','(678) 234-9704',0,'active'),
  -- 205
  (1,'Letesha','Henry',                 'Henryletesha4@gmail.com',    '!','renter','205','(838) 267-9315',0,'active'),
  -- 207
  (1,'Angela','D''Ambrosia',            'r207x1@noemail.bellair.com', '!','renter','207','(386) 214-5888',0,'active'),
  -- 211 (existing 3 renter rows kept; these are additions from the tenant list)
  (1,'Doug','',                         'r211x1@noemail.bellair.com', '!','renter','211','(386) 290-2427',0,'active'),
  (1,'Lee','Taseu',                     'r211x2@noemail.bellair.com', '!','renter','211','(531) 333-6244',0,'active'),
  -- 214 (Kortbanks belongs to Kori Banks unit 521 — Mikel gets placeholder)
  (1,'Mikel P.','Scott',                'r214x1@noemail.bellair.com', '!','renter','214','(386) 346-5672',0,'active'),
  (1,'Michael','Barr',                  'r214x2@noemail.bellair.com', '!','renter','214','(386) 689-1663',0,'active'),
  (1,'Derek','Maiden',                  'r214x3@noemail.bellair.com', '!','renter','214','(386) 846-2600',0,'active'),
  -- 217
  (1,'Angel','Peterson',                'r217x1@noemail.bellair.com', '!','renter','217','(386) 882-6888',0,'active'),
  -- 220
  (1,'William','Ingersoll',             'r220x1@noemail.bellair.com', '!','renter','220','(386) 293-5410',0,'active'),
  -- 303
  (1,'Diana','Zanolini',                'r303x1@noemail.bellair.com', '!','renter','303','(561) 716-6077',0,'active'),
  -- 314
  (1,'Kathleen Ann','Ingaharro',        'r314x1@noemail.bellair.com', '!','renter','314','(978) 335-2685',0,'active'),
  -- 321
  (1,'Chris','Pereira',                 'r321x1@noemail.bellair.com', '!','renter','321','(386) 316-9449',0,'active'),
  (1,'Karen','Foster',                  'r321x2@noemail.bellair.com', '!','renter','321','(203) 491-6887',0,'active'),
  (1,'Jerry & Lana','Zvarich',          'r321x3@noemail.bellair.com', '!','renter','321','(386) 569-3633',0,'active'),
  -- 409
  (1,'Winston','Fuentes',               'r409x1@noemail.bellair.com', '!','renter','409','(321) 274-6164',0,'active'),
  -- 411
  (1,'Jeffery','Hunter',                'r411x1@noemail.bellair.com', '!','renter','411','(323) 327-5575',0,'active'),
  -- 420
  (1,'Casey','Wells',                   'r420x1@noemail.bellair.com', '!','renter','420','(352) 699-4374',0,'active'),
  (1,'Mohamed & wife','Hussein',        'r420x2@noemail.bellair.com', '!','renter','420','(321) 444-8716',0,'active'),
  -- 503
  (1,'Luke','Lacasse',                  'r503x1@noemail.bellair.com', '!','renter','503','(603) 833-6348',0,'active'),
  -- 504
  (1,'Tammy','Paine',                   'r504x1@noemail.bellair.com', '!','renter','504','(919) 624-2468',0,'active'),
  -- 511
  (1,'John','John',                     'r511x1@noemail.bellair.com', '!','renter','511','386-212-3837',  0,'active'),
  -- 519
  (1,'Kristie','Whetstein',             'r519x1@noemail.bellair.com', '!','renter','519','(386) 405-0623',0,'active'),
  -- 521
  (1,'Kori','Banks',                    'Kortbanks@yahoo.com',        '!','renter','521','(386) 346-5672',0,'active'),
  -- 610
  (1,'Meaghan','Gulliksen',             'meaghangulliksen@gmail.com', '!','renter','610','(386) 341-3317',0,'active'),
  -- 614
  (1,'Michaela','Hammer',               'r614x1@noemail.bellair.com', '!','renter','614','(772) 882-5010',0,'active'),
  (1,'Mary Lynn','Schneller',           'r614x2@noemail.bellair.com', '!','renter','614','(386) 679-2140',0,'active'),
  -- 704 (Kevin & Sandra now here — real email)
  (1,'Kevin & Sandra','Campbell / Meyer','Kevmcamp42@gmail.com',      '!','renter','704','(262) 266-0117',0,'active'),
  (1,'Bruce','Fields',                  'r704x1@noemail.bellair.com', '!','renter','704','(336) 553-8128',0,'active'),
  -- 705
  (1,'William & Lisa','Applegarth',     'lisaapplegarth@yahoo.com',   '!','renter','705','(330) 414-6470',0,'active');
