<?php
// Friendly 404 page — pointed at by .htaccess ErrorDocument. Pure HTML/CSS,
// no auth or DB so it can render for unauthenticated visitors too.
http_response_code(404);

$jokes = [
    "Looks like this page took an unannounced absence — and the board is *not* happy about it.",
    "This URL is in violation of bylaw §404.1: <em>Pages shall remain at their assigned address.</em>",
    "The page you're looking for is on the HOA's <strong>missing pages list</strong>. We're working on it.",
    "404: this page is not on the approved list of paint colors. Try a different shade.",
    "If this page had a parking spot, it'd be in the wrong one. Towing dispatched.",
    "Someone fined this page for being late and it never came back.",
    "The board reviewed this URL and politely declined.",
    "This page didn't pay its dues. Access suspended pending appeal.",
];
$pick = $jokes[array_rand($jokes)];

$pageTitle = 'Page not found — BadassHOA';
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
<style>
    :root { --navy:#0f1f3d; --orange:#f05a28; --bg:#f8f7f4; --text:#1a2238; --soft:#5a5a6e; }
    * { box-sizing: border-box; }
    body {
        font-family: Inter, system-ui, sans-serif;
        background: var(--bg); color: var(--text); margin: 0;
        min-height: 100vh; display: grid; place-items: center;
        padding: 32px; line-height: 1.55;
    }
    .card {
        max-width: 620px; background: #fff; border: 1px solid #e5e3da;
        border-radius: 18px; padding: 48px 40px; text-align: center;
        box-shadow: 0 12px 40px rgba(15,31,61,0.08);
    }
    .num {
        font-family: 'Syne', sans-serif; font-weight: 800;
        font-size: clamp(72px, 16vw, 132px); line-height: 1;
        background: linear-gradient(180deg, var(--navy) 50%, var(--orange) 50%);
        -webkit-background-clip: text; background-clip: text;
        -webkit-text-fill-color: transparent;
        letter-spacing: -0.04em; margin: 0 0 8px;
    }
    .sign {
        display: inline-block; transform: rotate(-3deg);
        background: #fff; border: 3px solid var(--navy); border-radius: 6px;
        padding: 8px 16px; margin-bottom: 24px;
        font-family: 'Syne', sans-serif; font-weight: 700;
        font-size: 14px; text-transform: uppercase; letter-spacing: 0.12em;
        color: var(--navy);
        box-shadow: 4px 4px 0 rgba(0,0,0,0.15);
    }
    .sign::before { content: '⚠ '; color: var(--orange); }
    h1 {
        font-family: 'Syne', sans-serif; font-weight: 700;
        font-size: 28px; margin: 0 0 16px; color: var(--navy);
    }
    .joke {
        font-size: 16px; color: var(--soft); margin: 0 0 28px;
        max-width: 480px; margin-left: auto; margin-right: auto;
    }
    .joke em { color: var(--orange); font-style: normal; font-weight: 600; }
    .joke strong { color: var(--navy); }
    .actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
    .btn {
        display: inline-block; padding: 12px 22px; border-radius: 999px;
        font-weight: 600; font-size: 14px; text-decoration: none;
        transition: transform 120ms ease, box-shadow 120ms ease;
    }
    .btn-primary { background: var(--orange); color: #fff; }
    .btn-ghost   { background: transparent; color: var(--navy); border: 1px solid #e5e3da; }
    .btn:hover { transform: translateY(-1px); box-shadow: 0 8px 22px rgba(15,31,61,0.10); }
    .meta {
        margin-top: 32px; padding-top: 20px; border-top: 1px solid #eee;
        font-size: 12px; color: var(--soft);
    }
</style>
</head><body>
    <div class="card" role="main">
        <div class="num">404</div>
        <div class="sign">Violation Notice</div>
        <h1>Page not found.</h1>
        <p class="joke"><?= $pick /* trusted static text */ ?></p>
        <div class="actions">
            <a class="btn btn-primary" href="/">Take me home</a>
            <a class="btn btn-ghost"   href="javascript:history.back()">← Go back</a>
        </div>
        <div class="meta">
            BadassHOA · If you think this is a bug, email <a href="mailto:success@badasshoa.com" style="color: var(--orange); text-decoration: none;">success@badasshoa.com</a>.
        </div>
    </div>
</body></html>
