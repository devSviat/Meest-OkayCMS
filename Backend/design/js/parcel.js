/* Блок посилки Meest на сторінці замовлення. Поля мають data-field (не name): вони всередині форми
   замовлення й не повинні зберігатися разом із нею; кожна дія — окремий POST у ParcelAdmin.
   Відповіді вставляються лише через textContent: текст Meest потрапляє сюди як є. */
(function () {
    'use strict';

    var TOAST_KEY = 'sviat_meest_parcel_toast';
    /** Поля форми, які підсвічує помилка поля з ParcelDraft. */
    var INPUTS = {
        sides: ['length', 'width', 'height']
    };

    function rootOf(node) {
        return node.closest('.fn_meest_parcel');
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text) {
            node.textContent = text;
        }
        return node;
    }

    /** toastr 2.1.4 за замовчуванням вставляє текст як HTML, а сюди може потрапити текст Meest. */
    function toast(kind, text, title) {
        if (text && window.toastr) {
            window.toastr[kind](text, title, { escapeHtml: true });
        }
    }

    try {
        var waiting = JSON.parse(window.sessionStorage.getItem(TOAST_KEY) || 'null');
        window.sessionStorage.removeItem(TOAST_KEY);
        if (waiting) {
            document.addEventListener('DOMContentLoaded', function () { toast('success', waiting[0], waiting[1]); });
        }
    } catch (e) {}

    function fields(root) {
        var data = {};
        root.querySelectorAll('[data-field]').forEach(function (input) {
            if (!input.disabled) {
                data[input.getAttribute('data-field')] = input.value;
            }
        });
        return data;
    }

    function clearIssues(root) {
        root.querySelectorAll('.meest-parcel__error').forEach(function (span) { span.textContent = ''; });
        root.querySelectorAll('.meest-parcel__invalid').forEach(function (input) { input.classList.remove('meest-parcel__invalid'); });
        root.querySelectorAll('.meest-parcel__issues, .meest-parcel__notices').forEach(function (box) { box.textContent = ''; });
        var candidates = root.querySelector('.meest-parcel__candidates');
        if (candidates) {
            candidates.textContent = '';
            candidates.hidden = true;
        }
    }

    function showIssues(root, issues, box) {
        var target = box || root.querySelector('.meest-parcel__issues');
        issues.forEach(function (issue) {
            var span = issue.field ? root.querySelector('.meest-parcel__error[data-for="' + CSS.escape(issue.field) + '"]') : null;
            if (span) {
                span.textContent = [issue.text, issue.advice].filter(Boolean).join(' ');
                (INPUTS[issue.field] || [issue.field]).forEach(function (name) {
                    var input = root.querySelector('[data-field="' + CSS.escape(name) + '"]');
                    if (input) {
                        input.classList.add('meest-parcel__invalid');
                    }
                });
                return;
            }
            if (!target) {
                return;
            }
            var alert = el('div', 'alert alert--error meest-parcel__issue');
            var content = el('div', 'alert__content');
            content.appendChild(el('p', 'text_600', root.getAttribute('data-failed')));
            content.appendChild(el('p', '', issue.text));
            if (issue.orderUrl) {
                var link = el('a', '', root.getAttribute('data-order-text'));
                link.href = issue.orderUrl;
                link.target = '_blank';
                link.rel = 'noopener';
                var line = el('p', '');
                line.appendChild(link);
                content.appendChild(line);
            }
            if (issue.advice) {
                content.appendChild(el('p', 'text_grey', issue.advice));
            }
            alert.appendChild(content);
            target.appendChild(alert);
        });
        var first = root.querySelector('.meest-parcel__invalid, .meest-parcel__issue');
        if (first && first.scrollIntoView) {
            first.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
        toast('error', root.getAttribute('data-failed'), root.getAttribute('data-toast-error'));
    }

    /** Пошук знайшов кілька посилок: менеджер вибирає сам, а ParcelAdmin перевіряє вибір ще раз. */
    function showCandidates(root, answer) {
        var box = root.querySelector('.meest-parcel__candidates');
        var notices = root.querySelector('.meest-parcel__notices');
        if (notices && answer.notices && answer.notices[0]) {
            var note = el('div', 'alert alert--warning');
            var content = el('div', 'alert__content');
            content.appendChild(el('p', '', answer.notices[0]));
            note.appendChild(content);
            notices.appendChild(note);
        }
        if (!box) {
            return;
        }
        answer.candidates.forEach(function (candidate) {
            var row = el('div', 'meest-parcel__candidate');
            row.appendChild(el('span', '', candidate.barcode || candidate.parcel_id));
            var choose = el('button', 'btn btn_small btn_border_blue fn_meest_parcel_choose', root.getAttribute('data-choose'));
            choose.type = 'button';
            choose.setAttribute('data-parcel', candidate.parcel_id);
            row.appendChild(choose);
            box.appendChild(row);
        });
        box.hidden = false;
    }

    function networkIssue(root, box) {
        showIssues(root, [{ field: null, text: root.getAttribute('data-network') }], box);
    }

    /**
     * json | pdf | session | server. 400–405 і вхід адмінки (редирект, 200 з HTML) — найчастіше прострочена
     * сесія, яку лікує лише оновлення сторінки; 5xx — збій сервера магазину, а не сесія.
     */
    function answerKind(response) {
        var type = response.headers.get('Content-Type') || '';
        if (response.status >= 500) {
            return 'server';
        }
        if (!response.ok || response.redirected) {
            return 'session';
        }
        if (type.indexOf('application/json') === 0) {
            return 'json';
        }
        return type.indexOf('application/pdf') === 0 || type.indexOf('application/zip') === 0 || isStickerAttachment(response) ? 'pdf' : 'session';
    }

    /**
     * Response стоку OkayCMS 4.5.3 перезаписує Content-Type стікера на text/plain, тож файл упізнаємо й за
     * Content-Disposition. Сторінка входу адмінки вкладенням не приходить.
     */
    function isStickerAttachment(response) {
        return /^attachment;.*\.(pdf|zip)"?$/i.test(response.headers.get('Content-Disposition') || '');
    }

    function answerIssue(root, kind, box) {
        var text = kind === 'server' ? root.getAttribute('data-unexpected') : root.getAttribute('data-expired');
        showIssues(root, [{ field: null, text: text }], box);
    }

    function post(root, action, extra) {
        var body = new URLSearchParams(Object.assign({ action: action, order_id: root.getAttribute('data-order'), session_id: root.getAttribute('data-token') }, extra));
        return fetch(root.getAttribute('data-url'), { method: 'POST', body: body, credentials: 'same-origin' });
    }

    function busy(button, on, text) {
        button.disabled = on;
        button.classList.toggle('is-busy', on);
        if (on && text) {
            button.setAttribute('data-label', button.textContent);
            button.textContent = text;
        } else if (!on && button.hasAttribute('data-label')) {
            button.textContent = button.getAttribute('data-label');
            button.removeAttribute('data-label');
        }
    }

    /** Будь-яка успішна дія міняє вигляд блоку (форма ↔ картка в іншій колонці): сторінка перезавантажується. */
    function send(root, button, action, extra, box) {
        if (button.disabled) {
            return;
        }
        clearIssues(root);
        busy(button, true, action === 'create' ? root.getAttribute('data-busy') : null);
        post(root, action, extra)
            .then(function (response) {
                var kind = answerKind(response);
                if (kind !== 'json') {
                    answerIssue(root, kind, box);
                    return null;
                }
                return response.json();
            })
            .then(function (answer) {
                if (!answer) {
                    return;
                }
                if (answer.ok) {
                    try {
                        window.sessionStorage.setItem(TOAST_KEY, JSON.stringify([
                            (answer.notices && answer.notices[0]) || root.getAttribute('data-done-' + action),
                            root.getAttribute('data-toast-ok')
                        ]));
                    } catch (e) {}
                    window.location.reload();
                    return;
                }
                if (answer.candidates && answer.candidates.length) {
                    showCandidates(root, answer);
                    return;
                }
                showIssues(root, answer.issues || [], box);
            })
            .catch(function () { networkIssue(root, box); })
            .finally(function () { busy(button, false); });
    }

    function fileName(response, root) {
        var match = /filename="([A-Za-z0-9.\-]+)"/.exec(response.headers.get('Content-Disposition') || '');
        return match ? match[1] : 'meest-' + root.getAttribute('data-order') + '.pdf';
    }

    /**
     * Стікер приходить байтами PDF і відкривається в новій вкладці, щоб одразу надрукувати; помилка — JSON,
     * і її показують на сторінці. Вкладку відкриваємо ще в обробнику кліку: після fetch браузер уже
     * вважає window.open спливним вікном і блокує його. Якщо вкладку все ж заблоковано — файл скачується.
     */
    function sticker(root, button) {
        if (button.disabled) {
            return;
        }
        clearIssues(root);
        busy(button, true);
        var tab = window.open('', '_blank');
        if (tab) {
            tab.opener = null;
        }
        var closeTab = function () {
            if (tab && !tab.closed) {
                tab.close();
            }
        };
        post(root, 'sticker', {})
            .then(function (response) {
                var kind = answerKind(response);
                if (kind === 'session' || kind === 'server') {
                    closeTab();
                    answerIssue(root, kind);
                    return;
                }
                if (kind === 'json') {
                    closeTab();
                    return response.json().then(function (answer) { showIssues(root, answer.issues || []); });
                }
                return response.arrayBuffer().then(function (bytes) {
                    var url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
                    if (tab && !tab.closed) {
                        tab.location.href = url;
                    } else {
                        var link = el('a');
                        link.href = url;
                        link.download = fileName(response, root);
                        document.body.appendChild(link);
                        link.click();
                        link.remove();
                    }
                    // Вкладка вантажить blob асинхронно: раннє звільнення лишило б її порожньою.
                    setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
                });
            })
            .catch(function () { closeTab(); networkIssue(root); })
            .finally(function () { busy(button, false); });
    }

    function copied(button) {
        button.classList.add('is-copied');
        button.title = button.getAttribute('data-copied');
        setTimeout(function () { button.classList.remove('is-copied'); }, 1000);
    }

    /** Буфер обміну може відмовити (немає дозволу, вкладка не у фокусі): тоді кажемо про це, а не «Скопійовано». */
    function copyFailed(button) {
        var text = button.getAttribute('data-copy-failed');
        button.title = text;
        toast('error', text, '');
    }

    function copy(button) {
        var value = button.getAttribute('data-copy');
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(value).then(function () { copied(button); }, function () { copyFailed(button); });
            return;
        }
        var field = document.createElement('input');
        field.value = value;
        field.setAttribute('readonly', '');
        field.style.position = 'absolute';
        field.style.opacity = '0';
        document.body.appendChild(field);
        field.select();
        var done = false;
        try {
            done = document.execCommand('copy');
        } catch (e) {}
        document.body.removeChild(field);
        if (done) {
            copied(button);
        } else {
            copyFailed(button);
        }
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.fn_meest_parcel_create, .fn_meest_parcel_attach, .fn_meest_parcel_attach_toggle, .fn_meest_parcel_lookup, .fn_meest_parcel_choose, .fn_meest_parcel_remove, .fn_meest_parcel_unlink, .fn_meest_parcel_refresh, .fn_meest_parcel_sticker, .fn_meest_parcel_copy');
        var root = button ? rootOf(button) : null;
        if (!root) {
            return;
        }
        event.preventDefault();
        var has = function (name) { return button.classList.contains(name); };
        if (has('fn_meest_parcel_create')) {
            send(root, button, 'create', fields(root));
        } else if (has('fn_meest_parcel_attach')) {
            var barcode = root.querySelector('[data-attach="barcode"]');
            send(root, button, 'attach', { barcode: barcode ? barcode.value : '' }, root.querySelector('.meest-parcel__issues--attach'));
        } else if (has('fn_meest_parcel_attach_toggle')) {
            var panel = root.querySelector('.meest-parcel__attach');
            panel.hidden = !panel.hidden;
            button.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
            if (!panel.hidden) {
                panel.querySelector('[data-attach="barcode"]').focus();
            }
        } else if (has('fn_meest_parcel_lookup')) {
            send(root, button, 'lookup', {});
        } else if (has('fn_meest_parcel_choose')) {
            send(root, button, 'choose', { parcel_id: button.getAttribute('data-parcel') });
        } else if (has('fn_meest_parcel_refresh')) {
            send(root, button, 'refresh', {});
        } else if (has('fn_meest_parcel_sticker')) {
            sticker(root, button);
        } else if (has('fn_meest_parcel_copy')) {
            copy(button);
        } else if (has('fn_meest_parcel_remove')) {
            if (window.confirm(root.getAttribute('data-confirm-remove'))) {
                send(root, button, 'remove', {});
            }
        } else if (window.confirm(root.getAttribute(button.hasAttribute('data-uncertain') ? 'data-confirm-unlink-check' : 'data-confirm-unlink'))) {
            send(root, button, 'unlink', {});
        }
    });

    // Enter у полі блоку інакше надіслав би форму замовлення навколо нього.
    document.addEventListener('keydown', function (event) {
        var input = event.target;
        if (event.key !== 'Enter' || !input.matches || !input.matches('.fn_meest_parcel input')) {
            return;
        }
        event.preventDefault();
        var root = rootOf(input);
        var button = input.matches('[data-attach]') ? root.querySelector('.fn_meest_parcel_attach') : null;
        if (button) {
            button.click();
        }
    });

    // Ядро прив'язує .fn_clipboard ще в <head>, до появи рядків списку замовлень; без власного обробника
    // клік по href="" перезавантажує сторінку.
    document.addEventListener('click', function (event) {
        var link = event.target.closest('.fn_meest_list_copy');
        if (!link) {
            return;
        }
        event.preventDefault();
        var value = link.getAttribute('data-copy-string');
        var done = function () {
            link.classList.add('is-copied');
            setTimeout(function () { link.classList.remove('is-copied'); }, 1000);
        };
        var failed = function () { toast('error', link.getAttribute('data-copy-failed'), ''); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(value).then(done, failed);
            return;
        }
        var field = document.createElement('input');
        field.value = value;
        field.setAttribute('readonly', '');
        field.style.position = 'absolute';
        field.style.opacity = '0';
        document.body.appendChild(field);
        field.select();
        var copiedOk = false;
        try {
            copiedOk = document.execCommand('copy');
        } catch (e) {}
        document.body.removeChild(field);
        if (copiedOk) {
            done();
        } else {
            failed();
        }
    });

    document.addEventListener('click', function (event) {
        var hide = event.target.closest('.fn_meest_mass_hide');
        if (hide) {
            hide.closest('.fn_meest_mass_result').hidden = true;
        }
    });

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('.fn_meest_parcel_more');
        var card = toggle ? toggle.closest('.meest-card') : null;
        var panel = card ? card.querySelector('.meest-card__more') : null;
        if (!panel) {
            return;
        }
        var opening = panel.hidden;
        panel.hidden = !opening;
        toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
        toggle.querySelector('span').textContent = toggle.getAttribute(opening ? 'data-less' : 'data-more');
    });
})();
