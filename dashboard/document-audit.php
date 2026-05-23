<?php
// Document signing certificate — shows who was required to sign, who has signed,
// with IP, browser, timestamp, and tamper-evident event token.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_login();

$canManage = role_can_manage(viewing_role());

$docId = (int)($_GET['doc_id'] ?? 0);
if (!$docId) { http_response_code(400); die('doc_id required'); }

$stmt = db()->prepare('SELECT * FROM documents WHERE id = ? AND association_id = ?');
$stmt->execute([$docId, $assocId]);
$doc = $stmt->fetch();
if (!$doc) { http_response_code(404); die('Document not found'); }

// Access: same rules as the underlying document.
$access = (string)($doc['access_level'] ?? 'members_only');
if ($access === 'board_only' && !$canManage) { http_response_code(403); die('Access denied'); }
if ($access === 'unit_only' && !$canManage) {
    $uc = db()->prepare('SELECT 1 FROM unit_occupants WHERE unit_id = ? AND user_id = ? LIMIT 1');
    $uc->execute([(int)$doc['unit_id'], (int)current_user()['id']]);
    if (!$uc->fetchColumn()) { http_response_code(403); die('Access denied'); }
}

// All signature requests for this document.
$reqStmt = db()->prepare(
    'SELECT r.user_id, r.fulfilled_at, r.fulfilled_sig_id, r.created_at AS requested_at,
            CONCAT(u.first_name, " ", u.last_name) AS signer_name, u.email
       FROM document_signature_requests r
       JOIN users u ON u.id = r.user_id
      WHERE r.document_id = ?
      ORDER BY r.created_at'
);
$reqStmt->execute([$docId]);
$requests = $reqStmt->fetchAll();

// All actual signing events for this document (anyone who signed, whether required or not).
$sigStmt = db()->prepare(
    'SELECT s.*, CONCAT(u.first_name, " ", u.last_name) AS signer_name, u.email
       FROM document_signatures s
       JOIN users u ON u.id = s.signer_user_id
      WHERE s.document_id = ?
      ORDER BY s.created_at'
);
$sigStmt->execute([$docId]);
$sigEvents = $sigStmt->fetchAll();

// Build a map of fulfilled requests: sig_id → request row (for linking).
$fulfilledSigIds = array_filter(array_column($requests, 'fulfilled_sig_id'));

// Compute a SHA-256 fingerprint of the original PDF file for the certificate.
$originalHash = '—';
if (!empty($doc['file_path'])) {
    $abs = storage_path((string)$doc['file_path']);
    if (is_file($abs)) {
        $originalHash = hash_file('sha256', $abs);
    }
}

$totalRequired  = count($requests);
$totalFulfilled = count(array_filter($requests, fn($r) => !empty($r['fulfilled_at'])));
$allSigned      = $totalRequired > 0 && $totalFulfilled >= $totalRequired;

