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

  function initFetchStrategyToggle() {
    var radios = document.querySelectorAll('input[name$="[syndication][fetch_strategy]"]');
    var keyRow = document.getElementById('wpi-firecrawl-key-row');
    if (!radios.length || !keyRow) {
      return;
    }

    function toggle() {
      var selected = 'builtin';
      radios.forEach(function (r) { if (r.checked) selected = r.value; });
      keyRow.style.opacity = selected === 'firecrawl' ? '1' : '0.5';
      var input = keyRow.querySelector('input[type="password"], input[type="text"]');
      if (input) {
        input.required = selected === 'firecrawl';
      }
    }

    radios.forEach(function (r) { r.addEventListener('change', toggle); });
    toggle();
  }

  function initKeyToggleButtons() {
    document.addEventListener('click', function (e) {
      if (!e.target.classList.contains('wpi-toggle-key')) return;
      var btn = e.target;
      var input = document.getElementById(btn.getAttribute('data-target'));
      if (!input) return;
      if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = l10n.hideLabel || 'Hide';
      } else {
        input.type = 'password';
        btn.textContent = l10n.showLabel || 'Show';
      }
    });
  }

  function initAddStyleButton() {
    var btn = document.getElementById('wpi-add-style');
    var list = document.getElementById('wpi-styles-list');
    if (!btn || !list) return;

    var optionName = 'ai_composer_settings';

    btn.addEventListener('click', function () {
      var idx = list.querySelectorAll('.wpi-style-row').length;
      var id = 'custom_' + Date.now();
      var html =
        '<details class="wpi-style-row" open style="border:1px solid #e0e0e0;border-radius:3px;margin-bottom:8px;background:#fff;">' +
          '<summary style="padding:10px 14px;cursor:pointer;font-weight:500;display:flex;align-items:center;gap:8px;">New custom style</summary>' +
          '<div style="padding:10px 14px;border-top:1px solid #f0f0f0;">' +
            '<input type="hidden" name="' + optionName + '[syndication][content_styles][' + idx + '][id]" value="' + id + '">' +
            '<input type="hidden" name="' + optionName + '[syndication][content_styles][' + idx + '][builtin]" value="0">' +
            '<table class="form-table" role="presentation" style="margin:0;"><tbody>' +
              '<tr><th style="width:120px;padding:6px 0;"><label>Label</label></th>' +
              '<td style="padding:6px 0;"><input type="text" name="' + optionName + '[syndication][content_styles][' + idx + '][label]" value="" class="regular-text" placeholder="e.g. Press Release"></td></tr>' +
              '<tr><th style="width:120px;padding:6px 0;"><label>Source type</label></th>' +
              '<td style="padding:6px 0;"><select name="' + optionName + '[syndication][content_styles][' + idx + '][source_type]">' +
                '<option value="all">All sources</option><option value="url">URLs only</option><option value="video">Videos only</option><option value="text">Text/file only</option>' +
              '</select></td></tr>' +
              '<tr><th style="width:120px;padding:6px 0;"><label>Instructions</label></th>' +
              '<td style="padding:6px 0;"><textarea name="' + optionName + '[syndication][content_styles][' + idx + '][prompt]" rows="5" class="large-text code" placeholder="System prompt instructions for this style..."></textarea></td></tr>' +
            '</tbody></table>' +
          '</div>' +
        '</details>';
      list.insertAdjacentHTML('beforeend', html);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initBlockSelector();
    initResourceHintRows();
    initFetchStrategyToggle();
    initKeyToggleButtons();
    initAddStyleButton();
  });
})();
