// BadassHOA global JS
(function () {
    'use strict';

    // --- rule body expand-on-click ---
    // Long rule bodies get clamped to 4 lines via CSS; here we add a
    // "Show more" button only when the content actually overflows.
    document.querySelectorAll('.rule-body-clamp').forEach(function (el) {
        if (el.scrollHeight > el.clientHeight + 2) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'rule-body-toggle';
            btn.textContent = 'Show more';
            btn.addEventListener('click', function () {
                var expanded = el.classList.toggle('is-expanded');
                btn.textContent = expanded ? 'Show less' : 'Show more';
            });
            if (el.parentNode) el.parentNode.insertBefore(btn, el.nextSibling);
        }
    });

    // --- public site mobile nav toggle ---
    document.querySelectorAll('.nav-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var links = document.getElementById('site-nav-links');
            if (!links) return;
            var isOpen = links.classList.toggle('is-open');
            btn.classList.toggle('is-open', isOpen);
            btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    });
    document.addEventListener('click', function (ev) {
        var openLinks = document.querySelector('.site-nav__links.is-open');
        if (!openLinks) return;
        if (openLinks.contains(ev.target)) return;
        if (ev.target.closest('.nav-toggle')) return;
        openLinks.classList.remove('is-open');
        document.querySelectorAll('.nav-toggle.is-open').forEach(function (b) {
            b.classList.remove('is-open');
            b.setAttribute('aria-expanded', 'false');
        });
    });

    // --- Address autocomplete + ZIP lookup ---
    // Opt-in: add data-address-lookup to a form that has any of:
    //   input[name="address"]      → street autocomplete (Photon, OSM-backed, free)
    //   input[name="postal_code"]  → ZIP→city/state fallback (Zippopotam.us, free)
    //   input[name="city"], [name="state_region"], [name="country"]  → fields filled by both
    document.querySelectorAll('[data-address-lookup], [data-zip-lookup]').forEach(function (root) {
        var addr      = root.querySelector('input[name="address"]');
        var postal    = root.querySelector('input[name="postal_code"]');
        var city      = root.querySelector('input[name="city"]');
        var state     = root.querySelector('input[name="state_region"]');
        var countryEl = root.querySelector('[name="country"]');

        function htmlEscape(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
            });
        }

        function setCountry(code) {
            if (!countryEl || !code) return;
            var up = String(code).toUpperCase();
            if (countryEl.tagName === 'SELECT') {
                for (var i = 0; i < countryEl.options.length; i++) {
                    if (countryEl.options[i].value === up) { countryEl.value = up; return; }
                }
            } else {
                countryEl.value = up;
            }
        }

        // ===== Street-address autocomplete via Photon =====
        if (addr) {
            // Wrap the input so the dropdown can be absolutely positioned to it
            var wrap = addr.parentNode;
            if (!wrap.classList.contains('addr-wrap')) {
                wrap.classList.add('addr-wrap');
            }
            var dropdown = document.createElement('div');
            dropdown.className = 'addr-dropdown';
            dropdown.setAttribute('role', 'listbox');
            wrap.appendChild(dropdown);

            var debounceTimer = null;
            var lastQuery = '';

            addr.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                var q = addr.value.trim();
                if (q.length < 3) { dropdown.classList.remove('is-open'); dropdown.innerHTML = ''; return; }
                if (q === lastQuery) return;
                debounceTimer = setTimeout(function () { runQuery(q); }, 220);
            });

            addr.addEventListener('blur', function () {
                // Slight delay so click on a suggestion can register before we close
                setTimeout(function () { dropdown.classList.remove('is-open'); }, 150);
            });

            addr.addEventListener('focus', function () {
                if (dropdown.children.length) dropdown.classList.add('is-open');
            });

            async function runQuery(q) {
                lastQuery = q;
                try {
                    var url = 'https://photon.komoot.io/api/?q=' + encodeURIComponent(q) + '&limit=6';
                    var res = await fetch(url);
                    if (!res.ok) return;
                    var data = await res.json();
                    renderSuggestions(data.features || []);
                } catch (e) { /* silent — manual entry still works */ }
            }

            function renderSuggestions(features) {
                if (!features.length) { dropdown.classList.remove('is-open'); dropdown.innerHTML = ''; return; }
                dropdown.innerHTML = '';
                features.forEach(function (f) {
                    var p = f.properties || {};
                    var streetParts = [p.housenumber, p.street].filter(Boolean);
                    var line1 = streetParts.length ? streetParts.join(' ') : (p.name || '');
                    if (!line1) line1 = '(unnamed)';
                    var locality = p.city || p.town || p.village || p.suburb || '';
                    var line2 = [locality, p.state, p.postcode].filter(Boolean).join(', ');
                    if (p.country) line2 += (line2 ? ' · ' : '') + p.country;

                    var item = document.createElement('button');
                    item.type = 'button';
                    item.className = 'addr-dropdown__item';
                    item.setAttribute('role', 'option');
                    item.innerHTML = '<strong>' + htmlEscape(line1) + '</strong>'
                                   + '<span class="addr-dropdown__sub">' + htmlEscape(line2) + '</span>';
                    item.addEventListener('mousedown', function (ev) {
                        // mousedown (not click) fires before the input's blur — prevents the dropdown closing first
                        ev.preventDefault();
                        applyPlace(p);
                        dropdown.classList.remove('is-open');
                    });
                    dropdown.appendChild(item);
                });
                dropdown.classList.add('is-open');
            }

            function applyPlace(p) {
                var streetParts = [p.housenumber, p.street].filter(Boolean);
                if (streetParts.length) {
                    addr.value = streetParts.join(' ');
                } else if (p.name) {
                    addr.value = p.name;
                }
                if (city)   city.value   = p.city || p.town || p.village || p.suburb || '';
                if (state)  state.value  = p.state || '';
                if (postal) postal.value = p.postcode || '';
                setCountry(p.countrycode);
                lastQuery = addr.value.trim();
            }

            // Close on outside click
            document.addEventListener('click', function (ev) {
                if (!wrap.contains(ev.target)) dropdown.classList.remove('is-open');
            });
        }

        // ===== ZIP fallback via Zippopotam.us =====
        if (postal) {
            var lastZip = null;
            async function zipLookup() {
                var zip = (postal.value || '').trim();
                if (!zip || zip === lastZip) return;
                var cc = ((countryEl && countryEl.value) || 'US').toLowerCase();
                if (cc !== 'us' && cc !== 'ca') return;
                var lookupZip;
                if (cc === 'us') {
                    if (!/^\d{5}/.test(zip)) return;
                    lookupZip = zip.match(/^\d{5}/)[0];
                } else {
                    if (!/^[A-Za-z]\d[A-Za-z]/.test(zip)) return;
                    lookupZip = zip.replace(/\s+/g, '').substring(0, 3);
                }
                lastZip = zip;
                try {
                    var res = await fetch('https://api.zippopotam.us/' + cc + '/' + encodeURIComponent(lookupZip));
                    if (!res.ok) return;
                    var data = await res.json();
                    var place = (data.places || [])[0];
                    if (!place) return;
                    if (city  && !city.value.trim())  city.value  = place['place name'] || '';
                    if (state && !state.value.trim()) state.value = place['state']      || '';
                } catch (e) { /* silent */ }
            }
            postal.addEventListener('blur', zipLookup);
            postal.addEventListener('change', zipLookup);
        }
    });

    // --- left sidebar (dashboard + admin) ---
    var sideNav    = document.getElementById('side-nav');
    var sideToggle = document.getElementById('side-nav-toggle');
    var mobileBtn  = document.getElementById('mobile-menu-btn');
    var overlay    = document.getElementById('side-nav-overlay');
    var body       = document.body;

    if (sideNav) {
        function setSideToggleLabel(collapsed) {
            if (!sideToggle) return;
            var label = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
            sideToggle.setAttribute('aria-label', label);
            sideToggle.setAttribute('title', label);
        }

        // Restore collapsed state (pre-paint hint already applied padding)
        try {
            if (localStorage.getItem('sideNavCollapsed') === '1') {
                sideNav.classList.add('collapsed');
                body.classList.add('nav-collapsed');
                setSideToggleLabel(true);
            }
        } catch (_) {}

        if (sideToggle) {
            sideToggle.addEventListener('click', function () {
                var collapsed = sideNav.classList.toggle('collapsed');
                body.classList.toggle('nav-collapsed', collapsed);
                // The pre-paint script sets html[data-nav-collapsed="1"] on
                // initial load to avoid a flash of the wide sidebar. The CSS
                // rules that match that attribute have higher specificity than
                // the .collapsed class rules, so we MUST toggle the attribute
                // here too — otherwise removing .collapsed alone won't shrink
                // the body padding back, and the sidebar appears stuck narrow.
                if (collapsed) {
                    document.documentElement.dataset.navCollapsed = '1';
                } else {
                    delete document.documentElement.dataset.navCollapsed;
                }
                setSideToggleLabel(collapsed);
                try { localStorage.setItem('sideNavCollapsed', collapsed ? '1' : '0'); } catch (_) {}
            });
        }

        if (mobileBtn) {
            mobileBtn.addEventListener('click', function () {
                sideNav.classList.toggle('mobile-open');
            });
        }
        if (overlay) {
            overlay.addEventListener('click', function () {
                sideNav.classList.remove('mobile-open');
            });
        }

        // Close mobile nav on link tap
        sideNav.querySelectorAll('.side-nav__link').forEach(function (a) {
            a.addEventListener('click', function () {
                sideNav.classList.remove('mobile-open');
            });
        });
    }

    // --- pricing calculator ---
    var calc = document.querySelector('[data-calc]');
    if (calc) {
        var range = calc.querySelector('input[type="range"]');
        var num   = calc.querySelector('input[type="number"]');
        var price = calc.querySelector('[data-calc-price]');
        var tier  = calc.querySelector('[data-calc-tier]');
        var crumb     = calc.querySelector('[data-calc-breakdown]');
        var ctaBtn    = calc.querySelector('[data-calc-cta]');
        var unitCount = calc.querySelector('[data-calc-unit-count]');

        // 30-day free trial. Two pricing bands (Professional removed
        // 2026-05-13 — was superfluous):
        //   1-20   → $20 flat (Starter)
        //   21+    → $20 base + $0.50 per unit over 20 (Growth)
        function tierFor(units) {
            if (units <= 20) return { name: 'Starter', price: 20,                          cta: 'Start 30-day free trial' };
            return                  { name: 'Growth',  price: 20 + 0.50 * (units - 20),    cta: 'Start 30-day free trial' };
        }

        function fmt(n) {
            return '$' + n.toFixed(2).replace(/\.00$/, '');
        }

        function clampUnits(v) {
            var n = parseInt(v, 10);
            if (isNaN(n)) return 50;
            return Math.max(1, Math.min(2000, n));
        }

        function render(units) {
            var t = tierFor(units);
            if (unitCount) unitCount.textContent = units;
            calc.setAttribute('data-plan', units <= 20 ? 'starter' : 'growth');
            if (tier)   tier.textContent  = t.name;
            if (price)  price.textContent = fmt(t.price) + '/mo';
            if (ctaBtn) ctaBtn.textContent = t.cta;
            if (crumb) {
                if (units <= 20) {
                    crumb.textContent = 'Starter: $20/mo flat for up to 20 units. 30-day free trial included.';
                } else {
                    crumb.textContent = '$20 base + $0.50 × ' + (units - 20) + ' units over 20 = ' + fmt(t.price) + '/mo. 30-day free trial.';
                }
            }
        }

        // Slider drag: full sync (slider always has a value, so safe to write back).
        if (range) range.addEventListener('input', function () {
            var u = clampUnits(range.value);
            if (num) num.value = u;
            range.value = u;
            render(u);
        });

        // Number field: don't write back to num.value during typing — that would
        // truncate multi-digit input or block the user from clearing the field.
        if (num) {
            num.addEventListener('input', function () {
                if (num.value === '') return; // let user keep typing / retyping
                var n = parseInt(num.value, 10);
                if (isNaN(n)) return;
                var u = clampUnits(n);
                if (range) range.value = u;
                render(u);
            });
            num.addEventListener('blur', function () {
                if (num.value === '' || isNaN(parseInt(num.value, 10))) {
                    num.value = 50;
                    if (range) range.value = 50;
                    render(50);
                }
            });
        }

        // Initial paint.
        var initial = clampUnits((range && range.value) || (num && num.value) || 50);
        if (range) range.value = initial;
        if (num)   num.value   = initial;
        render(initial);
    }

    // --- live search (rules) ---
    var search = document.querySelector('[data-live-search]');
    if (search) {
        var input    = search.querySelector('input[type="search"]');
        var results  = search.querySelector('[data-results]');
        var endpoint = search.getAttribute('data-endpoint') || '/dashboard/search.php';
        var sourceSel = search.querySelector('select[name="source"]');
        var catSel    = search.querySelector('select[name="category"]');
        var t = null;
        function fire() {
            var q = input.value.trim();
            if (q.length < 2) { results.innerHTML = ''; return; }
            var qs = 'q=' + encodeURIComponent(q) + '&ajax=1';
            if (sourceSel && sourceSel.value) qs += '&source=' + encodeURIComponent(sourceSel.value);
            if (catSel    && catSel.value)    qs += '&category=' + encodeURIComponent(catSel.value);
            fetch(endpoint + '?' + qs, { credentials: 'same-origin' })
                .then(function (r) { return r.text(); })
                .then(function (html) { results.innerHTML = html; })
                .catch(function () { /* ignore */ });
        }
        input.addEventListener('input', function () { clearTimeout(t); t = setTimeout(fire, 200); });
        if (sourceSel) sourceSel.addEventListener('change', fire);
        if (catSel)    catSel.addEventListener('change', fire);
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