$active     = 'documents';
$page_title = 'Signing Certificate — ' . $doc['title'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 860px;">

    <div style="margin-bottom: var(--sp-4); display:flex; align-items:center; gap:var(--sp-3); flex-wrap:wrap;">
        <a href="/dashboard/documents.php?action=edit&id=<?= $docId ?>" class="btn btn--ghost" style="font-size:var(--fs-sm);">← Back to document</a>
        <button onclick="window.print()" class="btn btn--ghost" style="font-size:var(--fs-sm);">Print / Save PDF</button>
    </div>

    <!-- ===== Document identity ===== -->
    <div class="card card--padded" style="margin-bottom: var(--sp-4); border-top: 3px solid var(--color-primary);">
        <div style="display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:var(--sp-3);">
            <div>
                <h1 style="font-size: var(--fs-2xl); margin: 0 0 var(--sp-1);">Signing Certificate</h1>
                <p class="muted" style="margin:0; font-size:var(--fs-sm);"><?= e((string)$association['name']) ?></p>
            </div>
            <span class="badge <?= $allSigned ? 'badge--success' : ($totalRequired === 0 ? '' : 'badge--warning') ?>"
                  style="font-size:var(--fs-base); padding: var(--sp-2) var(--sp-3);">
                <?php if ($totalRequired === 0): ?>No signatures required
                <?php elseif ($allSigned): ?>Fully signed
                <?php else: ?><?= $totalFulfilled ?> / <?= $totalRequired ?> signed<?php endif; ?>
            </span>
        </div>

        <hr style="border:none; border-top:1px solid var(--color-border); margin: var(--sp-4) 0;">

        <dl style="display:grid; grid-template-columns: 160px 1fr; gap: var(--sp-2) var(--sp-4); font-size:var(--fs-sm);">
            <dt class="muted">Document</dt>
            <dd style="margin:0; font-weight:600;"><?= e((string)$doc['title']) ?></dd>

            <dt class="muted">Category</dt>
            <dd style="margin:0;"><?= e($doc['category'] ?: '—') ?></dd>

            <dt class="muted">Uploaded</dt>
            <dd style="margin:0;"><?= e(udate('F j, Y \a\t g:i A', strtotime((string)$doc['created_at']))) ?> UTC</dd>

            <?php if (!empty($doc['version'])): ?>
            <dt class="muted">Version</dt>
            <dd style="margin:0;"><?= e((string)$doc['version']) ?></dd>
            <?php endif; ?>

            <dt class="muted">Access level</dt>
            <dd style="margin:0;"><?= e(str_replace('_', ' ', (string)$doc['access_level'])) ?></dd>

            <dt class="muted">Original SHA-256</dt>
            <dd style="margin:0; font-family:monospace; font-size:var(--fs-xs); word-break:break-all;"><?= e($originalHash) ?></dd>

            <dt class="muted">Document ID</dt>
            <dd style="margin:0; font-family:monospace;">#<?= $docId ?></dd>
        </dl>
    </div>

    <!-- ===== Required signers ===== -->
    <?php if ($requests): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-4);">
        <h2 style="font-size:var(--fs-lg); margin:0 0 var(--sp-4);">Required signers</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Required since</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($requests as $r): ?>
                <tr>
                    <td><strong><?= e((string)$r['signer_name']) ?></strong></td>
                    <td style="font-size:var(--fs-xs);"><?= e((string)$r['email']) ?></td>
                    <td style="font-size:var(--fs-xs);"><?= e(udate('M j, Y', strtotime((string)$r['requested_at']))) ?></td>
                    <td>
                        <?php if ($r['fulfilled_at']): ?>
                            <span class="badge badge--success">Signed <?= e(udate('M j, Y', strtotime((string)$r['fulfilled_at']))) ?></span>
                        <?php else: ?>
                            <span class="badge badge--warning">Pending</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ===== Signing events ===== -->
    <div class="card card--padded" style="margin-bottom: var(--sp-4);">
        <h2 style="font-size:var(--fs-lg); margin:0 0 var(--sp-4);">Signature events</h2>

        <?php if (!$sigEvents): ?>
            <p class="muted">No signatures recorded yet.</p>
        <?php else: ?>
        <?php foreach ($sigEvents as $idx => $ev): ?>
        <div style="border: 1px solid var(--color-border); border-radius: var(--r-md); padding: var(--sp-4); margin-bottom: var(--sp-3); <?= $idx === 0 ? '' : '' ?>">
            <div style="display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:var(--sp-2); margin-bottom:var(--sp-3);">
                <div>
                    <strong style="font-size:var(--fs-base);"><?= e((string)$ev['signer_name']) ?></strong>
                    <span class="muted" style="font-size:var(--fs-xs); margin-left:var(--sp-2);"><?= e((string)$ev['email']) ?></span>
                </div>
                <span class="badge badge--success">Signed</span>
            </div>

            <dl style="display:grid; grid-template-columns: 140px 1fr; gap: var(--sp-1) var(--sp-3); font-size:var(--fs-sm);">
                <dt class="muted">Signed at</dt>
                <dd style="margin:0;"><?= e(udate('F j, Y \a\t g:i:s A', strtotime((string)$ev['created_at']))) ?> UTC</dd>

                <dt class="muted">Page signed</dt>
                <dd style="margin:0;">Page <?= (int)$ev['page_num'] + 1 ?></dd>

                <?php if ($canManage): ?>
                <dt class="muted">IP address</dt>
                <dd style="margin:0; font-family:monospace;"><?= e($ev['signer_ip'] ?: '—') ?></dd>

                <dt class="muted">Browser</dt>
                <dd style="margin:0; font-size:var(--fs-xs); word-break:break-all;"><?= e($ev['signer_agent'] ?: '—') ?></dd>

                <?php if (!empty($ev['sign_token'])): ?>
                <dt class="muted">Event token</dt>
                <dd style="margin:0; font-family:monospace; font-size:var(--fs-xs); word-break:break-all;"><?= e((string)$ev['sign_token']) ?></dd>
                <?php endif; ?>
                <?php endif; ?>

                <dt class="muted">Signed file</dt>
                <dd style="margin:0;">
                    <a href="/dashboard/signed-doc.php?id=<?= (int)$ev['id'] ?>"
                       target="_blank" rel="noopener" style="font-size:var(--fs-xs);">Download signed copy</a>
                </dd>
            </dl>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ===== Legal notice ===== -->
    <div style="background: var(--color-bg); border:1px solid var(--color-border); border-radius:var(--r-sm); padding:var(--sp-4); font-size:var(--fs-xs); color:var(--color-text-muted); line-height:1.6;">
        <strong style="color:var(--color-text);">About this certificate</strong><br>
        This certificate was generated by BadassHOA and records each signer's intent to sign, IP address, browser, and time of signing as captured by the platform's server at the moment of submission. The SHA-256 hash of the original file can be used to verify that the source document has not been modified after upload. The event token for each signing event is a one-time cryptographic hash generated at submission time and stored immutably. This record is consistent with the requirements of the Electronic Signatures in Global and National Commerce Act (E-SIGN) and the Uniform Electronic Transactions Act (UETA). Retain for your records.
    </div>

</div>

<style>
@media print {
    .btn, nav, footer { display: none !important; }
    .container { max-width: 100% !important; padding: 0 !important; }
}
</style>

<?php require __DIR__ . '/../includes/footer.php'; ?>
