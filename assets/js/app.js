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

    // ── Searchable select ────────────────────────────────────────────────────
    // Converts any <select class="js-searchable-select"> into a type-to-filter
    // combobox. The original <select> stays hidden for form submission.
    function initSearchableSelect(sel) {
        var opts = Array.from(sel.options).map(function (o) {
            return { value: o.value, label: o.text };
        });
        var selected = { value: sel.value, label: sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : '' };

        // wrapper
        var wrap = document.createElement('div');
        wrap.style.cssText = 'position:relative;';

        // text input shown to the user
        var inp = document.createElement('input');
        inp.type = 'text';
        inp.className = sel.className.replace('js-searchable-select', '').trim() || 'input';
        inp.autocomplete = 'off';
        inp.spellcheck = false;
        inp.setAttribute('role', 'combobox');
        inp.setAttribute('aria-expanded', 'false');
        inp.setAttribute('aria-haspopup', 'listbox');
        if (sel.id)       inp.setAttribute('aria-controls', sel.id + '-list');
        if (sel.required) inp.required = true;
        // copy relevant styling (width is inherited from the wrapper)
        inp.style.cssText = 'width:100%; box-sizing:border-box;';
        if (selected.value !== '') inp.value = selected.label;
        else inp.placeholder = selected.label || 'Search…';

        // dropdown list
        var list = document.createElement('ul');
        list.id = sel.id ? sel.id + '-list' : '';
        list.setAttribute('role', 'listbox');
        list.style.cssText = [
            'display:none',
            'position:absolute',
            'left:0',
            'right:0',
            'top:calc(100% + 2px)',
            'background:#fff',
            'border:1px solid var(--color-border,#d1d5db)',
            'border-radius:var(--r-md,6px)',
            'max-height:220px',
            'overflow-y:auto',
            'z-index:200',
            'list-style:none',
            'margin:0',
            'padding:4px 0',
            'box-shadow:0 6px 18px rgba(0,0,0,.12)',
        ].join(';');

        var activeIdx = -1;

        function renderList(q) {
            var q_lc = q.toLowerCase().trim();
            var filtered = opts.filter(function (o) {
                return o.value === '' || o.label.toLowerCase().includes(q_lc);
            });
            list.innerHTML = '';
            activeIdx = -1;
            filtered.forEach(function (o, i) {
                var li = document.createElement('li');
                li.setAttribute('role', 'option');
                li.dataset.value = o.value;
                li.dataset.label = o.label;
                li.style.cssText = 'padding:7px 12px;cursor:pointer;font-size:.875rem;line-height:1.4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
                // highlight match
                if (q_lc && o.value !== '') {
                    var idx = o.label.toLowerCase().indexOf(q_lc);
                    if (idx >= 0) {
                        li.innerHTML = e_html(o.label.slice(0, idx))
                            + '<strong>' + e_html(o.label.slice(idx, idx + q_lc.length)) + '</strong>'
                            + e_html(o.label.slice(idx + q_lc.length));
                    } else {
                        li.textContent = o.label;
                    }
                } else {
                    li.textContent = o.label;
                }
                if (o.value === sel.value) {
                    li.style.background = 'var(--color-navy-10,#eef2ff)';
                    li.setAttribute('aria-selected', 'true');
                }
                li.addEventListener('mouseenter', function () { setActive(i, filtered); });
                li.addEventListener('mouseleave', function () { clearActive(filtered); });
                li.addEventListener('mousedown', function (ev) {
                    ev.preventDefault();
                    choose(o);
                });
                list.appendChild(li);
            });
            list.style.display = filtered.length ? 'block' : 'none';
            inp.setAttribute('aria-expanded', filtered.length ? 'true' : 'false');
            return filtered;
        }

        function setActive(i, filtered) {
            clearActive(filtered);
            activeIdx = i;
            if (list.children[i]) {
                list.children[i].style.background = 'var(--color-navy-10,#eef2ff)';
                list.children[i].scrollIntoView({ block: 'nearest' });
            }
        }

        function clearActive(filtered) {
            if (activeIdx >= 0 && list.children[activeIdx]) {
                list.children[activeIdx].style.background =
                    filtered && filtered[activeIdx] && filtered[activeIdx].value === sel.value
                        ? 'var(--color-navy-10,#eef2ff)' : '';
            }
            activeIdx = -1;
        }

        function choose(o) {
            sel.value = o.value;
            inp.value = o.value === '' ? '' : o.label;
            if (o.value === '') inp.placeholder = o.label;
            list.style.display = 'none';
            inp.setAttribute('aria-expanded', 'false');
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function e_html(s) {
            return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        }

        inp.addEventListener('focus', function () { renderList(inp.value); });

        inp.addEventListener('input', function () { renderList(inp.value); });

        inp.addEventListener('keydown', function (ev) {
            var items = list.querySelectorAll('li');
            if (!items.length && ev.key !== 'Escape') return;
            if (ev.key === 'ArrowDown') {
                ev.preventDefault();
                var next = Math.min(activeIdx + 1, items.length - 1);
                var f = Array.from(items).map(function (li) { return { value: li.dataset.value, label: li.dataset.label }; });
                setActive(next, f);
            } else if (ev.key === 'ArrowUp') {
                ev.preventDefault();
                var prev = Math.max(activeIdx - 1, 0);
                var f2 = Array.from(items).map(function (li) { return { value: li.dataset.value, label: li.dataset.label }; });
                setActive(prev, f2);
            } else if (ev.key === 'Enter') {
                ev.preventDefault();
                if (activeIdx >= 0 && items[activeIdx]) {
                    choose({ value: items[activeIdx].dataset.value, label: items[activeIdx].dataset.label });
                }
            } else if (ev.key === 'Escape') {
                list.style.display = 'none';
                inp.setAttribute('aria-expanded', 'false');
            }
        });

        inp.addEventListener('blur', function () {
            setTimeout(function () {
                list.style.display = 'none';
                inp.setAttribute('aria-expanded', 'false');
                // if text doesn't match any option exactly, snap back to current select value
                var match = opts.find(function (o) { return o.label === inp.value; });
                if (!match) {
                    var cur = opts.find(function (o) { return o.value === sel.value; });
                    inp.value = cur && cur.value !== '' ? cur.label : '';
                    if (!inp.value) inp.placeholder = opts[0] ? opts[0].label : 'Search…';
                }
            }, 150);
        });

        // hide the real select
        sel.style.display = 'none';
        sel.insertAdjacentElement('beforebegin', wrap);
        wrap.appendChild(inp);
        wrap.appendChild(list);
        wrap.appendChild(sel);
    }

    window.initSearchableSelect = initSearchableSelect;
    document.querySelectorAll('select.js-searchable-select').forEach(initSearchableSelect);

})();
