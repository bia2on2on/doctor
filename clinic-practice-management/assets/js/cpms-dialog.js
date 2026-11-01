/**
 * CPMS in-page confirm / prompt dialog — ONE accessible Persian implementation.
 *
 * Replaces native `window.confirm` / `window.prompt` on CPMS surfaces so the
 * confirmation stays inside the product: RTL Persian labels, visible focus,
 * focus trap, Escape = cancel, Enter = confirm, focus restored to the trigger,
 * `role="dialog"` + `aria-modal` + labelled title/message.
 *
 * Contract:
 *   window.CpmsDialog.confirm(message, options?) -> Promise<boolean>
 *   window.CpmsDialog.prompt(message, options?)  -> Promise<string|null>
 *
 * options (both): { title, confirmLabel, cancelLabel }
 * options (prompt): { value, multiline, inputMode, required, maxLength }
 *
 * This is UX only, never authorization: every action behind a confirmation is
 * still re-authorized server-side (nonce + capability + scope).
 *
 * BACKWARD-COMPATIBLE MARKUP CONTRACT (do not remove):
 * the shipped acceptance harness `bin/rwp-acceptance.py::capture_confirm()`
 * drives a dangerous action and asserts on the PRODUCTION dialog surface —
 * it requires an element with class `cpms-modal-overlay`, a focused element
 * whose class contains `confirm`, and Escape closing the overlay. Those two
 * class tokens are therefore kept on the shared dialog's overlay and confirm
 * button, so replacing the per-page modal with this component does not weaken
 * or bypass the existing browser gate.
 */
