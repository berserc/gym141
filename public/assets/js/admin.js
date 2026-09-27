/**
 * Kleine Hilfen für den Verwaltungsbereich.
 * Kein Framework, keine externen Abhängigkeiten – die Seite funktioniert auch ohne JS.
 */
(function () {
    'use strict';

    // Navigation auf schmalen Bildschirmen ein-/ausklappen
    var burger = document.querySelector('.admin-burger');
    var nav = document.getElementById('admin-nav');

    if (burger && nav) {
        burger.addEventListener('click', function () {
            var open = nav.classList.toggle('is-open');
            burger.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    // "Alle auswählen" in Tabellen
    document.querySelectorAll('[data-check-all]').forEach(function (master) {
        var table = master.closest('table');

        if (!table) {
            return;
        }

        master.addEventListener('change', function () {
            table.querySelectorAll('tbody input[type="checkbox"][name="ids[]"]').forEach(function (box) {
                box.checked = master.checked;
            });
        });
    });

    // Sicherheitsabfrage für ganze Formulare
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        });
    });

    // Sicherheitsabfrage für einzelne Schaltflächen
    document.querySelectorAll('[data-confirm-click]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            if (!window.confirm(button.getAttribute('data-confirm-click'))) {
                event.preventDefault();
            }
        });
    });

    // Sammelaktionen: nur mit Auswahl und mit Rückfrage ausführen
    document.querySelectorAll('form[data-confirm-bulk]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var selected = form.querySelectorAll('input[name="ids[]"]:checked').length;

            if (selected === 0) {
                event.preventDefault();
                window.alert('Bitte zuerst mindestens ein Mitglied auswählen.');
                return;
            }

            var select = form.querySelector('select[name="action"]');
            var label = select && select.options[select.selectedIndex]
                ? select.options[select.selectedIndex].text
                : 'Aktion';

            if (!window.confirm('„' + label + '“ für ' + selected + ' Mitglied(er) ausführen?')) {
                event.preventDefault();
            }
        });
    });

    // Sektionsauswahl nur für die Rolle "Sektionsleitung" zeigen
    var roleSelect = document.querySelector('[data-role-select]');
    var roleSections = document.querySelector('[data-role-sections]');

    if (roleSelect && roleSections) {
        var syncRole = function () {
            roleSections.hidden = roleSelect.value !== 'sektionsleiter';
        };

        roleSelect.addEventListener('change', syncRole);
        syncRole();
    }

    // Navigation: Gruppen klappbar (Zustand bleibt gespeichert), Leiste ausblendbar
    var navSections = document.querySelectorAll('.admin-nav__section');

    if (navSections.length) {
        var closedGroups = [];

        try {
            closedGroups = JSON.parse(localStorage.getItem('gymNavClosed') || '[]');
        } catch (e) {}

        navSections.forEach(function (section) {
            var name = section.getAttribute('data-nav-group');

            // Die Gruppe mit dem aktiven Eintrag bleibt immer offen.
            if (closedGroups.indexOf(name) !== -1 && !section.hasAttribute('data-has-active')) {
                section.open = false;
            }

            section.addEventListener('toggle', function () {
                var list = [];
                navSections.forEach(function (s) {
                    if (!s.open) {
                        list.push(s.getAttribute('data-nav-group'));
                    }
                });
                try {
                    localStorage.setItem('gymNavClosed', JSON.stringify(list));
                } catch (e) {}
            });
        });

        // Alle auf- bzw. zuklappen
        var expandBtn = document.querySelector('[data-nav-expand]');

        if (expandBtn) {
            var expandLabel = expandBtn.querySelector('[data-nav-expand-label]');

            var syncExpandLabel = function () {
                var anyClosed = false;
                navSections.forEach(function (s) { if (!s.open) { anyClosed = true; } });
                expandLabel.textContent = anyClosed ? 'alle aufklappen' : 'alle zuklappen';
            };

            expandBtn.addEventListener('click', function () {
                var anyClosed = false;
                navSections.forEach(function (s) { if (!s.open) { anyClosed = true; } });
                navSections.forEach(function (s) { s.open = anyClosed; });
                syncExpandLabel();
            });

            navSections.forEach(function (s) {
                s.addEventListener('toggle', syncExpandLabel);
            });
            syncExpandLabel();
        }
    }

    // Seitenleiste komplett aus- und wieder einblenden
    var navCollapse = document.querySelector('[data-nav-collapse]');

    if (navCollapse) {
        navCollapse.addEventListener('click', function () {
            var hidden = document.body.classList.toggle('nav-hidden');
            try {
                localStorage.setItem('gymNavHidden', hidden ? '1' : '0');
            } catch (e) {}
        });
    }

    // YouTube-Links als Mini-Player unten rechts (wie in der YouTube-App)
    var youtubeId = function (url) {
        var m = url.match(/(?:youtube\.com\/(?:watch\?[^#]*v=|shorts\/|embed\/|live\/)|youtu\.be\/)([\w-]{6,20})/i);
        return m ? m[1] : null;
    };

    var closeMiniPlayer = function () {
        var alt = document.querySelector('.mini-player');
        if (alt) {
            alt.remove();
        }
    };

    var openMiniPlayer = function (id, title) {
        closeMiniPlayer();

        var wrap = document.createElement('div');
        wrap.className = 'mini-player';
        wrap.innerHTML =
            '<div class="mini-player__bar"><span class="mini-player__title"></span>' +
            '<button type="button" class="mini-player__close" title="Schließen">×</button></div>' +
            '<div class="mini-player__frame"><iframe src="https://www.youtube-nocookie.com/embed/' + id +
            '?autoplay=1" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen title="Video"></iframe></div>';
        wrap.querySelector('.mini-player__title').textContent = title || 'Video';
        wrap.querySelector('.mini-player__close').addEventListener('click', closeMiniPlayer);
        document.body.appendChild(wrap);
    };

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-video]');

        if (!trigger) {
            return;
        }

        var url = trigger.getAttribute('data-video');
        var id = youtubeId(url);

        if (id) {
            openMiniPlayer(id, trigger.textContent.replace(/^▶\s*/, '').trim());
        } else {
            window.open(url, '_blank', 'noopener');
        }
    });

    // Dateiablage: Auswahl-Popup fuer Anhaenge ("Aus Dateiablage wählen")
    var pickerTarget = null;

    document.addEventListener('click', function (event) {
        var btn = event.target.closest('.js-open-picker');

        if (!btn) {
            return;
        }

        event.preventDefault();
        pickerTarget = btn.closest('form');
        window.open(
            btn.getAttribute('data-picker-url'),
            'gymDateiauswahl',
            'width=980,height=640,resizable=yes,scrollbars=yes'
        );
    });

    window.__filePicked = function (file) {
        if (!pickerTarget) {
            return;
        }

        var hidden = pickerTarget.querySelector('input[name="media_file_id"]');
        var label = pickerTarget.querySelector('.js-picked');

        if (hidden) {
            hidden.value = file.id;
        }

        if (label) {
            label.textContent = '📎 ' + file.name;
        }
    };

    // Slug-Vorschlag beim Anlegen neuer Sektionen/Seiten
    var nameInput = document.getElementById('name') || document.getElementById('title');
    var slugInput = document.getElementById('slug');

    if (nameInput && slugInput && slugInput.value === '') {
        nameInput.addEventListener('input', function () {
            slugInput.placeholder = nameInput.value
                .toLowerCase()
                .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        });
    }
})();

