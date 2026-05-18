-- Migration 067: Update Bellair member contact info from CSV (2026-05-18)
-- Source: "Bellair Member Contact List - Cleaned.csv"
-- Association: Bellair Condominiums (association_id = 1)
--
-- Rules applied:
--   Units 110/116/214/404  Notes say "Phone: tenant" — phone fields untouched
--   Unit 120               Notes say "Mobile: doctor office" — phone2 untouched
--   Unit 516               Notes say "Mobile: out of service" — phone2 untouched
--   email UNIQUE constraint: Henry Orszulak (units 419 + 621) → email assigned to
--     id=186 (unit 621) only; id=120 (unit 419) left as placeholder intentionally
--   Thomas Ventura (units 115 + 303) → email assigned to id=23 (unit 115) only
--
-- EXCEPTIONS — DB data kept unchanged, discrepancy noted for later review:
--   1. Unit 105 Yueh Chen    id=9   DB: e_g_marshall@yahoo.com  CSV: 8693Chen@gmail.com
--   2. Unit 114 Gordon Benson id=22  DB: gbenson15@cfl.rr.com    CSV: alkov80@gmail.com  (looks like Ilkov data-entry error)
--   3. Unit 204 Edward Sheckler id=35  CSV shows "Edward Henry" / khpbiz@yahoo.com — possible ownership change
--   4. Unit 209 Eric Edelman id=43  DB: ericedelman@gmail.com  CSV: Joaneherrold@gmail.com — name does not match
--   5. Unit 221 Ray Baxter   id=62  CSV shows "Ray & Debbie Ray/St. John" / TDBaxteremail@gmail.com — name mismatch
--   6. Unit 317 Henry Cory   id=87  DB: Henrydcory@gmail.com  CSV: ashkcory@Yahoo.com
--   7. Unit 409 VanEssendelft id=106 CSV shows "Alexis Myles Bron Inc VMU REO" — possible ownership change
--   8. Unit 506 Clint Davis  id=135 DB: Pncdavis@comcast.net  CSV: dcrservices@aol.com

-- ─── EMAIL UPDATES ─────────────────────────────────────────────────────────────
-- placeholder → real email, or correction of misassigned email

