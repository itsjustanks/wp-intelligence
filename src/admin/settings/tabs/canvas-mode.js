import { useSelect, useDispatch } from '@wordpress/data';
import { Notice, CheckboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';
import Card from '../components/card';

const CORE_POST_TYPES = [
	{ slug: 'page', label: 'Pages' },
	{ slug: 'post', label: 'Posts' },
];

export default function CanvasModeTab() {
	const { settings, isActive } = useSelect( ( select ) => {
		const store = select( STORE_NAME );
		return {
			settings: store.getSettings(),
			isActive: store.isModuleActive( 'canvas_mode' ),
		};
	}, [] );

	const { updateSetting } = useDispatch( STORE_NAME );

	if ( ! isActive ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __( 'The Canvas Mode module is disabled. Enable it on the Modules tab.', 'wp-intelligence' ) }
			</Notice>
		);
	}

	const cmSettings = settings.canvas_mode || {};
	const defaultPostTypes = cmSettings.default_post_types || [ 'page' ];

	const customPostTypes = ( window.wpiSettingsConfig?.postTypes || [] )
		.filter( ( pt ) => pt.name !== 'page' && pt.name !== 'post' && pt.name !== 'attachment' );

	const allPostTypes = [
		...CORE_POST_TYPES,
		...customPostTypes.map( ( pt ) => ( { slug: pt.name, label: pt.label || pt.name } ) ),
	];

	function togglePostType( slug ) {
		const current = [ ...defaultPostTypes ];
		const idx = current.indexOf( slug );
		if ( idx === -1 ) {
			current.push( slug );
		} else {
			current.splice( idx, 1 );
		}
		updateSetting( 'canvas_mode', {
			...cmSettings,
			default_post_types: current,
		} );
	}

	return (
		<Card
			title={ __( 'Canvas Mode', 'wp-intelligence' ) }
			description={ __( 'Figma-style multi-viewport canvas for the block editor. Configure which post types open in canvas mode by default.', 'wp-intelligence' ) }
			icon="grid-view"
		>
			<div className="wpi-form-fields">
				<fieldset>
					<legend className="wpi-field-label">
						{ __( 'Auto-activate canvas mode for:', 'wp-intelligence' ) }
					</legend>
					<p className="wpi-field-help">
						{ __( 'Canvas mode will automatically activate when editing these post types. Users can always toggle it manually with Ctrl+Shift+M.', 'wp-intelligence' ) }
					</p>
					{ allPostTypes.map( ( pt ) => (
						<CheckboxControl
							key={ pt.slug }
							label={ pt.label }
							checked={ defaultPostTypes.includes( pt.slug ) }
							onChange={ () => togglePostType( pt.slug ) }
							__nextHasNoMarginBottom
						/>
					) ) }
				</fieldset>
			</div>
		</Card>
	);
}
