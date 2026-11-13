(function () {
  'use strict';

  function isoToUs(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
    if (!match) return '';
    return match[2] + '/' + match[3] + '/' + match[1];
  }

  function parseUsDate(value) {
    const match = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(String(value || '').trim());
    if (!match) return '';
    const month = Number(match[1]);
    const day = Number(match[2]);
    const year = Number(match[3]);
    if (year < 1000 || year > 9999 || month < 1 || month > 12 || day < 1 || day > 31) return '';
    const check = new Date(Date.UTC(year, month - 1, day));
    if (check.getUTCFullYear() !== year || check.getUTCMonth() !== month - 1 || check.getUTCDate() !== day) return '';
    return String(year).padStart(4, '0') + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0');
  }

  function formatDigits(value) {
    const digits = String(value || '').replace(/\D/g, '').slice(0, 8);
    if (digits.length <= 2) return digits;
    if (digits.length <= 4) return digits.slice(0, 2) + '/' + digits.slice(2);
    return digits.slice(0, 2) + '/' + digits.slice(2, 4) + '/' + digits.slice(4);
  }

  function normalizeTypedValue(display) {
    const oldValue = display.value;
    if (oldValue.indexOf('/') !== -1) {
      const parts = oldValue.split('/').slice(0, 3).map(function (part, index) {
        return part.replace(/\D/g, '').slice(0, index === 2 ? 4 : 2);
      });
      display.value = parts.join('/');
      return;
    }

    const caret = display.selectionStart === null ? oldValue.length : display.selectionStart;
    const digitsBeforeCaret = oldValue.slice(0, caret).replace(/\D/g, '').length;
    const formatted = formatDigits(oldValue);
    if (formatted !== oldValue) {
      display.value = formatted;
      let position = 0;
      let digitCount = 0;
      while (position < formatted.length && digitCount < digitsBeforeCaret) {
        if (/\d/.test(formatted.charAt(position))) digitCount++;
        position++;
      }
      while (position < formatted.length && formatted.charAt(position) === '/') position++;
      try { display.setSelectionRange(position, position); } catch (ignore) {}
    }
  }

  function getNative(display) {
    const wrapper = display.closest('.us-date-control');
    return wrapper ? wrapper.querySelector('.us-date-native') : null;
  }

  function syncNative(display, formatOnSuccess) {
    const native = getNative(display);
    if (!native) return false;
    const value = display.value.trim();
    if (value === '') {
      native.value = '';
      display.setCustomValidity('');
      return true;
    }
    const iso = parseUsDate(value);
    if (!iso) {
      native.value = '';
      display.setCustomValidity('');
      return false;
    }
    native.value = iso;
    display.setCustomValidity('');
    if (formatOnSuccess) display.value = isoToUs(iso);
    return true;
  }

  function attach(input) {
    if (input.dataset.usDateNative === '1') return;
    input.dataset.usDateNative = '1';

    const parent = input.parentNode;
    if (!parent) return;
    const originalId = input.id;
    const wasRequired = input.required;
    const wrapper = document.createElement('span');
    wrapper.className = 'us-date-control';
    parent.insertBefore(wrapper, input);

    const display = document.createElement('input');
    display.type = 'text';
    display.className = 'us-date-display';
    display.value = isoToUs(input.value);
    display.placeholder = 'MM/DD/YYYY';
    display.inputMode = 'numeric';
    display.autocomplete = 'off';
    display.spellcheck = false;
    display.required = wasRequired;
    display.setAttribute('aria-label', 'Date (MM/DD/YYYY)');
    display.setAttribute('data-us-date-display', '1');
    if (originalId) {
      display.id = originalId;
      input.removeAttribute('id');
    }
    const describedBy = input.getAttribute('aria-describedby');
    if (describedBy) display.setAttribute('aria-describedby', describedBy);

    input.classList.add('us-date-native');
    input.required = false;
    input.tabIndex = -1;
    input.setAttribute('aria-hidden', 'true');

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'us-date-trigger';
    trigger.setAttribute('aria-label', 'Choose date');
    trigger.setAttribute('title', 'Choose date');
    trigger.textContent = '▦';

    wrapper.appendChild(display);
    wrapper.appendChild(trigger);
    wrapper.appendChild(input);

    const syncDisabled = function () {
      display.disabled = input.disabled;
      trigger.disabled = input.disabled;
      display.required = wasRequired && !input.disabled;
    };
    syncDisabled();
    if (typeof MutationObserver !== 'undefined') {
      new MutationObserver(syncDisabled).observe(input, { attributes: true, attributeFilter: ['disabled'] });
    }
  }

  document.addEventListener('input', function (event) {
    const display = event.target;
    if (!(display instanceof HTMLInputElement) || !display.matches('.us-date-display')) return;
    normalizeTypedValue(display);
    syncNative(display, false);
  });

  document.addEventListener('blur', function (event) {
    const display = event.target;
    if (!(display instanceof HTMLInputElement) || !display.matches('.us-date-display')) return;
    if (display.value.trim() === '') {
      syncNative(display, false);
      return;
    }
    if (!syncNative(display, true)) {
      display.setCustomValidity('Enter a valid date in MM/DD/YYYY format.');
    }
  }, true);

  document.addEventListener('change', function (event) {
    const native = event.target;
    if (!(native instanceof HTMLInputElement) || !native.matches('.us-date-native')) return;
    const wrapper = native.closest('.us-date-control');
    const display = wrapper ? wrapper.querySelector('.us-date-display') : null;
    if (display) {
      display.value = isoToUs(native.value);
      display.setCustomValidity('');
    }
  }, true);

  document.addEventListener('click', function (event) {
    const trigger = event.target.closest('.us-date-trigger');
    if (!trigger || trigger.disabled) return;
    const wrapper = trigger.closest('.us-date-control');
    const native = wrapper ? wrapper.querySelector('.us-date-native') : null;
    if (!native || native.disabled) return;
    try {
      if (typeof native.showPicker === 'function') {
        native.showPicker();
      } else {
        native.focus();
        native.click();
      }
    } catch (error) {
      try { native.focus(); native.click(); } catch (ignore) {}
    }
  });

  document.addEventListener('submit', function (event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    const fields = form.querySelectorAll('.us-date-display');
    for (const display of fields) {
      if (display.disabled || display.value.trim() === '') {
        syncNative(display, false);
        continue;
      }
      if (!syncNative(display, true)) {
        display.setCustomValidity('Enter a valid date in MM/DD/YYYY format, for example 11/13/2026.');
        display.reportValidity();
        event.preventDefault();
        return;
      }
    }
  }, true);

  function initialize() {
    document.querySelectorAll('input[type="date"]:not([data-us-date-native="1"])').forEach(attach);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize);
  } else {
    initialize();
  }
})();