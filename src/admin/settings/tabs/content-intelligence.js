import { useState, useCallback } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	RadioControl,
	TextControl,
	TextareaControl,
	SelectControl,
	CheckboxControl,
	Button,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';
import Card from '../components/card';

export default function ContentIntelligenceTab() {
	const config = window.wpiSettingsConfig || {};
	const postTypes = config.postTypes || [];
	const taxonomies = config.taxonomies || [];

	const { settings, isActive } = useSelect( ( select ) => {
		const store = select( STORE_NAME );
		return {
			settings: store.getSettings(),
			isActive: store.isModuleActive( 'syndication' ),
		};
	}, [] );

	const { updateSetting, updateNestedSetting } = useDispatch( STORE_NAME );

	const syn = settings.syndication || {};
	const enabledPostTypes = syn.enabled_post_types || [];
	const systemPrompt = syn.system_prompt || '';
	const sourceTaxonomy = syn.source_taxonomy || '';
	const fetchStrategy = syn.fetch_strategy || 'builtin';
	const firecrawlKey = syn.firecrawl_api_key || '';
	const brandContext = syn.brand_context || '';
	const trainingUrls = syn.training_urls || '';
	const examplePostIds = ( syn.example_post_ids || [] ).join( ', ' );
	const outputFormatDefaults = syn.output_format_defaults || {};
	const contentStyles = syn.content_styles || config.defaultContentStyles || [];

	const [ showFirecrawlKey, setShowFirecrawlKey ] = useState( false );

	const updateSyn = useCallback(
		( key, value ) => updateNestedSetting( 'syndication', key, value ),
		[ updateNestedSetting ]
	);

	const handlePostTypeToggle = useCallback(
		( ptName, checked ) => {
			const updated = checked
				? [ ...enabledPostTypes, ptName ]
				: enabledPostTypes.filter( ( p ) => p !== ptName );
			updateSyn( 'enabled_post_types', updated );
		},
		[ enabledPostTypes, updateSyn ]
	);

	const handleOutputFormatChange = useCallback(
		( ptName, format ) => {
			updateSyn( 'output_format_defaults', {
				...outputFormatDefaults,
				[ ptName ]: format,
			} );
		},
		[ outputFormatDefaults, updateSyn ]
	);

	if ( ! isActive ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __( 'The Content Intelligence module is disabled. Enable it on the Modules tab.', 'wp-intelligence' ) }
			</Notice>
		);
	}

	return (
		<div className="wpi-content-intelligence-tab">
			<Card title={ __( 'Fetch Strategy', 'wp-intelligence' ) } icon="download">
				<RadioControl
					selected={ fetchStrategy }
					options={ [
						{
							label: __( 'Built-in (wp_remote_get)', 'wp-intelligence' ),
							value: 'builtin',
						},
						{
							label: __( 'Firecrawl API', 'wp-intelligence' ),
							value: 'firecrawl',
						},
					] }
					onChange={ ( v ) => updateSyn( 'fetch_strategy', v ) }
					help={ fetchStrategy === 'builtin'
						? __( 'Uses WordPress HTTP API with browser-like headers.', 'wp-intelligence' )
						: __( 'Cloud-based scraper that handles JS-rendered pages and anti-bot protections.', 'wp-intelligence' )
					}
				/>

				{ fetchStrategy === 'firecrawl' && (
					<div className="wpi-field-row">
						<TextControl
							label={ __( 'Firecrawl API Key', 'wp-intelligence' ) }
							value={ firecrawlKey }
							onChange={ ( v ) => updateSyn( 'firecrawl_api_key', v ) }
							type={ showFirecrawlKey ? 'text' : 'password' }
							autoComplete="off"
							placeholder="fc-..."
							__nextHasNoMarginBottom
						/>
						<Button
							variant="secondary"
							size="compact"
							onClick={ () => setShowFirecrawlKey( ! showFirecrawlKey ) }
							className="wpi-toggle-key-btn"
						>
							{ showFirecrawlKey ? __( 'Hide', 'wp-intelligence' ) : __( 'Show', 'wp-intelligence' ) }
						</Button>
					</div>
				) }
			</Card>

			<Card title={ __( 'Content Intelligence', 'wp-intelligence' ) } icon="welcome-write-blog">
				<div className="wpi-form-fields">
					<fieldset className="wpi-fieldset">
						<legend>{ __( 'Enabled post types', 'wp-intelligence' ) }</legend>
						{ postTypes.map( ( pt ) => (
							<div key={ pt.name } className="wpi-post-type-row">
								<CheckboxControl
									label={ `${ pt.label } (${ pt.name })` }
									checked={ enabledPostTypes.includes( pt.name ) }
									onChange={ ( c ) => handlePostTypeToggle( pt.name, c ) }
									__nextHasNoMarginBottom
								/>
								<SelectControl
									value={ outputFormatDefaults[ pt.name ] || 'blocks' }
									options={ [
										{ value: 'blocks', label: __( 'Blocks', 'wp-intelligence' ) },
										{ value: 'wysiwyg', label: __( 'WYSIWYG', 'wp-intelligence' ) },
									] }
									onChange={ ( v ) => handleOutputFormatChange( pt.name, v ) }
									disabled={ ! enabledPostTypes.includes( pt.name ) }
									__nextHasNoMarginBottom
								/>
							</div>
						) ) }
					</fieldset>

					<TextareaControl
						label={ __( 'Default rewrite prompt', 'wp-intelligence' ) }
						value={ systemPrompt }
						onChange={ ( v ) => updateSyn( 'system_prompt', v ) }
						rows={ 4 }
						placeholder={ __( 'Optional default instructions for how the AI should rewrite syndicated articles.', 'wp-intelligence' ) }
						__nextHasNoMarginBottom
					/>

					<SelectControl
						label={ __( 'Source taxonomy (optional)', 'wp-intelligence' ) }
						value={ sourceTaxonomy }
						options={ [
							{ value: '', label: __( 'Do not assign taxonomy terms', 'wp-intelligence' ) },
							...taxonomies.map( ( tax ) => ( {
								value: tax.name,
								label: `${ tax.label } (${ tax.name })`,
							} ) ),
						] }
						onChange={ ( v ) => updateSyn( 'source_taxonomy', v ) }
						help={ __( 'When set, the rewritten source name will be assigned to this taxonomy.', 'wp-intelligence' ) }
						__nextHasNoMarginBottom
					/>
				</div>
			</Card>

			<Card
				title={ __( 'Content Training', 'wp-intelligence' ) }
				description={ __( 'Persistent context applied to every syndication request.', 'wp-intelligence' ) }
				icon="welcome-learn-more"
			>
				<div className="wpi-form-fields">
					<TextareaControl
						label={ __( 'Brand context & guidelines', 'wp-intelligence' ) }
						value={ brandContext }
						onChange={ ( v ) => updateSyn( 'brand_context', v ) }
						rows={ 5 }
						placeholder={ __( 'e.g.\n- Always use our full brand name\n- Professional but accessible tone\n- Australian English spelling', 'wp-intelligence' ) }
						help={ __( 'Tone of voice, key messaging, terminology rules. Injected into every syndication prompt.', 'wp-intelligence' ) }
						__nextHasNoMarginBottom
					/>

					<TextareaControl
						label={ __( 'Style reference URLs', 'wp-intelligence' ) }
						value={ trainingUrls }
						onChange={ ( v ) => updateSyn( 'training_urls', v ) }
						rows={ 3 }
						placeholder={ __( 'https://example.com/article-with-good-style', 'wp-intelligence' ) }
						help={ __( 'URLs whose tone the AI should emulate. One per line, max 10.', 'wp-intelligence' ) }
						__nextHasNoMarginBottom
					/>

					<TextControl
						label={ __( 'Example posts', 'wp-intelligence' ) }
						value={ examplePostIds }
						onChange={ ( v ) => updateSyn( 'example_post_ids', v.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ) ) }
						placeholder={ __( 'e.g. 42, 108, 256', 'wp-intelligence' ) }
						help={ __( 'Comma-separated IDs of published posts to use as style examples (max 5).', 'wp-intelligence' ) }
						__nextHasNoMarginBottom
					/>
				</div>
			</Card>

			<Card
				title={ __( 'Content Styles', 'wp-intelligence' ) }
				description={ __( 'Manage the writing styles available in the editor.', 'wp-intelligence' ) }
				icon="art"
			>
				<div className="wpi-content-styles">
					{ contentStyles.map( ( style, idx ) => (
						<details key={ style.id || idx } className="wpi-style-row">
							<summary>
								<span className="wpi-style-row__label">{ style.label }</span>
								{ style.builtin && <span className="wpi-style-row__badge">{ __( 'built-in', 'wp-intelligence' ) }</span> }
								<span className="wpi-style-row__type">{ style.source_type }</span>
							</summary>
							<div className="wpi-style-row__body">
								<TextControl
									label={ __( 'Label', 'wp-intelligence' ) }
									value={ style.label }
									onChange={ ( v ) => {
										const updated = [ ...contentStyles ];
										updated[ idx ] = { ...updated[ idx ], label: v };
										updateSyn( 'content_styles', updated );
									} }
									__nextHasNoMarginBottom
								/>
								<SelectControl
									label={ __( 'Source type', 'wp-intelligence' ) }
									value={ style.source_type }
									options={ [
										{ value: 'all', label: __( 'All sources', 'wp-intelligence' ) },
										{ value: 'url', label: __( 'URLs only', 'wp-intelligence' ) },
										{ value: 'video', label: __( 'Videos only', 'wp-intelligence' ) },
										{ value: 'text', label: __( 'Text/file only', 'wp-intelligence' ) },
									] }
									onChange={ ( v ) => {
										const updated = [ ...contentStyles ];
										updated[ idx ] = { ...updated[ idx ], source_type: v };
										updateSyn( 'content_styles', updated );
									} }
									__nextHasNoMarginBottom
								/>
								<TextareaControl
									label={ __( 'Instructions', 'wp-intelligence' ) }
									value={ style.prompt }
									onChange={ ( v ) => {
										const updated = [ ...contentStyles ];
										updated[ idx ] = { ...updated[ idx ], prompt: v };
										updateSyn( 'content_styles', updated );
									} }
									rows={ 5 }
									help={ __( 'System prompt instructions for this style.', 'wp-intelligence' ) }
									__nextHasNoMarginBottom
								/>
							</div>
						</details>
					) ) }
					<Button
						variant="secondary"
						onClick={ () => {
							const newStyle = {
								id: 'custom_' + Date.now(),
								label: __( 'New custom style', 'wp-intelligence' ),
								source_type: 'all',
								prompt: '',
								builtin: false,
							};
							updateSyn( 'content_styles', [ ...contentStyles, newStyle ] );
						} }
					>
						{ __( '+ Add custom style', 'wp-intelligence' ) }
					</Button>
				</div>
			</Card>
		</div>
	);
}