UPDATE users SET email = 'kathygrvn@yahoo.com'       WHERE id = 18  AND association_id = 1;  -- Kathleen Garvin, unit 111
UPDATE users SET email = 'thomasventura00@gmail.com' WHERE id = 23  AND association_id = 1;  -- Thomas Ventura, unit 115 (303 skipped: UNIQUE)
UPDATE users SET email = 'kallyp61@gmail.com'        WHERE id = 28  AND association_id = 1;  -- Natalie DeLuca, unit 119
UPDATE users SET email = 'rakyna888@outlook.com'     WHERE id = 29  AND association_id = 1;  -- Steven Chamberlain, unit 120
UPDATE users SET email = 'rdoubina@yahoo.com'        WHERE id = 38  AND association_id = 1;  -- Richard Oubina, unit 205
UPDATE users SET email = 'mommo52@outlook.com'       WHERE id = 48  AND association_id = 1;  -- William Swenson, unit 212
UPDATE users SET email = 'rikkilmoran@gmail.com'     WHERE id = 71  AND association_id = 1;  -- Luz Silvestrini-Perez, unit 306
UPDATE users SET email = 'kimmistry@msn.com'         WHERE id = 73  AND association_id = 1;  -- Persis Mistry, unit 308
UPDATE users SET email = 'patchesquilting@gmail.com' WHERE id = 75  AND association_id = 1;  -- Terry Bingham, unit 309
UPDATE users SET email = 'wayneproie@aol.com'        WHERE id = 79  AND association_id = 1;  -- Wayne Proie, unit 311 (replaces tasmith0126 which was on wrong record)
UPDATE users SET email = 'dtolan72@gmail.com'        WHERE id = 92  AND association_id = 1;  -- David Tolan, unit 320
UPDATE users SET email = 'sashuda2727@yahoo.com'     WHERE id = 95  AND association_id = 1;  -- Stephen Shuda, unit 401 (covers 401/501 combined)
UPDATE users SET email = 'fitnessguybill@aol.com'    WHERE id = 101 AND association_id = 1;  -- William Manning, unit 405
UPDATE users SET email = 'kat.liv2ryd@yahoo.com'     WHERE id = 104 AND association_id = 1;  -- Kathleen Hamilton, unit 407 (corrects kat.livzryd→kat.liv2ryd)
UPDATE users SET email = 'lfrodella@gmail.com'       WHERE id = 105 AND association_id = 1;  -- Lucy Frodella, unit 408
UPDATE users SET email = 'tom-moran@sbcglobal.net'   WHERE id = 118 AND association_id = 1;  -- Thomas Moran, unit 417
UPDATE users SET email = 'deemarie6491@gmail.com'    WHERE id = 127 AND association_id = 1;  -- Roy Stone, unit 502
UPDATE users SET email = 'erwinmilo123@hotmail.com'  WHERE id = 144 AND association_id = 1;  -- Irwin Milo, unit 512
UPDATE users SET email = 'kastnerleigh@gmail.com'    WHERE id = 146 AND association_id = 1;  -- Michele Kastner, unit 514
UPDATE users SET email = '705normandy@gmail.com'     WHERE id = 150 AND association_id = 1;  -- Linda Depalo, unit 517
UPDATE users SET email = 'mrandmrsrmurphy@gmail.com' WHERE id = 151 AND association_id = 1;  -- James Brianas, unit 518
UPDATE users SET email = 'bjasasra@gmail.com'        WHERE id = 164 AND association_id = 1;  -- Basel Jasasra, unit 605
UPDATE users SET email = 'compacharles@gmail.com'    WHERE id = 175 AND association_id = 1;  -- Charles Comparetto, unit 612
UPDATE users SET email = 'leotaflee@gmail.com'       WHERE id = 180 AND association_id = 1;  -- Leota Lee, unit 616
UPDATE users SET email = 'h.orszulak@comcast.net'    WHERE id = 186 AND association_id = 1;  -- Henry Orszulak, unit 621 (unit 419 id=120 intentionally skipped: UNIQUE)
UPDATE users SET email = 'dianehudson6@gmail.com'    WHERE id = 187 AND association_id = 1;  -- Richard Hudson, unit 701
UPDATE users SET email = 'tmila2001@yahoo.com'       WHERE id = 191 AND association_id = 1;  -- Tresa Mila, unit 704
UPDATE users SET email = 'angelikanemeth1@gmail.com' WHERE id = 193 AND association_id = 1;  -- Angelika Nemeth, unit 705
UPDATE users SET email = 'marcocap21@comcast.net'    WHERE id = 194 AND association_id = 1;  -- Carmela Capellupo, unit 706

-- ─── PHONE CORRECTIONS ─────────────────────────────────────────────────────────
-- Clear digit errors from original import

UPDATE users SET phone = '724-594-4480', phone2 = '724-353-3096'
  WHERE id = 18 AND association_id = 1;  -- Garvin: 504→594, 3006→3096

UPDATE users SET phone = '239-938-4320'
  WHERE id = 39 AND association_id = 1;  -- Edgar Miller: area code 230→239

-- ─── PHONE ADDITIONS ───────────────────────────────────────────────────────────
-- DB was NULL; CSV has value

UPDATE users SET phone = '863-521-5459'
  WHERE id = 69 AND association_id = 1;  -- Charlotte Wimberly, unit 304

UPDATE users SET phone = '610-969-5668', phone2 = '484-542-1183'
  WHERE id = 97 AND association_id = 1;  -- Christina Hernandez, unit 403

UPDATE users SET phone = '201-248-7119', phone2 = '201-248-7120'
  WHERE id = 177 AND association_id = 1;  -- Ageliki Cocoros, unit 614
