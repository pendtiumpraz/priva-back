/*!
 * Consent Banner Widget v1.0
 * Multi-mode (banner/modal/fullscreen/inline) cookie consent dengan UU PDP audit trail.
 * White-label safe — semua URL detect runtime dari script.src.
 *
 * Embed (replace YOUR-HOST + COLLECTION-ID):
 *
 *   <script src="https://YOUR-HOST/consent-banner.js"
 *           data-collection-id="..." async></script>
 *
 * Optional data attributes:
 *   data-api-base="https://YOUR-HOST/api"   (override API)
 *   data-mode="auto"          (auto = follow server config; or override: banner_bottom|banner_top|modal_center|fullscreen|inline)
 *   data-mount="#my-anchor"   (only used kalau mode=inline)
 *   data-locale="id"          (id|en, default id)
 *
 * Behavior:
 *   - Frequency `once`: simpan decision di localStorage selamanya
 *   - Frequency `session`: simpan di sessionStorage, re-prompt next browser session
 *   - Frequency `every_load`: tidak simpan, prompt setiap page load (compliance-strict)
 *
 *   Kalau user REJECT non-essential:
 *     1. Banner closed
 *     2. localStorage/sessionStorage `pp_consent_state` = {decision, items, ts}
 *     3. Dispatches CustomEvent 'privasimu:consent-decision' dengan detail = decision
 *     4. window.PrivasimuConsent.state() returns current state untuk klien JS
 *     5. Klien dengar event → enable/disable analytics scripts accordingly
 *
 * Public API (window.PrivasimuConsent):
 *   .show()        — paksa tampilkan banner (untuk "Manage Cookies" footer link)
 *   .reset()       — clear stored decision (next load akan re-prompt)
 *   .state()       — returns last saved decision
 *   .open()        — alias .show()
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
    var mountSelector = script.getAttribute('data-mount') || null;
    var locale = script.getAttribute('data-locale') || 'id';

    var STORAGE_KEY = 'pp_consent_' + collectionId;

    var T = {
        id: {
            title: 'Pengaturan Privasi & Cookies',
            intro: 'Kami menggunakan cookies untuk fungsi situs, analytics, dan personalisasi. Pilih preferensi Anda.',
            accept_all: 'Terima Semua',
            reject_optional: 'Tolak yang Opsional',
            customize: 'Atur Detail',
            save: 'Simpan Preferensi',
            required: '(Wajib)',
            powered: 'Powered by Privasimu',
            cat_essential: 'Esensial',
            cat_analytics: 'Analytics',
            cat_marketing: 'Marketing',
            cat_functional: 'Fungsional',
            cat_personalization: 'Personalisasi',
            cat_third_party: 'Pihak Ketiga',
            cat_other: 'Lainnya',
        },
        en: {
            title: 'Privacy & Cookie Preferences',
            intro: 'We use cookies for site function, analytics, and personalization. Pick your preferences.',
            accept_all: 'Accept All',
            reject_optional: 'Reject Optional',
            customize: 'Customize',
            save: 'Save Preferences',
            required: '(Required)',
            powered: 'Powered by Privasimu',
            cat_essential: 'Essential',
            cat_analytics: 'Analytics',
            cat_marketing: 'Marketing',
            cat_functional: 'Functional',
            cat_personalization: 'Personalization',
            cat_third_party: 'Third Party',
            cat_other: 'Other',
        },
    };
    var t = T[locale] || T.id;

    var state = { config: null, captchaToken: null, captchaWidgetId: null, expanded: false, choices: {} };

    // ----------- Storage helpers (frequency-aware) -----------
    function getStored() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY) || sessionStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    }
    function saveStored(decision, freq) {
        var payload = JSON.stringify(decision);
        try {
            if (freq === 'session') {
                sessionStorage.setItem(STORAGE_KEY, payload);
            } else if (freq === 'once') {
                localStorage.setItem(STORAGE_KEY, payload);
            }
            // every_load → tidak simpan, akan re-prompt setiap reload
        } catch (e) {}
    }
    function clearStored() {
        try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
        try { sessionStorage.removeItem(STORAGE_KEY); } catch (e) {}
    }

    // ----------- Audience filter -----------
    function shouldShow(audience) {
        // Heuristic: kalau ada auth_token / session di localStorage → user dianggap "logged in".
        // Klien bisa override dengan window.PrivasimuConsent.show() manual.
        var loggedIn = !!localStorage.getItem('auth_token') || !!localStorage.getItem('session_token') || !!localStorage.getItem('jwt');
        if (audience === 'anonymous_only' && loggedIn) return false;
        if (audience === 'logged_in_only' && !loggedIn) return false;
        return true;
    }

    // ----------- Styles -----------
    var STYLES = ''
        + '#pp-c-overlay,#pp-c-banner,#pp-c-inline{position:fixed;z-index:2147483646;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;color:#0f172a}'
        + '#pp-c-banner{left:0;right:0;background:#fff;border:1px solid #e2e8f0;box-shadow:0 -4px 24px rgba(15,23,42,.12);padding:18px 22px;display:flex;flex-direction:column;gap:12px;max-height:80vh;overflow-y:auto}'
        + '#pp-c-banner.bottom{bottom:0;border-radius:12px 12px 0 0}'
        + '#pp-c-banner.top{top:0;border-radius:0 0 12px 12px;box-shadow:0 4px 24px rgba(15,23,42,.12)}'
        + '#pp-c-overlay{inset:0;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:20px;overflow-y:auto}'
        + '#pp-c-overlay.fullscreen{background:#fff;align-items:flex-start;justify-content:center;padding:40px 20px}'
        + '#pp-c-modal{background:#fff;border-radius:14px;max-width:540px;width:100%;padding:24px 26px;display:flex;flex-direction:column;gap:14px;box-shadow:0 30px 80px rgba(15,23,42,.35)}'
        + '#pp-c-overlay.fullscreen #pp-c-modal{box-shadow:none;border:1px solid #e2e8f0;max-width:680px}'
        + '#pp-c-inline{position:relative;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:18px;display:flex;flex-direction:column;gap:12px;margin:12px 0}'
        + '.pp-c-h{font-size:16px;font-weight:800;margin:0;color:var(--pp-c-primary,#0f172a)}'
        + '.pp-c-sub{font-size:13px;color:#475569;line-height:1.55;margin:0}'
        + '.pp-c-actions{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end}'
        + '.pp-c-btn{padding:9px 16px;border-radius:6px;font-weight:700;font-size:13px;cursor:pointer;border:0;transition:transform .12s}'
        + '.pp-c-btn:hover{transform:translateY(-1px)}'
        + '.pp-c-primary{background:var(--pp-c-accent,#0ea5e9);color:#fff}'
        + '.pp-c-secondary{background:#e2e8f0;color:#334155}'
        + '.pp-c-link{background:transparent;color:var(--pp-c-accent,#0ea5e9);text-decoration:underline;padding:9px 6px}'
        + '.pp-c-items{display:flex;flex-direction:column;gap:8px;margin:6px 0;max-height:340px;overflow-y:auto;padding-right:4px}'
        + '.pp-c-item{display:flex;gap:10px;padding:10px 12px;border:1px solid #e2e8f0;border-radius:8px;align-items:flex-start}'
        + '.pp-c-item input{margin-top:2px;flex-shrink:0;transform:scale(1.15)}'
        + '.pp-c-item-meta{flex:1}'
        + '.pp-c-item-title{font-size:13px;font-weight:700}'
        + '.pp-c-item-desc{font-size:11px;color:#64748b;margin-top:3px;line-height:1.5}'
        + '.pp-c-item-cat{display:inline-block;font-size:9px;font-weight:700;text-transform:uppercase;padding:2px 6px;border-radius:8px;margin-left:6px;letter-spacing:.5px}'
        + '.pp-c-cat-essential{background:#dcfce7;color:#166534}'
        + '.pp-c-cat-analytics{background:#dbeafe;color:#1e40af}'
        + '.pp-c-cat-marketing{background:#fce7f3;color:#9f1239}'
        + '.pp-c-cat-functional{background:#fef3c7;color:#92400e}'
        + '.pp-c-cat-personalization{background:#f3e8ff;color:#6b21a8}'
        + '.pp-c-cat-third_party{background:#ffedd5;color:#9a3412}'
        + '.pp-c-cat-other{background:#f1f5f9;color:#475569}'
        + '.pp-c-foot{font-size:10px;color:#94a3b8;text-align:center;margin-top:8px}'
        + '.pp-c-msg{padding:8px 12px;border-radius:6px;font-size:12px;display:none}'
        + '.pp-c-msg.err{background:#fee2e2;color:#991b1b;display:block}'
        + '@media (max-width:480px){#pp-c-banner{padding:14px 16px}#pp-c-modal{padding:18px 20px}.pp-c-actions{justify-content:stretch;flex-direction:column}.pp-c-actions .pp-c-btn{width:100%}}';

    function injectStyles() {
        if (document.getElementById('pp-c-styles')) return;
        var s = document.createElement('style');
        s.id = 'pp-c-styles';
        s.textContent = STYLES;
        document.head.appendChild(s);
    }

    // ----------- Render (mode-aware) -----------
    function render() {
        var cfg = state.config;
        var col = cfg.collection || {};
        var mode = (modeOverride !== 'auto' ? modeOverride : col.display_mode) || 'banner_bottom';

        // Apply branding CSS vars
        var settings = col.settings || {};
        var primary = settings.primary_color || '#0f172a';
        var accent = settings.accent_color || '#0ea5e9';

        var bodyHtml = ''
            + '<h3 class="pp-c-h" style="color:' + primary + '">' + escapeHtml(t.title) + '</h3>'
            + '<p class="pp-c-sub">' + escapeHtml(t.intro) + '</p>'
            + (state.expanded ? renderItems(cfg.items) : '')
            + '<div class="pp-c-msg" id="pp-c-msg"></div>'
            + '<div class="pp-c-actions">'
            +   (state.expanded
                ? '<button type="button" class="pp-c-btn pp-c-secondary" data-act="reject">' + escapeHtml(t.reject_optional) + '</button>'
                  + '<button type="button" class="pp-c-btn pp-c-primary" data-act="save" style="background:' + accent + '">' + escapeHtml(t.save) + '</button>'
                : '<button type="button" class="pp-c-btn pp-c-link" data-act="customize">' + escapeHtml(t.customize) + '</button>'
                  + '<button type="button" class="pp-c-btn pp-c-secondary" data-act="reject">' + escapeHtml(t.reject_optional) + '</button>'
                  + '<button type="button" class="pp-c-btn pp-c-primary" data-act="accept_all" style="background:' + accent + '">' + escapeHtml(t.accept_all) + '</button>')
            + '</div>'
            + '<div class="pp-c-foot">' + escapeHtml(t.powered) + '</div>';

        var container;
        // Remove existing first
        ['pp-c-overlay', 'pp-c-banner', 'pp-c-inline'].forEach(function (id) {
            var e = document.getElementById(id);
            if (e) e.parentNode.removeChild(e);
        });

        if (mode === 'banner_bottom' || mode === 'banner_top') {
            container = document.createElement('div');
            container.id = 'pp-c-banner';
            container.className = mode === 'banner_top' ? 'top' : 'bottom';
            container.style.setProperty('--pp-c-primary', primary);
            container.style.setProperty('--pp-c-accent', accent);
            container.innerHTML = bodyHtml;
            document.body.appendChild(container);
        } else if (mode === 'modal_center' || mode === 'fullscreen') {
            container = document.createElement('div');
            container.id = 'pp-c-overlay';
            if (mode === 'fullscreen') container.className = 'fullscreen';
            var modal = document.createElement('div');
            modal.id = 'pp-c-modal';
            modal.style.setProperty('--pp-c-primary', primary);
            modal.style.setProperty('--pp-c-accent', accent);
            modal.innerHTML = bodyHtml;
            container.appendChild(modal);
            document.body.appendChild(container);
            // Block scroll behind modal
            document.documentElement.style.overflow = 'hidden';
        } else if (mode === 'inline') {
            var anchor = mountSelector ? document.querySelector(mountSelector) : document.body;
            if (!anchor) {
                console.warn('[Privasimu Consent] Inline mode but data-mount selector not found');
                return;
            }
            container = document.createElement('div');
            container.id = 'pp-c-inline';
            container.style.setProperty('--pp-c-primary', primary);
            container.style.setProperty('--pp-c-accent', accent);
            container.innerHTML = bodyHtml;
            anchor.appendChild(container);
        }

        wireEvents();
    }

    function renderItems(items) {
        if (!items || items.length === 0) return '';
        return '<div class="pp-c-items">' + items.map(function (item) {
            var checked = state.choices[item.id] || item.is_required;
            var disabled = item.is_required ? 'disabled checked' : (checked ? 'checked' : '');
            var cat = item.category || 'essential';
            return '<label class="pp-c-item">'
                + '<input type="checkbox" data-id="' + item.id + '" ' + disabled + '>'
                + '<div class="pp-c-item-meta">'
                +   '<span class="pp-c-item-title">' + escapeHtml(item.title)
                +     (item.is_required ? ' <span style="color:#16a34a;font-size:10px">' + escapeHtml(t.required) + '</span>' : '')
                +     '<span class="pp-c-item-cat pp-c-cat-' + cat + '">' + escapeHtml(t['cat_' + cat] || cat) + '</span>'
                +   '</span>'
                + (item.description ? '<div class="pp-c-item-desc">' + escapeHtml(item.description) + '</div>' : '')
                + '</div>'
                + '</label>';
        }).join('') + '</div>';
    }

    function wireEvents() {
        var root = document.getElementById('pp-c-overlay') || document.getElementById('pp-c-banner') || document.getElementById('pp-c-inline');
        if (!root) return;
        root.querySelectorAll('button[data-act]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var act = btn.getAttribute('data-act');
                if (act === 'customize') { state.expanded = true; render(); return; }
                if (act === 'accept_all') { commitDecision(allTrue()); return; }
                if (act === 'reject') { commitDecision(allRequiredOnly()); return; }
                if (act === 'save') { commitDecision(collectChoices()); return; }
            });
        });
        // Track item checkbox changes
        if (state.expanded) {
            root.querySelectorAll('input[type="checkbox"][data-id]').forEach(function (cb) {
                cb.addEventListener('change', function () {
                    state.choices[cb.getAttribute('data-id')] = cb.checked;
                });
            });
        }
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
    function collectChoices() {
        var c = {};
        (state.config.items || []).forEach(function (it) {
            c[it.id] = it.is_required ? true : !!state.choices[it.id];
        });
        return c;
    }

    // ----------- Commit decision (POST + storage + dispatch event) -----------
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
            captcha_token: state.captchaToken,
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
                showMsg((resp.body && (resp.body.error || resp.body.message)) || 'Gagal menyimpan preferensi', 'err');
            }
        }).catch(function (err) {
            showMsg('Network error: ' + (err && err.message ? err.message : 'unknown'), 'err');
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

    function showMsg(text, kind) {
        var m = document.getElementById('pp-c-msg');
        if (m) { m.textContent = text; m.className = 'pp-c-msg ' + (kind || 'err'); }
    }

    function cleanup() {
        ['pp-c-overlay', 'pp-c-banner', 'pp-c-inline'].forEach(function (id) {
            var e = document.getElementById(id);
            if (e) e.parentNode.removeChild(e);
        });
        document.documentElement.style.overflow = '';
    }

    function dispatchEvent(name, detail) {
        try {
            var evt = new CustomEvent(name, { detail: detail });
            window.dispatchEvent(evt);
        } catch (e) {
            // Legacy IE fallback omitted — IE11 EOL'd
        }
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // ----------- Public API -----------
    window.PrivasimuConsent = {
        show: function () { state.expanded = false; init(true); },
        open: function () { this.show(); },
        reset: function () { clearStored(); },
        state: function () { return getStored(); },
    };

    // ----------- Init -----------
    function init(force) {
        var stored = getStored();
        if (stored && !force) {
            // Decision already stored — just dispatch so klien JS can read state
            dispatchEvent('privasimu:consent-decision', stored);
            return;
        }

        // Fetch config (cookie filter — anonymous visitors)
        fetch(apiBase + '/public/consent/config?collection_id=' + encodeURIComponent(collectionId) + '&category_filter=cookie')
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.data) return;
                state.config = res.data;
                var col = state.config.collection || {};
                if (!shouldShow(col.audience || 'anonymous_only')) return;
                injectStyles();
                render();
            })
            .catch(function (err) {
                console.warn('[Privasimu Consent] Config load failed:', err);
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(false); });
    } else {
        init(false);
    }
})();
