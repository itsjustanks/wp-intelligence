/**
 * Dynamic Data — Frontend Client-Side Resolution
 *
 * Handles three client-side concerns that PHP cannot:
 *
 * 1. Merge tag placeholders (.wpi-dd-pending):
 *    Resolves {{storage.key}} tags from localStorage/sessionStorage.
 *
 * 2. Conditional blocks (.wpi-dd-conditional):
 *    Evaluates {{#if storage.key}}...{{#else}}...{{/if}} and shows
 *    the correct branch.
 *
 * 3. Visibility rules ([data-wpi-dd-visibility]):
 *    Evaluates block visibility rules that reference client-side sources
 *    (e.g. localStorage) and shows/hides blocks accordingly.
 *
 * Uses the same anti-FOUC pattern as DataGlue: elements start hidden
 * and are revealed after evaluation.
 *
 * @package wp-intelligence
 * @since   3.9.0
 */
(function () {
  'use strict';

  /* ──────────────────────────────────────────────
   *  Storage read helpers
   * ────────────────────────────────────────────── */

  function readStorage(key) {
    var val = null;
    try { val = localStorage.getItem(key); } catch (e) { /* noop */ }
    if (val !== null) return val;

    try { val = sessionStorage.getItem(key); } catch (e) { /* noop */ }
    return val;
  }

  function resolveSourceValue(source, field) {
    if (source === 'storage') {
      return readStorage(field);
    }
    return null;
  }

  /* ──────────────────────────────────────────────
   *  1. Resolve merge tag placeholders
   * ────────────────────────────────────────────── */

  function resolveMergeTags() {
    var elements = document.querySelectorAll('.wpi-dd-pending');

    for (var i = 0; i < elements.length; i++) {
      var el = elements[i];
      try {
        var configStr = el.getAttribute('data-wpi-dd');
        if (!configStr) continue;

        var config = JSON.parse(configStr);
        var value = resolveSourceValue(config.source, config.field);

        if (value === null || value === '') {
          value = config.fallback || '';
        }

        var textNode = document.createTextNode(value);
        el.parentNode.replaceChild(textNode, el);
      } catch (e) {
        el.classList.remove('wpi-dd-pending');
      }
    }
  }

  /* ──────────────────────────────────────────────
   *  2. Evaluate conditional blocks
   * ────────────────────────────────────────────── */

  function evaluateCondition(tagPath, operator, compareValue) {
    var parts = tagPath.split('.');
    var source = parts[0];
    var field = parts.slice(1).join('.');
    var actual = resolveSourceValue(source, field);

    if (actual === null) actual = '';

    switch (operator) {
      case 'truthy':
        return actual !== '' && actual !== '0' && actual !== 'false' && actual !== 'null';
      case '==':
        return actual === compareValue;
      case '!=':
        return actual !== compareValue;
      case '>':
        return !isNaN(actual) && !isNaN(compareValue) && parseFloat(actual) > parseFloat(compareValue);
      case '<':
        return !isNaN(actual) && !isNaN(compareValue) && parseFloat(actual) < parseFloat(compareValue);
      case 'contains':
        return compareValue !== '' && actual.indexOf(compareValue) !== -1;
      case '!contains':
        return compareValue === '' || actual.indexOf(compareValue) === -1;
      default:
        return actual !== '';
    }
  }

  function resolveConditionals() {
    var elements = document.querySelectorAll('.wpi-dd-conditional');

    for (var i = 0; i < elements.length; i++) {
      var el = elements[i];
      try {
        var configStr = el.getAttribute('data-wpi-condition');
        if (!configStr) continue;

        var config = JSON.parse(configStr);
        var result = evaluateCondition(config.tag, config.operator, config.value || '');

        var ifBranch = el.querySelector('.wpi-dd-if');
        var elseBranch = el.querySelector('.wpi-dd-else');

        if (result && ifBranch) {
          ifBranch.style.display = '';
          var fragment = document.createDocumentFragment();
          while (ifBranch.firstChild) {
            fragment.appendChild(ifBranch.firstChild);
          }
          el.parentNode.replaceChild(fragment, el);
        } else if (!result && elseBranch) {
          elseBranch.style.display = '';
          var fragment2 = document.createDocumentFragment();
          while (elseBranch.firstChild) {
            fragment2.appendChild(elseBranch.firstChild);
          }
          el.parentNode.replaceChild(fragment2, el);
        } else if (!result) {
          el.parentNode.removeChild(el);
        } else {
          el.parentNode.removeChild(el);
        }
      } catch (e) {
        el.style.display = '';
      }
    }
  }

  /* ──────────────────────────────────────────────
   *  3. Evaluate visibility rules
   * ────────────────────────────────────────────── */

  function valueCompare(actual, operator, expected) {
    if (actual === null) actual = '';

    switch (operator) {
      case 'notEmpty':
        return actual !== '';
      case 'empty':
        return actual === '';
      case 'equal':
        return actual === expected;
      case 'notEqual':
        return actual !== expected;
      case 'contains':
        return expected !== '' && actual.indexOf(expected) !== -1;
      case 'notContain':
        return actual === '' || actual.indexOf(expected) === -1;
      case 'greaterThan':
        return !isNaN(actual) && !isNaN(expected) && parseFloat(actual) > parseFloat(expected);
      case 'lessThan':
        return !isNaN(actual) && !isNaN(expected) && parseFloat(actual) < parseFloat(expected);
      default:
        return true;
    }
  }

  function evaluateVisibilityRules(configArray) {
    for (var c = 0; c < configArray.length; c++) {
      var config = configArray[c];
      var ruleSets = config.ruleSets || [];
      var hideOnRuleSets = !!config.hideOnRuleSets;

      var setsResults = [];

      for (var i = 0; i < ruleSets.length; i++) {
        var ruleSet = ruleSets[i];
        if (ruleSet.enable === false) continue;

        var rules = ruleSet.rules || [];
        if (rules.length === 0) continue;

        var ruleResults = [];

        for (var j = 0; j < rules.length; j++) {
          var rule = rules[j];
          var actual = resolveSourceValue(rule.source, rule.field || '');
          var passed = valueCompare(actual, rule.operator, rule.value != null ? rule.value : '');
          ruleResults.push(passed ? 'visible' : 'hidden');
        }

        var setResult = ruleResults.indexOf('hidden') !== -1 ? 'hidden' : 'visible';

        if (hideOnRuleSets) {
          setResult = setResult === 'visible' ? 'hidden' : 'visible';
        }

        setsResults.push(setResult);
      }

      if (setsResults.length === 0) continue;

      if (!hideOnRuleSets && setsResults.indexOf('visible') === -1) return false;
      if (hideOnRuleSets && setsResults.indexOf('hidden') !== -1) return false;
    }

    return true;
  }

  function processVisibility() {
    var blocks = document.querySelectorAll('[data-wpi-dd-visibility]');

    for (var i = 0; i < blocks.length; i++) {
      var block = blocks[i];
      try {
        var config = JSON.parse(block.getAttribute('data-wpi-dd-visibility'));
        var visible = evaluateVisibilityRules(config);

        if (visible) {
          block.classList.remove('wpi-dd-vis-pending');
        } else {
          block.style.display = 'none';
          block.classList.remove('wpi-dd-vis-pending');
          block.classList.add('wpi-dd-vis-hidden');
        }
      } catch (e) {
        block.classList.remove('wpi-dd-vis-pending');
      }

      block.removeAttribute('data-wpi-dd-visibility');
    }
  }

  /* ──────────────────────────────────────────────
   *  Run everything
   * ────────────────────────────────────────────── */

  function processAll() {
    resolveMergeTags();
    resolveConditionals();
    processVisibility();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', processAll);
  } else {
    processAll();
  }

  window.addEventListener('storage', function () {
    processAll();
  });

  if (window.wpiDynamicData) {
    window.wpiDynamicData.resolve = processAll;
  } else {
    window.wpiDynamicData = { resolve: processAll };
  }
})();
