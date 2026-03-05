import { useCallback } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { TextControl, CheckboxControl, Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';
import Card from '../components/card';

export default function ResourceHintsTab() {
	const { settings, isActive } = useSelect( ( select ) => {
		const store = select( STORE_NAME );
		return {
			settings: store.getSettings(),
			isActive: store.isModuleActive( 'resource_hints' ),
		};
	}, [] );

	const { updateSetting } = useDispatch( STORE_NAME );

	if ( ! isActive ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __( 'The Resource Hints module is disabled. Enable it on the Modules tab.', 'wp-intelligence' ) }
			</Notice>
		);
	}

	const hintsSettings = settings.wpi_resource_hints || {};
	const origins = hintsSettings.origins || [];

	const updateOrigins = useCallback(
		( updated ) => {
			updateSetting( 'wpi_resource_hints', {
				...hintsSettings,
				origins: updated,
			} );
		},
		[ hintsSettings, updateSetting ]
	);

	const addOrigin = () => {
		updateOrigins( [ ...origins, { url: '', crossorigin: false } ] );
	};

	const removeOrigin = ( index ) => {
		updateOrigins( origins.filter( ( _, i ) => i !== index ) );
	};

	const updateOrigin = ( index, key, value ) => {
		const updated = [ ...origins ];
		updated[ index ] = { ...updated[ index ], [ key ]: value };
		updateOrigins( updated );
	};

	return (
		<Card
			title={ __( 'Resource Hints', 'wp-intelligence' ) }
			description={ __( 'Add preconnect and dns-prefetch hints for external domains to improve page load speed.', 'wp-intelligence' ) }
			icon="admin-links"
		>
			<div className="wpi-resource-hints">
				{ origins.map( ( origin, idx ) => (
					<div key={ idx } className="wpi-origin-row">
						<TextControl
							value={ origin.url || '' }
							onChange={ ( v ) => updateOrigin( idx, 'url', v ) }
							placeholder="https://cdn.example.com"
							type="url"
							__nextHasNoMarginBottom
						/>
						<CheckboxControl
							label={ __( 'crossorigin', 'wp-intelligence' ) }
							checked={ !! origin.crossorigin }
							onChange={ ( v ) => updateOrigin( idx, 'crossorigin', v ) }
							__nextHasNoMarginBottom
						/>
						<Button
							variant="tertiary"
							isDestructive
							size="compact"
							onClick={ () => removeOrigin( idx ) }
							aria-label={ __( 'Remove origin', 'wp-intelligence' ) }
						>
							&times;
						</Button>
					</div>
				) ) }
				<Button variant="secondary" onClick={ addOrigin }>
					{ __( '+ Add origin', 'wp-intelligence' ) }
				</Button>
			</div>
		</Card>
	);
}
