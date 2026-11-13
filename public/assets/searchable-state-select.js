(function () {
  'use strict';

  const root = document.querySelector('[data-searchable-state]');
  if (!root) return;

  const input = root.querySelector('[data-state-search]');
  const hidden = root.querySelector('[data-state-value]');
  const menu = root.querySelector('.searchable-state-menu');
  const emptyMessage = root.querySelector('[data-state-empty]');
  const options = Array.from(root.querySelectorAll('[data-state-option]'));
  if (!input || !hidden || !menu || !emptyMessage || options.length === 0) return;

  let visibleOptions = [];
  let activeIndex = -1;

  function openMenu() {
    menu.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  }

  function closeMenu() {
    menu.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    activeIndex = -1;
    options.forEach(function (option) {
      option.classList.remove('is-active');
    });
  }

  function filterOptions(query) {
    const normalizedQuery = String(query || '').trim().toLocaleLowerCase();
    visibleOptions = options.filter(function (option) {
      const searchableText = String(option.dataset.search || option.dataset.value || '').toLocaleLowerCase();
      const matches = normalizedQuery === '' || searchableText.includes(normalizedQuery);
      option.hidden = !matches;
      option.classList.remove('is-active');
      return matches;
    });
    emptyMessage.hidden = visibleOptions.length > 0;
    activeIndex = -1;
  }

  function activateOption(index) {
    if (!visibleOptions.length) return;
    activeIndex = Math.max(0, Math.min(index, visibleOptions.length - 1));
    options.forEach(function (option) {
      option.classList.remove('is-active');
    });
    const activeOption = visibleOptions[activeIndex];
    activeOption.classList.add('is-active');
    activeOption.scrollIntoView({ block: 'nearest' });
  }

  function chooseOption(option) {
    const selectedValue = String(option.dataset.value || '');
    input.value = selectedValue;
    hidden.value = selectedValue;
    options.forEach(function (item) {
      item.setAttribute('aria-selected', item === option ? 'true' : 'false');
    });
    closeMenu();
  }

  input.addEventListener('focus', function () {
    const currentValueIsSelected = hidden.value !== '' && input.value === hidden.value;
    filterOptions(currentValueIsSelected ? '' : input.value);
    openMenu();
    if (currentValueIsSelected) input.select();
  });

  input.addEventListener('input', function () {
    hidden.value = '';
    options.forEach(function (option) {
      option.setAttribute('aria-selected', 'false');
    });
    filterOptions(input.value);
    openMenu();
  });

  input.addEventListener('keydown', function (event) {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      if (menu.hidden) {
        filterOptions(input.value);
        openMenu();
      }
      activateOption(activeIndex < 0 ? 0 : (activeIndex + 1) % visibleOptions.length);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      if (menu.hidden) {
        filterOptions(input.value);
        openMenu();
      }
      activateOption(activeIndex < 0 ? visibleOptions.length - 1 : (activeIndex - 1 + visibleOptions.length) % visibleOptions.length);
    } else if (event.key === 'Enter' && !menu.hidden) {
      const exactMatch = visibleOptions.find(function (option) {
        return String(option.dataset.value || '').toLocaleLowerCase() === input.value.trim().toLocaleLowerCase();
      });
      const selectedOption = activeIndex >= 0 ? visibleOptions[activeIndex] : exactMatch;
      if (selectedOption) {
        event.preventDefault();
        chooseOption(selectedOption);
      }
    } else if (event.key === 'Escape') {
      closeMenu();
    } else if (event.key === 'Tab') {
      closeMenu();
    }
  });

  menu.addEventListener('mousedown', function (event) {
    event.preventDefault();
  });

  menu.addEventListener('click', function (event) {
    const option = event.target.closest('[data-state-option]');
    if (option && !option.hidden) chooseOption(option);
  });

  document.addEventListener('click', function (event) {
    if (!root.contains(event.target)) closeMenu();
  });
})();
