/* Draws QR codes in the browser (no outside service, works offline).
   On a page: fills every [data-qr] box, using several CPU cores (web workers) so even
   1000 codes are ready quickly. #printBtn stays disabled and #qrStatus shows progress
   until every code is drawn.
   In a worker: receives [index, text] pairs and returns [index, svg] pairs. */

/** SVG for one QR code; dark squares in a row are joined into one shape */
function qrSvg(text) {
    var qr = qrcode(0, 'M');
    qr.addData(text);
    qr.make();
    var n = qr.getModuleCount(), q = 2, d = '';
    for (var r = 0; r < n; r++) {
        for (var c = 0; c < n; c++) {
            if (!qr.isDark(r, c)) continue;
            var run = 1;
            while (c + run < n && qr.isDark(r, c + run)) run++;
            d += 'M' + (c + q) + ' ' + (r + q) + 'h' + run + 'v1h-' + run + 'z';
            c += run - 1;
        }
    }
    var size = n + q * 2;
    return '<svg viewBox="0 0 ' + size + ' ' + size + '" shape-rendering="crispEdges" xmlns="http://www.w3.org/2000/svg">'
        + '<rect width="' + size + '" height="' + size + '" fill="#fff"/><path d="' + d + '" fill="#000"/></svg>';
}

if (typeof importScripts === 'function' && typeof document === 'undefined') {
    // ---- Running as a worker ----
    importScripts('qrcode.min.js');
    onmessage = function (e) {
        postMessage(e.data.map(function (item) { return [item[0], qrSvg(item[1])]; }));
    };
} else {
    // ---- Running on the page ----
    (function () {
        var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-qr]'));
        var printBtn = document.getElementById('printBtn');
        var status = document.getElementById('qrStatus');
        var drawn = 0, total = boxes.length, finished = false;
        if (!total) return;

        function put(i, svg) {
            if (boxes[i].firstChild) return;   // already drawn (e.g. before printing)
            boxes[i].innerHTML = svg;
            drawn++;
        }
        function progress() {
            if (drawn < total) {
                if (status) status.textContent = 'Preparing QR codes… ' + drawn + ' / ' + total;
                return;
            }
            if (finished) return;
            finished = true;
            if (status) status.textContent = total + (total === 1 ? ' QR code ready.' : ' QR codes ready.');
            if (printBtn) { printBtn.disabled = false; printBtn.classList.remove('loading'); }
        }
        function drawRestHere() {
            boxes.forEach(function (box, i) { if (!box.firstChild) put(i, qrSvg(box.getAttribute('data-qr'))); });
            progress();
        }

        if (printBtn) { printBtn.disabled = true; printBtn.classList.add('loading'); }
        // Printing with Ctrl+P before drawing finished: draw the rest first
        window.addEventListener('beforeprint', drawRestHere);

        // Split the work into groups of 40 and share it between workers
        var groups = [];
        for (var i = 0; i < total; i += 40) {
            groups.push(boxes.slice(i, i + 40).map(function (box, j) { return [i + j, box.getAttribute('data-qr')]; }));
        }
        var workerCount = Math.max(1, Math.min(groups.length, (navigator.hardwareConcurrency || 4) - 1, 8));
        var started = 0;
        try {
            var workers = [];
            for (var w = 0; w < workerCount; w++) workers.push(new Worker('assets/qr-draw.js'));
            workers.forEach(function (worker) {
                worker.onmessage = function (e) {
                    e.data.forEach(function (pair) { put(pair[0], pair[1]); });
                    progress();
                    if (started < groups.length) worker.postMessage(groups[started++]);
                    else worker.terminate();
                };
                worker.onerror = function () { worker.terminate(); setTimeout(drawRestHere, 0); };
                if (started < groups.length) worker.postMessage(groups[started++]);
            });
        } catch (err) {
            // Workers not available: draw in small groups on the page so it stays responsive
            (function step() {
                var end = Math.min(drawn + 40, total);
                for (var k = 0; k < total && drawn < end; k++) if (!boxes[k].firstChild) put(k, qrSvg(boxes[k].getAttribute('data-qr')));
                progress();
                if (drawn < total) setTimeout(step, 0);
            })();
        }
        progress();
    })();
}
