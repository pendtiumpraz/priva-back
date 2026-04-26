/*!
 * Consent Banner Widget v2.0 — 2-layer OneTrust-style UX.
 *
 * Layer 1: Banner singkat di pojok (default bottom-right) dengan 3 button:
 *          [Pengaturan Cookie] [Tolak Semua] [Terima Semua Cookie]
 *
 * Layer 2: Modal tengah "Pusat Preferensi Privasi" dengan:
 *          - Accordion per category (Cookie Penargetan, Performa, dll)
 *          - Toggle switch ON/OFF (required = locked ON)
 *          - Footer: [Tolak Semua] [Konfirmasi Pilihan Saya]
 *
 * White-label safe — semua URL detect runtime dari script.src.
 *
 * Embed:
 *   <script src="https://YOUR-HOST/consent-banner.js"
 *           data-collection-id="..." async></script>
 *
 * Optional data attributes:
 *   data-api-base="https://..."  (override API)
 *   data-mode="auto"             (banner_bottom|banner_top|modal_center|fullscreen|inline)
 *   data-locale="id"             (id|en)
 *
 * Public API (window.PrivasimuConsent):
 *   .show()    — paksa tampilkan (untuk "Manage Cookies" footer link)
 *   .reset()   — hapus stored decision
 *   .state()   — baca decision terakhir
 */
