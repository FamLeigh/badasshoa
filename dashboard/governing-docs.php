<?php
require __DIR__ . '/_bootstrap.php';

$page_title = 'Governing Documents Guide — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 860px;">

    <div style="margin-bottom: var(--sp-6);">
        <a class="btn btn--ghost" href="/dashboard/search.php" style="margin-bottom: var(--sp-4); display: inline-block;">← Back to Rules &amp; Bylaws</a>
        <h1 style="font-size: var(--fs-3xl); margin: 0 0 var(--sp-1);">Understanding HOA Governing Documents</h1>
        <p class="muted" style="font-size: var(--fs-lg); margin: 0 0 var(--sp-2);">A Resident's Guide to How Rules Are Changed in Florida</p>
        <p class="muted" style="font-size: var(--fs-sm);">Prepared for residents of <?= e($association['city'] ?? 'your community') ?>, Florida</p>
    </div>

    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <h2 style="font-size: var(--fs-xl); margin: 0 0 var(--sp-3);">Overview</h2>
        <p style="line-height: 1.7;">Florida homeowners associations (HOAs) operate under a hierarchy of governing documents. Each type of document requires a different process and level of member approval to amend. As a resident, understanding this hierarchy helps you know your rights and how to participate in the process.</p>
    </div>

    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <h2 style="font-size: var(--fs-xl); margin: 0 0 var(--sp-4);">Types of Governing Documents &amp; How They're Changed</h2>
        <div style="overflow-x: auto;">
        <table class="table" style="min-width: 580px;">
            <thead>
                <tr>
                    <th>Document</th>
                    <th>What It Covers</th>
                    <th>Approval Required</th>
                    <th style="white-space: nowrap;">Must Be Recorded?</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Declaration (CC&amp;Rs)</strong></td>
                    <td>Property use restrictions, maintenance obligations, common areas</td>
                    <td>Two-thirds (&#8532;) vote of <em>all</em> voting interests</td>
                    <td><span style="color: var(--color-success);">&#10003;</span> Yes &mdash; Volusia County Clerk</td>
                </tr>
                <tr>
                    <td><strong>Articles of Incorporation</strong></td>
                    <td>Legal structure of the HOA as a corporation</td>
                    <td>Typically two-thirds (&#8532;) vote</td>
                    <td><span style="color: var(--color-success);">&#10003;</span> Yes &mdash; FL Division of Corporations</td>
                </tr>
                <tr>
                    <td><strong>Bylaws</strong></td>
                    <td>Board elections, meetings, voting procedures</td>
                    <td>Two-thirds (&#8532;) vote of voting interests</td>
                    <td><span style="color: var(--color-success);">&#10003;</span> Recommended</td>
                </tr>
                <tr>
                    <td><strong>Rules &amp; Regulations</strong></td>
                    <td>Day-to-day community rules (parking, pool hours, etc.)</td>
                    <td>Board approval only (no membership vote required)</td>
                    <td><span style="color: var(--color-error);">&#10007;</span> Not required</td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>

    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <h2 style="font-size: var(--fs-xl); margin: 0 0 var(--sp-4);">Step-by-Step Amendment Process</h2>
        <p style="margin: 0 0 var(--sp-4); line-height: 1.7;">For <strong>Declaration, Articles of Incorporation, or Bylaws</strong>, the following steps apply under Florida Statute 720.306:</p>

        <ol style="padding-left: var(--sp-5); line-height: 1; list-style: none; counter-reset: steps;">
            <?php
            $steps = [
                ['Review Current Documents', 'The board or a resident committee reviews existing language and works with a Florida HOA attorney to draft proposed changes.'],
                ['Board Approval to Proceed', 'The board votes to present the proposed amendment to all members.'],
                ['Written Notice to All Members', 'All residents must receive written notice <strong>at least 14 days before the vote</strong>, including the full text of the proposed amendment.'],
                ['Member Meeting &amp; Vote', 'A quorum of at least <strong>30% of voting interests</strong> must be present or represented by proxy. A <strong>two-thirds (&#8532;) majority</strong> of all voting interests is required for approval.'],
                ['Recording with the County', 'Approved amendments must be filed with the <strong>Volusia County Clerk of Circuit Court within 30 days</strong>. The amendment does <strong>not take effect</strong> until it is officially recorded.'],
                ['Distribution to Residents', 'Copies of the recorded amendment must be provided to all members within 30 days of recording.'],
            ];
            foreach ($steps as $i => $step):
                $n = $i + 1;
            ?>
            <li style="display: flex; gap: var(--sp-4); margin-bottom: var(--sp-4); align-items: flex-start;">
                <span style="flex: 0 0 36px; height: 36px; width: 36px; background: var(--color-navy); color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: var(--fs-md);"><?= $n ?></span>
                <div style="padding-top: 6px; line-height: 1.65;">
                    <strong><?= $step[0] ?></strong> &mdash; <?= $step[1] ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ol>
    </div>

    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <h2 style="font-size: var(--fs-xl); margin: 0 0 var(--sp-3);">Rules &amp; Regulations &mdash; Simpler Process</h2>
        <p style="line-height: 1.7; margin: 0 0 var(--sp-3);"><strong>Rules and Regulations</strong> are the easiest documents to change. The Board of Directors may adopt, amend, or repeal rules <strong>without a member vote</strong>, as long as:</p>
        <ul style="padding-left: var(--sp-5); line-height: 1.7; margin: 0;">
            <li>Proper board meeting notice is given</li>
            <li>The change does not conflict with the Declaration, Bylaws, or Florida law</li>
            <li>Members are notified of the change after it is approved</li>
        </ul>
    </div>

    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <h2 style="font-size: var(--fs-xl); margin: 0 0 var(--sp-3);">Your Rights as a Resident</h2>
        <ul style="padding-left: var(--sp-5); line-height: 1.85; margin: 0;">
            <li>You have the right to <strong>receive notice</strong> of any proposed amendment at least 14 days in advance.</li>
            <li>You have the right to <strong>vote</strong> on amendments to the Declaration, Articles of Incorporation, and Bylaws.</li>
            <li>You have the right to <strong>review</strong> all governing documents &mdash; request copies from the board or management company at any time.</li>
            <li>You may <strong>petition</strong> the board to consider a rule change by gathering support from fellow residents.</li>
        </ul>
    </div>

    <div class="card card--padded" style="margin-bottom: var(--sp-6); border-left: 4px solid var(--color-navy);">
        <h2 style="font-size: var(--fs-xl); margin: 0 0 var(--sp-3);">Key Florida Law Reference</h2>
        <blockquote style="margin: 0; padding: var(--sp-3) var(--sp-4); background: var(--color-surface); border-radius: var(--r-md); font-size: var(--fs-md); line-height: 1.65;">
            <strong>Florida Statute &sect; 720.306</strong> governs HOA meetings, voting, and amendments to governing documents for residential communities.
        </blockquote>
        <p style="margin: var(--sp-3) 0 0; line-height: 1.65; color: var(--color-text-soft);">For questions about your specific community's governing documents or amendment procedures, contact your HOA board or a licensed Florida HOA attorney.</p>
    </div>

    <p class="muted" style="font-size: var(--fs-xs); border-top: 1px solid var(--color-border); padding-top: var(--sp-4); line-height: 1.6;">
        <em>This document is intended for general informational purposes only and does not constitute legal advice.</em>
    </p>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
