/**
 * DataGlue Block Visibility — Frontend Evaluation
 *
 * Evaluates DataGlue visibility rules client-side against browser storage
 * (localStorage, sessionStorage, cookies). Blocks with rules start hidden
 * via the .block-visibility-glue-pending class and are revealed or kept
 * hidden after evaluation.
 *
 * @package wp-intelligence
 * @since   3.9.0
 */
(function () {
	'use strict';

	var ATTRIBUTION_FIELDS = {
		utm_source:   { initial: 'glue_initial_utm_source',   last: 'glue_last_utm_source' },
		utm_medium:   { initial: 'glue_initial_utm_medium',   last: 'glue_last_utm_medium' },
		utm_campaign: { initial: 'glue_initial_utm_campaign', last: 'glue_last_utm_campaign' },
		utm_term:     { initial: 'glue_initial_utm_term',     last: 'glue_last_utm_term' },
		utm_content:  { initial: 'glue_initial_utm_content',  last: 'glue_last_utm_content' },
		gclid:        { initial: 'glue_initial_gclid',        last: 'glue_last_gclid' },
		fbclid:       { initial: 'glue_initial_fbclid',       last: 'glue_last_fbclid' },
		msclkid:      { initial: 'glue_initial_msclkid',      last: 'glue_last_msclkid' },
		ttclid:       { initial: 'glue_initial_ttclid',       last: 'glue_last_ttclid' }
	};

	var IDENTITY_FIELDS = {
		user_id:            'glue_user_id',
		traffic_type:       'glue_traffic_type',
		in_app_browser:     'glue_in_app_browser',
		adblocker_detected: 'glue_adblocker_detected',
		session_id:         'glue_session_id'
	};

	function readCookie(name) {
		var v = '; ' + document.cookie;
		var parts = v.split('; ' + name + '=');
		if (parts.length === 2) {
			return parts.pop().split(';').shift();
		}
		return null;
	}

	function getFromStorage(key) {
		var val = null;
		try { val = localStorage.getItem(key); } catch (e) { /* noop */ }
		if (val !== null) return val;

		try { val = sessionStorage.getItem(key); } catch (e) { /* noop */ }
		if (val !== null) return val;

		return readCookie(key);
	}

	/**
	 * Resolve a field + subField to an actual value from browser storage.
	 *
	 * Field categories:
	 *   - Attribution (initial/last): utm_source, gclid, fbclid, etc.
	 *   - Identity: user_id, traffic_type, in_app_browser, etc.
	 *   - Synced attribute (subField "attr"): arbitrary key → glue_attr_{key}
	 *   - Raw key (fallback): the field value is used as the storage key directly.
	 */
	function resolveValue(field, subField) {
		if (subField === 'attr') {
			return getFromStorage('glue_attr_' + field);
		}

		if (subField === 'custom') {
			try {
				var raw = getFromStorage('glue_custom_attributes');
				if (raw) {
					var parsed = JSON.parse(raw);
					return parsed[field] !== undefined ? String(parsed[field]) : null;
				}
			} catch (e) { /* noop */ }
			return null;
		}

		var mapping = ATTRIBUTION_FIELDS[field];
		if (mapping) {
			if (subField === 'initial') return getFromStorage(mapping.initial);
			if (subField === 'last')    return getFromStorage(mapping.last);
			return getFromStorage(mapping.last) || getFromStorage(mapping.initial);
		}

		var identityKey = IDENTITY_FIELDS[field];
		if (identityKey) {
			return getFromStorage(identityKey);
		}

		return getFromStorage(field);
	}

	function valueCompare(actual, operator, expected) {
		switch (operator) {
			case 'notEmpty':
				return actual !== null && actual !== '';
			case 'empty':
				return actual === null || actual === '';
			case 'equal':
				return actual === expected;
			case 'notEqual':
				return actual !== expected;
			case 'contains':
				return actual !== null && actual.indexOf(expected) !== -1;
			case 'notContain':
				return actual === null || actual.indexOf(expected) === -1;
			default:
				return true;
		}
	}

	function evaluateRules(configArray) {
		for (var c = 0; c < configArray.length; c++) {
			var config      = configArray[c];
			var ruleSets    = config.ruleSets || [];
			var hideOnRuleSets = !!config.hideOnRuleSets;

			var setsResults = [];

			for (var i = 0; i < ruleSets.length; i++) {
				var ruleSet = ruleSets[i];
				if (ruleSet.enable === false) continue;

				var rules = ruleSet.rules || [];
				if (rules.length === 0) continue;

				var ruleResults = [];

				for (var j = 0; j < rules.length; j++) {
					var rule     = rules[j];
					var field    = rule.field;
					var subField = rule.subField || 'last';
					var operator = rule.operator;
					var value    = rule.value != null ? rule.value : null;

					var actual = resolveValue(field, subField);
					var passed = valueCompare(actual, operator, value);

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
			if (hideOnRuleSets  && setsResults.indexOf('hidden')  !== -1) return false;
		}

		return true;
	}

	function processBlocks() {
		var blocks = document.querySelectorAll('[data-glue-visibility]');

		for (var i = 0; i < blocks.length; i++) {
			var block = blocks[i];
			try {
				var config  = JSON.parse(block.getAttribute('data-glue-visibility'));
				var visible = evaluateRules(config);

				if (visible) {
					block.classList.remove('block-visibility-glue-pending');
				} else {
					block.style.display = 'none';
					block.classList.remove('block-visibility-glue-pending');
					block.classList.add('block-visibility-glue-hidden');
				}
			} catch (e) {
				block.classList.remove('block-visibility-glue-pending');
			}

			block.removeAttribute('data-glue-visibility');
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', processBlocks);
	} else {
		processBlocks();
	}

	window.addEventListener('glue:attribute', function () {
		processBlocks();
	});
})();