(function () {
    'use strict';

    var script = document.currentScript;
    if (!script) return;

    var collectionId = script.getAttribute('data-collection-id');
    if (!collectionId) {
        console.warn('[Privasimu Consent] Missing data-collection-id');
        return;
    }
    var apiBase = (script.getAttribute('data-api-base') || (new URL(script.src).origin + '/api')).replace(/\/+$/, '');
    var modeOverride = script.getAttribute('data-mode') || 'auto';
    var localeOverride = script.getAttribute('data-locale') || null; // null = pakai server config
    var locale = localeOverride || 'id'; // initial; akan di-replace dari config setelah fetch

    var STORAGE_KEY = 'pp_consent_' + collectionId;
    var EXPANDED_CAT = {}; // { category: bool } — accordion state in modal

    var T = {
        id: {
            banner_text: 'Kami menggunakan cookie agar situs kami berfungsi dengan baik, mempersonalisasikan konten dan iklan, menyediakan fitur media sosial, dan menganalisis traffik kami. Kami juga berbagi informasi penggunaan situs kami oleh Anda dengan mitra media sosial, periklanan, dan analisis kami.',
            settings: 'Pengaturan Cookie',
            reject_all: 'Tolak Semua',
            accept_all: 'Terima Semua Cookie',
            modal_title: 'Pusat Preferensi Privasi',
            modal_intro: 'Saat Anda mengunjungi situs web mana pun, situs tersebut dapat menyimpan atau mengambil informasi di browser Anda, sebagian besar dalam bentuk cookie. Informasi ini dapat berupa Anda, preferensi Anda, atau perangkat Anda. Anda dapat mengatur preferensi cookie di bawah ini.',
            confirm: 'Konfirmasi Pilihan Saya',
            required_label: 'Selalu Aktif',
            powered: 'Powered by Privasimu',
            cookies_count: 'cookies',
        },
        en: {
            banner_text: 'We use cookies to make our site work properly, personalize content and ads, provide social media features, and analyze our traffic. We also share information about your use of our site with our social media, advertising, and analytics partners.',
            settings: 'Cookie Settings',
            reject_all: 'Reject All',
            accept_all: 'Accept All Cookies',
            modal_title: 'Privacy Preference Center',
            modal_intro: 'When you visit any website, it may store or retrieve information on your browser, mostly in the form of cookies. This information might be about you, your preferences, or your device. You can manage your cookie preferences below.',
            confirm: 'Confirm My Choices',
            required_label: 'Always Active',
            powered: 'Powered by Privasimu',
            cookies_count: 'cookies',
        },
    };
    var t = T[locale] || T.id;

    var CAT_LABELS_BY_LOCALE = {
        id: {
            essential: 'Cookie yang Sangat Diperlukan',
            functional: 'Cookie Fungsional',
            analytics: 'Cookie Performa',
            marketing: 'Cookie Penargetan',
            personalization: 'Cookie Personalisasi',
            third_party: 'Cookie Pihak Ketiga',
            other: 'Cookie Lainnya',
        },
        en: {
            essential: 'Strictly Necessary Cookies',
            functional: 'Functional Cookies',
            analytics: 'Performance Cookies',
            marketing: 'Targeting Cookies',
            personalization: 'Personalization Cookies',
            third_party: 'Third Party Cookies',
            other: 'Other Cookies',
        },
    };
    var CAT_LABELS = CAT_LABELS_BY_LOCALE[locale] || CAT_LABELS_BY_LOCALE.id;

    /** Refresh translations dictionary kalau config.collection.locale berubah */
    function applyLocale(newLocale) {
        if (!newLocale || !T[newLocale]) return;
        locale = newLocale;
        t = T[locale];
        CAT_LABELS = CAT_LABELS_BY_LOCALE[locale] || CAT_LABELS_BY_LOCALE.id;
    }

    var state = { config: null, choices: {} };

    // ----------- Storage (frequency-aware) -----------
    function getStored() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY) || sessionStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    }
    function saveStored(decision, freq) {
        var p = JSON.stringify(decision);
        try {
            if (freq === 'session') sessionStorage.setItem(STORAGE_KEY, p);
            else if (freq === 'once') localStorage.setItem(STORAGE_KEY, p);
        } catch (e) {}
    }
    function clearStored() {
        try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
        try { sessionStorage.removeItem(STORAGE_KEY); } catch (e) {}
    }

    function shouldShow(audience) {
        var loggedIn = !!localStorage.getItem('auth_token') || !!localStorage.getItem('session_token') || !!localStorage.getItem('jwt');
        if (audience === 'anonymous_only' && loggedIn) return false;
        if (audience === 'logged_in_only' && !loggedIn) return false;
        return true;
    }

    // ----------- Styles -----------
    var STYLES = ''
        // Layer 1 banner
        + '#pp-c-banner{position:fixed;z-index:2147483646;background:#fff;max-width:520px;border-radius:8px;box-shadow:0 12px 48px rgba(15,23,42,.18);font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;color:#0f172a;padding:18px 20px;display:flex;flex-direction:column;gap:14px;line-height:1.5;}'
        + '#pp-c-banner.bottom-right{bottom:20px;right:20px}'
        + '#pp-c-banner.bottom-left{bottom:20px;left:20px}'
        + '#pp-c-banner.bottom-center{bottom:20px;left:50%;transform:translateX(-50%)}'
        + '#pp-c-banner.top-right{top:20px;right:20px}'
        + '#pp-c-banner.top-left{top:20px;left:20px}'
        + '#pp-c-banner.full-bottom{bottom:0;left:0;right:0;max-width:none;border-radius:0;border-top:1px solid #e2e8f0;flex-direction:row;align-items:center;padding:14px 24px;}'
        + '#pp-c-banner .pp-c-text{font-size:13px;color:#334155;flex:1}'
        + '#pp-c-banner .pp-c-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}'
        + '#pp-c-banner.full-bottom .pp-c-actions{flex-shrink:0}'
        // Layer 2 modal
        + '#pp-c-overlay{position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:2147483647;display:flex;align-items:center;justify-content:center;padding:20px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;color:#0f172a}'
        + '#pp-c-modal{background:#fff;border-radius:10px;max-width:720px;width:100%;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 30px 80px rgba(15,23,42,.4)}'
        + '#pp-c-modal .pp-c-mhead{padding:20px 24px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:flex-start;gap:12px}'
        + '#pp-c-modal .pp-c-mtitle{font-size:18px;font-weight:800;margin:0;color:var(--pp-c-primary,#0f172a)}'
        + '#pp-c-modal .pp-c-mintro{font-size:13px;color:#475569;margin:6px 0 0;line-height:1.55}'
        + '#pp-c-modal .pp-c-mclose{background:transparent;border:0;cursor:pointer;font-size:24px;color:#94a3b8;line-height:1;padding:0;flex-shrink:0}'
        + '#pp-c-modal .pp-c-mbody{padding:0 24px;overflow-y:auto;flex:1}'
        + '#pp-c-modal .pp-c-cat{border-bottom:1px solid #e2e8f0;padding:14px 0}'
        + '#pp-c-modal .pp-c-cat:last-child{border-bottom:0}'
        + '#pp-c-modal .pp-c-cat-head{display:flex;align-items:center;gap:10px;cursor:pointer}'
        + '#pp-c-modal .pp-c-cat-toggle-wrap{margin-left:auto;display:flex;align-items:center;gap:8px}'
        + '#pp-c-modal .pp-c-cat-title{font-size:14px;font-weight:700;flex:1}'
        + '#pp-c-modal .pp-c-cat-meta{font-size:11px;color:#94a3b8;font-weight:500;margin-left:6px}'
        + '#pp-c-modal .pp-c-cat-arrow{transition:transform .15s;color:#94a3b8;font-weight:700}'
        + '#pp-c-modal .pp-c-cat-arrow.expanded{transform:rotate(180deg)}'
        + '#pp-c-modal .pp-c-cat-body{margin-top:10px;padding:0 8px 0 28px;font-size:12px;color:#475569;line-height:1.6;display:none}'
        + '#pp-c-modal .pp-c-cat-body.expanded{display:block}'
        + '#pp-c-modal .pp-c-item-row{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px dashed #e2e8f0}'
        + '#pp-c-modal .pp-c-item-row:last-child{border-bottom:0}'
        + '#pp-c-modal .pp-c-item-info{flex:1;min-width:0}'
        + '#pp-c-modal .pp-c-item-name{font-size:13px;font-weight:700;color:#0f172a;margin-bottom:3px}'
        + '#pp-c-modal .pp-c-item-desc{font-size:11.5px;color:#64748b;margin-top:2px;line-height:1.5}'
        + '#pp-c-modal .pp-c-item-cookies{font-size:10.5px;color:#94a3b8;margin-top:4px}'
        + '#pp-c-modal .pp-c-item-cookies code{background:#f1f5f9;padding:0 4px;border-radius:3px;font-size:10px}'
        + '#pp-c-modal .pp-c-item-control{flex-shrink:0;display:flex;align-items:center}'
        + '#pp-c-modal .pp-c-cat-body ul{margin:6px 0 0 16px;padding:0}'
        + '#pp-c-modal .pp-c-cat-body code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11px}'
        + '#pp-c-modal .pp-c-mfoot{padding:16px 24px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:8px}'
        // Required label (replaces toggle for essential)
        + '.pp-c-req-label{font-size:11px;font-weight:700;color:#16a34a;text-transform:uppercase;letter-spacing:.4px}'
        // Toggle switch
        + '.pp-c-switch{position:relative;display:inline-block;width:38px;height:22px}'
        + '.pp-c-switch input{opacity:0;width:0;height:0}'
        + '.pp-c-slider{position:absolute;cursor:pointer;inset:0;background:#cbd5e1;border-radius:22px;transition:.18s}'
        + '.pp-c-slider:before{position:absolute;content:"";height:16px;width:16px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.18s;box-shadow:0 1px 3px rgba(0,0,0,.2)}'
        + '.pp-c-switch input:checked + .pp-c-slider{background:var(--pp-c-accent,#0ea5e9)}'
        + '.pp-c-switch input:checked + .pp-c-slider:before{transform:translateX(16px)}'
        // Buttons
        + '.pp-c-btn{padding:9px 14px;border-radius:6px;font-weight:700;font-size:13px;cursor:pointer;border:0;transition:transform .12s,filter .12s;font-family:inherit}'
        + '.pp-c-btn:hover{transform:translateY(-1px);filter:brightness(1.05)}'
        + '.pp-c-primary{background:var(--pp-c-accent,#0ea5e9);color:#fff}'
        + '.pp-c-secondary{background:#fff;color:#334155;border:1px solid #cbd5e1}'
        + '.pp-c-link{background:transparent;color:var(--pp-c-accent,#0ea5e9);text-decoration:underline;padding:9px 6px}'
        // Footer powered-by
        + '.pp-c-foot{font-size:11px;color:#94a3b8;text-align:center;padding:8px 0 4px}'
        + '@media (max-width:540px){#pp-c-banner{max-width:none;left:8px!important;right:8px!important;transform:none!important}.pp-c-actions{justify-content:stretch}.pp-c-actions .pp-c-btn{flex:1;text-align:center}}';

    function injectStyles() {
        if (document.getElementById('pp-c-styles')) return;
        var s = document.createElement('style');
        s.id = 'pp-c-styles';
        s.textContent = STYLES;
        document.head.appendChild(s);
    }

    // ----------- Render Layer 1 (banner) -----------
    function renderBanner() {
        cleanup();
        var cfg = state.config;
        var col = cfg.collection || {};
        var settings = col.settings || {};
        var primary = settings.primary_color || '#0f172a';
        var accent = settings.accent_color || '#0ea5e9';

        // Use server-configured banner text if provided
        var bannerText = settings.banner_text || t.banner_text;

        var mode = (modeOverride !== 'auto' ? modeOverride : col.display_mode) || 'banner_bottom';
        var pos = mode === 'banner_top' ? 'top-right' : (mode === 'fullscreen' || mode === 'modal_center') ? null : 'bottom-right';
        // For fullscreen/modal_center mode → directly open Layer 2 modal as the entry point
        if (mode === 'modal_center' || mode === 'fullscreen') {
            renderModal(true);
            return;
        }

        var banner = document.createElement('div');
        banner.id = 'pp-c-banner';
        banner.className = pos;
        banner.style.setProperty('--pp-c-primary', primary);
        banner.style.setProperty('--pp-c-accent', accent);

        banner.innerHTML = ''
            + '<div class="pp-c-text">' + escapeHtml(bannerText) + '</div>'
            + '<div class="pp-c-actions">'
            +   '<button type="button" class="pp-c-btn pp-c-secondary" data-act="settings">' + escapeHtml(t.settings) + '</button>'
            +   '<button type="button" class="pp-c-btn pp-c-secondary" data-act="reject">' + escapeHtml(t.reject_all) + '</button>'
            +   '<button type="button" class="pp-c-btn pp-c-primary" data-act="accept">' + escapeHtml(t.accept_all) + '</button>'
            + '</div>'
            + footerHtml(cfg);

        document.body.appendChild(banner);

        banner.querySelectorAll('button[data-act]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var act = btn.getAttribute('data-act');
                if (act === 'accept') return commitDecision(allTrue());
                if (act === 'reject') return commitDecision(allRequiredOnly());
                if (act === 'settings') return renderModal(false);
            });
        });
    }

    // ----------- Render Layer 2 (modal — Pusat Preferensi Privasi) -----------
    function renderModal(removeBanner) {
        // Banner stays visible behind overlay unless explicitly removed
        if (removeBanner) cleanup();
        else {
            var b = document.getElementById('pp-c-banner');
            if (b) b.style.display = 'none';
        }
        // Prevent duplicate
        var existing = document.getElementById('pp-c-overlay');
        if (existing) existing.remove();

        var cfg = state.config;
        var col = cfg.collection || {};
        var settings = col.settings || {};
        var primary = settings.primary_color || '#0f172a';
        var accent = settings.accent_color || '#0ea5e9';

        // Group items by category
        var byCat = {};
        (cfg.items || []).forEach(function (item) {
            var cat = item.category || 'essential';
            if (!byCat[cat]) byCat[cat] = [];
            byCat[cat].push(item);
        });
        // Stable order
        var order = ['essential', 'functional', 'analytics', 'marketing', 'personalization', 'third_party', 'other'];
        var cats = order.filter(function (c) { return byCat[c]; });
        Object.keys(byCat).forEach(function (c) { if (cats.indexOf(c) < 0) cats.push(c); });

        // Initialize choices for required items
        cats.forEach(function (c) {
            byCat[c].forEach(function (item) {
                if (item.is_required) state.choices[item.id] = true;
                else if (state.choices[item.id] === undefined) state.choices[item.id] = false;
            });
        });

        var overlay = document.createElement('div');
        overlay.id = 'pp-c-overlay';

        var modal = document.createElement('div');
        modal.id = 'pp-c-modal';
        modal.style.setProperty('--pp-c-primary', primary);
        modal.style.setProperty('--pp-c-accent', accent);
        modal.onclick = function (e) { e.stopPropagation(); };

        var head = ''
            + '<div class="pp-c-mhead">'
            +   '<div>'
            +     '<h2 class="pp-c-mtitle">' + escapeHtml(t.modal_title) + '</h2>'
            +     '<p class="pp-c-mintro">' + escapeHtml(settings.modal_intro_text || t.modal_intro) + '</p>'
            +   '</div>'
            +   '<button type="button" class="pp-c-mclose" aria-label="Close" data-act="close">&times;</button>'
            + '</div>';

        var body = '<div class="pp-c-mbody">';
        cats.forEach(function (cat) {
            var label = (cfg.category_labels && cfg.category_labels[cat]) || CAT_LABELS[cat] || cat;
            var items = byCat[cat];
            var totalCookies = items.reduce(function (s, i) { return s + (Array.isArray(i.cookie_keys) ? i.cookie_keys.length : 0); }, 0);
            var expanded = !!EXPANDED_CAT[cat];
            var itemCount = items.length;

            // Per-CATEGORY accordion shows item count + total cookies. Expand to see PER-ITEM toggles.
            body += '<div class="pp-c-cat" data-cat="' + escapeAttr(cat) + '">'
                +     '<div class="pp-c-cat-head" data-act="toggle-cat" data-cat="' + escapeAttr(cat) + '" style="cursor:pointer">'
                +       '<span class="pp-c-cat-arrow' + (expanded ? ' expanded' : '') + '">▼</span>'
                +       '<div class="pp-c-cat-title">' + escapeHtml(label)
                +         '<span class="pp-c-cat-meta"> · ' + itemCount + ' ' + (itemCount === 1 ? 'item' : 'items')
                +         (totalCookies > 0 ? ' / ' + totalCookies + ' ' + escapeHtml(t.cookies_count) : '')
                +         '</span>'
                +       '</div>'
                +     '</div>'
                +     '<div class="pp-c-cat-body' + (expanded ? ' expanded' : '') + '">'
                +       (items.map(function (it) {
                            var keys = Array.isArray(it.cookie_keys) ? it.cookie_keys : [];
                            var checked = state.choices[it.id] ? 'checked' : '';
                            // Per-ITEM row: nama + deskripsi + cookies + toggle individual (atau "Selalu Aktif" lock)
                            return '<div class="pp-c-item-row">'
                                +    '<div class="pp-c-item-info">'
                                +      '<div class="pp-c-item-name">' + escapeHtml(it.title) + '</div>'
                                +      (it.description ? '<div class="pp-c-item-desc">' + escapeHtml(it.description) + '</div>' : '')
                                +      (keys.length > 0 ? '<div class="pp-c-item-cookies"><em>Cookies:</em> ' + keys.map(escapeHtml).join(', ') + '</div>' : '')
                                +    '</div>'
                                +    '<div class="pp-c-item-control">'
                                +      (it.is_required
                                          ? '<span class="pp-c-req-label">' + escapeHtml(t.required_label) + '</span>'
                                          : '<label class="pp-c-switch">'
                                            + '<input type="checkbox" data-item-toggle="' + escapeAttr(it.id) + '" ' + checked + '>'
                                            + '<span class="pp-c-slider"></span>'
                                            + '</label>')
                                +    '</div>'
                                +  '</div>';
                          }).join(''))
                +     '</div>'
                + '</div>';
        });
        body += '</div>';

        var foot = ''
            + '<div class="pp-c-mfoot">'
            +   '<button type="button" class="pp-c-btn pp-c-secondary" data-act="reject">' + escapeHtml(t.reject_all) + '</button>'
            +   '<button type="button" class="pp-c-btn pp-c-primary" data-act="confirm">' + escapeHtml(t.confirm) + '</button>'
            + '</div>'
            + footerHtml(state.config);

        modal.innerHTML = head + body + foot;
        overlay.appendChild(modal);
        overlay.onclick = function (e) {
            // Click outside modal closes (back to banner). Esc not handled to avoid accidents.
            if (e.target === overlay) {
                overlay.remove();
                var b = document.getElementById('pp-c-banner');
                if (b) b.style.display = '';
            }
        };
        document.body.appendChild(overlay);

        // Wire events
        modal.querySelectorAll('[data-act="toggle-cat"]').forEach(function (head) {
            head.addEventListener('click', function () {
                var cat = head.getAttribute('data-cat');
                EXPANDED_CAT[cat] = !EXPANDED_CAT[cat];
                var arrow = head.querySelector('.pp-c-cat-arrow');
                var bodyEl = modal.querySelector('.pp-c-cat[data-cat="' + cat + '"] .pp-c-cat-body');
                if (arrow) arrow.classList.toggle('expanded', EXPANDED_CAT[cat]);
                if (bodyEl) bodyEl.classList.toggle('expanded', EXPANDED_CAT[cat]);
            });
        });
        modal.querySelectorAll('input[data-item-toggle]').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var itemId = cb.getAttribute('data-item-toggle');
                state.choices[itemId] = cb.checked;
            });
        });
        modal.querySelectorAll('button[data-act]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var act = btn.getAttribute('data-act');
                if (act === 'close') {
                    overlay.remove();
                    var b = document.getElementById('pp-c-banner');
                    if (b) b.style.display = '';
                } else if (act === 'reject') {
                    commitDecision(allRequiredOnly());
                } else if (act === 'confirm') {
                    commitDecision(currentChoices());
                }
            });
        });
    }

    function allTrue() {
        var c = {};
        (state.config.items || []).forEach(function (it) { c[it.id] = true; });
        return c;
    }
    function allRequiredOnly() {
        var c = {};
        (state.config.items || []).forEach(function (it) { c[it.id] = !!it.is_required; });
        return c;
    }
    function currentChoices() {
        var c = {};
        (state.config.items || []).forEach(function (it) {
            c[it.id] = it.is_required ? true : !!state.choices[it.id];
        });
        return c;
    }

    function footerHtml(cfg) {
        var b = (cfg && cfg.collection && cfg.collection.settings) || {};
        if (b.show_powered_by === false) return '';
        // Default = Privasimu Nexus logo (non-inverted, color-on-light).
        // Klien bisa override pakai branding.powered_by_logo / powered_by_text / powered_by_url.
        var logoUrl = b.powered_by_logo || (apiBase.replace(/\/api\/?$/, '') + '/nexus.png');
        var text = b.powered_by_text || 'Powered by';
        var url = b.powered_by_url || 'https://privasimu.com';
        var inner = '<span style="display:inline-flex;align-items:center;gap:6px">'
                  +   '<span>' + escapeHtml(text) + '</span>'
                  +   '<img src="' + escapeAttr(logoUrl) + '" alt="Privasimu Nexus" style="height:18px;vertical-align:middle">'
                  + '</span>';
        var wrapped = url
            ? '<a href="' + escapeAttr(url) + '" target="_blank" rel="noopener" style="color:inherit;text-decoration:none">' + inner + '</a>'
            : inner;
        return '<div class="pp-c-foot">' + wrapped + '</div>';
    }

    // ----------- Commit decision -----------
    function commitDecision(consentedItems) {
        var col = state.config.collection;
        var freq = col.display_frequency || 'once';
        var decision = {
            items: consentedItems,
            policy_version: state.config.policy_version || '1.0',
            ts: Date.now(),
            decided_at: new Date().toISOString(),
        };

        var userId = getOrCreateUserId();
        var body = {
            collection_id: collectionId,
            user_identifier: userId,
            consented_items: consentedItems,
            policy_version: decision.policy_version,
        };

        fetch(apiBase + '/public/consent/capture', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(body),
        }).then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
          .then(function (resp) {
            if (resp.status === 201) {
                saveStored(decision, freq);
                cleanup();
                dispatchEvent('privasimu:consent-decision', decision);
            } else {
                console.warn('[Privasimu Consent] capture failed:', resp.body);
            }
        }).catch(function (err) {
            console.warn('[Privasimu Consent] network error:', err);
        });
    }

    function getOrCreateUserId() {
        var key = 'pp_user_id_' + collectionId;
        try {
            var existing = localStorage.getItem(key);
            if (existing) return existing;
            var newId = 'visitor_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);
            localStorage.setItem(key, newId);
            return newId;
        } catch (e) {
            return 'anonymous_' + Date.now();
        }
    }

    function cleanup() {
        ['pp-c-overlay', 'pp-c-banner'].forEach(function (id) {
            var e = document.getElementById(id);
            if (e) e.parentNode.removeChild(e);
        });
        document.documentElement.style.overflow = '';
    }

    function dispatchEvent(name, detail) {
        try { window.dispatchEvent(new CustomEvent(name, { detail: detail })); } catch (e) {}
    }
    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function escapeAttr(s) { return escapeHtml(s); }

    // ----------- Public API -----------
    window.PrivasimuConsent = {
        show: function () { state.choices = {}; init(true); },
        open: function () { this.show(); },
        reset: function () { clearStored(); },
        state: function () { return getStored(); },
    };

    // ----------- Init -----------
    function init(force) {
        var stored = getStored();
        if (stored && !force) {
            dispatchEvent('privasimu:consent-decision', stored);
            return;
        }

        fetch(apiBase + '/public/consent/config?collection_id=' + encodeURIComponent(collectionId) + '&category_filter=cookie')
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.data) return;
                state.config = res.data;
                var col = state.config.collection || {};
                // Apply server locale unless klien explicitly override via data-locale
                if (!localeOverride && col.locale) applyLocale(col.locale);
                if (!shouldShow(col.audience || 'anonymous_only')) return;
                injectStyles();
                renderBanner();
            })
            .catch(function (err) {
                console.warn('[Privasimu Consent] config load failed:', err);
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(false); });
    } else {
        init(false);
    }
})();
