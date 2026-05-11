</main>

<?php $page_layout = $page_layout ?? 'public'; ?>
<?php if ($page_layout === 'public'): ?>
<footer class="site-foot">
    <div class="container site-foot__inner">
        <div class="site-foot__brand">
            <img src="/assets/images/logo-white.png" alt="BadassHOA" class="site-foot__logo">
            <span class="muted">— run your condo like a boss.</span>
        </div>
        <div class="site-foot__links">
            <a href="/pricing.php">Pricing</a>
            <a href="/changelog.php">Changelog</a>
            <a href="/signup.php">Get started</a>
            <a href="/login.php">Sign in</a>
        </div>
        <div class="site-foot__legal muted">
            &copy; <?= (int)date('Y') ?> Savvy Brain LLC and Kevin B. Leigh. Powered by BadassHOA.
        </div>
    </div>
</footer>
<?php else: ?>
<!-- Tiny copyright stripe for app + admin pages -->
<div class="app-foot" style="text-align: center; padding: var(--sp-3); color: var(--color-text-soft); font-size: var(--fs-xs);">
    &copy; <?= (int)date('Y') ?> Savvy Brain LLC and Kevin B. Leigh · Powered by BadassHOA
</div>
<?php endif; ?>

<?php $jsPath = __DIR__ . '/../assets/js/app.js'; $jsVer = is_file($jsPath) ? filemtime($jsPath) : ''; ?>
<script src="/assets/js/app.js?v=<?= e((string)$jsVer) ?>" defer></script>
</body>
</html>
