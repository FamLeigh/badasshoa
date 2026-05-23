<?php
// PDF signing page — user places their saved signature on an uploaded PDF.
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_login();

$user      = current_user();
$docId     = (int)($_GET['doc_id'] ?? 0);
$canManage = role_can_manage(viewing_role());

if (!$docId) { http_response_code(400); die('doc_id required'); }

$stmt = db()->prepare('SELECT * FROM documents WHERE id = ? AND association_id = ?');
$stmt->execute([$docId, $assocId]);
$doc = $stmt->fetch();

if (!$doc)                                        { http_response_code(404); die('Document not found'); }
if (empty($doc['file_path']))                     { http_response_code(400); die('Only uploaded files can be signed'); }
if (($doc['file_type'] ?? '') !== 'application/pdf') { http_response_code(400); die('Only PDF files can be signed'); }

$accessLevel = (string)($doc['access_level'] ?? 'members_only');
if ($accessLevel === 'board_only' && !$canManage) { http_response_code(403); die('Access denied'); }
if ($accessLevel === 'unit_only' && !$canManage) {
    $uc = db()->prepare('SELECT 1 FROM unit_occupants WHERE unit_id = ? AND user_id = ? LIMIT 1');
    $uc->execute([(int)$doc['unit_id'], (int)$user['id']]);
    if (!$uc->fetchColumn()) { http_response_code(403); die('Access denied'); }
}

// --- POST: receive the client-side-signed PDF and save it ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'sign_pdf') {
    csrf_check();

    $pdfB64    = trim((string)($_POST['signed_pdf']   ?? ''));
    $sigId     = (int)($_POST['sig_id']               ?? 0);
    $pageNum   = max(0, (int)($_POST['page_num']      ?? 0));
    $saveSig   = !empty($_POST['save_sig']);
    $newSigB64 = trim((string)($_POST['new_sig_data'] ?? ''));
    $sigLabel  = mb_substr(trim((string)($_POST['sig_label'] ?? '')), 0, 80);

    if ($pdfB64 === '') {
        flash('error', 'No signed PDF data received — please try again.');
        redirect('/dashboard/sign-pdf.php?doc_id=' . $docId);
    }

    // Strip data-URI prefix if the client included it.
    if (str_starts_with($pdfB64, 'data:')) {
        $pdfB64 = substr($pdfB64, strpos($pdfB64, ',') + 1);
    }
    $pdfBytes = base64_decode($pdfB64, true);
    if (!$pdfBytes || strlen($pdfBytes) < 50) {
        flash('error', 'Invalid PDF data — please try again.');
        redirect('/dashboard/sign-pdf.php?doc_id=' . $docId);
    }

    // Optionally save the drawn signature to the user's library.
    if ($saveSig && $newSigB64 !== '' && $sigId === 0) {
        $rawB64 = $newSigB64;
        if (str_starts_with($rawB64, 'data:')) {
            $rawB64 = substr($rawB64, strpos($rawB64, ',') + 1);
        }
        $sigBytes = base64_decode($rawB64, true);
        if ($sigBytes && strlen($sigBytes) > 100) {
            $ext    = 'png';
            $relDir = 'uploads/' . $assocId . '/signatures/library';
            ensure_dir(storage_path($relDir));
            $fname   = bin2hex(random_bytes(12)) . '.' . $ext;
            $libPath = "$relDir/$fname";
            if (file_put_contents(storage_path($libPath), $sigBytes) !== false) {
                db()->prepare(
                    'INSERT INTO user_signatures (user_id, kind, label, image_path) VALUES (?, ?, ?, ?)'
                )->execute([(int)$user['id'], 'drawn', $sigLabel ?: null, $libPath]);
                $sigId = (int)db()->lastInsertId();
                audit('signature.saved', ['kind' => 'drawn', 'label' => $sigLabel ?: null],
                      $sigId, 'user_signature');
            }
        }
    }

    // Save the signed PDF.
    $relDir  = "uploads/{$assocId}/signed-docs";
    $absDir  = storage_path($relDir);
    ensure_dir($absDir);
    $fname   = 'signed_' . time() . '_' . bin2hex(random_bytes(6)) . '.pdf';
    $relPath = "$relDir/$fname";
    if (file_put_contents(storage_path($relPath), $pdfBytes) === false) {
        flash('error', 'Could not save signed document. Check storage permissions.');
        redirect('/dashboard/sign-pdf.php?doc_id=' . $docId);
    }

    $sigImgPath = null;
    if ($sigId) {
        $sq = db()->prepare('SELECT image_path FROM user_signatures WHERE id = ? AND user_id = ?');
        $sq->execute([$sigId, (int)$user['id']]);
        $sigImgPath = $sq->fetchColumn() ?: null;
    }

    // Build tamper-evident event token: SHA-256 of (doc+user+time+random nonce).
    $signToken = hash('sha256', $docId . ':' . $user['id'] . ':' . microtime(true) . ':' . bin2hex(random_bytes(16)));
    $signerIp  = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $signerAgent = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

    db()->prepare(
        'INSERT INTO document_signatures
             (association_id, document_id, signer_user_id, signed_file_path, signature_image_path, page_num, signer_ip, signer_agent, sign_token)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$assocId, $docId, (int)$user['id'], $relPath, $sigImgPath, $pageNum, $signerIp, $signerAgent, $signToken]);
    $recId = (int)db()->lastInsertId();

    // Mark the signature request as fulfilled (if one exists for this user).
    db()->prepare(
        'UPDATE document_signature_requests
            SET fulfilled_at = NOW(), fulfilled_sig_id = ?
          WHERE document_id = ? AND user_id = ? AND fulfilled_at IS NULL'
    )->execute([$recId, $docId, (int)$user['id']]);

    audit('document.signed', [
        'doc_id'     => $docId,
        'doc_title'  => (string)$doc['title'],
        'page'       => $pageNum,
        'sig_id'     => $sigId ?: null,
        'sign_token' => $signToken,
    ], $recId, 'document_signatures');

    if ($sigId) touch_user_signature((int)$user['id'], $sigId);

    flash('success', '"' . e((string)$doc['title']) . '" signed and saved.');
    redirect('/dashboard/documents.php');
}

