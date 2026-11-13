(function () {
  'use strict';
  const selector = 'input[data-amount-format]';

  function formatAmount(value) {
    const cleaned = String(value == null ? '' : value).replace(/[^0-9.]/g, '');
    if (!cleaned) return '';
    const decimalIndex = cleaned.indexOf('.');
    const hasDecimal = decimalIndex !== -1;
    let integerPart = hasDecimal ? cleaned.slice(0, decimalIndex) : cleaned;
    const decimalPart = hasDecimal ? cleaned.slice(decimalIndex + 1).replace(/\./g, '').slice(0, 2) : '';
    integerPart = integerPart.replace(/^0+(?=\d)/, '');
    const groupedInteger = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    if (!hasDecimal) return groupedInteger;
    return (groupedInteger || '0') + '.' + decimalPart;
  }

  function formatInput(input, preserveCaret) {
    const previousValue = input.value;
    const start = preserveCaret ? input.selectionStart : null;
    const end = preserveCaret ? input.selectionEnd : null;
    const nextValue = formatAmount(previousValue);
    if (nextValue === previousValue) return;
    input.value = nextValue;
    if (start !== null && end !== null && typeof input.setSelectionRange === 'function') {
      try {
        input.setSelectionRange(
          formatAmount(previousValue.slice(0, start)).length,
          formatAmount(previousValue.slice(0, end)).length
        );
      } catch (error) {
        // Some browsers do not expose selection ranges for every input state.
      }
    }
  }

  document.querySelectorAll(selector).forEach(input => formatInput(input, false));
  document.addEventListener('input', event => {
    if (event.target instanceof HTMLInputElement && event.target.matches(selector)) formatInput(event.target, true);
  });
  document.addEventListener('focusout', event => {
    if (event.target instanceof HTMLInputElement && event.target.matches(selector)) formatInput(event.target, false);
  });
})();
