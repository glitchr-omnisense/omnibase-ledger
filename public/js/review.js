/*
 * omnibase/ledger - the front office's bank screen (templates/front/review.html.twig).
 *
 * The page works without this script: the filters are links and a form. Here it becomes a
 * keyboard screen: j / k (or the arrows) move from line to line, Enter accepts the line's
 * suggestion, c opens the category picker, s the split, i ignores, u undoes the last action,
 * a attaches a receipt, m marks it missing, r makes the rule the toast offers (« Toujours
 * classer “Monoprix” ainsi »), / goes to the search. Every action is an XHR
 * answering the row again, the counters and how to undo it (a toast says what was done).
 * A receipt dropped on a row is attached to it. Nothing takes the focus on its own: it moves
 * when someone moves it, and comes back to the row an action replaced.
 *
 * No dependency; every listener is on the document, so a page swapped in later
 * (transparent.js, Turbo) works too, and loading the script twice does nothing more.
 */
(function () {
    'use strict';

    if (window.LedgerReview) {
        window.LedgerReview.scan();
        return;
    }

    var current = null;      // the row the keyboard is on
    var lastUndo = null;     // {url, data} of the last action
    var lastRule = null;     // the rule the toast offers (« Toujours classer “Monoprix” ainsi »)
    var attachTo = null;     // the row a chosen file goes to
    var pickerRow = null;    // the row the picker is open for
    var splitRow = null;     // the row the split is open for
    var toastTimer = null;
    var observer = null;

    function root() {
        return document.querySelector('[data-ledger-review]');
    }

    function rows() {
        var r = root();
        return r ? Array.prototype.slice.call(r.querySelectorAll('.ledger-row')) : [];
    }

    function rowOf(element) {
        return element && element.closest ? element.closest('.ledger-row') : null;
    }

    function fold(text) {
        return (text || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }

    function money(minor) {
        var r = root();
        try {
            // Symfony's locale (« fr_FR ») as a language tag (« fr-FR »).
            return new Intl.NumberFormat((r.dataset.locale || 'fr').replace('_', '-'), { style: 'currency', currency: r.dataset.currency || 'EUR' }).format(minor / 100);
        } catch (e) {
            return (minor / 100).toFixed(2);
        }
    }

    function minor(text) {
        var match = (text || '').replace(/[\s  €]/g, '').match(/^(\d+)(?:[.,](\d{1,2}))?$/);
        return match ? parseInt(match[1], 10) * 100 + parseInt((match[2] || '0').padEnd(2, '0'), 10) : 0;
    }

    /* ---- requests ---- */

    function send(url, fields, method) {
        var r = root();
        var options = { method: method || 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } };
        if ('GET' !== options.method) {
            var body = new FormData();
            ['book', 'state', 'account', 'month', 'q'].forEach(function (key) {
                if (r.dataset[key]) {
                    body.append(key, r.dataset[key]);
                }
            });
            (fields || []).forEach(function (pair) {
                if (null !== pair[1] && undefined !== pair[1]) {
                    body.append(pair[0], pair[1]);
                }
            });
            body.append('_token', r.dataset.token);
            options.body = body;
        }
        r.setAttribute('aria-busy', 'true');
        return fetch(url, options)
            .then(function (response) {
                return response.json().catch(function () {
                    return { ok: false, message: r.dataset.textError };
                });
            })
            .catch(function () {
                return { ok: false, message: r.dataset.textError };
            })
            .finally(function () {
                r.removeAttribute('aria-busy');
            });
    }

    function pairs(object) {
        return Object.keys(object || {}).map(function (key) { return [key, object[key]]; });
    }

    function html(markup) {
        var template = document.createElement('template');
        template.innerHTML = markup.trim();
        return template.content.firstElementChild;
    }

    function replaceRow(id, markup) {
        var old = document.getElementById('ledger-line-' + id);
        if (!old) {
            return null;
        }
        var row = html(markup);
        var hadFocus = old.contains(document.activeElement);
        var wasCurrent = old === current;
        old.replaceWith(row);
        if (wasCurrent) {
            select(row, hadFocus);
        }
        return row;
    }

    /** What every answer may carry: the row, other rows, the counters, a message, how to undo it. */
    function apply(answer, after) {
        var r = root();
        if (!r) {
            return;
        }
        var row = null;
        if (answer.row && answer.id) {
            row = replaceRow(answer.id, answer.row);
        }
        Object.keys(answer.rows || {}).forEach(function (id) {
            replaceRow(id, answer.rows[id]);
        });
        if (answer.counts) {
            Object.keys(answer.counts).forEach(function (state) {
                var count = r.querySelector('[data-count="' + state + '"]');
                if (count) {
                    count.textContent = answer.counts[state];
                }
            });
        }
        if (answer.progress) {
            var text = r.querySelector('[data-progress-text]');
            var bar = r.querySelector('[data-progress-bar]');
            if (text && answer.progress.text) {
                text.textContent = answer.progress.text;
            }
            if (bar) {
                bar.style.width = (answer.progress.total ? Math.round(100 * answer.progress.done / answer.progress.total) : 0) + '%';
            }
        }
        if (answer.undo) {
            lastUndo = answer.undo;
        }
        toast(answer.message, answer.ok ? answer.undo : null, answer.ok ? answer.rule : null, !answer.ok);
        if (answer.reload) {
            window.location.reload();
            return;
        }
        if (after) {
            after(answer, row);
        }
    }

    /* ---- the toast ---- */

    function toast(message, undo, rule, error) {
        var r = root();
        var box = r && r.querySelector('[data-toast]');
        if (!box || !message) {
            return;
        }
        box.querySelector('[data-toast-text]').textContent = message;
        box.classList.toggle('is-error', !!error);
        var actions = box.querySelector('[data-toast-actions]');
        actions.innerHTML = '';
        lastRule = rule && rule.label ? rule : null;
        if (lastRule) {
            var offer = document.createElement('button');
            offer.type = 'button';
            offer.className = 'ledger-toast-rule';
            offer.innerHTML = '';
            offer.appendChild(document.createTextNode(r.dataset.textRule.replace('__LABEL__', rule.label) + ' '));
            var key = document.createElement('kbd');
            key.textContent = 'r';
            offer.appendChild(key);
            offer.addEventListener('click', function () { makeRule(rule); });
            actions.appendChild(offer);
        }
        if (undo) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'ledger-toast-undo';
            button.innerHTML = r.dataset.textUndo + ' <kbd>u</kbd>';
            button.addEventListener('click', function () { runUndo(undo); });
            actions.appendChild(button);
        }
        box.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            if (!box.matches(':hover') && !box.contains(document.activeElement)) {
                box.hidden = true;
            }
        }, rule ? 12000 : 7000);
    }

    function makeRule(rule) {
        if (!rule) {
            return;
        }
        lastRule = null;
        send(rule.url, [['number', rule.account], ['party', rule.party], ['tax', rule.tax]]).then(function (answer) { apply(answer); });
    }

    function runUndo(undo) {
        if (!undo) {
            return;
        }
        if (lastUndo === undo) {
            lastUndo = null;
        }
        send(undo.url, pairs(undo.data)).then(function (answer) {
            apply(answer);
            var r = root();
            var box = r && r.querySelector('[data-toast]');
            if (box && answer.ok) {
                box.querySelector('[data-toast-actions]').innerHTML = '';
            }
        });
    }

    /* ---- moving ---- */

    function select(row, focus) {
        if (current && current !== row) {
            current.classList.remove('is-current');
        }
        current = row;
        if (!row) {
            return;
        }
        row.classList.add('is-current');
        if (focus) {
            row.focus({ preventScroll: true });
            row.scrollIntoView({ block: 'nearest' });
        }
    }

    function move(step) {
        var list = rows();
        if (!list.length) {
            return;
        }
        var index = current ? list.indexOf(current) : -1;
        if (index < 0) {
            var first = list.filter(function (row) { return '1' === row.dataset.pending; })[0] || list[0];
            select(first, true);
            return;
        }
        select(list[Math.max(0, Math.min(list.length - 1, index + step))], true);
        if (index + step >= list.length) {
            loadMore();
        }
    }

    /** After a line is handled: the next one still to do below it, else the next row. */
    function next(row) {
        var list = rows();
        var index = list.indexOf(row);
        for (var i = index + 1; i < list.length; i++) {
            if ('1' === list[i].dataset.pending) {
                select(list[i], true);
                return;
            }
        }
        if (index >= 0 && index + 1 < list.length) {
            select(list[index + 1], true);
        }
    }

    /* ---- actions on a line ---- */

    function act(row, action, fields) {
        if (!row) {
            return;
        }
        var url = row.dataset['url' + action.charAt(0).toUpperCase() + action.slice(1)];
        if (!url) {
            return;
        }
        select(row, false);
        var handling = 'accept' === action || 'ignore' === action || 'categorize' === action || 'split' === action;
        send(url, fields).then(function (answer) {
            apply(answer, function (result, replaced) {
                if (result.ok && handling && replaced) {
                    next(replaced);
                }
            });
        });
    }

    function accept(row, index) {
        if (!row || '1' !== row.dataset.pending) {
            return;
        }
        var chip = row.querySelector('[data-action="accept"][data-suggestion="' + (index || 0) + '"]');
        if (!chip) {
            openPicker(row);
            return;
        }
        act(row, 'accept', [['suggestion', chip.dataset.suggestion]]);
    }

    function undoCurrent() {
        if (lastUndo) {
            runUndo(lastUndo);
            return;
        }
        if (current && '0' === current.dataset.pending) {
            act(current, 'undo');
        }
    }

    function attach(row) {
        var input = root().querySelector('[data-receipt-input]');
        if (!row || !input) {
            return;
        }
        attachTo = row;
        input.value = '';
        input.click();
    }

    function upload(row, file) {
        if (!row || !file) {
            return;
        }
        act(row, 'receipt', [['receipt', file]]);
    }

    /* ---- the category picker ---- */

    function picker() {
        var r = root();
        return r && r.querySelector('[data-picker]');
    }

    function openPicker(row) {
        var box = picker();
        if (!row || !box || '1' !== row.dataset.pending) {
            return;
        }
        closeSplit();
        pickerRow = row;
        select(row, false);
        row.after(box);
        box.hidden = false;
        var search = box.querySelector('[data-picker-search]');
        search.value = '';
        filterPicker('');
        search.focus();
    }

    function closePicker(back) {
        var box = picker();
        if (!box || box.hidden) {
            return;
        }
        box.hidden = true;
        var row = pickerRow;
        pickerRow = null;
        if (back && row && document.body.contains(row)) {
            select(row, true);
        }
    }

    function options() {
        var box = picker();
        return Array.prototype.slice.call(box.querySelectorAll('[role="option"]'));
    }

    function filterPicker(query) {
        var words = fold(query).split(/\s+/).filter(Boolean);
        var box = picker();
        var seen = {};
        options().forEach(function (option) {
            var text = fold(option.dataset.search);
            var match = words.every(function (word) { return -1 !== text.indexOf(word); });
            // Searching, an account shows once (the frequent ones are also in their group).
            if (match && words.length && seen[option.dataset.number]) {
                match = false;
            }
            if (match && words.length) {
                seen[option.dataset.number] = true;
            }
            option.hidden = !match;
        });
        Array.prototype.slice.call(box.querySelectorAll('.ledger-picker-group')).forEach(function (group) {
            var sibling = group.nextElementSibling;
            var any = false;
            while (sibling && !sibling.classList.contains('ledger-picker-group')) {
                any = any || !sibling.hidden;
                sibling = sibling.nextElementSibling;
            }
            group.hidden = !any;
        });
        highlight(options().filter(function (option) { return !option.hidden; })[0] || null);
    }

    function highlight(option) {
        var box = picker();
        options().forEach(function (other) {
            other.classList.toggle('is-active', other === option);
            other.setAttribute('aria-selected', other === option ? 'true' : 'false');
        });
        var search = box.querySelector('[data-picker-search]');
        if (option) {
            option.id = option.id || 'ledger-option-' + option.dataset.number + (option.hasAttribute('data-frequent') ? '-f' : '');
            search.setAttribute('aria-activedescendant', option.id);
            option.scrollIntoView({ block: 'nearest' });
        } else {
            search.removeAttribute('aria-activedescendant');
        }
        var vat = box.querySelector('[data-picker-vat]');
        if (vat) {
            var kind = option ? option.dataset.vat : '';
            vat.hidden = !kind;
            var tax = vat.querySelector('select');
            Array.prototype.slice.call(tax.options).forEach(function (choice) {
                choice.hidden = !!choice.dataset.vat && choice.dataset.vat !== kind;
            });
            if (tax.selectedOptions[0] && tax.selectedOptions[0].hidden) {
                tax.value = '';
            }
        }
    }

    function stepPicker(step) {
        var visible = options().filter(function (option) { return !option.hidden; });
        var active = picker().querySelector('[role="option"].is-active');
        var index = visible.indexOf(active);
        highlight(visible[Math.max(0, Math.min(visible.length - 1, index + step))] || null);
    }

    function choose(option) {
        var box = picker();
        var row = pickerRow;
        if (!option || !row) {
            return;
        }
        var vat = box.querySelector('[data-picker-vat]');
        var fields = [
            ['number', option.dataset.number],
            ['party', box.querySelector('[data-picker-party]').value],
            ['tax', vat && !vat.hidden ? vat.querySelector('select').value : ''],
        ];
        closePicker(false);
        act(row, 'categorize', fields);
    }

    /* ---- the split ---- */

    function dialog() {
        var r = root();
        return r && r.querySelector('[data-split]');
    }

    function openSplit(row) {
        var box = dialog();
        if (!row || !box || '1' !== row.dataset.pending) {
            return;
        }
        closePicker(false);
        splitRow = row;
        select(row, false);
        box.querySelector('[data-split-label]').textContent = (row.dataset.label || '') + ' · ' + money(parseInt(row.dataset.amount, 10));
        var parts = box.querySelector('[data-split-parts]');
        parts.innerHTML = '';
        box._candidates = [];
        addPart(row.dataset.amount);
        addPart('');
        send(row.dataset.urlCandidates, null, 'GET').then(function (items) {
            if (!Array.isArray(items)) {
                return;
            }
            box._candidates = items;
            Array.prototype.slice.call(parts.querySelectorAll('[data-part-items]')).forEach(fillItems);
        });
        if (box.showModal) {
            box.showModal();
        } else {
            box.setAttribute('open', '');
        }
        var first = parts.querySelector('[data-part-target]');
        if (first) {
            first.focus();
        }
        updateSplit();
    }

    function fillItems(group) {
        var box = dialog();
        group.innerHTML = '';
        (box._candidates || []).forEach(function (item) {
            var option = document.createElement('option');
            option.value = 'items:' + item.id;
            option.dataset.amount = item.amount;
            option.textContent = item.label + ' · ' + item.date + ' · ' + money(item.amount);
            group.appendChild(option);
        });
        group.hidden = !group.children.length;
    }

    function addPart(amount) {
        var box = dialog();
        var part = box.querySelector('[data-split-template]').content.firstElementChild.cloneNode(true);
        fillItems(part.querySelector('[data-part-items]'));
        var amountInput = part.querySelector('[data-part-amount]');
        if (amount) {
            amountInput.value = (parseInt(amount, 10) / 100).toFixed(2).replace('.', ',');
        }
        box.querySelector('[data-split-parts]').appendChild(part);
        updateSplit();
        return part;
    }

    function updateSplit() {
        var box = dialog();
        if (!box || !splitRow) {
            return;
        }
        var total = parseInt(splitRow.dataset.amount, 10);
        var used = 0;
        var complete = true;
        Array.prototype.slice.call(box.querySelectorAll('.ledger-split-part')).forEach(function (part) {
            var amount = minor(part.querySelector('[data-part-amount]').value);
            used += amount;
            if (amount > 0 && !part.querySelector('[data-part-target]').value) {
                complete = false;
            }
        });
        var left = total - used;
        box.querySelector('[data-split-left]').textContent = root().dataset.textLeft.replace('__AMOUNT__', money(left));
        box.querySelector('[data-split-left]').classList.toggle('is-off', 0 !== left);
        box.querySelector('[data-split-submit]').disabled = !(0 === left && used > 0 && complete);
    }

    function submitSplit() {
        var box = dialog();
        var row = splitRow;
        if (!row) {
            return;
        }
        var fields = [];
        var index = 0;
        Array.prototype.slice.call(box.querySelectorAll('.ledger-split-part')).forEach(function (part) {
            var amount = minor(part.querySelector('[data-part-amount]').value);
            var target = part.querySelector('[data-part-target]').value;
            if (amount <= 0 || !target) {
                return;
            }
            var key = 'parts[' + index++ + ']';
            fields.push([key + '[amount]', (amount / 100).toFixed(2)]);
            fields.push(0 === target.indexOf('items:') ? [key + '[items]', target.slice(6)] : [key + '[account]', target.slice(8)]);
            fields.push([key + '[label]', part.querySelector('[data-part-label]').value]);
        });
        closeSplit();
        act(row, 'split', fields);
    }

    function closeSplit() {
        var box = dialog();
        if (box && box.open) {
            box.close ? box.close() : box.removeAttribute('open');
        }
        splitRow = null;
    }

    /* ---- the list's next page ---- */

    function loadMore() {
        var r = root();
        var button = r && r.querySelector('[data-more]');
        if (!button || button.dataset.loading) {
            return;
        }
        button.dataset.loading = '1';
        send(button.dataset.more, null, 'GET').then(function (answer) {
            delete button.dataset.loading;
            if (!answer || undefined === answer.html) {
                return;
            }
            var list = r.querySelector('[data-list]');
            var template = document.createElement('template');
            template.innerHTML = answer.html;
            list.appendChild(template.content);
            if (answer.more) {
                button.dataset.more = answer.more;
            } else {
                button.remove();
            }
        });
    }

    /* ---- listeners, all on the document ---- */

    document.addEventListener('click', function (event) {
        var r = root();
        if (!r || !r.contains(event.target)) {
            return;
        }
        var confirmable = event.target.closest('[data-confirm]');
        if (confirmable && !window.confirm(confirmable.dataset.confirm)) {
            event.preventDefault();
            return;
        }
        var option = event.target.closest('[data-picker] [role="option"]');
        if (option) {
            choose(option);
            return;
        }
        if (event.target.closest('[data-more]')) {
            loadMore();
            return;
        }
        if (event.target.closest('[data-split-add]')) {
            addPart('');
            return;
        }
        if (event.target.closest('[data-part-remove]')) {
            event.target.closest('.ledger-split-part').remove();
            updateSplit();
            return;
        }
        if (event.target.closest('[data-split-cancel]')) {
            closeSplit();
            return;
        }
        // Buttons, not a form's submission: the site's navigation (transparent.js) may take a form's submit for itself.
        var submit = event.target.closest('[data-split-submit]');
        if (submit) {
            if (!submit.disabled) {
                submitSplit();
            }
            return;
        }
        var button = event.target.closest('[data-action]');
        var row = rowOf(event.target);
        if (button && !button.disabled) {
            var action = button.dataset.action;
            if ('sync' === action || 'auto' === action) {
                button.disabled = true;
                send(r.dataset['url' + action.charAt(0).toUpperCase() + action.slice(1)]).then(function (answer) {
                    button.disabled = false;
                    apply(answer);
                });
                return;
            }
            if ('accept' === action) {
                var details = button.closest('details');
                if (details) {
                    details.open = false;
                }
                accept(row, parseInt(button.dataset.suggestion, 10));
            } else if ('choose' === action) {
                openPicker(row);
            } else if ('split' === action) {
                openSplit(row);
            } else if ('attach' === action) {
                attach(row);
            } else if ('missing' === action) {
                act(row, 'missing');
            } else {
                act(row, action);
            }
            return;
        }
        if (row && !event.target.closest('a, button, summary, input, select, label')) {
            select(row, true);
        }
    });

    document.addEventListener('change', function (event) {
        var r = root();
        if (!r || !event.target.closest) {
            return;
        }
        if (event.target.matches('[data-ledger-goto]')) {
            window.location.href = event.target.value;
            return;
        }
        if (!r.contains(event.target)) {
            return;
        }
        if (event.target.matches('[data-ledger-submit]')) {
            var form = event.target.form;
            form.requestSubmit ? form.requestSubmit() : form.submit();
        } else if (event.target.matches('[data-receipt-input]')) {
            upload(attachTo, event.target.files[0]);
            attachTo = null;
        } else if (event.target.matches('[data-part-target]')) {
            // A piece chosen: its amount, when the part has none yet.
            var picked = event.target.selectedOptions[0];
            var amount = event.target.closest('.ledger-split-part').querySelector('[data-part-amount]');
            if (picked && picked.dataset.amount && !minor(amount.value)) {
                amount.value = (parseInt(picked.dataset.amount, 10) / 100).toFixed(2).replace('.', ',');
            }
            updateSplit();
        }
    });

    document.addEventListener('input', function (event) {
        if (!root() || !event.target.matches) {
            return;
        }
        if (event.target.matches('[data-picker-search]')) {
            filterPicker(event.target.value);
        } else if (event.target.matches('[data-part-amount]')) {
            updateSplit();
        }
    });

    document.addEventListener('submit', function (event) {
        if (!root() || !event.target.matches) {
            return;
        }
        if (event.target.matches('[data-picker-form]')) {
            event.preventDefault();
            choose(picker().querySelector('[role="option"].is-active'));
        } else if (event.target.matches('[data-split-form]')) {
            event.preventDefault();
            if (!dialog().querySelector('[data-split-submit]').disabled) {
                submitSplit();
            }
        }
    });

    document.addEventListener('keydown', function (event) {
        var r = root();
        if (!r || event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }
        var target = event.target;
        if (target.matches && target.matches('[data-picker-search]')) {
            if ('ArrowDown' === event.key || 'ArrowUp' === event.key) {
                event.preventDefault();
                stepPicker('ArrowDown' === event.key ? 1 : -1);
            } else if ('Enter' === event.key) {
                event.preventDefault();
                choose(picker().querySelector('[role="option"].is-active'));
            } else if ('Escape' === event.key) {
                event.preventDefault();
                closePicker(true);
            }
            return;
        }
        var box = picker();
        if (box && !box.hidden && box.contains(target) && 'Escape' === event.key) {
            closePicker(true);
            return;
        }
        var split = dialog();
        if (split && split.open) {
            return; // the dialog's own keys (Esc closes it)
        }
        var typing = target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName);
        if (typing) {
            if ('Escape' === event.key && target.matches('[data-ledger-search]')) {
                target.blur();
            }
            return;
        }
        if (!r.contains(target) && target !== document.body && target !== document.documentElement) {
            return; // the site's header, a menu: their keys
        }
        var onControl = /^(BUTTON|A|SUMMARY)$/.test(target.tagName);
        switch (event.key) {
            case 'j':
            case 'ArrowDown':
                event.preventDefault();
                move(1);
                break;
            case 'k':
            case 'ArrowUp':
                event.preventDefault();
                move(-1);
                break;
            case 'Enter':
                if (onControl || !current) {
                    return;
                }
                event.preventDefault();
                accept(current, 0);
                break;
            case 'c':
                event.preventDefault();
                openPicker(current);
                break;
            case 's':
                event.preventDefault();
                openSplit(current);
                break;
            case 'i':
                if (current && '1' === current.dataset.pending) {
                    event.preventDefault();
                    act(current, 'ignore');
                }
                break;
            case 'u':
                event.preventDefault();
                undoCurrent();
                break;
            case 'r':
                if (lastRule) {
                    event.preventDefault();
                    makeRule(lastRule);
                }
                break;
            case 'a':
                event.preventDefault();
                attach(current);
                break;
            case 'm':
                if (current && current.querySelector('[data-action="missing"]')) {
                    event.preventDefault();
                    act(current, 'missing');
                }
                break;
            case '/':
                event.preventDefault();
                var search = r.querySelector('[data-ledger-search]');
                if (search) {
                    search.focus();
                    search.select();
                }
                break;
            case 'Escape':
                closePicker(true);
                Array.prototype.slice.call(r.querySelectorAll('details[open]')).forEach(function (details) { details.open = false; });
                break;
        }
    });

    // A receipt dropped on a row.
    function hasFiles(event) {
        return event.dataTransfer && Array.prototype.indexOf.call(event.dataTransfer.types || [], 'Files') !== -1;
    }
    document.addEventListener('dragover', function (event) {
        var row = rowOf(event.target);
        if (!root() || !row || !hasFiles(event) || !row.dataset.urlReceipt) {
            return;
        }
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
        rows().forEach(function (other) { other.classList.toggle('is-drop', other === row); });
    });
    document.addEventListener('dragleave', function (event) {
        var row = rowOf(event.target);
        if (row && !row.contains(event.relatedTarget)) {
            row.classList.remove('is-drop');
        }
    });
    document.addEventListener('drop', function (event) {
        var row = rowOf(event.target);
        if (!root() || !row || !hasFiles(event)) {
            return;
        }
        event.preventDefault();
        row.classList.remove('is-drop');
        upload(row, event.dataTransfer.files[0]);
    });

    document.addEventListener('close', function (event) {
        if (event.target.matches && event.target.matches('[data-split]')) {
            splitRow = null;
        }
    }, true);

    /** The next page loads when its button comes into view. */
    function scan() {
        var r = root();
        if (!r) {
            current = null;
            return;
        }
        if (current && !r.contains(current)) {
            current = null;
        }
        var button = r.querySelector('[data-more]:not([data-watched])');
        if (button && 'IntersectionObserver' in window) {
            button.setAttribute('data-watched', '');
            if (observer) {
                observer.disconnect();
            }
            observer = new IntersectionObserver(function (entries) {
                if (entries.some(function (entry) { return entry.isIntersecting; })) {
                    loadMore();
                }
            }, { rootMargin: '400px' });
            observer.observe(button);
        }
    }

    window.LedgerReview = { scan: scan };
    scan();
    if ('MutationObserver' in window) {
        var pending = null;
        new MutationObserver(function () {
            clearTimeout(pending);
            pending = setTimeout(scan, 50);
        }).observe(document.body || document.documentElement, { childList: true, subtree: true });
    }
})();
