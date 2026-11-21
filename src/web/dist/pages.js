/* Faro SEO: small helpers for the Broken links, Redirects and Settings pages. */
(function () {
    'use strict';

    function t(msg) { return Craft.t('faro-seo', msg); }

    function post(action, data) {
        return Craft.sendActionRequest('POST', action, {data: data});
    }

    function fail(e) {
        var msg = e && e.response && e.response.data && e.response.data.message;
        Craft.cp.displayError(msg || t('Something went wrong. Try again.'));
    }

    function dropRows(ids) {
        ids.forEach(function (id) {
            document.querySelectorAll('.faro-row[data-id="' + id + '"]').forEach(function (row) { row.remove(); });
        });
    }

    // Broken links: accept, edit, ignore and ignore all, without reloading.
    document.querySelectorAll('form[data-faro-fix]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var id = form.querySelector('input[name="id"]').value;
            var dest = form.querySelector('input[name="destination"]');
            post('faro-seo/broken-links/accept', {id: id, destination: dest ? dest.value : ''})
                .then(function (r) { Craft.cp.displayNotice(r.data.message); dropRows([id]); })
                .catch(fail);
        });
        var edit = form.querySelector('[data-faro-edit]');
        if (edit) {
            edit.addEventListener('click', function () {
                var input = form.querySelector('input[name="destination"]');
                input.readOnly = false;
                input.focus();
                input.select();
            });
        }
    });

    function ignore(ids) {
        post('faro-seo/broken-links/ignore', {id: ids})
            .then(function (r) { Craft.cp.displayNotice(r.data.message); dropRows(ids); })
            .catch(fail);
    }

    document.querySelectorAll('[data-faro-ignore]').forEach(function (btn) {
        btn.addEventListener('click', function () { ignore([btn.getAttribute('data-faro-ignore')]); });
    });
    document.querySelectorAll('[data-faro-ignore-all]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var ids = JSON.parse(btn.getAttribute('data-faro-ignore-all') || '[]').map(String);
            if (ids.length) { ignore(ids); }
        });
    });

    // Redirects: drag to reorder (first match wins) and delete.
    var table = document.getElementById('faro-redirects');
    if (table && window.Craft && Craft.DataTableSorter) {
        new Craft.DataTableSorter(table, {
            helperClass: 'faro-sorting',
            onSortChange: function () {
                var ids = Array.prototype.map.call(table.querySelectorAll('tbody tr[data-id]'), function (tr) { return tr.getAttribute('data-id'); });
                post('faro-seo/redirects/reorder', {ids: ids})
                    .then(function () { Craft.cp.displayNotice(t('Order saved.')); })
                    .catch(fail);
            }
        });
    }
    document.querySelectorAll('[data-faro-delete]').forEach(function (a) {
        a.addEventListener('click', function () {
            if (!window.confirm(t('Delete this redirect?'))) { return; }
            var id = a.getAttribute('data-faro-delete');
            post('faro-seo/redirects/delete', {id: id})
                .then(function () {
                    var tr = a.closest('tr');
                    if (tr) { tr.remove(); }
                    Craft.cp.displayNotice(t('Redirect deleted.'));
                })
                .catch(fail);
        });
    });

    // Settings → Titles: keep the example line in step with the fields.
    document.querySelectorAll('[data-faro-title-example]').forEach(function (box) {
        var name = box.querySelector('input[name$="[siteName]"]');
        var sep = box.querySelector('select[name$="[separator]"]');
        var pos = box.querySelector('select[name$="[position]"]');
        var out = box.querySelector('[data-faro-example]');
        if (!name || !sep || !pos || !out) { return; }
        var page = t('{Page title}');
        function draw() {
            var site = name.value.trim() || name.getAttribute('data-faro-site-name') || name.getAttribute('placeholder') || '';
            var s = sep.value;
            out.textContent = pos.value === 'before' ? site + ' ' + s + ' ' + page
                : (pos.value === 'none' ? page : page + ' ' + s + ' ' + site);
        }
        [name, sep, pos].forEach(function (el) { el.addEventListener('input', draw); el.addEventListener('change', draw); });
        draw();
    });
})();
