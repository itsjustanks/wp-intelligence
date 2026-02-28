(function () {
  'use strict';

  var l10n = window.wpiSettingsL10n || {};

  function formatCount(selected, total) {
    var template = l10n.blockCountTemplate || '%1$d / %2$d blocks enabled';
    return template.replace('%1$d', selected).replace('%2$d', total);
  }

  function initBlockSelector() {
    var list = document.getElementById('wpi-block-list');
    if (!list) {
      return;
    }

    var radios = document.querySelectorAll('.wpi-mode-radio');
    var checkboxes = list.querySelectorAll('.wpi-block-cb');
    var countNode = document.getElementById('wpi-count');
    var searchInput = document.getElementById('wpi-block-search');
    var selectAll = document.getElementById('wpi-sel-all');
    var selectNone = document.getElementById('wpi-sel-none');

    function updateCount() {
      if (!countNode) {
        return;
      }
      var selected = list.querySelectorAll('.wpi-block-cb:checked').length;
      countNode.textContent = formatCount(selected, checkboxes.length);
    }

    function updateModeState() {
      var allMode = false;

      radios.forEach(function (radio) {
        if (radio.checked && radio.value === 'all') {
          allMode = true;
        }
      });

      list.classList.toggle('is-disabled', allMode);
    }

    function filterBlocks(query) {
      var normalized = (query || '').trim().toLowerCase();
      var details = list.querySelectorAll('details');

      details.forEach(function (detail) {
        var rows = detail.querySelectorAll('.wpi-block-candidate');
        var visibleRows = 0;

        rows.forEach(function (row) {
          var haystack = (row.getAttribute('data-block-search') || '').toLowerCase();
          var visible = normalized === '' || haystack.indexOf(normalized) !== -1;
          row.style.display = visible ? 'block' : 'none';
          if (visible) {
            visibleRows++;
          }
        });

        detail.style.display = visibleRows > 0 ? 'block' : 'none';
      });
    }

    radios.forEach(function (radio) {
      radio.addEventListener('change', updateModeState);
    });

    checkboxes.forEach(function (checkbox) {
      checkbox.addEventListener('change', updateCount);
    });

    if (searchInput) {
      searchInput.addEventListener('input', function () {
        filterBlocks(searchInput.value);
      });
    }

    if (selectAll) {
      selectAll.addEventListener('click', function () {
        checkboxes.forEach(function (checkbox) {
          if (!checkbox.disabled) {
            checkbox.checked = true;
          }
        });
        updateCount();
      });
    }

    if (selectNone) {
      selectNone.addEventListener('click', function () {
        checkboxes.forEach(function (checkbox) {
          if (!checkbox.disabled) {
            checkbox.checked = false;
          }
        });
        updateCount();
      });
    }

    updateModeState();
    updateCount();
  }

  function initResourceHintRows() {
    var list = document.getElementById('wpi-origins-list');
    var addButton = document.getElementById('wpi-add-origin');
    if (!list || !addButton) {
      return;
    }

    var optionName = addButton.getAttribute('data-option') || list.getAttribute('data-option') || 'wpi_resource_hints';
    var nextIndex = parseInt(list.getAttribute('data-next-index') || '0', 10);
    if (!Number.isFinite(nextIndex) || nextIndex < 0) {
      nextIndex = list.querySelectorAll('.wpi-origin-row').length;
    }

    function buildRow(index) {
      var row = document.createElement('div');
      row.className = 'wpi-origin-row';
      row.innerHTML =
        '<input type="url" class="regular-text" name="' + optionName + '[origins][' + index + '][url]" value="" placeholder="https://cdn.example.com">' +
        '<label><input type="checkbox" name="' + optionName + '[origins][' + index + '][crossorigin]" value="1"> crossorigin</label>' +
        '<button type="button" class="button button-link-delete wpi-rm-origin" aria-label="Remove origin">&times;</button>';
      return row;
    }

    addButton.addEventListener('click', function () {
      list.appendChild(buildRow(nextIndex));
      nextIndex++;
      list.setAttribute('data-next-index', String(nextIndex));
    });

    list.addEventListener('click', function (event) {
      var target = event.target;
      if (!(target instanceof Element)) {
        return;
      }
      if (!target.classList.contains('wpi-rm-origin')) {
        return;
      }
      var row = target.closest('.wpi-origin-row');
      if (row) {
        row.remove();
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initBlockSelector();
    initResourceHintRows();
  });
})();
