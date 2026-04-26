/*!
 * DSR Widget v1.0 — embed at klien web for subject DSR submission.
 * White-label safe: serves from any host (klien on-prem, custom domain, localhost).
 *
 * Embed (replace YOUR-HOST with the actual Privasimu deployment URL —
 * dashboard "Integration Guide" generates the snippet pre-filled):
 *
 *   <script src="https://YOUR-HOST/dsr-widget.js"
 *           data-embed-token="..." async></script>
 *
 * Optional data attributes:
 *   data-button-text="🔒 Privacy Request"   (default)
 *   data-button-position="bottom-right"     (bottom-right|bottom-left|top-right|top-left)
 *   data-api-base="https://YOUR-HOST/api"   (override API base — defaults to script.src origin + /api)
 */
(function () {
    'use strict';

    var script = document.currentScript;
    if (!script) return; // legacy browser

    var token = script.getAttribute('data-embed-token');
    if (!token) {
        console.warn('[Privasimu DSR] Missing data-embed-token attribute.');
        return;
    }
    var buttonText = script.getAttribute('data-button-text') || '🔒 Privacy Request';
    var position   = script.getAttribute('data-button-position') || 'bottom-right';
    var apiBase    = (script.getAttribute('data-api-base') || (new URL(script.src).origin + '/api')).replace(/\/+$/, '');

    var REQUEST_TYPE_LABELS = {
        access:           'Akses Data Saya',
        correction:       'Koreksi Data',
        rectification:    'Koreksi Data',
        deletion:         'Hapus Data Saya',
        erasure:          'Hapus Data Saya',
        portability:      'Portabilitas Data',
        restriction:      'Pembatasan Pemrosesan',
        objection:        'Keberatan atas Pemrosesan',
        withdraw_consent: 'Tarik Persetujuan',
        info:             'Info Pemrosesan',
    };

    var state = { config: null, captchaToken: null, captchaWidgetId: null };

    // -----------------------------------------------------------------------
    // STYLES
    // -----------------------------------------------------------------------
    var styles = ''
        + '#pp-dsr-btn{position:fixed;z-index:2147483646;border:0;border-radius:24px;padding:12px 18px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-size:13px;font-weight:600;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,.18);transition:transform .15s ease,box-shadow .15s ease;background:var(--pp-primary,#0f172a);color:#fff}'
        + '#pp-dsr-btn:hover{transform:translateY(-1px);box-shadow:0 6px 22px rgba(0,0,0,.22)}'
        + '.pp-dsr-pos-bottom-right{bottom:20px;right:20px}'
        + '.pp-dsr-pos-bottom-left{bottom:20px;left:20px}'
        + '.pp-dsr-pos-top-right{top:20px;right:20px}'
        + '.pp-dsr-pos-top-left{top:20px;left:20px}'
        + '#pp-dsr-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:2147483647;display:none;align-items:flex-start;justify-content:center;padding:24px 12px;overflow-y:auto}'
        + '#pp-dsr-overlay.open{display:flex}'
        + '#pp-dsr-modal{background:#fff;border-radius:14px;max-width:520px;width:100%;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;color:#0f172a;box-shadow:0 30px 80px rgba(15,23,42,.35);overflow:hidden;margin-top:32px}'
        + '#pp-dsr-modal .pp-head{background:var(--pp-primary,#0f172a);color:#fff;padding:16px 22px;display:flex;justify-content:space-between;align-items:center}'
        + '#pp-dsr-modal .pp-head h2{margin:0;font-size:16px;font-weight:700}'
        + '#pp-dsr-modal .pp-head .pp-x{background:transparent;border:0;color:#fff;font-size:24px;cursor:pointer;line-height:1;padding:0 6px}'
        + '#pp-dsr-modal .pp-body{padding:20px 22px}'
        + '#pp-dsr-modal label{display:block;margin:12px 0 4px;font-size:12px;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.5px}'
        + '#pp-dsr-modal input,#pp-dsr-modal select,#pp-dsr-modal textarea{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;font-family:inherit;box-sizing:border-box}'
        + '#pp-dsr-modal textarea{min-height:80px;resize:vertical}'
        + '#pp-dsr-modal .pp-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}'
        + '#pp-dsr-modal .pp-actions{margin-top:18px;display:flex;justify-content:flex-end;gap:8px}'
        + '#pp-dsr-modal button.pp-btn{padding:10px 18px;border-radius:6px;font-weight:700;font-size:14px;cursor:pointer;border:0}'
        + '#pp-dsr-modal button.pp-cancel{background:#e2e8f0;color:#334155}'
        + '#pp-dsr-modal button.pp-submit{background:var(--pp-accent,#0ea5e9);color:#fff}'
        + '#pp-dsr-modal button.pp-submit:disabled{opacity:.5;cursor:not-allowed}'
        + '#pp-dsr-modal .pp-msg{margin-top:14px;padding:12px;border-radius:6px;font-size:13px;display:none}'
        + '#pp-dsr-modal .pp-msg.ok{background:#dcfce7;color:#166534;display:block}'
        + '#pp-dsr-modal .pp-msg.err{background:#fee2e2;color:#991b1b;display:block}'
        + '#pp-dsr-modal .pp-foot{font-size:11px;color:#94a3b8;text-align:center;padding:12px;border-top:1px solid #e2e8f0}'
        + '#pp-dsr-modal .pp-foot a{color:inherit;text-decoration:underline}'
        + '#pp-dsr-modal .pp-captcha{margin-top:12px;min-height:30px}';

    function injectStyles() {
        var s = document.createElement('style');
        s.id = 'pp-dsr-styles';
        s.textContent = styles;
        document.head.appendChild(s);
    }

    // -----------------------------------------------------------------------
    // ELEMENTS
    // -----------------------------------------------------------------------
    function buildButton(branding) {
        var btn = document.createElement('button');
        btn.id = 'pp-dsr-btn';
        btn.className = 'pp-dsr-pos-' + position;
        btn.textContent = buttonText;
        if (branding && branding.primary_color) {
            btn.style.setProperty('--pp-primary', branding.primary_color);
        }
        btn.addEventListener('click', openModal);
        document.body.appendChild(btn);
    }

    function buildModal(config) {
        var overlay = document.createElement('div');
        overlay.id = 'pp-dsr-overlay';
        overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });

        var modal = document.createElement('div');
        modal.id = 'pp-dsr-modal';
        if (config.branding && config.branding.primary_color) {
            modal.style.setProperty('--pp-primary', config.branding.primary_color);
        }
        if (config.branding && config.branding.accent_color) {
            modal.style.setProperty('--pp-accent', config.branding.accent_color);
        }

        // Dedupe by display label — backend keeps GDPR aliases (rectification/erasure)
        // for backwards-compat, but UI hanya tampilkan 1 entry per label biar tidak ada
        // "Koreksi Data" muncul 2x.
        var seenLabels = {};
        var typeOptions = (config.request_types || []).reduce(function (acc, t) {
            var label = REQUEST_TYPE_LABELS[t] || t;
            if (seenLabels[label]) return acc; // skip duplicate label
            seenLabels[label] = true;
            return acc + '<option value="' + t + '">' + label + '</option>';
        }, '');

        modal.innerHTML = ''
            + '<div class="pp-head">'
            +   '<h2>Permintaan Hak Subjek Data</h2>'
            +   '<button type="button" class="pp-x" aria-label="Tutup">&times;</button>'
            + '</div>'
            + '<div class="pp-body">'
            +   '<form id="pp-dsr-form" novalidate>'
            +     '<label>Jenis Permintaan</label>'
            +     '<select name="request_type" required>' + typeOptions + '</select>'
            +     '<div class="pp-row">'
            +       '<div><label>Nama Lengkap</label><input name="requester_name" required maxlength="200"></div>'
            +       '<div><label>Email</label><input name="requester_email" type="email" required maxlength="200"></div>'
            +     '</div>'
            +     '<div class="pp-row">'
            +       '<div><label>Telepon (opsional)</label><input name="requester_phone" maxlength="20"></div>'
            +       '<div><label>NIK (opsional)</label><input name="subject_data[nik]" maxlength="20"></div>'
            +     '</div>'
            +     '<label>Deskripsi / Detail</label>'
            +     '<textarea name="description" maxlength="5000" placeholder="Jelaskan permintaan Anda…"></textarea>'
            +     '<div class="pp-captcha" id="pp-dsr-captcha"></div>'
            +     '<div class="pp-msg" id="pp-dsr-msg"></div>'
            +     '<div class="pp-actions">'
            +       '<button type="button" class="pp-btn pp-cancel">Batal</button>'
            +       '<button type="submit" class="pp-btn pp-submit">Kirim Permintaan</button>'
            +     '</div>'
            +   '</form>'
            + '</div>'
            + buildFooter(config);

        overlay.appendChild(modal);
        document.body.appendChild(overlay);

        modal.querySelector('.pp-x').addEventListener('click', closeModal);
        modal.querySelector('.pp-cancel').addEventListener('click', closeModal);
        modal.querySelector('#pp-dsr-form').addEventListener('submit', onSubmit);

        if (config.captcha) renderCaptcha(config.captcha);
    }

    /**
     * Footer "Powered by" — driven by config.branding from server:
     *   show_powered_by: false → no footer at all (full white-label)
     *   powered_by_text: custom text (default: "Powered by Privasimu Nexus · UU PDP")
     *   powered_by_url:  custom link target (default: empty = no link)
     * Always shows a thin "compliance reference" line for legal traceability.
     */
    function buildFooter(cfg) {
        var b = (cfg && cfg.branding) || {};
        if (b.show_powered_by === false) return '';
        // Default = Privasimu Nexus logo. Klien override via branding.powered_by_logo.
        var logoUrl = b.powered_by_logo || (apiBase.replace(/\/api\/?$/, '') + '/nexus.png');
        var text = b.powered_by_text || 'Powered by';
        var url = b.powered_by_url || 'https://privasimu.com';
        var inner = '<span style="display:inline-flex;align-items:center;gap:6px;justify-content:center">'
                  +   '<span>' + escapeHtml(text) + '</span>'
                  +   '<img src="' + escapeHtml(logoUrl) + '" alt="Privasimu Nexus" style="height:18px;vertical-align:middle">'
                  + '</span>';
        var wrapped = url
            ? '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener" style="color:inherit;text-decoration:none">' + inner + '</a>'
            : inner;
        return '<div class="pp-foot">' + wrapped + '</div>';
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function openModal() { document.getElementById('pp-dsr-overlay').classList.add('open'); }
    function closeModal() { document.getElementById('pp-dsr-overlay').classList.remove('open'); resetMsg(); }

    function showMsg(text, kind) {
        var m = document.getElementById('pp-dsr-msg');
        m.textContent = text;
        m.className = 'pp-msg ' + (kind || 'err');
    }
    function resetMsg() {
        var m = document.getElementById('pp-dsr-msg');
        if (m) { m.className = 'pp-msg'; m.textContent = ''; }
    }

    // -----------------------------------------------------------------------
    // CAPTCHA — load provider script lazily
    // -----------------------------------------------------------------------
    function renderCaptcha(captchaCfg) {
        var slot = document.getElementById('pp-dsr-captcha');
        if (!slot) return;

        if (captchaCfg.provider === 'turnstile') {
            loadScript('https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', function () {
                if (!window.turnstile) return;
                state.captchaWidgetId = window.turnstile.render(slot, {
                    sitekey: captchaCfg.site_key,
                    callback: function (t) { state.captchaToken = t; },
                    'error-callback': function () { state.captchaToken = null; },
                    'expired-callback': function () { state.captchaToken = null; },
                });
            });
        } else if (captchaCfg.provider === 'hcaptcha') {
            loadScript('https://js.hcaptcha.com/1/api.js?render=explicit', function () {
                if (!window.hcaptcha) return;
                state.captchaWidgetId = window.hcaptcha.render(slot, {
                    sitekey: captchaCfg.site_key,
                    callback: function (t) { state.captchaToken = t; },
                    'expired-callback': function () { state.captchaToken = null; },
                });
            });
        } else if (captchaCfg.provider === 'recaptcha_v3') {
            loadScript('https://www.google.com/recaptcha/api.js?render=' + encodeURIComponent(captchaCfg.site_key), function () {
                // v3 is invisible — token fetched right before submit
                slot.innerHTML = '<div style="font-size:11px;color:#94a3b8">Verifikasi otomatis (reCAPTCHA v3)</div>';
            });
        }
    }

    function fetchCaptchaTokenIfNeeded(captchaCfg) {
        if (!captchaCfg) return Promise.resolve(null);
        if (captchaCfg.provider === 'recaptcha_v3' && window.grecaptcha) {
            return new Promise(function (resolve) {
                window.grecaptcha.ready(function () {
                    window.grecaptcha.execute(captchaCfg.site_key, { action: 'dsr_submit' })
                        .then(resolve)
                        .catch(function () { resolve(null); });
                });
            });
        }
        return Promise.resolve(state.captchaToken);
    }

    function loadScript(src, onload) {
        if (document.querySelector('script[data-pp-loaded="' + src + '"]')) { onload(); return; }
        var s = document.createElement('script');
        s.src = src; s.async = true; s.defer = true;
        s.setAttribute('data-pp-loaded', src);
        s.onload = onload;
        document.head.appendChild(s);
    }

    // -----------------------------------------------------------------------
    // SUBMIT
    // -----------------------------------------------------------------------
    function onSubmit(e) {
        e.preventDefault();
        resetMsg();
        var form = e.target;
        var btn = form.querySelector('.pp-submit');
        btn.disabled = true; btn.textContent = 'Mengirim...';

        fetchCaptchaTokenIfNeeded(state.config && state.config.captcha).then(function (captchaToken) {
            var fd = new FormData(form);
            var payload = {
                request_type: fd.get('request_type'),
                requester_name: fd.get('requester_name'),
                requester_email: fd.get('requester_email'),
                requester_phone: fd.get('requester_phone') || null,
                description: fd.get('description') || null,
                subject_data: { nik: fd.get('subject_data[nik]') || null },
                captcha_token: captchaToken || null,
            };

            return fetch(apiBase + '/public/dsr/submit/' + encodeURIComponent(token), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(payload),
            }).then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); });
        }).then(function (resp) {
            btn.disabled = false; btn.textContent = 'Kirim Permintaan';
            if (resp.status === 202) {
                showMsg('✓ Permintaan diterima (' + (resp.body.request_id || 'DSR') + '). Cek email Anda untuk verifikasi.', 'ok');
                setTimeout(closeModal, 4500);
            } else if (resp.status === 409) {
                showMsg('Anda sudah punya permintaan aktif: ' + (resp.body.existing_request_id || ''), 'err');
            } else if (resp.status === 429) {
                showMsg(resp.body.error || 'Terlalu banyak percobaan. Coba lagi nanti.', 'err');
            } else {
                showMsg(resp.body.error || resp.body.message || 'Gagal mengirim permintaan.', 'err');
                resetCaptcha();
            }
        }).catch(function (err) {
            btn.disabled = false; btn.textContent = 'Kirim Permintaan';
            showMsg('Network error: ' + (err && err.message ? err.message : 'unknown'), 'err');
        });
    }

    function resetCaptcha() {
        var p = state.config && state.config.captcha && state.config.captcha.provider;
        if (p === 'turnstile' && window.turnstile && state.captchaWidgetId !== null) {
            window.turnstile.reset(state.captchaWidgetId);
        } else if (p === 'hcaptcha' && window.hcaptcha && state.captchaWidgetId !== null) {
            window.hcaptcha.reset(state.captchaWidgetId);
        }
        state.captchaToken = null;
    }

    // -----------------------------------------------------------------------
    // BOOTSTRAP
    // -----------------------------------------------------------------------
    function init() {
        injectStyles();
        fetch(apiBase + '/public/dsr/config/' + encodeURIComponent(token), {
            headers: { 'Accept': 'application/json' },
            credentials: 'omit',
        }).then(function (r) {
            return r.json().then(function (j) { return { status: r.status, body: j }; });
        }).then(function (resp) {
            // Reject errors loud — don't silently render broken UI
            if (resp.status !== 200) {
                console.warn('[Privasimu DSR] Config request failed:', resp.status, resp.body);
                return;
            }
            var cfg = resp.body;
            // Defensive: kalau backend gak return request_types (broken response /
            // origin blocked), fallback ke daftar default supaya dropdown gak kosong
            if (!cfg.request_types || !Array.isArray(cfg.request_types) || cfg.request_types.length === 0) {
                console.warn('[Privasimu DSR] request_types empty in config — using fallback list');
                cfg.request_types = ['access', 'correction', 'deletion', 'portability', 'restriction', 'objection', 'withdraw_consent', 'info'];
            }
            state.config = cfg;
            buildButton(cfg.branding || {});
            buildModal(cfg);
        }).catch(function (err) {
            console.warn('[Privasimu DSR] Failed to load config:', err);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