/* ------------------------------------------------ Inhaltsbloecke: Drag&Drop */
(function () {
    'use strict';

    var container = document.getElementById('blocks-sortier');
    if (!container) { return; }

    var gezogen = null;

    container.querySelectorAll('.block-griff').forEach(function (griff) {
        var karte = griff.closest('.block-card');

        griff.addEventListener('dragstart', function (event) {
            gezogen = karte;
            karte.classList.add('block-card--gezogen');
            event.dataTransfer.effectAllowed = 'move';
            // Firefox braucht Daten, sonst startet der Drag nicht.
            event.dataTransfer.setData('text/plain', karte.dataset.blockId);
            event.dataTransfer.setDragImage(karte, 20, 20);
        });

        griff.addEventListener('dragend', function () {
            karte.classList.remove('block-card--gezogen');
            gezogen = null;
            speichern();
        });
    });

    container.addEventListener('dragover', function (event) {
        if (!gezogen) { return; }
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        var ziel = event.target.closest('.block-card');
        if (!ziel || ziel === gezogen) { return; }

        var rect = ziel.getBoundingClientRect();
        var danach = event.clientY > rect.top + rect.height / 2;
        container.insertBefore(gezogen, danach ? ziel.nextSibling : ziel);
    });

    container.addEventListener('drop', function (event) { event.preventDefault(); });

    function speichern() {
        var daten = new FormData();
        daten.append('csrf_token', container.dataset.csrf);
        container.querySelectorAll('.block-card').forEach(function (karte) {
            daten.append('ids[]', karte.dataset.blockId);
        });

        fetch(container.dataset.sortierUrl, {
            method: 'POST',
            body: daten,
            credentials: 'same-origin'
        }).catch(function () {
            // Beim naechsten Laden gilt wieder die gespeicherte Reihenfolge.
        });
    }
})();

