import { useState, useCallback, useMemo } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	RadioControl,
	CheckboxControl,
	TextareaControl,
	SearchControl,
	Button,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';
import Card from '../components/card';

export default function ComposerTab() {
	const config = window.wpiSettingsConfig || {};
	const blockCatalog = config.blockCatalog || {};
	const forcedBlocks = config.forcedBlocks || [];

	const [ blockSearch, setBlockSearch ] = useState( '' );

	const { settings, isActive } = useSelect( ( select ) => {
		const store = select( STORE_NAME );
		return {
			settings: store.getSettings(),
			isActive: store.isModuleActive( 'ai_composer' ),
		};
	}, [] );

	const { updateSetting } = useDispatch( STORE_NAME );

	const mode = settings.block_selection_mode || 'all';
	const enabledBlocks = settings.enabled_blocks || [];
	const themeStrategy = ( settings.theme_strategy_enabled || '1' ) === '1';
	const prepend = settings.system_prompt_prepend || '';
	const append = settings.system_prompt_append || '';

	const handleModeChange = useCallback(
		( value ) => updateSetting( 'block_selection_mode', value ),
		[ updateSetting ]
	);

	const handleBlockToggle = useCallback(
		( blockName, checked ) => {
			const updated = checked
				? [ ...enabledBlocks, blockName ]
				: enabledBlocks.filter( ( b ) => b !== blockName );
			updateSetting( 'enabled_blocks', updated );
		},
		[ enabledBlocks, updateSetting ]
	);

	const selectAll = useCallback( () => {
		const allNames = Object.values( blockCatalog )
			.flat()
			.map( ( b ) => b.name );
		updateSetting( 'enabled_blocks', [ ...new Set( [ ...allNames, ...forcedBlocks ] ) ] );
	}, [ blockCatalog, forcedBlocks, updateSetting ] );

	const selectNone = useCallback( () => {
		updateSetting( 'enabled_blocks', [ ...forcedBlocks ] );
	}, [ forcedBlocks, updateSetting ] );

	const filteredCatalog = useMemo( () => {
		if ( ! blockSearch.trim() ) {
			return blockCatalog;
		}
		const q = blockSearch.toLowerCase();
		const result = {};
		for ( const [ prefix, blocks ] of Object.entries( blockCatalog ) ) {
			const filtered = blocks.filter(
				( b ) =>
					b.name.toLowerCase().includes( q ) ||
					( b.title || '' ).toLowerCase().includes( q )
			);
			if ( filtered.length ) {
				result[ prefix ] = filtered;
			}
		}
		return result;
	}, [ blockCatalog, blockSearch ] );

	const totalBlocks = Object.values( blockCatalog ).reduce( ( sum, blocks ) => sum + blocks.length, 0 );
	const selectedCount = enabledBlocks.length;

	if ( ! isActive ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __( 'The AI Composer module is disabled. Enable it on the Modules tab.', 'wp-intelligence' ) }
			</Notice>
		);
	}

	return (
		<div className="wpi-composer-tab">
			<Card
				title={ __( 'Block Library', 'wp-intelligence' ) }
				description={ __( 'Choose which blocks the AI is allowed to use when composing pages.', 'wp-intelligence' ) }
				icon="screenoptions"
			>
				<RadioControl
					selected={ mode }
					options={ [
						{ label: __( 'All blocks (auto-discover)', 'wp-intelligence' ), value: 'all' },
						{ label: __( 'Only selected blocks', 'wp-intelligence' ), value: 'selected' },
					] }
					onChange={ handleModeChange }
				/>

				{ mode === 'selected' && (
					<>
						{ forcedBlocks.length > 0 && (
							<p className="wpi-forced-note">
								{ __( 'Core composable blocks are always enabled:', 'wp-intelligence' ) }{ ' ' }
								<code>{ forcedBlocks.slice( 0, 8 ).join( ', ' ) }{ forcedBlocks.length > 8 ? ' …' : '' }</code>
							</p>
						) }

						<div className="wpi-block-tools">
							<SearchControl
								value={ blockSearch }
								onChange={ setBlockSearch }
								placeholder={ __( 'Filter blocks…', 'wp-intelligence' ) }
							/>
							<div className="wpi-block-tools__actions">
								<Button variant="secondary" size="compact" onClick={ selectAll }>
									{ __( 'Select All', 'wp-intelligence' ) }
								</Button>
								<Button variant="secondary" size="compact" onClick={ selectNone }>
									{ __( 'Select None', 'wp-intelligence' ) }
								</Button>
								<span className="wpi-block-count">
									{ `${ selectedCount } / ${ totalBlocks } ${ __( 'blocks', 'wp-intelligence' ) }` }
								</span>
							</div>
						</div>

						<div className="wpi-block-list">
							{ Object.entries( filteredCatalog ).map( ( [ prefix, blocks ] ) => (
								<details key={ prefix } open={ [ 'core', 'acf' ].includes( prefix ) }>
									<summary>
										{ prefix }
										<span className="wpi-block-list__count">({ blocks.length })</span>
									</summary>
									<div className="wpi-block-list__group">
										{ blocks.map( ( block ) => {
											const isForced = forcedBlocks.includes( block.name );
											const checked = mode === 'all' || enabledBlocks.includes( block.name ) || isForced;
											return (
												<CheckboxControl
													key={ block.name }
													label={ `${ block.name }${ block.title && block.title !== block.name ? ` — ${ block.title }` : '' }` }
													checked={ checked }
													disabled={ isForced }
													onChange={ ( c ) => handleBlockToggle( block.name, c ) }
													__nextHasNoMarginBottom
												/>
											);
										} ) }
									</div>
								</details>
							) ) }
						</div>
					</>
				) }
			</Card>

			<Card title={ __( 'Prompting & Strategy', 'wp-intelligence' ) } icon="editor-code">
				<CheckboxControl
					label={ __( 'Enable automatic theme-aware prompting (recommended)', 'wp-intelligence' ) }
					help={ __( 'Adds strategy hints based on active theme and available block libraries.', 'wp-intelligence' ) }
					checked={ themeStrategy }
					onChange={ ( v ) => updateSetting( 'theme_strategy_enabled', v ? '1' : '0' ) }
					__nextHasNoMarginBottom
				/>

				<TextareaControl
					label={ __( 'System Prompt (prepend)', 'wp-intelligence' ) }
					help={ __( 'Instructions inserted before base prompt rules.', 'wp-intelligence' ) }
					value={ prepend }
					onChange={ ( v ) => updateSetting( 'system_prompt_prepend', v ) }
					rows={ 4 }
					__nextHasNoMarginBottom
				/>

				<TextareaControl
					label={ __( 'System Prompt (append)', 'wp-intelligence' ) }
					help={ __( 'Instructions appended after base prompt rules.', 'wp-intelligence' ) }
					value={ append }
					onChange={ ( v ) => updateSetting( 'system_prompt_append', v ) }
					rows={ 4 }
					__nextHasNoMarginBottom
				/>
			</Card>
		</div>
	);
}
