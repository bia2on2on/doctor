(function () {
    'use strict';
    function start(CFG) {
    if (!CFG || !document.getElementById('cpms-hw-canvas')) { return null; }
    var listeners = new AbortController();

    // ================= State =================
    var state = {
        doc: null,            // {id, visit_id, patient_id, pages:[{id,page_index,...}]}
        current: null,        // صفحه فعال (merge سرور + local)
        strokes: [],          // Strokeهای صفحه فعال [{id,tool,color,size,points:[[x,y,p,ts]]}]
        baseRevision: 0,      // R — آخرین revision تاییدشده سرور (پایه Save بعدی)
        dirty: false,
        undoStack: [], redoStack: [],
        tool: 'pen', color: '#1a1a2e', size: 4,
        view: {scale: 1, tx: 0, ty: 0}, // Zoom/Pan (مختصات منطقی → صفحه)
        saving: false, syncState: 'loading',
        pending: null,           // تلاش Save جاری {key, clientRevision, snapshot}
        backoffIdx: 0, retryTimer: null,
        conflictServer: null, // وضعیت سرور از 409 (برای دیالوگ)
        template: 'lined', bgImage: null, bgAttachmentId: null,
        closed: false
    };
    var BACKOFF = [5000, 30000, 120000, 600000, 1800000]; // ADR-0014

    var canvas = document.getElementById('cpms-hw-canvas');
    var ctx = canvas.getContext('2d');
    var stage = document.getElementById('cpms-hw-stage');
    var $ = function (id) { return document.getElementById(id); };

    // ================= Utils =================
    function uuid() {
        if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }

    function apiUrl(path) {
        if (CFG.portal) {
            var base = 'doctor/portal/visits/' + encodeURIComponent(String(CFG.visit_id)) + '/handwriting';
            path = path.replace(/^handwriting\/documents\?visit_id=.*$/, base)
                .replace(/^handwriting\/documents$/, base)
                .replace(/^handwriting\/documents\/(\d+)\/pages$/, base + '/documents/$1/pages')
                .replace(/^handwriting\/pages\/(\d+)$/, base + '/pages/$1');
        }
        if (CFG.rest_url.indexOf('?') !== -1 && path.indexOf('?') !== -1) {
            return CFG.rest_url + path.replace('?', '&');
        }
        return CFG.rest_url + path;
    }

    function api(method, path, body, extraHeaders, raw) {
        var opts = { method: method, headers: { 'X-WP-Nonce': CFG.nonce } };
        if (body !== undefined && body !== null && !raw) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        } else if (body !== undefined && body !== null) {
            opts.body = body;
        }
        if (CFG.scope_headers) { Object.keys(CFG.scope_headers).forEach(function (k) { opts.headers[k] = CFG.scope_headers[k]; }); }
        if (extraHeaders) { Object.keys(extraHeaders).forEach(function (k) { opts.headers[k] = extraHeaders[k]; }); }
        return fetch(apiUrl(path), opts).then(function (r) {
            return r.json().then(function (j) { return { status: r.status, body: j }; }, function () {
                return { status: r.status, body: {} };
            });
        });
    }

    function errCode(resp) {
        return (resp && resp.body && resp.body.code) || '';
    }
    function errData(resp) {
        return (resp && resp.body && resp.body.data) || {};
    }

    // base64(gzip(JSON)) اگر CompressionStream موجود باشد؛ وگرنه base64(JSON خام)
    // (سرور magic \x1f\x8b را چک می‌کند — سازگاری Safari قدیمی).
    function encodeStrokes(strokes) {
        var json = JSON.stringify(strokes);
        if (window.CompressionStream && typeof CompressionStream === 'function') {
            var bytes = new TextEncoder().encode(json);
            var cs = new CompressionStream('gzip');
            var stream = new Blob([bytes]).stream().pipeThrough(cs);
            return new Response(stream).arrayBuffer().then(function (buf) {
                return { b64: bufToB64(buf), compressed: true };
            });
        }
        return Promise.resolve({ b64: b64EncodeUnicode(json), compressed: false });
    }

    function bufToB64(buf) {
        var u8 = new Uint8Array(buf), s = '';
        for (var i = 0; i < u8.length; i += 0x8000) {
            s += String.fromCharCode.apply(null, u8.subarray(i, i + 0x8000));
        }
        return btoa(s);
    }
    function b64EncodeUnicode(str) {
        return btoa(unescape(encodeURIComponent(str)));
    }

    // ================= IndexedDB (ADR-0014 — Offline همیشه ممکن) =================
    var idb = null;
    function idbOpen() {
        if (idb) { return Promise.resolve(idb); }
        return new Promise(function (resolve, reject) {
            var req = indexedDB.open('cpms-hw', 1);
            req.onupgradeneeded = function () {
                var db = req.result;
                if (!db.objectStoreNames.contains('pending')) {
                    db.createObjectStore('pending', { keyPath: 'page_id' });
                }
            };
            req.onsuccess = function () { idb = req.result; resolve(idb); };
            req.onerror = function () { reject(req.error); };
        });
    }
    function idbPut(rec) {
        return idbOpen().then(function (db) {
            return new Promise(function (res, rej) {
                var tx = db.transaction('pending', 'readwrite');
                tx.objectStore('pending').put(rec);
                tx.oncomplete = res; tx.onerror = function () { rej(tx.error); };
            });
        });
    }
    function idbGet(pageId) {
        return idbOpen().then(function (db) {
            return new Promise(function (res, rej) {
                var tx = db.transaction('pending', 'readonly');
                var rq = tx.objectStore('pending').get(pageId);
                rq.onsuccess = function () { res(rq.result || null); };
                rq.onerror = function () { rej(rq.error); };
            });
        });
    }
    function idbDelete(pageId) {
        return idbOpen().then(function (db) {
            return new Promise(function (res) {
                var tx = db.transaction('pending', 'readwrite');
                tx.objectStore('pending')['delete'](pageId);
                tx.oncomplete = res; tx.onerror = res;
            });
        });
    }

    // ================= Sync state UI =================
    function setSync(kind, text) {
        state.syncState = kind;
        var el = $('cpms-hw-sync');
        el.dataset.state = kind;
        el.textContent = text;
    }

    // ================= Load flow =================
    function boot() {
        api('GET', 'handwriting/documents?visit_id=' + CFG.visit_id).then(function (r) {
            if (r.status !== 200) { throw new Error('load'); }
            var doc = r.body.data && r.body.data.document;
            if (!doc) {
                // اولین بار: ایجاد سند با یک صفحه A4
                return api('POST', 'handwriting/documents', { visit_id: CFG.visit_id, pages: [{ width: 1240, height: 1754 }] })
                    .then(function (r2) {
                        if (r2.status !== 201) { throw new Error('create'); }
                        return r2.body.data;
                    });
            }
            return doc;
        }).then(function (doc) {
            state.doc = doc;
            renderPageTabs();
            var first = doc.pages && doc.pages[0];
            if (!first) { throw new Error('nopage'); }
            return openPage(first.id);
        }).catch(function () {
            setSync('failed', '⚠️ بارگذاری ناموفق — صفحه را نوسازی کنید');
        });
    }

    function openPage(pageId) {
        if (state.dirty) { localCheckpoint(); } // بدون از دست رفتن Stroke (D-24)
        state.current = null;
        state.strokes = []; state.undoStack = []; state.redoStack = [];
        state.conflictServer = null;
        state.pending = null; // تلاش Save صفحه قبلی باطل
        renderPageTabs();
        return api('GET', 'handwriting/pages/' + pageId).then(function (r) {
            if (r.status !== 200) { throw new Error('page'); }
            var page = r.body.data;
            return idbGet(page.id).then(function (local) {
                state.current = page;
                state.template = page.background_template || 'lined';
                state.bgAttachmentId = page.background_attachment_id || null;
                state.baseRevision = page.client_revision;
                $('cpms-hw-template').value = state.template;

                // Recovery آفلاین: نسخه Local جدیدتر از سرور → ادامه همان نسخه.
                if (local && local.client_revision > page.client_revision && local.strokes) {
                    state.strokes = local.strokes;
                    state.dirty = true;
                    setSync('offline', '📡 نسخه ذخیره‌شده محلی بازیابی شد — در انتظار Sync');
                    scheduleRetry(0);
                } else {
                    state.strokes = page.strokes || [];
                    state.dirty = false;
                    setSync('saved', '✅ ذخیره شد');
                }
                loadBgImage();
                fitView();
                draw();
            });
        }).catch(function () {
            setSync('failed', '⚠️ بارگذاری صفحه ناموفق');
        });
    }

    function renderPageTabs() {
        var nav = $('cpms-hw-pages');
        nav.innerHTML = '';
        (state.doc && state.doc.pages ? state.doc.pages : []).forEach(function (p, i) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'cpms-hw-page-tab' + (state.current && p.id === state.current.id ? ' is-active' : '');
            b.textContent = 'صفحه ' + (i + 1);
            b.addEventListener('click', function () { openPage(p.id); });
            nav.appendChild(b);
        });
    }

    // ================= Canvas rendering =================
    function resizeCanvas() {
        var dpr = window.devicePixelRatio || 1;
        canvas.width = Math.round(stage.clientWidth * dpr);
        canvas.height = Math.round(stage.clientHeight * dpr);
        draw();
    }

    // مختصات منطقی صفحه → CSS px
    function pageToScreen(x, y) {
        return [x * state.view.scale + state.view.tx, y * state.view.scale + state.view.ty];
    }
    function screenToPage(px, py) {
        return [(px - state.view.tx) / state.view.scale, (py - state.view.ty) / state.view.scale];
    }

    function fitView() {
        if (!state.current) { return; }
        var w = state.current.width, h = state.current.height;
        var sw = stage.clientWidth, sh = stage.clientHeight;
        var scale = Math.min((sw - 40) / w, (sh - 40) / h);
        state.view = { scale: scale, tx: (sw - w * scale) / 2, ty: (sh - h * scale) / 2 };
    }

    function draw() {
        var dpr = window.devicePixelRatio || 1;
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

        if (!state.current) { return; }
        var w = state.current.width, h = state.current.height;
        var o = pageToScreen(0, 0);
        var pw = w * state.view.scale, ph = h * state.view.scale;

        // کاغذ
        ctx.fillStyle = '#fff';
        ctx.fillRect(o[0], o[1], pw, ph);
        ctx.save();
        ctx.translate(o[0], o[1]);
        ctx.scale(state.view.scale, state.view.scale);
        drawTemplate(w, h);
        ctx.restore();

        // Strokeها — در مختصات منطقی (Pressure → ضخامت)
        ctx.save();
        ctx.translate(o[0], o[1]);
        ctx.scale(state.view.scale, state.view.scale);
        state.strokes.forEach(function (s) { drawStroke(ctx, s); });
        if (activeStroke) { drawStroke(ctx, activeStroke); }
        ctx.restore();

        // سایه صفحه
        ctx.strokeStyle = 'rgba(0,0,0,.4)';
        ctx.strokeRect(o[0], o[1], pw, ph);
    }

    function drawTemplate(w, h) {
        ctx.save();
        ctx.lineWidth = 1;
        if (state.bgImage) {
            ctx.drawImage(state.bgImage, 0, 0, w, h);
        } else if (state.template === 'lined') {
            ctx.strokeStyle = '#cfd8e3';
            for (var y = 120; y < h; y += 56) {
                ctx.beginPath(); ctx.moveTo(60, y); ctx.lineTo(w - 60, y); ctx.stroke();
            }
        } else if (state.template === 'graph') {
            ctx.strokeStyle = '#dfe6ef';
            for (var gx = 0; gx <= w; gx += 56) { ctx.beginPath(); ctx.moveTo(gx, 0); ctx.lineTo(gx, h); ctx.stroke(); }
            for (var gy = 0; gy <= h; gy += 56) { ctx.beginPath(); ctx.moveTo(0, gy); ctx.lineTo(w, gy); ctx.stroke(); }
        } else if (state.template === 'form') {
            ctx.strokeStyle = '#8a97a8';
            ctx.strokeRect(60, 60, w - 120, 110);
            ctx.beginPath(); ctx.moveTo(60, 130); ctx.lineTo(w - 60, 130); ctx.stroke();
            ctx.strokeStyle = '#cfd8e3';
            for (var fy = 220; fy < h - 60; fy += 56) { ctx.beginPath(); ctx.moveTo(60, fy); ctx.lineTo(w - 60, fy); ctx.stroke(); }
        }
        ctx.restore();
    }

    function drawStroke(c, s) {
        if (!s.points || !s.points.length) { return; }
        var alpha = s.tool === 'highlighter' ? 0.35 : 1;
        c.globalCompositeOperation = s.tool === 'highlighter' ? 'multiply' : 'source-over';
        c.strokeStyle = s.color || '#1a1a2e';
        c.globalAlpha = alpha;
        c.lineCap = 'round';
        c.lineJoin = 'round';
        if (s.points.length === 1) {
            var p0 = s.points[0];
            c.beginPath();
            c.arc(p0[0], p0[1], Math.max(0.6, (s.size || 4) * (p0[2] || 0.5)) / 2, 0, Math.PI * 2);
            c.fillStyle = s.color || '#1a1a2e';
            c.fill();
        } else {
            for (var i = 1; i < s.points.length; i++) {
                var a = s.points[i - 1], b = s.points[i];
                var press = Math.max(0.15, b[2] == null ? 0.5 : b[2]);
                c.lineWidth = Math.max(0.5, (s.size || 4) * press);
                c.beginPath();
                c.moveTo(a[0], a[1]);
                c.lineTo(b[0], b[1]);
                c.stroke();
            }
        }
        c.globalAlpha = 1;
        c.globalCompositeOperation = 'source-over';
    }

    // ================= Input — قلم/ماوس = رسم، لمس = pan/pinch (D-24) =================
    var activeStroke = null;
    var pointers = new Map(); // pointerId → {x,y}
    var pinch = null;

    function canvasPos(e) {
        var rect = canvas.getBoundingClientRect();
        return screenToPage(e.clientX - rect.left, e.clientY - rect.top);
    }

    canvas.addEventListener('pointerdown', function (e) {
        if (!state.current) { return; }
        canvas.setPointerCapture(e.pointerId);
        pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });

        // لمس یک‌انگشتی = جابجایی (Palm rejection: فقط pen/mouse رسم می‌کنند)
        if (e.pointerType === 'touch') {
            if (pointers.size === 2) { startPinch(); }
            return;
        }
        if (pointers.size > 1) { return; }
        var p = canvasPos(e);
        if (state.tool === 'eraser') {
            eraseAt(p[0], p[1]);
            return;
        }
        var press = e.pressure && e.pressure > 0 ? e.pressure : 0.5;
        activeStroke = {
            id: uuid(),
            tool: state.tool,
            color: state.color,
            size: state.size,
            points: [[p[0], p[1], press, Date.now()]]
        };
        draw();
    });

    canvas.addEventListener('pointermove', function (e) {
        if (!pointers.has(e.pointerId)) { return; }
        pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
        if (e.pointerType === 'touch') {
            if (pinch) { movePinch(); }
            else if (pointers.size === 1) { panBy(e.movementX || 0, e.movementY || 0); }
            return;
        }
        if (activeStroke) {
            var evs = e.getCoalescedEvents ? e.getCoalescedEvents() : [e];
            evs.forEach(function (ev) {
                var p = canvasPos(ev);
                var press = ev.pressure && ev.pressure > 0 ? ev.pressure : 0.5;
                var pts = activeStroke.points;
                var last = pts[pts.length - 1];
                if (Math.abs(p[0] - last[0]) + Math.abs(p[1] - last[1]) < 1.2) { return; } // نویز
                pts.push([p[0], p[1], press, Date.now()]);
            });
            draw();
        }
    });

    function endPointer(e) {
        pointers['delete'](e.pointerId);
        if (e.pointerType === 'touch') {
            if (pointers.size < 2) { pinch = null; }
            return;
        }
        if (activeStroke) {
            if (activeStroke.points.length === 1) {
                var d = activeStroke.points[0];
                activeStroke.points.push([d[0] + 0.8, d[1], d[2], Date.now()]); // نقطه → خط ریز
            }
            pushUndo();
            state.strokes.push(activeStroke);
            activeStroke = null;
            markDirty();
            draw();
        }
    }
    canvas.addEventListener('pointerup', endPointer);
    canvas.addEventListener('pointercancel', endPointer);

    function startPinch() {
        var pts = Array.from(pointers.values());
        pinch = {
            d: Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y),
            scale: state.view.scale,
            cx: (pts[0].x + pts[1].x) / 2, cy: (pts[0].y + pts[1].y) / 2
        };
    }
    function movePinch() {
        var pts = Array.from(pointers.values());
        if (pts.length < 2 || !pinch) { return; }
        var d = Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y);
        var factor = d / (pinch.d || 1);
        zoomAt(pinch.cx, pinch.cy, pinch.scale * factor);
    }
    function panBy(dx, dy) {
        state.view.tx += dx; state.view.ty += dy; draw();
    }

    stage.addEventListener('wheel', function (e) {
        e.preventDefault();
        var rect = canvas.getBoundingClientRect();
        zoomAt(e.clientX - rect.left, e.clientY - rect.top, state.view.scale * (e.deltaY < 0 ? 1.12 : 0.89));
    }, { passive: false });

    function zoomAt(cx, cy, target) {
        var t = Math.min(6, Math.max(0.2, target));
        var p = screenToPage(cx, cy);
        state.view.scale = t;
        state.view.tx = cx - p[0] * t;
        state.view.ty = cy - p[1] * t;
        draw();
    }

    // پاک‌کن سطح-Stroke: حذف کامل نزدیک‌ترین Stroke
    function eraseAt(x, y) {
        var r = 14 / state.view.scale;
        for (var i = state.strokes.length - 1; i >= 0; i--) {
            var s = state.strokes[i];
            for (var j = 0; j < s.points.length; j++) {
                if (Math.hypot(s.points[j][0] - x, s.points[j][1] - y) <= r + (s.size || 4)) {
                    pushUndo();
                    state.strokes.splice(i, 1);
                    markDirty();
                    draw();
                    return;
                }
            }
        }
    }

    // ================= Undo / Redo (کلاینتی) =================
    function pushUndo() {
        state.undoStack.push(JSON.stringify(state.strokes));
        if (state.undoStack.length > 40) { state.undoStack.shift(); }
        state.redoStack = [];
    }
    function undo() {
        if (!state.undoStack.length) { return; }
        state.redoStack.push(JSON.stringify(state.strokes));
        state.strokes = JSON.parse(state.undoStack.pop());
        markDirty(); draw();
    }
    function redo() {
        if (!state.redoStack.length) { return; }
        state.undoStack.push(JSON.stringify(state.strokes));
        state.strokes = JSON.parse(state.redoStack.pop());
        markDirty(); draw();
    }

    // ================= Tools UI =================
    Array.prototype.forEach.call(document.querySelectorAll('.cpms-hw-tool'), function (b) {
        b.addEventListener('click', function () {
            state.tool = b.dataset.tool;
            Array.prototype.forEach.call(document.querySelectorAll('.cpms-hw-tool'), function (x) { x.classList.remove('is-active'); });
            b.classList.add('is-active');
        });
    });
    Array.prototype.forEach.call(document.querySelectorAll('.cpms-hw-size'), function (b) {
        b.addEventListener('click', function () {
            state.size = parseInt(b.dataset.size, 10);
            Array.prototype.forEach.call(document.querySelectorAll('.cpms-hw-size'), function (x) { x.classList.remove('is-active'); });
            b.classList.add('is-active');
        });
    });
    Array.prototype.forEach.call(document.querySelectorAll('.cpms-hw-color'), function (b) {
        b.addEventListener('click', function () {
            state.color = b.dataset.color;
            Array.prototype.forEach.call(document.querySelectorAll('.cpms-hw-color'), function (x) { x.classList.remove('is-active'); });
            b.classList.add('is-active');
        });
    });
    $('cpms-hw-undo').addEventListener('click', undo);
    $('cpms-hw-redo').addEventListener('click', redo);
    $('cpms-hw-zin').addEventListener('click', function () { zoomAt(stage.clientWidth / 2, stage.clientHeight / 2, state.view.scale * 1.25); });
    $('cpms-hw-zout').addEventListener('click', function () { zoomAt(stage.clientWidth / 2, stage.clientHeight / 2, state.view.scale * 0.8); });
    $('cpms-hw-zreset').addEventListener('click', function () { fitView(); draw(); });
    $('cpms-hw-full').addEventListener('click', function () {
        var app = $('cpms-hw-app');
        if (document.fullscreenElement) { document.exitFullscreen(); }
        else if (app.requestFullscreen) { app.requestFullscreen(); }
    });
    document.addEventListener('fullscreenchange', function () { setTimeout(resizeCanvas, 60); }, { signal: listeners.signal });

    $('cpms-hw-template').addEventListener('change', function () {
        state.template = this.value;
        state.bgAttachmentId = null; // انتخاب قلمی، تصویر پس‌زمینه را کنار می‌گذارد
        state.bgImage = null;
        markDirty(); draw();
    });

    // ================= Annotation روی تصویر (E16 → پس‌زمینه) =================
    $('cpms-hw-image').addEventListener('click', function () {
        if (!CFG.can_upload || !state.current) { return; }
        $('cpms-hw-image-input').click();
    });
    $('cpms-hw-image-input').addEventListener('change', function () {
        var f = this.files && this.files[0];
        this.value = '';
        if (!f || !state.current) { return; }
        var fd = new FormData();
        fd.append('file', f);
        fd.append('patient_id', state.current.patient_id || (state.doc && state.doc.patient_id) || 0);
        fd.append('visit_id', CFG.visit_id);
        fd.append('category', 'image');
        setSync('saving', '⏳ در حال بارگذاری تصویر…');
        api('POST', 'files', fd, null, true).then(function (r) {
            if (r.status !== 201) { throw new Error('upload'); }
            var att = r.body.data;
            state.bgAttachmentId = att.id;
            state.template = 'blank';
            $('cpms-hw-template').value = 'blank';
            loadBgImage();
            markDirty();
        }).catch(function () {
            setSync('failed', '⚠️ بارگذاری تصویر ناموفق');
        });
    });

    function loadBgImage() {
        state.bgImage = null;
        if (!state.bgAttachmentId) { return; }
        fetch(CFG.rest_url + 'files/' + state.bgAttachmentId + '/stream', { headers: { 'X-WP-Nonce': CFG.nonce } })
            .then(function (r) { return r.ok ? r.blob() : null; })
            .then(function (blob) { return blob ? createImageBitmap(blob) : null; })
            .then(function (img) { state.bgImage = img; draw(); })
            .catch(function () { /* پس‌زمینه متن جایگزین (Template) باقی می‌ماند */ });
    }

    // ================= Multi-page =================
    $('cpms-hw-addpage').addEventListener('click', function () {
        if (!state.doc) { return; }
        if (state.dirty) { localCheckpoint(); }
        api('POST', 'handwriting/documents/' + state.doc.id + '/pages', {
            width: state.current ? state.current.width : 1240,
            height: state.current ? state.current.height : 1754,
            background_template: state.template
        }).then(function (r) {
            if (r.status !== 201) { throw new Error('addpage'); }
            var page = r.body.data;
            state.doc.pages.push({
                id: page.id, page_index: page.page_index, width: page.width, height: page.height,
                background_template: page.background_template, client_revision: page.client_revision || 0,
                version: 1, stroke_count: 0
            });
            state.doc.page_count = state.doc.pages.length;
            openPage(page.id);
        }).catch(function () { setSync('failed', '⚠️ افزودن صفحه ناموفق'); });
    });

    // ================= Autosave + Sync (ADR-0014) =================
    var autosaveTimer = null;
    function markDirty() {
        state.dirty = true;
        setSync('saving', '✏️ تغییرات ثبت نشده');
        if (autosaveTimer) { clearTimeout(autosaveTimer); }
        autosaveTimer = setTimeout(autosaveTick, CFG.autosave_sec * 1000);
    }

    function autosaveTick() {
        if (!state.current || !state.dirty || state.saving) { return; }
        localCheckpoint().then(syncNow);
    }

    // همیشه اول IndexedDB — نوشتن آفلاین ممکن است (FR-9.5)
    function localCheckpoint() {
        if (!state.current) { return Promise.resolve(); }
        return idbPut({
            page_id: state.current.id,
            visit_id: CFG.visit_id,
            document_id: state.doc ? state.doc.id : null,
            strokes: state.strokes,
            client_revision: state.baseRevision + (state.dirty || state.pending ? 1 : 0),
            template: state.template,
            bg_attachment_id: state.bgAttachmentId,
            saved_at: new Date().toISOString()
        });
    }

    // تلاش Save جاری — رترای همان تلاش «همان» Idempotency-Key را می‌فرستد تا
    // Timeout شبکه بعد از apply موفق → پاسخ ذخیره‌شده (نه Conflict کاذب).
    function syncNow() {
        if (!state.current || state.saving || state.conflictServer) { return; }
        if (!state.pending) {
            state.pending = { key: uuid(), clientRevision: state.baseRevision + 1, snapshot: null };
        }
        var attempt = state.pending;
        state.saving = true;
        setSync('saving', '💾 در حال ذخیره…');
        encodeStrokes(state.strokes).then(function (enc) {
            attempt.snapshot = JSON.stringify(state.strokes);
            return api('PUT', 'handwriting/pages/' + state.current.id, {
                client_revision: attempt.clientRevision,
                stroke_data: enc.b64,
                width: state.current.width,
                height: state.current.height,
                background_template: state.template,
                background_attachment_id: state.bgAttachmentId,
                saved_by: 'autosave'
            }, { 'Idempotency-Key': attempt.key });
        }).then(function (r) {
            state.saving = false;
            if (r.status === 200) {
                var snapshot = attempt.snapshot;
                state.pending = null;
                onSaved(r.body.data, snapshot);
            } else if (errCode(r) === 'CLINIC_CONFLICT') {
                state.pending = null; // پاسخ قطعی — کلید دیگر تکرار نمی‌شود
                openConflict(errData(r).server || null);
            } else if (errCode(r) === 'CLINIC_DUPLICATE_IN_FLIGHT') {
                scheduleRetry(0); // Save موازی در پرواز — کلید همان می‌ماند
            } else {
                setSync('failed', '⚠️ Sync ناموفق — تلاش دوباره');
                scheduleRetry();
            }
        }).catch(function () {
            state.saving = false;
            setSync('offline', '📡 آفلاین — تغییرات محلی ذخیره شد');
            scheduleRetry(); // کلید/Revision همان تلاش باقی می‌ماند
        });
    }

    /**
     * @param {object} data پاسخ سرور
     * @param {string|null} snapshot JSON استروک‌ها در لحظه Encode (برای dirty-check)
     */
    function onSaved(data, snapshot) {
        if (!state.current) { return; }
        state.baseRevision = data.client_revision; // R = C
        state.current.version = data.version;
        state.current.client_revision = data.client_revision;
        // فقط وقتی «تمیز» که Strokeها از لحظه Encode تغییر نکرده باشند
        if (snapshot === JSON.stringify(state.strokes)) {
            state.dirty = false;
        }
        state.backoffIdx = 0;
        setSync('saved', '✅ ذخیره شد (' + new Date().toLocaleTimeString('fa-IR') + ')');
        // سیاست Local (T-16): off = پاک، last = نگه‌داشتن آخرین، always = نگه‌داشتن
        if (CFG.local_retain === 'off' && !state.dirty) {
            idbDelete(state.current.id);
        }
        renderPageTabs();
        if (state.dirty) { // ویرایش حین Save — چرخه بعدی
            if (autosaveTimer) { clearTimeout(autosaveTimer); }
            autosaveTimer = setTimeout(autosaveTick, CFG.autosave_sec * 1000);
        }
    }

    function scheduleRetry(immediate) {
        if (state.retryTimer) { clearTimeout(state.retryTimer); }
        var delay = immediate === 0 ? 1500 : BACKOFF[Math.min(state.backoffIdx, BACKOFF.length - 1)];
        if (immediate !== 0) { state.backoffIdx++; }
        state.retryTimer = setTimeout(function () {
            if (state.dirty) { syncNow(); }
        }, delay);
    }

    // resume: online/focus (ADR-0014)
    window.addEventListener('online', function () { state.backoffIdx = 0; if (state.dirty) { syncNow(); } }, { signal: listeners.signal });
    window.addEventListener('focus', function () { if (navigator.onLine && state.dirty) { syncNow(); } }, { signal: listeners.signal });

    $('cpms-hw-save').addEventListener('click', function () {
        if (state.current && state.dirty) { localCheckpoint().then(syncNow); }
    });

    // خروج امن: آخرین وضعیت محلی را باقی می‌گذاریم (IndexedDB) — Stroke از دست نمی‌رود
    window.addEventListener('beforeunload', function (e) {
        if (state.dirty) {
            localCheckpoint();
            e.preventDefault();
            e.returnValue = '';
        }
    }, { signal: listeners.signal });

    // ================= Conflict (دو تب — بدون ادغام خودکار) =================
    function openConflict(server) {
        state.conflictServer = server;
        $('cpms-hw-conflict').hidden = false;
        // نسخه من
        renderPreview($('cpms-hw-cv-mine'), state.current, state.strokes, state.template, state.bgImage);
        // نسخه سرور
        if (server) {
            var img = null; // تصویر پس‌زمینه سرور در دیالوگ ساده نمایش داده می‌شود
            renderPreview($('cpms-hw-cv-server'), state.current, server.strokes || [], 'lined', img);
        }
    }

    function renderPreview(cv, page, strokes, template, bgImage) {
        var c = cv.getContext('2d');
        var w = page ? page.width : 1240, h = page ? page.height : 1754;
        var s = Math.min(cv.width / w, cv.height / h);
        c.setTransform(1, 0, 0, 1, 0, 0);
        c.fillStyle = '#fff';
        c.fillRect(0, 0, cv.width, cv.height);
        c.setTransform(s, 0, 0, s, (cv.width - w * s) / 2, (cv.height - h * s) / 2);
        var keepTemplate = state.template, keepImg = state.bgImage;
        state.template = bgImage ? 'blank' : template;
        state.bgImage = bgImage;
        drawTemplateOn(c, w, h);
        state.template = keepTemplate; state.bgImage = keepImg;
        strokes.forEach(function (st) { drawStroke(c, st); });
    }

    function drawTemplateOn(c, w, h) {
        var real = ctx;
        ctx = c; // drawTemplate از ctx سراسری استفاده می‌کند
        try { drawTemplate(w, h); } finally { ctx = real; }
    }

    Array.prototype.forEach.call(document.querySelectorAll('.cpms-hw-tab'), function (b) {
        b.addEventListener('click', function () {
            Array.prototype.forEach.call(document.querySelectorAll('.cpms-hw-tab'), function (x) { x.classList.remove('is-active'); });
            b.classList.add('is-active');
            $('cpms-hw-cv-mine').hidden = b.dataset.tab !== 'mine';
            $('cpms-hw-cv-server').hidden = b.dataset.tab !== 'server';
        });
    });

    // «نسخه سرور» → Local دورریخته، پایه = سرور
    $('cpms-hw-keep-server').addEventListener('click', function () {
        var server = state.conflictServer;
        $('cpms-hw-conflict').hidden = true;
        state.conflictServer = null;
        state.pending = null; // تلاش معلق قدیمی باطل
        state.backoffIdx = 0;
        if (server) {
            state.baseRevision = server.client_revision;
            state.strokes = server.strokes || [];
            state.dirty = false;
        }
        idbDelete(state.current.id).then(function () { draw(); setSync('saved', '✅ نسخه سرور نگه داشته شد'); });
    });

    // «نسخه من» → load-then-save: بازنویسی با C=R+1 و conflict_reason در Audit
    $('cpms-hw-keep-mine').addEventListener('click', function () {
        var server = state.conflictServer;
        $('cpms-hw-conflict').hidden = true;
        state.conflictServer = null;
        state.pending = null;
        if (server) { state.baseRevision = server.client_revision; } // پایه = سرور، سپس Save من
        state.dirty = true;
        localCheckpoint().then(function () {
            encodeStrokes(state.strokes).then(function (enc) {
                var snapshot = JSON.stringify(state.strokes);
                setSync('saving', '💾 در حال بازنویسی…');
                return api('PUT', 'handwriting/pages/' + state.current.id, {
                    client_revision: state.baseRevision + 1,
                    stroke_data: enc.b64,
                    width: state.current.width,
                    height: state.current.height,
                    background_template: state.template,
                    background_attachment_id: state.bgAttachmentId,
                    saved_by: 'manual',
                    conflict_reason: 'overwrite_after_conflict'
                }, { 'Idempotency-Key': uuid() }).then(function (r) { return { r: r, snapshot: snapshot }; });
            }).then(function (out) {
                if (out.r.status === 200) { onSaved(out.r.body.data, out.snapshot); }
                else { openConflict(errData(out.r).server || null); } // باز هم تضاد → دیالوگ مجدد
            }).catch(function () { setSync('failed', '⚠️ بازنویسی ناموفق'); });
        });
    });

    // ================= Resize =================
    window.addEventListener('resize', resizeCanvas, { signal: listeners.signal });
    resizeCanvas();
    boot();
    return function () {
        state.closed = true;
        listeners.abort();
        clearTimeout(autosaveTimer);
        clearTimeout(state.retryTimer);
        if (state.dirty) { localCheckpoint(); }
    };
    }
    window.CPMSHandwriting = { start: start };
    if (window.CPMS_HW) { start(window.CPMS_HW); }
})();