// ---------------------------------------------------------------------------
// Ungespeicherte Aenderungen: Wer ein POST-Formular veraendert hat und die
// Seite verlaesst (Link, Tab zu, Adresse wechseln), bekommt eine Warnung.
// Das Absenden eines Formulars gilt als Speichern und hebt die Warnung auf.
(function () {
    var geaendert = false;

    document.addEventListener('input', function (e) {
        var form = e.target && e.target.closest ? e.target.closest('form') : null;

        // Nur echte Bearbeitungsformulare (POST) - Filter/Suchen (GET) nicht.
        if (form && (form.method || '').toLowerCase() === 'post') {
            geaendert = true;
        }
    });

    document.addEventListener('change', function (e) {
        var form = e.target && e.target.closest ? e.target.closest('form') : null;

        if (form && (form.method || '').toLowerCase() === 'post') {
            geaendert = true;
        }
    });

    document.addEventListener('submit', function () {
        geaendert = false;
    });

    window.addEventListener('beforeunload', function (e) {
        if (geaendert) {
            e.preventDefault();
            e.returnValue = ''; // Browser zeigen ihren Standard-Warntext
        }
    });
})();


/* ---- Bildbibliothek: Auswahl mit Suche, Tag-Filter, Reihenfolge (Drag & Drop) und Titelbild (★) ---- */
(function () {
    document.querySelectorAll('.js-image-picker').forEach(function (box) {
        var field  = box.getAttribute('data-field');
        var single = box.classList.contains('image-picker--single');
        var q      = box.querySelector('.image-picker__q');
        var only   = box.querySelector('.image-picker__only');
        var count  = box.querySelector('.image-picker__count');
        var tagBox = box.querySelector('.image-picker__tags');
        var selBox = box.querySelector('.image-picker__sel');
        var items  = Array.prototype.slice.call(box.querySelectorAll('.image-picker__item'));
        if (!q || items.length === 0) { return; }

        var form   = box.closest('form');
        var cover  = form ? form.querySelector('[data-cover-input]') : null;
        var order  = document.createElement('input');
        order.type = 'hidden'; order.name = field + '_order'; box.appendChild(order);

        var active = {};
        var counts = {};
        items.forEach(function (it) {
            (it.getAttribute('data-tags') || '').split('|').forEach(function (t) { if (t) { counts[t] = (counts[t] || 0) + 1; } });
        });
        Object.keys(counts).sort(function (a, b) { return counts[b] - counts[a] || a.localeCompare(b); }).forEach(function (t) {
            var s = document.createElement('span');
            s.className = 'tag'; s.innerHTML = t + ' <small>' + counts[t] + '</small>';
            s.addEventListener('click', function () { active[t] = !active[t]; s.classList.toggle('is-on', !!active[t]); filter(); });
            tagBox.appendChild(s);
        });

        function filter() {
            var words = (q.value || '').toLowerCase().split(/\s+/).filter(Boolean), shown = 0;
            items.forEach(function (it) {
                var tags = (it.getAttribute('data-tags') || '').split('|');
                var text = it.getAttribute('data-text') || '';
                var ok = true;
                Object.keys(active).forEach(function (t) { if (active[t] && tags.indexOf(t) < 0) { ok = false; } });
                words.forEach(function (w) { if (text.indexOf(w) < 0) { ok = false; } });
                if (only && only.checked && !it.querySelector('input').checked) { ok = false; }
                it.classList.toggle('is-hidden', !ok);
                if (ok) { shown++; }
            });
            count.textContent = shown + ' von ' + items.length + ' Bildern';
        }

        function byId(id) { return items.filter(function (it) { return it.getAttribute('data-id') === id; })[0]; }
        // Startreihenfolge aus data-selected (gespeicherte Reihenfolge), Rest der angehakten dahinter
        var checkedIds = items.filter(function (it) { return it.querySelector('input').checked; }).map(function (it) { return it.getAttribute('data-id'); });
        var selected = (box.getAttribute('data-selected') || '').split(',').filter(function (id) { return checkedIds.indexOf(id) >= 0; });
        checkedIds.forEach(function (id) { if (selected.indexOf(id) < 0) { selected.push(id); } });

        function renderSel() {
            if (!selBox) { return; }
            selBox.innerHTML = '';
            var coverId = cover ? cover.value : '';
            selected.forEach(function (id, n) {
                var it = byId(id); if (!it) { return; }
                var d = document.createElement('div');
                d.className = 'image-picker__ms' + (coverId === id ? ' is-cover' : ''); d.draggable = true; d.setAttribute('data-id', id);
                d.innerHTML = '<img src="' + it.querySelector('img').src + '" alt=""><b>' + (n + 1) + '</b>'
                    + (cover ? '<i class="image-picker__star" title="als Titelbild">★</i>' : '') + '<i class="image-picker__x" title="entfernen">✕</i>';
                d.querySelector('.image-picker__x').addEventListener('click', function () { it.querySelector('input').checked = false; sync(); });
                if (cover) {
                    d.querySelector('.image-picker__star').addEventListener('click', function () { cover.value = cover.value === id ? '' : id; renderSel(); });
                }
                d.addEventListener('dragstart', function (e) { e.dataTransfer.setData('text/plain', id); d.classList.add('is-dragging'); });
                d.addEventListener('dragend', function () { d.classList.remove('is-dragging'); });
                d.addEventListener('dragover', function (e) { e.preventDefault(); });
                d.addEventListener('drop', function (e) {
                    e.preventDefault();
                    var from = e.dataTransfer.getData('text/plain');
                    if (!from || from === id) { return; }
                    selected.splice(selected.indexOf(from), 1);
                    selected.splice(selected.indexOf(id), 0, from);
                    renderSel();
                });
                selBox.appendChild(d);
            });
            order.value = selected.join(',');
            if (selected.length === 0) { selBox.innerHTML = '<span class="muted">noch nichts ausgewählt</span>'; }
        }

        function sync() {
            items.forEach(function (it) {
                var id = it.getAttribute('data-id'), on = it.querySelector('input').checked;
                it.classList.toggle('is-on', on);
                if (on && selected.indexOf(id) < 0) { selected.push(id); }
                if (!on && selected.indexOf(id) >= 0) { selected.splice(selected.indexOf(id), 1); }
            });
            if (single) { selected = selected.slice(-1); }
            if (cover && cover.value && selected.indexOf(cover.value) < 0) { cover.value = ''; }
            renderSel(); filter();
        }

        box.addEventListener('change', function (e) { if (e.target.matches('.image-picker__item input')) { sync(); } });
        q.addEventListener('input', filter);
        if (only) { only.addEventListener('change', filter); }
        sync();
    });
})();


