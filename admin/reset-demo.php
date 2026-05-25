<?php
declare(strict_types=1);

// Reset a designated demo association by cloning all data from a source
// association (typically Bellair) into it, with owner / renter / board PII
// randomized. Super-admin only. Destructive — wipes the target's existing
// rows in every association-scoped table.
//
// Usage: /admin/reset-demo.php?source=1&target=2
//
// Add new demo / sandbox IDs to ALLOWED_TARGET_IDS to enable resetting them.
//
// Strategy: auto-discover every table with an `association_id` column, then
// clone it from source to target. FK columns are rewritten through an
// old_id → new_id map per parent table, driven by the global $FK_RULES
// dictionary at the top of the file. New prod tables get picked up
// automatically as long as their FK columns follow the project's naming
// conventions (user_id, unit_id, meeting_id, etc.).

require __DIR__ . '/_bootstrap.php';

const ALLOWED_TARGET_IDS = [2];

/* ---------------------------------------------------------------------------
 * FK column → parent table. Any column matching one of these keys in any
 * cloned row gets remapped via $idMap[parent][oldId] during INSERT. If the
 * old id isn't in the map (parent wasn't cloned, or original row was already
 * deleted), the column is set to NULL.
 * ------------------------------------------------------------------------- */

$FK_RULES = [
    // → users
    'user_id'                  => 'users',
    'author_id'                => 'users',
    'uploaded_by'              => 'users',
    'signer_user_id'           => 'users',
    'voter_user_id'            => 'users',
    'entered_by_user_id'       => 'users',
    'proposed_by_user_id'      => 'users',
    'moved_by_user_id'         => 'users',
    'seconded_by_user_id'      => 'users',
    'proof_signed_by_user_id'  => 'users',
    'assigned_to_user_id'      => 'users',
    'submitted_by_user_id'     => 'users',
    'reviewed_by_user_id'      => 'users',
    'created_by'               => 'users',
    'created_by_user_id'       => 'users',
    'resolved_by_user_id'      => 'users',
    'noted_by_user_id'         => 'users',
    'target_user_id'           => 'users',

    // → units
    'unit_id'                  => 'units',
    'target_unit_id'           => 'units',

    // → board_meetings
    'meeting_id'               => 'board_meetings',

    // → agenda_items
    'agenda_item_id'           => 'agenda_items',

    // → resolutions
    'resolution_id'            => 'resolutions',

    // → documents
    'document_id'              => 'documents',

    // → committees
    'committee_id'             => 'committees',

    // → amenities
    'amenity_id'               => 'amenities',

    // → events
    'event_id'                 => 'events',

    // → property_listings
    'listing_id'               => 'property_listings',

    // → association_attractions
    'attraction_id'            => 'association_attractions',

    // → document_signatures
    'fulfilled_sig_id'         => 'document_signatures',
    'signature_id'             => 'document_signatures',

    // → rules
    'rule_id'                  => 'rules',
    'target_rule_id'           => 'rules',

    // → announcements
    'announcement_id'          => 'announcements',

    // → arc_requests
    'arc_request_id'           => 'arc_requests',

    // → work_orders
    'work_order_id'            => 'work_orders',

    // → violations
    'violation_id'             => 'violations',

    // → employees
    'employee_id'              => 'employees',

    // → insurance_policies
    'policy_id'                => 'insurance_policies',

    // → meeting_minutes
    'minutes_id'               => 'meeting_minutes',
    'minute_id'                => 'meeting_minutes',

    // → vote_questions
    'question_id'              => 'vote_questions',

    // → concerns
    'concern_id'               => 'concerns',

    // → user_signatures
    'user_signature_id'        => 'user_signatures',

    // → parking_spots
    'spot_id'                  => 'parking_spots',
    'parking_spot_id'          => 'parking_spots',

    // → marketplace_listings
    'marketplace_listing_id'   => 'marketplace_listings',

    // → association_contacts
    'contact_id'               => 'association_contacts',

    // → newsletter_subscribers
    'subscriber_id'            => 'newsletter_subscribers',

    // → property_listings.listing_photos (child)
    'listing_photo_id'         => 'listing_photos',

    // → broadcasts (wipe-only, but in case some child table references it)
    'broadcast_id'             => 'broadcasts',
];

/* ---------------------------------------------------------------------------
 * Topological clone order. Parents listed before children. Any table with
 * `association_id` that isn't in this list is appended at the end after
 * auto-discovery (and warned about in the confirm screen). Wipe runs in
 * reverse order so children are removed before parents.
 * ------------------------------------------------------------------------- */