(function () {
    'use strict';

    if (typeof window === 'undefined') {
        return;
    }
    if (window.CpmsDialog && window.CpmsDialog.__cpmsShared === true) {
        return;
    }

    var STYLE_ID = 'cpms-dialog-styles';
    var CSS = '' +
        '.cpms-dialog-overlay{position:fixed;inset:0;z-index:100000;display:flex;' +
        'align-items:center;justify-content:center;padding:16px;background:rgba(15,23,42,.55)}\n' +
        '.cpms-dialog{background:#fff;color:#1f2937;border-radius:10px;max-width:520px;width:100%;' +
        'padding:18px;box-shadow:0 20px 50px rgba(15,23,42,.35);direction:rtl;text-align:right}\n' +
        '.cpms-dialog__title{margin:0 0 8px;font-size:1.1rem}\n' +
        '.cpms-dialog__message{margin:0 0 12px;line-height:1.8;overflow-wrap:anywhere}\n' +
        '.cpms-dialog__input{width:100%;min-height:44px;padding:8px 10px;border:1px solid #cbd5e1;' +
        'border-radius:8px;font:inherit;margin-bottom:10px;box-sizing:border-box}\n' +
        '.cpms-dialog__error{margin:0 0 10px;color:#a12828;font-size:.86rem}\n' +
        '.cpms-dialog__actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-start}\n' +
        '.cpms-dialog__btn{min-height:40px;padding:6px 16px;border:1px solid #cbd5e1;border-radius:8px;' +
        'background:#fff;color:inherit;font:inherit;cursor:pointer}\n' +
        '.cpms-dialog__btn--primary{background:#0f5c56;border-color:#0f5c56;color:#fff}\n' +
        '.cpms-dialog__btn:focus-visible,.cpms-dialog__input:focus-visible{outline:3px solid #0f5c56;outline-offset:2px}\n';

    function ensureStyles() {
        if (document.getElementById(STYLE_ID)) {
            return;
        }
        var style = document.createElement('style');
        style.id = STYLE_ID;
        style.textContent = CSS;
        (document.head || document.body || document.documentElement).appendChild(style);
    }

    function el(tag, className) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        return node;
    }

    function open(options) {
        var settings = options || {};
        var isPrompt = settings.mode === 'prompt';
        var lastFocus = document.activeElement;

        ensureStyles();
        var previous = document.querySelector('.cpms-dialog-overlay');
        if (previous && previous.parentNode) {
            previous.parentNode.removeChild(previous);
        }

        // `cpms-modal-overlay` is the legacy/shipped acceptance token — see the
        // markup contract in this file's header.
        var overlay = el('div', 'cpms-dialog-overlay cpms-modal-overlay');
        var dialog = el('div', 'cpms-dialog');
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('dir', 'rtl');

        var title = el('h3', 'cpms-dialog__title');
        title.id = 'cpms-dialog-title';
        title.textContent = settings.title || (isPrompt ? 'ورود اطلاعات' : 'تأیید عملیات');

        var message = el('p', 'cpms-dialog__message');
        message.id = 'cpms-dialog-message';
        message.textContent = settings.message || '';

        dialog.setAttribute('aria-labelledby', title.id);
        dialog.setAttribute('aria-describedby', message.id);
        dialog.appendChild(title);
        dialog.appendChild(message);

        var input = null;
        var error = null;
        if (isPrompt) {
            if (settings.multiline) {
                input = el('textarea', 'cpms-dialog__input');
                input.rows = 3;
            } else {
                input = el('input', 'cpms-dialog__input');
                input.type = 'text';
            }
            input.value = settings.value === undefined || settings.value === null ? '' : String(settings.value);
            if (settings.maxLength) {
                input.setAttribute('maxlength', String(settings.maxLength));
            }
            if (settings.inputMode) {
                input.setAttribute('inputmode', String(settings.inputMode));
            }
            input.setAttribute('aria-describedby', message.id);
            dialog.appendChild(input);
            error = el('p', 'cpms-dialog__error');
            error.setAttribute('role', 'alert');
            error.hidden = true;
            dialog.appendChild(error);
        }

        var actions = el('div', 'cpms-dialog__actions');
        var confirmBtn = el('button', 'cpms-dialog__btn cpms-dialog__btn--primary');
        confirmBtn.type = 'button';
        confirmBtn.className = 'cpms-dialog__btn cpms-dialog__btn--primary cpms-modal-confirm';
        confirmBtn.textContent = settings.confirmLabel || (isPrompt ? 'ثبت' : 'تأیید و ادامه');
        var cancelBtn = el('button', 'cpms-dialog__btn');
        cancelBtn.type = 'button';
        cancelBtn.textContent = settings.cancelLabel || 'انصراف';
        actions.appendChild(confirmBtn);
        actions.appendChild(cancelBtn);
        dialog.appendChild(actions);

        overlay.appendChild(dialog);
        (document.body || document.documentElement).appendChild(overlay);

        return new Promise(function (resolve) {
            var settled = false;

            function finish(value) {
                if (settled) {
                    return;
                }
                settled = true;
                document.removeEventListener('keydown', onKey, true);
                if (overlay.parentNode) {
                    overlay.parentNode.removeChild(overlay);
                }
                resolve(value);
                if (lastFocus && typeof lastFocus.focus === 'function') {
                    try {
                        lastFocus.focus();
                    } catch (e) { /* detached trigger node */ }
                }
            }

            function focusables() {
                var list = [confirmBtn, cancelBtn];
                if (input) {
                    list.unshift(input);
                }
                return list;
            }

            function submit() {
                if (!input) {
                    finish(true);
                    return;
                }
                var value = String(input.value || '').trim();
                if (settings.required === true && value === '') {
                    error.textContent = settings.requiredMessage || 'این مقدار الزامی است.';
                    error.hidden = false;
                    input.focus();
                    return;
                }
                finish(value);
            }

            function onKey(event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    finish(isPrompt ? null : false);
                    return;
                }
                if (event.key === 'Enter' && !settings.multiline && !event.shiftKey) {
                    var tag = (event.target && event.target.tagName) || '';
                    if (tag !== 'TEXTAREA') {
                        event.preventDefault();
                        submit();
                        return;
                    }
                }
                if (event.key === 'Tab') {
                    var list = focusables();
                    var current = list.indexOf(document.activeElement);
                    if (event.shiftKey) {
                        if (current <= 0) {
                            event.preventDefault();
                            list[list.length - 1].focus();
                        }
                    } else if (current === -1 || current === list.length - 1) {
                        event.preventDefault();
                        list[0].focus();
                    }
                }
            }

            confirmBtn.addEventListener('click', submit);
            cancelBtn.addEventListener('click', function () { finish(isPrompt ? null : false); });
            overlay.addEventListener('click', function (event) {
                if (event.target === overlay) {
                    finish(isPrompt ? null : false);
                }
            });
            document.addEventListener('keydown', onKey, true);

            if (input) {
                input.focus();
                if (typeof input.select === 'function') {
                    input.select();
                }
            } else {
                confirmBtn.focus();
            }
        });
    }

    window.CpmsDialog = {
        __cpmsShared: true,
        confirm: function (message, options) {
            var settings = options || {};
            settings.mode = 'confirm';
            settings.message = message;
            return open(settings);
        },
        prompt: function (message, options) {
            var settings = options || {};
            settings.mode = 'prompt';
            settings.message = message;
            return open(settings);
        }
    };
})();