/* ---- Bilder per Drag & Drop hochladen: jedes Datei-Feld mit accept="image/*" bekommt eine Ablagefläche ---- */
(function () {
    document.querySelectorAll('input[type=file][accept*="image"]').forEach(function (input) {
        if (input.closest('.dropzone')) { return; }
        var zone = document.createElement('div');
        zone.className = 'dropzone';
        zone.innerHTML = '<span class="dropzone__text">' + (input.multiple ? 'Bilder hierher ziehen' : 'Bild hierher ziehen') + ' <small>oder klicken zum Auswählen</small></span><div class="dropzone__list"></div>';
        input.parentNode.insertBefore(zone, input);
        zone.appendChild(input);
        input.classList.add('dropzone__input');

        var list = zone.querySelector('.dropzone__list');
        function render() {
            list.innerHTML = '';
            var files = Array.prototype.slice.call(input.files || []);
            if (!files.length) { zone.classList.remove('has-files'); return; }
            zone.classList.add('has-files');
            files.slice(0, 40).forEach(function (f) {
                var item = document.createElement('span'); item.className = 'dropzone__file';
                if (f.type.indexOf('image/') === 0) { var img = document.createElement('img'); img.src = URL.createObjectURL(f); item.appendChild(img); }
                item.appendChild(document.createTextNode(f.name));
                list.appendChild(item);
            });
            var info = document.createElement('span'); info.className = 'dropzone__count';
            var mb = files.reduce(function (s, f) { return s + f.size; }, 0) / 1048576;
            info.textContent = files.length + ' Datei(en), ' + mb.toFixed(1) + ' MB';
            list.appendChild(info);
        }
        zone.addEventListener('click', function (e) { if (e.target === input) { return; } input.click(); });
        ['dragenter', 'dragover'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('is-over'); }); });
        ['dragleave', 'drop'].forEach(function (ev) { zone.addEventListener(ev, function () { zone.classList.remove('is-over'); }); });
        zone.addEventListener('drop', function (e) {
            e.preventDefault();
            var dropped = Array.prototype.slice.call(e.dataTransfer.files || []).filter(function (f) { return f.type.indexOf('image/') === 0; });
            if (!dropped.length) { return; }
            var dt = new DataTransfer();
            if (input.multiple) { Array.prototype.slice.call(input.files || []).forEach(function (f) { dt.items.add(f); }); }
            dropped.slice(0, input.multiple ? 200 : 1).forEach(function (f) { dt.items.add(f); });
            input.files = dt.files;
            render();
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
        input.addEventListener('change', render);
    });
})();

