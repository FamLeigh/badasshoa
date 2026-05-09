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
            &copy; <?= (int)date('Y') ?> BadassHOA. Built for boards that ship.
        </div>
    </div>
</footer>
<?php endif; ?>

<?php $jsPath = __DIR__ . '/../assets/js/app.js'; $jsVer = is_file($jsPath) ? filemtime($jsPath) : ''; ?>
<script src="/assets/js/app.js?v=<?= e((string)$jsVer) ?>" defer></script>
</body>
</html>