// Load user's saved signatures.
$savedSigs = user_saved_signatures((int)$user['id']);
$pdfUrl    = '/dashboard/file.php?type=document&id=' . $docId;

$active     = 'documents';
$page_title = 'Sign: ' . $doc['title'];
require __DIR__ . '/../includes/header.php';
?>

<style>
.sign-layout {
    display: flex;
    gap: var(--sp-4);
    align-items: flex-start;
    max-width: 1200px;
    margin: 0 auto;
    padding: var(--sp-6) var(--sp-4) var(--sp-12);
}
.sign-viewer {
    flex: 1 1 0;
    min-width: 0;
}
.sign-sidebar {
    flex: 0 0 280px;
    width: 280px;
    position: sticky;
    top: var(--sp-4);
}
.pdf-pages {
    background: #555;
    border-radius: var(--r-md);
    padding: var(--sp-4);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--sp-4);
    min-height: 400px;
    max-height: calc(100vh - 200px);
    overflow-y: auto;
}
.page-wrapper {
    position: relative;
    display: inline-block;
    box-shadow: 0 2px 12px rgba(0,0,0,.5);
    cursor: crosshair;
    line-height: 0;
}
.page-wrapper canvas { display: block; max-width: 100%; }
.sig-overlay {
    position: absolute;
    border: 2px dashed var(--color-primary);
    background: rgba(240,90,40,.06);
    pointer-events: none;
    box-sizing: border-box;
}
.sig-overlay img {
    width: 100%; height: 100%;
    object-fit: contain;
    object-position: center;
    display: block;
}
.sig-thumb {
    width: 100%;
    border: 2px solid var(--color-border);
    border-radius: var(--r-sm);
    padding: var(--sp-2);
    cursor: pointer;
    background: #fff;
    margin-bottom: var(--sp-2);
    transition: border-color .15s;
}
.sig-thumb:hover { border-color: var(--color-primary); }
.sig-thumb.active { border-color: var(--color-primary); background: #fff8f5; }
.sig-thumb img { display: block; max-height: 50px; width: 100%; object-fit: contain; }
.draw-canvas-wrap {
    border: 2px solid var(--color-border);
    border-radius: var(--r-sm);
    background: #fff;
    overflow: hidden;
    position: relative;
    touch-action: none;
}
#draw-canvas { display: block; cursor: crosshair; }
.placement-info {
    background: var(--color-bg);
    border: 1px solid var(--color-border);
    border-radius: var(--r-sm);
    padding: var(--sp-3);
    font-size: var(--fs-xs);
    color: var(--color-text-muted);
}
@media (max-width: 680px) {
    .sign-layout { flex-direction: column; }
    .sign-sidebar { width: 100%; position: static; }
}
</style>

<div class="sign-layout">

    <!-- ===== LEFT: PDF viewer ===== -->
    <div class="sign-viewer">
        <div style="margin-bottom: var(--sp-3); display:flex; align-items:center; gap:var(--sp-3); flex-wrap:wrap;">
            <a href="/dashboard/documents.php" class="btn btn--ghost" style="font-size:var(--fs-sm);">← Back</a>
            <div>
                <strong><?= e((string)$doc['title']) ?></strong>
                <span class="muted" style="font-size:var(--fs-xs);"> — click on the PDF to place your signature</span>
            </div>
        </div>
        <div id="pdf-pages" class="pdf-pages">
            <p class="muted" style="color:#ccc; padding: var(--sp-8);">Loading PDF…</p>
        </div>
        <div id="placement-info" class="placement-info" style="margin-top:var(--sp-3); display:none;">
            Signature placed on page <strong id="pi-page">—</strong>.
            <button type="button" id="clear-placement" class="btn btn--ghost"
                    style="padding:0.2rem 0.5rem; font-size:var(--fs-xs); margin-left:var(--sp-2);">
                Remove
            </button>
        </div>
    </div>

    <!-- ===== RIGHT: sidebar ===== -->
    <div class="sign-sidebar">

        <!-- Saved signatures -->
        <div class="card card--padded" style="margin-bottom:var(--sp-3);">
            <h3 style="font-size:var(--fs-base); margin:0 0 var(--sp-3);">Your signature</h3>

            <div id="saved-sigs-list">
            <?php if ($savedSigs): ?>
                <?php foreach ($savedSigs as $ss): if (empty($ss['image_path'])) continue; ?>
                <div class="sig-thumb" data-sig-id="<?= (int)$ss['id'] ?>"
                     data-sig-url="/dashboard/signature-image.php?saved_id=<?= (int)$ss['id'] ?>">
                    <img src="/dashboard/signature-image.php?saved_id=<?= (int)$ss['id'] ?>"
                         alt="<?= e((string)($ss['label'] ?? 'Signature')) ?>">
                    <?php if (!empty($ss['label'])): ?>
                        <div style="font-size:var(--fs-xs); color:var(--color-text-muted); margin-top:2px;"><?= e((string)$ss['label']) ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="muted" style="font-size:var(--fs-sm); margin-bottom:var(--sp-3);">No saved signatures yet.</p>
            <?php endif; ?>
            </div>

            <!-- Draw new -->
            <details id="draw-details" style="margin-top:var(--sp-2);">
                <summary style="cursor:pointer; font-size:var(--fs-sm); color:var(--color-primary); font-weight:600; list-style:none; user-select:none;">
                    ✏️ Draw new
                </summary>
                <div style="margin-top:var(--sp-3);">
                    <div class="draw-canvas-wrap" style="margin-bottom:var(--sp-2);">
                        <canvas id="draw-canvas" width="240" height="80"
                                style="width:100%; height:80px;"></canvas>
                    </div>
                    <div class="row" style="gap:var(--sp-2); margin-bottom:var(--sp-2);">
                        <button type="button" id="draw-clear" class="btn btn--ghost"
                                style="flex:1; font-size:var(--fs-xs); padding:0.3rem 0.5rem;">Clear</button>
                        <button type="button" id="draw-use" class="btn btn--primary"
                                style="flex:1; font-size:var(--fs-xs); padding:0.3rem 0.5rem;">Use this</button>
                    </div>
                    <div class="field" style="margin:0;">
                        <label style="display:flex; align-items:center; gap:var(--sp-2); font-size:var(--fs-xs); cursor:pointer;">
                            <input type="checkbox" id="save-sig-chk" value="1" style="width:14px;height:14px;">
                            Save for later
                        </label>
                        <input class="input" id="sig-label-inp" type="text" maxlength="80"
                               placeholder="e.g. My Initials"
                               style="margin-top:var(--sp-1); font-size:var(--fs-xs); display:none;">
                    </div>
                </div>
            </details>
        </div>

        <!-- Sign button + hidden form -->
        <form id="sign-form" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="sign_pdf">
            <input type="hidden" name="signed_pdf"   id="signed-pdf-input">
            <input type="hidden" name="sig_id"        id="sig-id-input" value="0">
            <input type="hidden" name="page_num"      id="page-num-input" value="0">
            <input type="hidden" name="new_sig_data"  id="new-sig-data-input">
            <input type="hidden" name="save_sig"      id="save-sig-input" value="0">
            <input type="hidden" name="sig_label"     id="sig-label-input">
        </form>

        <button type="button" id="sign-btn" class="btn btn--primary" style="width:100%; margin-bottom:var(--sp-2);" disabled>
            Sign &amp; Save
        </button>
        <p class="muted" style="font-size:var(--fs-xs);" id="sign-hint">
            Pick a signature, then click the PDF to place it.
        </p>

    </div>
</div>

<!-- PDF.js + pdf-lib from CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script src="https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>

<script>
(function () {
    'use strict';

    /* ── globals ── */
    const PDF_URL       = <?= json_encode($pdfUrl) ?>;
    const pagesEl       = document.getElementById('pdf-pages');
    const placementInfo = document.getElementById('placement-info');
    const piPage        = document.getElementById('pi-page');
    const clearBtn      = document.getElementById('clear-placement');
    const signBtn       = document.getElementById('sign-btn');
    const signHint      = document.getElementById('sign-hint');

    let pages       = [];     // { canvas, viewport, wrapper, pageIndex }
    let placement   = null;   // { pageIndex, pdfX, pdfY, pdfW, pdfH, wrapper }
    let activeSigId  = 0;
    let activeSigUrl = null;  // URL to fetch for pdf-lib embedding
    let drawnSigData = null;  // PNG data-URL (if drawn, not saved yet)

    /* ── PDF.js ── */
    pdfjsLib.GlobalWorkerOptions.workerSrc =
        'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

    async function renderPDF() {
        let arrayBuf;
        try {
            const resp = await fetch(PDF_URL, {credentials: 'include'});
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            arrayBuf = await resp.arrayBuffer();
        } catch (e) {
            pagesEl.innerHTML = '<p style="color:#f99; padding:var(--sp-6);">Could not load PDF: ' + e.message + '</p>';
            return;
        }

        let pdfDoc;
        try {
            pdfDoc = await pdfjsLib.getDocument({data: arrayBuf}).promise;
        } catch (e) {
            pagesEl.innerHTML = '<p style="color:#f99; padding:var(--sp-6);">Could not parse PDF: ' + e.message + '</p>';
            return;
        }

        pagesEl.innerHTML = '';
        const scale = Math.min(1.4, (pagesEl.clientWidth - 32) / 595); // approx A4 width

        for (let i = 1; i <= pdfDoc.numPages; i++) {
            const page     = await pdfDoc.getPage(i);
            const viewport = page.getViewport({scale});

            const wrapper  = document.createElement('div');
            wrapper.className       = 'page-wrapper';
            wrapper.dataset.pageIdx = String(i - 1);

            const canvas  = document.createElement('canvas');
            canvas.width  = viewport.width;
            canvas.height = viewport.height;
            canvas.style.width  = viewport.width  + 'px';
            canvas.style.height = viewport.height + 'px';

            const label = document.createElement('div');
            label.style.cssText = 'position:absolute;bottom:4px;right:6px;font-size:11px;color:rgba(255,255,255,.6);pointer-events:none;';
            label.textContent = 'Page ' + i;

            wrapper.appendChild(canvas);
            wrapper.appendChild(label);
            pagesEl.appendChild(wrapper);

            await page.render({canvasContext: canvas.getContext('2d'), viewport}).promise;

            const rec = {canvas, viewport, wrapper, pageIndex: i - 1};
            pages.push(rec);
            wrapper.addEventListener('click', function (evt) { onPageClick(evt, rec); });
        }
    }

    /* ── signature thumbnails ── */
    document.querySelectorAll('.sig-thumb').forEach(function (el) {
        el.addEventListener('click', function () {
            selectSavedSig(
                parseInt(el.dataset.sigId, 10),
                el.dataset.sigUrl
            );
            document.querySelectorAll('.sig-thumb').forEach(function (t) { t.classList.remove('active'); });
            el.classList.add('active');
        });
    });

    function selectSavedSig(id, url) {
        activeSigId  = id;
        activeSigUrl = url;
        drawnSigData = null;
        document.getElementById('sig-id-input').value = id;
        document.getElementById('new-sig-data-input').value = '';
        signHint.textContent = placement ? 'Ready — click "Sign & Save".' : 'Click the PDF to place your signature.';
        refreshSignBtn();
    }

    /* ── draw-pad ── */
    (function () {
        const canvas  = document.getElementById('draw-canvas');
        const ctx     = canvas.getContext('2d');
        let drawing   = false;
        let lastX = 0, lastY = 0;

        function pos(evt) {
            const r = canvas.getBoundingClientRect();
            const e = evt.touches ? evt.touches[0] : evt;
            return [(e.clientX - r.left) * (canvas.width / r.width),
                    (e.clientY - r.top)  * (canvas.height / r.height)];
        }

        function start(evt) { evt.preventDefault(); drawing = true; [lastX, lastY] = pos(evt); }
        function end()      { drawing = false; }
        function draw(evt)  {
            if (!drawing) return;
            evt.preventDefault();
            const [x, y] = pos(evt);
            ctx.strokeStyle = '#0f1f3d';
            ctx.lineWidth   = 2;
            ctx.lineCap     = 'round';
            ctx.lineJoin    = 'round';
            ctx.beginPath();
            ctx.moveTo(lastX, lastY);
            ctx.lineTo(x, y);
            ctx.stroke();
            [lastX, lastY] = [x, y];
        }

        canvas.addEventListener('mousedown',  start);
        canvas.addEventListener('mousemove',  draw);
        canvas.addEventListener('mouseup',    end);
        canvas.addEventListener('mouseleave', end);
        canvas.addEventListener('touchstart', start, {passive:false});
        canvas.addEventListener('touchmove',  draw,  {passive:false});
        canvas.addEventListener('touchend',   end);

        document.getElementById('draw-clear').addEventListener('click', function () {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        });

        document.getElementById('draw-use').addEventListener('click', function () {
            const data = canvas.toDataURL('image/png');
            // check if it's blank
            const blank = document.createElement('canvas');
            blank.width = canvas.width; blank.height = canvas.height;
            if (data === blank.toDataURL('image/png')) { alert('Draw your signature first.'); return; }

            drawnSigData = data;
            activeSigId  = 0;
            activeSigUrl = data; // use data-URL directly for pdf-lib
            document.getElementById('sig-id-input').value     = 0;
            document.getElementById('new-sig-data-input').value = data;
            document.querySelectorAll('.sig-thumb').forEach(function (t) { t.classList.remove('active'); });
            signHint.textContent = placement ? 'Ready — click "Sign & Save".' : 'Click the PDF to place your signature.';
            refreshSignBtn();
            document.getElementById('draw-details').removeAttribute('open');
        });

        document.getElementById('save-sig-chk').addEventListener('change', function () {
            const inp = document.getElementById('sig-label-inp');
            inp.style.display = this.checked ? '' : 'none';
        });
    })();

    /* ── placement ── */
    function onPageClick(evt, pageData) {
        if (!activeSigUrl) { signHint.textContent = 'Pick a signature first.'; return; }

        const canvas  = pageData.canvas;
        const rect    = canvas.getBoundingClientRect();
        const cssX    = evt.clientX - rect.left;
        const cssY    = evt.clientY - rect.top;

        // Convert CSS pixels → PDF points (pdf-lib uses bottom-left origin)
        const scaleX  = canvas.width  / rect.width;
        const scaleY  = canvas.height / rect.height;
        const canvasX = cssX * scaleX;
        const canvasY = cssY * scaleY;
        const pdfX    = canvasX / pageData.viewport.scale;
        const pdfY    = pageData.viewport.height / pageData.viewport.scale
                        - canvasY / pageData.viewport.scale;

        // Signature size in PDF points (~2 × 0.65 inches)
        const pdfW = 144;
        const pdfH = 46;

        // Remove old overlay
        clearOverlay();

        placement = {
            pageIndex: pageData.pageIndex,
            pdfX: pdfX - pdfW / 2,
            pdfY: pdfY - pdfH / 2,
            pdfW,
            pdfH,
            wrapper: pageData.wrapper,
        };

        // CSS overlay (top-left origin, in CSS px)
        const cssW = pdfW * pageData.viewport.scale / scaleX;
        const cssH = pdfH * pageData.viewport.scale / scaleY;

        const ov  = document.createElement('div');
        ov.className = 'sig-overlay';
        ov.style.left   = (cssX - cssW / 2) + 'px';
        ov.style.top    = (cssY - cssH / 2) + 'px';
        ov.style.width  = cssW + 'px';
        ov.style.height = cssH + 'px';
        ov.style.pointerEvents = 'none'; // set on wrapper, not here

        const img = document.createElement('img');
        img.src = activeSigUrl;
        ov.appendChild(img);

        // Bottom-right resize handle
        const handle = document.createElement('div');
        handle.className = 'sig-resize-handle';
        handle.style.cssText = [
            'position:absolute', 'right:-6px', 'bottom:-6px',
            'width:14px', 'height:14px',
            'background:var(--color-primary)', 'border-radius:50%',
            'cursor:se-resize', 'pointer-events:all',
            'border:2px solid #fff', 'box-sizing:border-box',
        ].join(';');
        ov.appendChild(handle);
        ov.style.pointerEvents = 'none'; // restore — handle is all
        pageData.wrapper.appendChild(ov);

        // Make the overlay itself draggable
        ov.style.cursor    = 'move';
        ov.style.pointerEvents = 'all';
        img.style.pointerEvents = 'none';

        // Drag-to-move
        (function setupDrag(el, pd) {
            let dragging = false, startX, startY, startLeft, startTop;
            el.addEventListener('mousedown', function (e) {
                if (e.target === handle) return; // let resize handle own this
                dragging = true;
                startX = e.clientX; startY = e.clientY;
                startLeft = parseFloat(el.style.left);
                startTop  = parseFloat(el.style.top);
                e.preventDefault();
            });
            window.addEventListener('mousemove', function (e) {
                if (!dragging) return;
                const dx = e.clientX - startX;
                const dy = e.clientY - startY;
                const newLeft = startLeft + dx;
                const newTop  = startTop  + dy;
                el.style.left = newLeft + 'px';
                el.style.top  = newTop  + 'px';
                // Keep placement.pdfX/Y in sync
                const w = parseFloat(el.style.width);
                const h = parseFloat(el.style.height);
                const sx = pd.canvas.width  / pd.canvas.getBoundingClientRect().width;
                const sy = pd.canvas.height / pd.canvas.getBoundingClientRect().height;
                placement.pdfX = (newLeft * sx) / pd.viewport.scale;
                placement.pdfY = pd.viewport.height / pd.viewport.scale
                                 - ((newTop + h) * sy) / pd.viewport.scale;
            });
            window.addEventListener('mouseup', function () { dragging = false; });
        })(ov, pageData);

        // Drag-to-resize (bottom-right corner)
        (function setupResize(el, pd) {
            let resizing = false, startX, startY, startW, startH;
            handle.addEventListener('mousedown', function (e) {
                resizing = true;
                startX = e.clientX; startY = e.clientY;
                startW = parseFloat(el.style.width);
                startH = parseFloat(el.style.height);
                e.preventDefault();
                e.stopPropagation();
            });
            window.addEventListener('mousemove', function (e) {
                if (!resizing) return;
                const newW = Math.max(40, startW + (e.clientX - startX));
                const newH = Math.max(14, startH + (e.clientY - startY));
                el.style.width  = newW + 'px';
                el.style.height = newH + 'px';
                // Keep placement.pdfW/H in sync
                const sx = pd.canvas.width  / pd.canvas.getBoundingClientRect().width;
                const sy = pd.canvas.height / pd.canvas.getBoundingClientRect().height;
                placement.pdfW = (newW * sx) / pd.viewport.scale;
                placement.pdfH = (newH * sy) / pd.viewport.scale;
            });
            window.addEventListener('mouseup', function () { resizing = false; });
        })(ov, pageData);

        // Update info
        placementInfo.style.display = '';
        piPage.textContent = String(pageData.pageIndex + 1);
        document.getElementById('page-num-input').value = String(pageData.pageIndex);

        signHint.textContent = 'Ready — click "Sign & Save".';
        refreshSignBtn();
    }

    function clearOverlay() {
        if (placement) {
            const ov = placement.wrapper.querySelector('.sig-overlay');
            if (ov) ov.remove();
            placement = null;
        }
        placementInfo.style.display = 'none';
        refreshSignBtn();
    }

    clearBtn.addEventListener('click', clearOverlay);

    function refreshSignBtn() {
        const ready = activeSigUrl && placement;
        signBtn.disabled = !ready;
        if (ready && signHint.textContent.indexOf('Pick') !== -1) {
            signHint.textContent = 'Ready — click "Sign & Save".';
        }
    }

    /* ── sign & save ── */
    signBtn.addEventListener('click', async function () {
        if (!activeSigUrl || !placement) return;

        signBtn.disabled   = true;
        signBtn.textContent = 'Signing…';

        try {
            // 1. Fetch original PDF
            const pdfResp = await fetch(PDF_URL, {credentials: 'include'});
            if (!pdfResp.ok) throw new Error('Could not fetch PDF (' + pdfResp.status + ')');
            const pdfArrayBuf = await pdfResp.arrayBuffer();

            // 2. Fetch signature image
            const sigResp = await fetch(activeSigUrl, {credentials: 'include'});
            if (!sigResp.ok) throw new Error('Could not fetch signature (' + sigResp.status + ')');
            const sigArrayBuf = await sigResp.arrayBuffer();
            const sigBytes    = new Uint8Array(sigArrayBuf);

            // 3. Load with pdf-lib
            const { PDFDocument } = PDFLib;
            const pdfLibDoc = await PDFDocument.load(pdfArrayBuf);

            // 4. Embed image (detect PNG vs JPEG)
            const isPng = sigBytes[0] === 0x89 && sigBytes[1] === 0x50;
            const embeddedImage = isPng
                ? await pdfLibDoc.embedPng(sigArrayBuf)
                : await pdfLibDoc.embedJpg(sigArrayBuf);

            // 5. Draw on the target page
            const pdfPages = pdfLibDoc.getPages();
            if (placement.pageIndex >= pdfPages.length) throw new Error('Page index out of range');
            const targetPage = pdfPages[placement.pageIndex];

            targetPage.drawImage(embeddedImage, {
                x:      placement.pdfX,
                y:      placement.pdfY,
                width:  placement.pdfW,
                height: placement.pdfH,
            });

            // 6. Serialize
            const signedBytes = await pdfLibDoc.save();
            const signedB64   = uint8ToBase64(signedBytes);

            // 7. Fill hidden inputs + submit form
            document.getElementById('signed-pdf-input').value = signedB64;
            const saveSigChk  = document.getElementById('save-sig-chk');
            document.getElementById('save-sig-input').value  = (saveSigChk && saveSigChk.checked) ? '1' : '0';
            document.getElementById('sig-label-input').value = document.getElementById('sig-label-inp').value;
            document.getElementById('sign-form').submit();

        } catch (err) {
            console.error(err);
            alert('Signing failed: ' + err.message);
            signBtn.disabled   = false;
            signBtn.textContent = 'Sign & Save';
        }
    });

    function uint8ToBase64(bytes) {
        let binary = '';
        const chunk = 8192;
        for (let i = 0; i < bytes.length; i += chunk) {
            binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
        }
        return btoa(binary);
    }

    /* ── init ── */
    renderPDF();
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