$KNOWN_ORDER = [
    'rule_categories',
    'rules',
    'faqs',
    'units',
    'users',
    'user_signatures',
    'amenities',
    'committees',
    'committee_members',
    'employees',
    'insurance_policies',
    'parking_spots',
    'association_contacts',
    'documents',
    'media',
    'events',
    'announcements',
    'association_attractions',
    'property_listings',
    'listing_photos',
    'marketplace_listings',
    'newsletter_subscribers',
    'board_notes',
    'board_meetings',
    'agenda_items',
    'meeting_minutes',
    'resolutions',
    'vote_questions',
    'vote_responses',
    'resolution_votes',
    'document_signatures',
    'document_signature_requests',
    'amenity_bookings',
    'arc_requests',
    'work_orders',
    'violations',
    'concerns',
    'unit_occupants',
    'unit_media',
];

// Tables wiped from the target but not cloned from source. Keep the demo
// clean of operational noise + avoid leaking PII via broadcast bodies, audit
// metadata, login attempt logs, etc.
$WIPE_ONLY = [
    'audit_log',
    'platform_messages',
    'broadcasts',
    'broadcast_attachments',
    'login_attempts',
];

// Per-table file-path rewrites (columns whose string value contains
// "uploads/<source>/" → "uploads/<target>/").
$PATH_REWRITES = [
    'documents'           => ['file_path'],
    'media'               => ['file_path'],
    'unit_media'          => ['file_path'],
    'document_signatures' => ['signed_file_path', 'signature_image_path'],
    'user_signatures'     => ['signature_path', 'image_path'],
    'broadcast_attachments' => ['file_path'],
];

// Per-table PII scrub. The callback receives the in-progress row (after FK
// remap) and returns the mutated row.
$PII_SCRUB = [
    'users' => function (array $row, string $demoPassword): array {
        $row['first_name']    = demo_first_name();
        $row['last_name']     = demo_last_name();
        $row['email']         = 'demo+' . bin2hex(random_bytes(4)) . '@badasshoa.com';
        $row['phone']         = demo_phone();
        $row['password_hash'] = password_hash($demoPassword, PASSWORD_BCRYPT, ['cost' => 10]);
        foreach (['invite_token', 'invite_sent_at', 'invite_expires_at', 'last_login_at'] as $k) {
            if (array_key_exists($k, $row)) $row[$k] = null;
        }
        return $row;
    },
    'property_listings' => function (array $row): array {
        if (array_key_exists('contact_name',  $row)) $row['contact_name']  = demo_first_name() . ' ' . demo_last_name();
        if (array_key_exists('contact_email', $row)) $row['contact_email'] = 'listing+' . bin2hex(random_bytes(4)) . '@badasshoa.com';
        if (array_key_exists('contact_phone', $row)) $row['contact_phone'] = demo_phone();
        return $row;
    },
    'marketplace_listings' => function (array $row): array {
        if (array_key_exists('contact_email', $row)) $row['contact_email'] = 'market+' . bin2hex(random_bytes(4)) . '@badasshoa.com';
        if (array_key_exists('contact_phone', $row)) $row['contact_phone'] = demo_phone();
        return $row;
    },
    'association_contacts' => function (array $row): array {
        if (array_key_exists('name',  $row)) $row['name']  = demo_first_name() . ' ' . demo_last_name();
        if (array_key_exists('email', $row)) $row['email'] = 'contact+' . bin2hex(random_bytes(4)) . '@badasshoa.com';
        if (array_key_exists('phone', $row)) $row['phone'] = demo_phone();
        return $row;
    },
    'employees' => function (array $row): array {
        if (array_key_exists('first_name', $row)) $row['first_name'] = demo_first_name();
        if (array_key_exists('last_name',  $row)) $row['last_name']  = demo_last_name();
        if (array_key_exists('email',      $row)) $row['email']      = 'staff+' . bin2hex(random_bytes(4)) . '@badasshoa.com';
        if (array_key_exists('phone',      $row)) $row['phone']      = demo_phone();
        // Leave pay_rate / salary — they're "fake demo data" too, and the
        // surrounding UI already gates them by role.
        return $row;
    },
    'newsletter_subscribers' => function (array $row): array {
        if (array_key_exists('email', $row)) $row['email'] = 'subscriber+' . bin2hex(random_bytes(4)) . '@badasshoa.com';
        return $row;
    },
    'document_signatures' => function (array $row): array {
        // The signed PDF physical files still have the original signer name
        // baked in. Acceptable for a demo. Strip the verification metadata.
        foreach (['signer_ip', 'signer_agent', 'sign_token'] as $k) {
            if (array_key_exists($k, $row)) $row[$k] = null;
        }
        return $row;
    },
];

