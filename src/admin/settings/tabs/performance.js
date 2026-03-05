import { useSelect, useDispatch } from '@wordpress/data';
import { ToggleControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';
import Card from '../components/card';

const PERFORMANCE_FIELDS = [
	{ key: 'gzip_compression', label: 'Enable GZIP compression', description: 'Compresses HTML output for faster page delivery.' },
	{ key: 'disable_emojis', label: 'Disable WordPress emojis', description: 'Removes the emoji detection script and styles.' },
	{ key: 'disable_embeds', label: 'Disable oEmbed embeds', description: 'Removes the oEmbed discovery and embed scripts.' },
	{ key: 'disable_dashicons', label: 'Disable Dashicons on frontend', description: 'Removes Dashicons CSS from non-logged-in users.' },
	{ key: 'disable_jquery_migrate', label: 'Disable jQuery Migrate', description: 'Removes the jQuery Migrate compatibility script.' },
];

export default function PerformanceTab() {
	const { settings, isActive } = useSelect( ( select ) => {
		const store = select( STORE_NAME );
		return {
			settings: store.getSettings(),
			isActive: store.isModuleActive( 'performance' ),
		};
	}, [] );

	const { updateSetting } = useDispatch( STORE_NAME );

	if ( ! isActive ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __( 'The Performance module is disabled. Enable it on the Modules tab.', 'wp-intelligence' ) }
			</Notice>
		);
	}

	const perfSettings = settings.wpi_performance || {};

	return (
		<Card
			title={ __( 'Performance', 'wp-intelligence' ) }
			description={ __( 'Runtime performance optimizations and asset management.', 'wp-intelligence' ) }
			icon="performance"
		>
			<div className="wpi-form-fields">
				{ PERFORMANCE_FIELDS.map( ( field ) => (
					<ToggleControl
						key={ field.key }
						label={ __( field.label, 'wp-intelligence' ) }
						help={ __( field.description, 'wp-intelligence' ) }
						checked={ !! perfSettings[ field.key ] }
						onChange={ ( v ) => {
							updateSetting( 'wpi_performance', {
								...perfSettings,
								[ field.key ]: v,
							} );
						} }
						__nextHasNoMarginBottom
					/>
				) ) }
			</div>
		</Card>
	);
}
