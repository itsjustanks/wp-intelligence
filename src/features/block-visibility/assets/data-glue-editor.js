/**
 * DataGlue Block Visibility — Editor Control
 *
 * Registers "Data Glue" as an integration in the block visibility controls
 * list and provides the rule-set UI for configuring DataGlue visibility rules.
 *
 * Requires an active DataGlue subscription (https://dataglue.io).
 *
 * @package wp-intelligence
 * @since   3.9.0
 */
(function () {
	'use strict';

	var el        = wp.element.createElement;
	var Fragment  = wp.element.Fragment;
	var hooks     = wp.hooks;
	var __        = wp.i18n.__;
	var assign    = Object.assign;
	var SVG       = wp.primitives.SVG;
	var Path      = wp.primitives.Path;

	var Button        = wp.components.Button;
	var PanelBody     = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var TextControl   = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var Notice        = wp.components.Notice;

	var CONTROL_NAME = 'dataGlue';
	var SETTING_SLUG = 'data_glue';

	var DATA_GLUE_ICON = el(SVG, { xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24' },
		el(Path, { d: 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z' })
	);

	/* ── Register DataGlue in the controls list ── */

	hooks.addFilter(
		'blockVisibility.controls',
		'wpi/data-glue/register',
		function (controls) {
			controls.push({
				label:         __( 'Data Glue', 'wp-intelligence' ),
				type:          'integration',
				attributeSlug: CONTROL_NAME,
				settingSlug:   SETTING_SLUG,
				icon:          DATA_GLUE_ICON
			});
			return controls;
		}
	);

	/* ── Field definitions ── */

	var FIELD_GROUPS = [
		{
			label: __( 'Attribution', 'wp-intelligence' ),
			options: [
				{ value: 'utm_source',   label: 'UTM Source' },
				{ value: 'utm_medium',   label: 'UTM Medium' },
				{ value: 'utm_campaign', label: 'UTM Campaign' },
				{ value: 'utm_term',     label: 'UTM Term' },
				{ value: 'utm_content',  label: 'UTM Content' },
				{ value: 'gclid',        label: 'Google Click ID (gclid)' },
				{ value: 'fbclid',       label: 'Facebook Click ID (fbclid)' },
				{ value: 'msclkid',      label: 'Microsoft Click ID (msclkid)' },
				{ value: 'ttclid',       label: 'TikTok Click ID (ttclid)' }
			]
		},
		{
			label: __( 'Identity', 'wp-intelligence' ),
			options: [
				{ value: 'user_id',            label: 'Visitor ID' },
				{ value: 'traffic_type',       label: 'Traffic Type' },
				{ value: 'in_app_browser',     label: 'In-App Browser' },
				{ value: 'adblocker_detected', label: 'Ad Blocker Detected' },
				{ value: 'session_id',         label: 'Session ID' }
			]
		}
	];

	var SUB_FIELD_OPTIONS = [
		{ value: 'last',    label: __( 'Last Touch', 'wp-intelligence' ) },
		{ value: 'initial', label: __( 'First Touch', 'wp-intelligence' ) }
	];

	var ATTRIBUTION_KEYS = [
		'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
		'gclid', 'fbclid', 'msclkid', 'ttclid'
	];

	var OPERATORS = [
		{ value: 'notEmpty',   label: __( 'Has any value', 'wp-intelligence' ) },
		{ value: 'empty',      label: __( 'Has no value', 'wp-intelligence' ) },
		{ value: 'equal',      label: __( 'Is equal to', 'wp-intelligence' ) },
		{ value: 'notEqual',   label: __( 'Is not equal to', 'wp-intelligence' ) },
		{ value: 'contains',   label: __( 'Contains', 'wp-intelligence' ) },
		{ value: 'notContain', label: __( 'Does not contain', 'wp-intelligence' ) }
	];

	var NO_VALUE_OPS = ['notEmpty', 'empty'];

	function isAttributionField(field) {
		return ATTRIBUTION_KEYS.indexOf(field) !== -1;
	}

	function buildFieldOptions() {
		var opts = [{ value: '', label: __( 'Select Field…', 'wp-intelligence' ) }];
		FIELD_GROUPS.forEach(function (group) {
			group.options.forEach(function (opt) {
				opts.push({ value: opt.value, label: group.label + ' — ' + opt.label });
			});
		});
		opts.push({ value: '__attr__', label: __( 'Synced Attribute (glue_attr_*)', 'wp-intelligence' ) });
		opts.push({ value: '__custom__', label: __( 'Custom Attribute', 'wp-intelligence' ) });
		opts.push({ value: '__raw__', label: __( 'Raw Storage Key', 'wp-intelligence' ) });
		return opts;
	}

	var FIELD_OPTIONS = buildFieldOptions();

	function getDefaultRule() {
		return { field: '', subField: 'last', operator: 'notEmpty', value: '' };
	}

	function getDefaultRuleSet() {
		return { enable: true, rules: [getDefaultRule()] };
	}

	/* ── Single Rule ── */

	function RuleRow(props) {
		var rule       = props.rule;
		var ruleIndex  = props.ruleIndex;
		var rules      = props.rules;
		var ruleSetIdx = props.ruleSetIndex;
		var ruleSets   = props.ruleSets;
		var controlAtts = props.controlAtts;
		var setControl = props.setControlAtts;

		var fieldVal = rule.field || '';
		var isAttr   = rule.subField === 'attr';
		var isCustom = rule.subField === 'custom';
		var isRaw    = rule.subField === 'raw';
		var displayField = isAttr ? '__attr__' : isCustom ? '__custom__' : isRaw ? '__raw__' : fieldVal;

		function updateRule(patch) {
			var newRules = rules.map(function (r, i) {
				return i === ruleIndex ? assign({}, r, patch) : r;
			});
			var newSets = ruleSets.map(function (s, i) {
				return i === ruleSetIdx ? assign({}, s, { rules: newRules }) : s;
			});
			setControl(CONTROL_NAME, assign({}, controlAtts, { ruleSets: newSets }));
		}

		function removeRule() {
			var newRules = rules.filter(function (_, i) { return i !== ruleIndex; });
			var newSets = ruleSets.map(function (s, i) {
				return i === ruleSetIdx ? assign({}, s, { rules: newRules }) : s;
			});
			setControl(CONTROL_NAME, assign({}, controlAtts, { ruleSets: newSets }));
		}

		var showValueInput = NO_VALUE_OPS.indexOf(rule.operator) === -1;

		return el('div', { className: 'rule', style: { marginBottom: '12px', padding: '8px', background: '#f0f0f0', borderRadius: '4px' } },
			el('div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' } },
				el('span', { style: { fontSize: '11px', textTransform: 'uppercase', color: '#757575' } },
					ruleIndex === 0
						? __( 'If', 'wp-intelligence' )
						: __( 'And if', 'wp-intelligence' )
				),
				rules.length > 1 && el(Button, {
					isDestructive: true,
					isSmall: true,
					onClick: removeRule,
					label: __( 'Remove rule', 'wp-intelligence' )
				}, '×')
			),

			el(SelectControl, {
				label: __( 'Field', 'wp-intelligence' ),
				value: displayField,
				options: FIELD_OPTIONS,
				onChange: function (val) {
					if (val === '__attr__') {
						updateRule({ field: '', subField: 'attr' });
					} else if (val === '__custom__') {
						updateRule({ field: '', subField: 'custom' });
					} else if (val === '__raw__') {
						updateRule({ field: '', subField: 'raw' });
					} else {
						var patch = { field: val };
						if (!isAttributionField(val)) {
							patch.subField = 'last';
						}
						updateRule(patch);
					}
				}
			}),

			(isAttr || isCustom || isRaw) && el(TextControl, {
				label: isAttr
					? __( 'Attribute Key', 'wp-intelligence' )
					: isCustom
						? __( 'Custom Attribute Key', 'wp-intelligence' )
						: __( 'Storage Key', 'wp-intelligence' ),
				value: rule.field || '',
				placeholder: isAttr ? 'e.g. segment' : isCustom ? 'e.g. plan_type' : 'e.g. glue_user_id',
				onChange: function (val) { updateRule({ field: val }); }
			}),

			isAttributionField(fieldVal) && !isAttr && !isCustom && !isRaw && el(SelectControl, {
				label: __( 'Touch Point', 'wp-intelligence' ),
				value: rule.subField || 'last',
				options: SUB_FIELD_OPTIONS,
				onChange: function (val) { updateRule({ subField: val }); }
			}),

			el(SelectControl, {
				label: __( 'Operator', 'wp-intelligence' ),
				value: rule.operator || 'notEmpty',
				options: OPERATORS,
				onChange: function (val) { updateRule({ operator: val }); }
			}),

			showValueInput && el(TextControl, {
				label: __( 'Value', 'wp-intelligence' ),
				value: rule.value || '',
				onChange: function (val) { updateRule({ value: val }); }
			})
		);
	}

	/* ── Rule Set ── */

	function RuleSet(props) {
		var ruleSet    = props.ruleSet;
		var ruleSetIdx = props.ruleSetIndex;
		var ruleSets   = props.ruleSets;
		var controlAtts = props.controlAtts;
		var setControl = props.setControlAtts;

		var rules = ruleSet.rules || [];

		function addRule() {
			var newRules = rules.concat([getDefaultRule()]);
			var newSets  = ruleSets.map(function (s, i) {
				return i === ruleSetIdx ? assign({}, s, { rules: newRules }) : s;
			});
			setControl(CONTROL_NAME, assign({}, controlAtts, { ruleSets: newSets }));
		}

		function removeRuleSet() {
			var newSets = ruleSets.filter(function (_, i) { return i !== ruleSetIdx; });
			setControl(CONTROL_NAME, assign({}, controlAtts, { ruleSets: newSets }));
		}

		function toggleRuleSet() {
			var newSets = ruleSets.map(function (s, i) {
				return i === ruleSetIdx ? assign({}, s, { enable: !s.enable }) : s;
			});
			setControl(CONTROL_NAME, assign({}, controlAtts, { ruleSets: newSets }));
		}

		return el('div', {
			className: 'rule-set',
			style: {
				marginBottom: '16px',
				padding: '12px',
				border: '1px solid #ddd',
				borderRadius: '4px',
				opacity: ruleSet.enable === false ? 0.5 : 1
			}
		},
			el('div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' } },
				el('strong', null,
					ruleSetIdx === 0
						? __( 'Rule Set', 'wp-intelligence' )
						: __( 'Or Rule Set', 'wp-intelligence' )
				),
				el('div', null,
					el(Button, { isSmall: true, onClick: toggleRuleSet },
						ruleSet.enable === false
							? __( 'Enable', 'wp-intelligence' )
							: __( 'Disable', 'wp-intelligence' )
					),
					ruleSets.length > 1 && el(Button, { isSmall: true, isDestructive: true, onClick: removeRuleSet },
						__( 'Remove', 'wp-intelligence' )
					)
				)
			),

			rules.map(function (rule, idx) {
				return el(RuleRow, {
					key: idx,
					rule: rule,
					ruleIndex: idx,
					rules: rules,
					ruleSetIndex: ruleSetIdx,
					ruleSets: ruleSets,
					controlAtts: controlAtts,
					setControlAtts: setControl
				});
			}),

			el(Button, { isSecondary: true, isSmall: true, onClick: addRule, style: { marginTop: '4px' } },
				__( '+ Add Rule', 'wp-intelligence' )
			)
		);
	}

	/* ── Main Control Component ── */

	function DataGlueControl(props) {
		var controlSetAtts  = props.controlSetAtts;
		var setControlAtts  = props.setControlAtts;
		var enabledControls = props.enabledControls;
		var settings        = props.settings;

		if (!enabledControls || !enabledControls.some(function (c) {
			return c.settingSlug === SETTING_SLUG && c.isActive;
		})) {
			return null;
		}

		var controlAtts = (controlSetAtts && controlSetAtts.controls && controlSetAtts.controls[CONTROL_NAME]) || {};
		var ruleSets    = controlAtts.ruleSets || [];
		var hideOn      = !!controlAtts.hideOnRuleSets;

		return el('div', { className: 'controls-panel-item' },
			el('div', { className: 'controls-panel-item__header' },
				el('h3', null, __( 'Data Glue', 'wp-intelligence' ))
			),

			el('span', { className: 'controls-panel-item__description' },
				hideOn
					? __( 'Hide the block if any rule set applies.', 'wp-intelligence' )
					: __( 'Show the block if any rule set applies.', 'wp-intelligence' )
			),

			el(Notice, {
				status: 'warning',
				isDismissible: false,
				style: { margin: '8px 0' }
			}, el('span', null,
				__( 'Requires ', 'wp-intelligence' ),
				el('a', { href: 'https://dataglue.io', target: '_blank', rel: 'noopener noreferrer' }, 'DataGlue'),
				__( ' (paid). Visitor tracking must be active for rules to evaluate.', 'wp-intelligence' )
			)),

			el('div', { className: 'controls-panel-item__control-fields' },
				el('div', { className: 'rule-sets' },
					ruleSets.map(function (ruleSet, idx) {
						return el(RuleSet, {
							key: idx,
							ruleSet: ruleSet,
							ruleSetIndex: idx,
							ruleSets: ruleSets,
							controlAtts: controlAtts,
							setControlAtts: setControlAtts
						});
					})
				),

				el(Button, {
					isSecondary: true,
					onClick: function () {
						setControlAtts(CONTROL_NAME, assign({}, controlAtts, {
							ruleSets: ruleSets.concat([getDefaultRuleSet()])
						}));
					},
					style: { marginBottom: '12px' }
				}, __( '+ Add Rule Set', 'wp-intelligence' )),

				el('div', { className: 'control-fields-item__hide-when' },
					el(ToggleControl, {
						label: __( 'Hide when rules apply', 'wp-intelligence' ),
						checked: hideOn,
						onChange: function () {
							setControlAtts(CONTROL_NAME, assign({}, controlAtts, {
								hideOnRuleSets: !hideOn
							}));
						}
					})
				)
			)
		);
	}

	/* ── Register via withFilters extension point ── */

	hooks.addFilter(
		'blockVisibility.addControlSetControls',
		'wpi/data-glue/ui',
		function (OriginalComponent) {
			return function (props) {
				return el(Fragment, null,
					el(OriginalComponent, props),
					el(DataGlueControl, props)
				);
			};
		}
	);
})();
