</main>

<?php $page_layout = $page_layout ?? 'public'; ?>
<?php if ($page_layout === 'public'): ?>
<footer class="site-foot">
    <div class="container site-foot__inner">
        <div class="site-foot__brand">
            <svg class="brand__mark" viewBox="0 0 32 32" aria-hidden="true" width="28" height="28">
                <rect width="32" height="32" rx="7" fill="#0f1f3d"/>
                <path d="M9 7h9a5 5 0 0 1 3.5 8.5A5.5 5.5 0 0 1 17.5 25H9V7zm4 4v4h4a2 2 0 0 0 0-4h-4zm0 8v4h4.5a2 2 0 0 0 0-4H13z" fill="#f05a28"/>
            </svg>
            <strong>BadassHOA</strong>
            <span class="muted">— run your condo like a boss.</span>
        </div>
        <div class="site-foot__links">
            <a href="/pricing.php">Pricing</a>
            <a href="/signup.php">Get started</a>
            <a href="/login.php">Sign in</a>
        </div>
        <div class="site-foot__legal muted">
            &copy; <?= (int)date('Y') ?> BadassHOA. Built for boards that ship.
        </div>
    </div>
</footer>
<?php endif; ?>

<script src="/assets/js/app.js" defer></script>
</body>
</html>
