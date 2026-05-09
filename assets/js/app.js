// BadassHOA global JS
(function () {
    'use strict';

    // --- mobile nav toggle ---
    document.querySelectorAll('.nav-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = btn.classList.contains('nav-toggle--app') ? 'app-nav-links' : 'site-nav-links';
            var links = document.getElementById(targetId);
            if (!links) return;
            var isOpen = links.classList.toggle('is-open');
            btn.classList.toggle('is-open', isOpen);
            btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    });

    // --- close mobile nav on outside click ---
    document.addEventListener('click', function (ev) {
        var openLinks = document.querySelector('.site-nav__links.is-open, .app-nav__links.is-open');
        if (!openLinks) return;
        if (openLinks.contains(ev.target)) return;
        if (ev.target.closest('.nav-toggle')) return;
        openLinks.classList.remove('is-open');
        document.querySelectorAll('.nav-toggle.is-open').forEach(function (b) {
            b.classList.remove('is-open');
            b.setAttribute('aria-expanded', 'false');
        });
    });

    // --- pricing calculator ---
    var calc = document.querySelector('[data-calc]');
    if (calc) {
        var range = calc.querySelector('input[type="range"]');
        var num   = calc.querySelector('input[type="number"]');
        var price = calc.querySelector('[data-calc-price]');
        var tier  = calc.querySelector('[data-calc-tier]');
        var crumb = calc.querySelector('[data-calc-breakdown]');
        var ctaBtn = calc.querySelector('[data-calc-cta]');

        // Unified formula: $20 base + $0.50/unit. Tier label is just for feature gating.
        function tierFor(units) {
            var price = 20 + (0.50 * units);
            if (units <= 50)  return { name: 'Starter',      price: price, cta: 'Start free' };
            if (units <= 150) return { name: 'Growth',       price: price, cta: 'Start trial' };
            if (units <= 300) return { name: 'Professional', price: price, cta: 'Start trial' };
            return                   { name: 'Enterprise',  price: null,  cta: 'Contact sales' };
        }

        function fmt(n) {
            return '$' + n.toFixed(2).replace(/\.00$/, '');
        }

        function update(units) {
            units = Math.max(1, Math.min(1000, parseInt(units, 10) || 50));
            if (range) range.value = units;
            if (num)   num.value   = units;
            var t = tierFor(units);
            if (tier)  tier.textContent  = t.name;
            if (price) price.textContent = t.price === null ? "Let's talk" : (fmt(t.price) + '/mo');
            if (ctaBtn) ctaBtn.textContent = t.cta;
            if (crumb) {
                if (units > 300) {
                    crumb.textContent = "300+ units — let's customize a quote.";
                } else {
                    crumb.textContent = '$20 base + $0.50 × ' + units + ' units = ' + fmt(t.price) + '/mo.';
                }
            }
        }

        if (range) range.addEventListener('input', function () { update(range.value); });
        if (num)   num.addEventListener('input',   function () { update(num.value); });
        update((range && range.value) || (num && num.value) || 50);
    }

    // --- live search (rules) ---
    var search = document.querySelector('[data-live-search]');
    if (search) {
        var input   = search.querySelector('input[type="search"]');
        var results = search.querySelector('[data-results]');
        var endpoint = search.getAttribute('data-endpoint') || '/dashboard/search.php';
        var t = null;
        input.addEventListener('input', function () {
            clearTimeout(t);
            var q = input.value.trim();
            if (q.length < 2) { results.innerHTML = ''; return; }
            t = setTimeout(function () {
                fetch(endpoint + '?q=' + encodeURIComponent(q) + '&ajax=1', { credentials: 'same-origin' })
                    .then(function (r) { return r.text(); })
                    .then(function (html) { results.innerHTML = html; })
                    .catch(function () { /* ignore */ });
            }, 200);
        });
    }

    // --- step nav for signup ---
    var stepper = document.querySelector('[data-stepper]');
    if (stepper) {
        var steps = stepper.querySelectorAll('[data-step]');
        var panels = document.querySelectorAll('[data-step-panel]');
        function show(idx) {
            steps.forEach(function (s, i) {
                s.classList.toggle('is-active', i === idx);
                s.classList.toggle('is-done', i < idx);
            });
            panels.forEach(function (p, i) {
                p.hidden = i !== idx;
            });
        }
        document.querySelectorAll('[data-step-next]').forEach(function (btn) {
            btn.addEventListener('click', function (ev) {
                var panel = btn.closest('[data-step-panel]');
                var inputs = panel.querySelectorAll('input[required], select[required]');
                for (var i = 0; i < inputs.length; i++) {
                    if (!inputs[i].value.trim()) {
                        inputs[i].reportValidity();
                        ev.preventDefault();
                        return;
                    }
                    if (inputs[i].type === 'email' && !inputs[i].checkValidity()) {
                        inputs[i].reportValidity();
                        ev.preventDefault();
                        return;
                    }
                }
                ev.preventDefault();
                show(parseInt(btn.getAttribute('data-step-next'), 10));
            });
        });
        document.querySelectorAll('[data-step-prev]').forEach(function (btn) {
            btn.addEventListener('click', function (ev) {
                ev.preventDefault();
                show(parseInt(btn.getAttribute('data-step-prev'), 10));
            });
        });
        show(0);
    }

})();