/* ---------------------------------------------------------------------------
 * Setup
 * ------------------------------------------------------------------------- */

$sourceId = (int)($_GET['source'] ?? $_POST['source'] ?? 1);
$targetId = (int)($_GET['target'] ?? $_POST['target'] ?? 2);

if ($sourceId === $targetId) {
    http_response_code(400);
    die('Source and target must differ.');
}
if (!in_array($targetId, ALLOWED_TARGET_IDS, true)) {
    http_response_code(400);
    die('Target #' . (int)$targetId . ' is not in the demo allowlist (see ALLOWED_TARGET_IDS in admin/reset-demo.php).');
}

$source = db()->prepare('SELECT * FROM associations WHERE id = ?');
$source->execute([$sourceId]);
$source = $source->fetch();

$target = db()->prepare('SELECT * FROM associations WHERE id = ?');
$target->execute([$targetId]);
$target = $target->fetch();

if (!$source || !$target) {
    http_response_code(404);
    die('Source or target association not found.');
}

/* ---------------------------------------------------------------------------
 * Schema discovery
 * ------------------------------------------------------------------------- */

function table_exists(string $name): bool
{
    static $cache = null;
    if ($cache === null) {
        $rows = db()->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")
            ->fetchAll(PDO::FETCH_COLUMN);
        $cache = array_flip($rows);
    }
    return isset($cache[$name]);
}