/* ---- Bilder eines Blocks per Drag & Drop sortieren (.gallery-admin[data-sortable]) ---- */
(function () {
    document.querySelectorAll('.gallery-admin[data-sortable]').forEach(function (grid) {
        var dragged = null;
        function items() { return Array.prototype.slice.call(grid.querySelectorAll('.gallery-admin__item')); }
        function renumber() { items().forEach(function (it, n) { var b = it.querySelector('.gallery-admin__no'); if (b) { b.textContent = n + 1; } }); }
        items().forEach(function (it) {
            it.setAttribute('draggable', 'true');
            it.addEventListener('dragstart', function (e) {
                if (e.target && e.target.tagName === 'INPUT') { e.preventDefault(); return; }
                dragged = it; it.classList.add('is-dragging'); e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', 'sort'); } catch (err) { /* IE */ }
            });
            it.addEventListener('dragend', function () { it.classList.remove('is-dragging'); dragged = null; });
            it.addEventListener('dragover', function (e) {
                if (!dragged || dragged === it) { return; }
                e.preventDefault();
                var r = it.getBoundingClientRect();
                var before = (e.clientX - r.left) < r.width / 2;
                grid.insertBefore(dragged, before ? it : it.nextSibling);
            });
            it.addEventListener('drop', function (e) { e.preventDefault(); renumber(); });
        });
        renumber();
    });
})();
