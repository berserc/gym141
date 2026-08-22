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