function table_columns(string $name): array
{
    static $cache = [];
    if (!isset($cache[$name])) {
        $stmt = db()->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        $stmt->execute([$name]);
        $cache[$name] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    return $cache[$name];
}

function has_assoc_col(string $t): bool
{
    return table_exists($t) && in_array('association_id', table_columns($t), true);
}

/**
 * Returns the resolved clone order: every $KNOWN_ORDER table that exists,
 * followed by any auto-discovered association-scoped tables we forgot.
 */
function resolve_clone_order(array $known): array
{
    $present = [];
    foreach ($known as $t) {
        if (has_assoc_col($t) && !in_array($t, $present, true)) {
            $present[] = $t;
        }
    }
    // Append any auto-discovered tables with association_id that aren't in
    // the known list. We don't know their dependency order, so we tack them
    // on at the end — clones will still succeed because FK columns get
    // remapped via $FK_RULES (or NULLed if their parent wasn't cloned).
    $auto = db()->query(
        "SELECT TABLE_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'association_id'"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($auto as $t) {
        if (!in_array($t, $present, true)) {
            $present[] = $t;
        }
    }
    return $present;
}

/* ---------------------------------------------------------------------------
 * Random name generators
 * ------------------------------------------------------------------------- */

function demo_first_name(): string
{
    static $pool = [
        'Alex','Jordan','Taylor','Morgan','Casey','Riley','Avery','Quinn','Reese','Skyler',
        'Sage','Rowan','Hayden','Cameron','Drew','Finley','Harper','Logan','Mason','Parker',
        'Ainsley','Beckett','Brody','Camille','Dante','Elena','Fernando','Greta','Hugo','Ivy',
        'Jasmine','Keiko','Leon','Maya','Nico','Octavia','Priya','Quincy','Rafael','Saskia',
        'Theo','Una','Vera','Wilder','Xander','Yara','Zane','Bianca','Cyrus','Delphine',
        'Emil','Freya','Gus','Hana','Idris','Juno','Kira','Lyle','Marin','Nadia',
        'Owen','Phoebe','Rhett','Soren','Tova','Uma','Vance','Willa','Xavi','Zoe',
    ];
    return $pool[array_rand($pool)];
}

function demo_last_name(): string
{
    static $pool = [
        'Bennett','Chen','Diaz','Evans','Fitzgerald','Garcia','Hayes','Iverson','Jordan','Khan',
        'Lopez','Mitchell','Nguyen','Okafor','Patel','Quinn','Ramirez','Sato','Thompson','Underwood',
        'Vargas','Walsh','Xu','Yang','Zimmerman','Adler','Brennan','Cabrera','Dempsey','Ellis',
        'Foster','Greene','Halloway','Ingram','Jacobs','Kowalski','Lambert','Marchetti','Nakamura','Ortiz',
        'Park','Quintero','Reyes','Silva','Tanaka','Ueno','Valenti','Whitford','Xanders','Yoon',
        'Zhao','Acosta','Burke','Cardenas','Donovan','Esposito','Flores','Goldberg','Hartwell','Ito',
        'Jensen','Kaur','Lin','Morales','Novak','Ogawa','Petrov','Rivera','Saunders','Tate',
        'Ulloa','Vasquez','Wong','Xiong','Yamada','Zeller','Abel','Barlow','Carrington','Donnelly',
    ];
    return $pool[array_rand($pool)];
}

function demo_phone(): string
{
    return sprintf('555-01%02d', random_int(0, 99));
}

/* ---------------------------------------------------------------------------
 * File copy helpers (PHP-only; shell_exec is disabled on Hostinger).
 * ------------------------------------------------------------------------- */

function rcopy(string $src, string $dst): void
{
    if (!is_dir($src)) return;
    if (!is_dir($dst) && !mkdir($dst, 0755, true)) {
        throw new RuntimeException("Could not create $dst");
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $rel = substr($item->getPathname(), strlen($src) + 1);
        $to  = $dst . '/' . $rel;
        if ($item->isDir()) {
            if (!is_dir($to) && !mkdir($to, 0755, true)) {
                throw new RuntimeException("Could not create $to");
            }
        } else {
            if (!copy($item->getPathname(), $to)) {
                throw new RuntimeException("Could not copy $item to $to");
            }
        }
    }
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if ($item->isDir()) @rmdir($item->getPathname());
        else                @unlink($item->getPathname());
    }
    @rmdir($dir);
}

/* ---------------------------------------------------------------------------
 * The reset itself
 * ------------------------------------------------------------------------- */

function do_reset(int $sourceId, int $targetId, array $cloneOrder, array $wipeOnly,
                  array $fkRules, array $pathRewrites, array $piiScrub,
                  string $demoLoginEmail, string $demoLoginPassword): array
{
    $storageBase = __DIR__ . '/../storage/uploads';
    $srcDir = "$storageBase/$sourceId";
    $tgtDir = "$storageBase/$targetId";
    $newDir = "$storageBase/{$targetId}.new";
    $oldDir = "$storageBase/{$targetId}.old." . time();

    // 1. Pre-step: stage file copy outside the DB transaction. Idempotent —
    //    delete any leftover .new from a failed previous run.
    if (is_dir($newDir)) rrmdir($newDir);
    if (is_dir($srcDir)) rcopy($srcDir, $newDir);

    $counts = ['wiped' => [], 'cloned' => []];

    // Tables we never touch (system / auth / global, no tenancy).
    $systemTables = [
        'sessions', 'password_resets', 'login_attempts', 'signups',
        'audit_log', 'help_topics', 'statutes', 'help_topic_images',
        'associations',
    ];

    db()->beginTransaction();
    try {
        // 2a. SCOPED wipe of child tables WITHOUT association_id. Must run
        //     BEFORE we wipe their parents, since the WHERE-IN subquery
        //     resolves through parent.association_id = $targetId. This is
        //     what guarantees we never touch another association's data,
        //     even if there's a pre-existing orphan row sitting around.
        $allTables = db()->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($allTables as $t) {
            if (has_assoc_col($t)) continue;          // handled in 2b
            if (in_array($t, $wipeOnly, true)) continue;
            if (in_array($t, $systemTables, true)) continue;
            $cols = table_columns($t);
            foreach ($cols as $c) {
                if (!isset($fkRules[$c])) continue;
                $parent = $fkRules[$c];
                if (!has_assoc_col($parent)) continue;
                $stmt = db()->prepare(
                    "DELETE FROM `$t` WHERE `$c` IN
                     (SELECT id FROM `$parent` WHERE association_id = ?)"
                );
                $stmt->execute([$targetId]);
                if ($stmt->rowCount() > 0) $counts['wiped'][$t] = $stmt->rowCount();
                break; // one scoping parent per child is enough
            }
        }

        // 2b. Wipe assoc-scoped tables — every DELETE is scoped to
        //     association_id = $targetId, so it can only touch the demo.
        foreach (array_reverse($cloneOrder) as $t) {
            if (!has_assoc_col($t)) continue;
            $stmt = db()->prepare("DELETE FROM `$t` WHERE association_id = ?");
            $stmt->execute([$targetId]);
            if ($stmt->rowCount() > 0) $counts['wiped'][$t] = $stmt->rowCount();
        }

        // 2c. Wipe-only tables. Also association_id-scoped.
        foreach ($wipeOnly as $t) {
            if (!table_exists($t)) continue;
            $cols = table_columns($t);
            if (in_array('association_id', $cols, true)) {
                $stmt = db()->prepare("DELETE FROM `$t` WHERE association_id = ?");
                $stmt->execute([$targetId]);
                if ($stmt->rowCount() > 0) $counts['wiped'][$t] = $stmt->rowCount();
            }
        }

        // 3. Clone source rows into target. Build $idMap[table][oldId]=newId
        //    incrementally so child tables can rewrite FKs through it.
        //    Skip WIPE_ONLY tables — those are wiped from target but never
        //    cloned (cloning them would leave NOT NULL FK columns dangling
        //    when their parents weren't in idMap, e.g. broadcast_attachments
        //    pointing at a broadcast_id with no entry in idMap['broadcasts']).
        $idMap = [];
        foreach ($cloneOrder as $t) {
            if (!has_assoc_col($t)) continue;
            if (in_array($t, $wipeOnly, true)) continue;
            $cols = table_columns($t);
            $stmt = db()->prepare("SELECT * FROM `$t` WHERE association_id = ?");
            $stmt->execute([$sourceId]);
            $rows = $stmt->fetchAll();

            $cloned = 0;
            foreach ($rows as $row) {
                $oldId = (int)$row['id'];
                unset($row['id']);
                $row['association_id'] = $targetId;

                // Generic FK remap by column name.
                foreach ($row as $col => $val) {
                    if ($val === null || $val === '') continue;
                    if (!isset($fkRules[$col])) continue;
                    $parent = $fkRules[$col];
                    $row[$col] = $idMap[$parent][(int)$val] ?? null;
                }

                // File-path rewrite.
                foreach (($pathRewrites[$t] ?? []) as $col) {
                    if (!array_key_exists($col, $row)) continue;
                    if (!is_string($row[$col]) || $row[$col] === '') continue;
                    $row[$col] = str_replace(
                        "uploads/$sourceId/",
                        "uploads/$targetId/",
                        $row[$col]
                    );
                }

                // Per-table PII scrub.
                if (isset($piiScrub[$t])) {
                    $row = $t === 'users'
                        ? $piiScrub[$t]($row, $demoLoginPassword)
                        : $piiScrub[$t]($row);
                }

                $insertCols   = array_keys($row);
                $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
                $colList      = '`' . implode('`,`', $insertCols) . '`';
                db()->prepare("INSERT INTO `$t` ($colList) VALUES ($placeholders)")
                    ->execute(array_values($row));
                $idMap[$t][$oldId] = (int)db()->lastInsertId();
                $cloned++;
            }
            if ($cloned > 0) $counts['cloned'][$t] = $cloned;
        }

        // 4. Clone child tables WITHOUT association_id. Target rows for
        //    these were already wiped in phase 2a via parent.association_id.
        //    Here we select source rows via the source's parent IDs and
        //    re-insert with FK columns rewritten through $idMap.
        foreach ($allTables as $t) {
            if (has_assoc_col($t)) continue;
            if (in_array($t, $wipeOnly, true)) continue;
            if (in_array($t, $systemTables, true)) continue;
            $cols = table_columns($t);
            if (!in_array('id', $cols, true)) continue;

            // Find a parent fk that's been cloned.
            $parentCol = null; $parent = null;
            foreach ($cols as $c) {
                if (isset($fkRules[$c]) && isset($idMap[$fkRules[$c]])) {
                    $parentCol = $c; $parent = $fkRules[$c]; break;
                }
            }
            if (!$parentCol) continue;

            $parentOldIds = array_keys($idMap[$parent]);
            if (!$parentOldIds) continue;

            // Pull source rows.
            $in = implode(',', array_map('intval', $parentOldIds));
            $rows = db()->query("SELECT * FROM `$t` WHERE `$parentCol` IN ($in)")->fetchAll();

            $cloned = 0;
            foreach ($rows as $row) {
                unset($row['id']);
                foreach ($row as $col => $val) {
                    if ($val === null || $val === '') continue;
                    if (!isset($fkRules[$col])) continue;
                    $p = $fkRules[$col];
                    $row[$col] = $idMap[$p][(int)$val] ?? null;
                }
                foreach (($pathRewrites[$t] ?? []) as $col) {
                    if (!array_key_exists($col, $row)) continue;
                    if (!is_string($row[$col]) || $row[$col] === '') continue;
                    $row[$col] = str_replace("uploads/$sourceId/", "uploads/$targetId/", $row[$col]);
                }
                if (isset($piiScrub[$t])) $row = $piiScrub[$t]($row);

                $insertCols   = array_keys($row);
                $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
                $colList      = '`' . implode('`,`', $insertCols) . '`';
                db()->prepare("INSERT INTO `$t` ($colList) VALUES ($placeholders)")
                    ->execute(array_values($row));
                $cloned++;
            }
            if ($cloned > 0) $counts['cloned'][$t] = $cloned;
        }

        // 5. Guarantee a known demo login.
        $stmt = db()->prepare("SELECT id FROM users WHERE association_id = ? AND email = ?");
        $stmt->execute([$targetId, $demoLoginEmail]);
        $existing = $stmt->fetchColumn();
        if (!$existing) {
            $pick = db()->prepare(
                "SELECT id FROM users
                  WHERE association_id = ?
                  ORDER BY (role = 'board_admin') DESC, id ASC LIMIT 1"
            );
            $pick->execute([$targetId]);
            $userId = (int)$pick->fetchColumn();
            $hash   = password_hash($demoLoginPassword, PASSWORD_BCRYPT, ['cost' => 10]);
            if ($userId) {
                db()->prepare(
                    "UPDATE users
                        SET email = ?, first_name = 'Demo', last_name = 'Admin',
                            password_hash = ?, role = 'board_admin', status = 'active'
                      WHERE id = ?"
                )->execute([$demoLoginEmail, $hash, $userId]);
            } else {
                db()->prepare(
                    "INSERT INTO users
                       (association_id, first_name, last_name, email, password_hash, role, status)
                     VALUES (?, 'Demo', 'Admin', ?, ?, 'board_admin', 'active')"
                )->execute([$targetId, $demoLoginEmail, $hash]);
            }
        }

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        if (is_dir($newDir)) rrmdir($newDir);
        throw $e;
    }

    // 6. Swap upload dirs now that the DB is committed.
    if (is_dir($srcDir)) {
        if (is_dir($tgtDir)) {
            if (!rename($tgtDir, $oldDir)) {
                throw new RuntimeException('Could not stash old target uploads dir.');
            }
        }
        if (!rename($newDir, $tgtDir)) {
            throw new RuntimeException('Could not promote new uploads dir into place.');
        }
        if (is_dir($oldDir)) rrmdir($oldDir);
    }

    return $counts;
}

/* ---------------------------------------------------------------------------
 * Compute clone order, source/target counts
 * ------------------------------------------------------------------------- */

$cloneOrder = resolve_clone_order($KNOWN_ORDER);

function row_counts_for(array $tables, int $assocId): array
{
    $out = [];
    foreach ($tables as $t) {
        if (!has_assoc_col($t)) { $out[$t] = '—'; continue; }
        $stmt = db()->prepare("SELECT COUNT(*) FROM `$t` WHERE association_id = ?");
        $stmt->execute([$assocId]);
        $out[$t] = (int)$stmt->fetchColumn();
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 * POST — perform the reset
 * ------------------------------------------------------------------------- */

$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $confirmSlug  = trim((string)($_POST['confirm_slug'] ?? ''));
    $demoEmail    = trim((string)($_POST['demo_email'] ?? 'demo@badasshoa.com'));
    $demoPassword = (string)($_POST['demo_password'] ?? '');

    if (strtolower(trim($confirmSlug)) !== strtolower((string)$target['subdomain'])) {
        $flashError = 'You must type the target slug to confirm: ' . $target['subdomain'];
    } elseif (strlen($demoPassword) < 6) {
        $flashError = 'Demo password must be at least 6 characters.';
    } elseif (!filter_var($demoEmail, FILTER_VALIDATE_EMAIL)) {
        $flashError = 'Demo login email is not a valid address.';
    } else {
        try {
            $counts = do_reset(
                $sourceId, $targetId,
                $cloneOrder, $WIPE_ONLY,
                $FK_RULES, $PATH_REWRITES, $PII_SCRUB,
                $demoEmail, $demoPassword
            );
            audit('demo.reset', [
                'source_id'  => $sourceId,
                'target_id'  => $targetId,
                'counts'     => $counts,
                'demo_email' => $demoEmail,
            ], $targetId, 'association');
            $totalCloned = array_sum($counts['cloned']);
            flash('success', sprintf(
                'Reset complete — cloned %d rows from %s into %s. Demo login: %s',
                $totalCloned,
                e((string)$source['name']),
                e((string)$target['name']),
                e($demoEmail)
            ));
            redirect('/admin/associations.php?action=edit&id=' . $targetId);
        } catch (Throwable $e) {
            $flashError = 'Reset failed: ' . $e->getMessage();
        }
    }
}

/* ---------------------------------------------------------------------------
 * GET — confirm screen
 * ------------------------------------------------------------------------- */

$sourceCounts = row_counts_for($cloneOrder, $sourceId);
$targetCounts = row_counts_for($cloneOrder, $targetId);

$page_title = 'Reset demo from Bellair — Admin';
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 920px;">

    <div class="row row--between" style="align-items: flex-start; flex-wrap: wrap; gap: var(--sp-3);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Reset demo association</h1>
            <p class="muted">Wipes the target and rebuilds it from the source with randomized owner / renter / board names.</p>
        </div>
        <a class="muted" style="font-size: var(--fs-sm);" href="/admin/associations.php">← Back to associations</a>
    </div>

    <?php if ($flashError): ?>
        <div class="flash flash--error"><?= e($flashError) ?></div>
    <?php endif; ?>

    <div class="card card--padded" style="margin: var(--sp-6) 0;">
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: var(--sp-6);">
            <div>
                <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600;"><?= e((string)$source['name']) ?> <span class="badge badge--success" style="margin-left: var(--sp-1);">source — read only</span></div>
                <div style="font-size: var(--fs-lg); font-weight: 700; margin: var(--sp-1) 0;">
                    <?= e((string)$source['name']) ?>
                    <code class="muted" style="font-weight:400; font-size: var(--fs-sm);">#<?= (int)$source['id'] ?></code>
                </div>
                <div class="muted" style="font-size: var(--fs-sm);">slug: <code><?= e((string)$source['subdomain']) ?></code></div>
            </div>
            <div>
                <div class="muted" style="font-size: var(--fs-xs); text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600;"><?= e((string)$target['name']) ?> <span class="badge badge--error" style="margin-left: var(--sp-1);">will be wiped</span></div>
                <div style="font-size: var(--fs-lg); font-weight: 700; margin: var(--sp-1) 0;">
                    <?= e((string)$target['name']) ?>
                    <code class="muted" style="font-weight:400; font-size: var(--fs-sm);">#<?= (int)$target['id'] ?></code>
                </div>
                <div class="muted" style="font-size: var(--fs-sm);">slug: <code><?= e((string)$target['subdomain']) ?></code></div>
            </div>
        </div>

        <h3 style="margin: var(--sp-6) 0 var(--sp-3); font-size: var(--fs-lg);">Row counts</h3>
        <div style="overflow-x:auto; max-height: 380px; overflow-y: auto;">
        <table class="table" style="font-size: var(--fs-sm);">
            <thead>
                <tr>
                    <th>Table</th>
                    <th style="text-align:right;"><?= e((string)$source['name']) ?> (will be cloned)</th>
                    <th style="text-align:right;"><?= e((string)$target['name']) ?> (will be wiped)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cloneOrder as $t):
                    if (!has_assoc_col($t)) continue;
                ?>
                    <tr>
                        <td><code><?= e($t) ?></code></td>
                        <td style="text-align:right;"><?= e((string)$sourceCounts[$t]) ?></td>
                        <td style="text-align:right;"><?= e((string)$targetCounts[$t]) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($WIPE_ONLY as $t):
                    if (!table_exists($t)) continue;
                    $cols = table_columns($t);
                    if (!in_array('association_id', $cols, true)) continue;
                    $cntStmt = db()->prepare("SELECT COUNT(*) FROM `$t` WHERE association_id = ?");
                    $cntStmt->execute([$targetId]);
                    $cnt = (int)$cntStmt->fetchColumn();
                ?>
                    <tr style="opacity:.7;">
                        <td><code><?= e($t) ?></code> <span class="muted" style="font-size: var(--fs-xs);">(wiped, not cloned)</span></td>
                        <td style="text-align:right;" class="muted">—</td>
                        <td style="text-align:right;"><?= (int)$cnt ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <div style="background: var(--color-surface); border: 1px solid var(--color-border); border-left: 3px solid var(--color-warning, #c47f00); padding: var(--sp-3) var(--sp-4); border-radius: var(--r-sm); margin-top: var(--sp-4); font-size: var(--fs-sm);">
            <strong>What this does — strictly scoped to association <code>#<?= (int)$target['id'] ?></code>:</strong>
            <ul style="margin: var(--sp-2) 0 0; padding-left: var(--sp-5);">
                <li><strong>Every DELETE is filtered by <code>association_id = <?= (int)$target['id'] ?></code></strong> (or, for child tables without that column, by parent IDs whose <code>association_id = <?= (int)$target['id'] ?></code>). No row belonging to any other association is ever touched.</li>
                <li>Re-clones each row from association <code>#<?= (int)$source['id'] ?></code>. All user first/last names are randomized; emails become <code>demo+&lt;hex&gt;@badasshoa.com</code>; phones become <code>555-01xx</code>; passwords are reset to the password below.</li>
                <li>Physically copies <code>storage/uploads/<?= (int)$source['id'] ?>/</code> over to <code>storage/uploads/<?= (int)$target['id'] ?>/</code>. Only the target's upload directory is replaced.</li>
                <li>Wipes <code>audit_log</code>, <code>platform_messages</code>, and <code>broadcasts</code> for the target (not cloned — too noisy and may contain real PII in bodies).</li>
                <li>Guarantees a known demo login exists at the email below with the password below.</li>
                <li>Target ID is gated by the <code>ALLOWED_TARGET_IDS</code> constant — currently <code>[<?= implode(',', ALLOWED_TARGET_IDS) ?>]</code>.</li>
            </ul>
        </div>

        <form method="post" style="margin-top: var(--sp-6); border-top: 1px solid var(--color-border); padding-top: var(--sp-4);">
            <?= csrf_field() ?>
            <input type="hidden" name="source" value="<?= (int)$sourceId ?>">
            <input type="hidden" name="target" value="<?= (int)$targetId ?>">

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="demo_email">Demo board_admin email</label>
                    <input class="input" id="demo_email" name="demo_email" type="email" required value="demo@badasshoa.com">
                    <div class="field__hint">This account will exist after the reset with the password below.</div>
                </div>
                <div class="field">
                    <label class="field__label" for="demo_password">Demo password (applied to ALL cloned users)</label>
                    <input class="input" id="demo_password" name="demo_password" type="text" required minlength="6" autocomplete="off" placeholder="At least 6 characters">
                    <div class="field__hint">Sets the password for every cloned user so you can log in as anyone for demo purposes.</div>
                </div>
            </div>

            <div class="field">
                <label class="field__label" for="confirm_slug">
                    Type the target slug to confirm: <code><?= e((string)$target['subdomain']) ?></code>
                </label>
                <input class="input" id="confirm_slug" name="confirm_slug" autocomplete="off" required
                       placeholder="<?= e((string)$target['subdomain']) ?>">
                <div id="confirm_slug_hint" class="field__hint" style="margin-top: var(--sp-1);">Match is case-insensitive and trims whitespace.</div>
            </div>

            <div class="row" style="justify-content: flex-end; gap: var(--sp-3); margin-top: var(--sp-4);">
                <a class="btn btn--ghost" href="/admin/associations.php">Cancel</a>
                <button class="btn btn--primary" id="do-reset" type="submit" disabled
                        onclick="return confirm('Last chance. Wipe association #<?= (int)$target['id'] ?> and re-clone from #<?= (int)$source['id'] ?>?');">
                    Wipe &amp; rebuild demo
                </button>
            </div>

            <script>
            (function () {
                var TARGET_SLUG = <?= json_encode(strtolower((string)$target['subdomain'])) ?>;
                var input  = document.getElementById('confirm_slug');
                var btn    = document.getElementById('do-reset');
                var hint   = document.getElementById('confirm_slug_hint');

                function evaluate() {
                    var v = (input.value || '').trim().toLowerCase();
                    var ok = (v === TARGET_SLUG);
                    btn.disabled = !ok;
                    if (v === '') {
                        hint.textContent = 'Match is case-insensitive and trims whitespace.';
                        hint.style.color = '';
                    } else if (ok) {
                        hint.textContent = '✓ Slug matches — ready to wipe & rebuild.';
                        hint.style.color = 'var(--color-success, #1f7a4e)';
                    } else {
                        hint.textContent = '✗ Doesn’t match — expected "' + TARGET_SLUG + '".';
                        hint.style.color = 'var(--color-error, #c0382b)';
                    }
                }

                input.addEventListener('input', evaluate);
                input.addEventListener('change', evaluate);
                evaluate();
            })();
            </script>
        </form>
    </div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
