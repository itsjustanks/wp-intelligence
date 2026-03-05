import { useSelect, useDispatch } from '@wordpress/data';
import { ToggleControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';
import Card from '../components/card';

const WOO_FIELDS = [
	{ key: 'conditional_assets', label: 'Conditional asset loading', description: 'Only load WooCommerce scripts and styles on shop pages.' },
	{ key: 'checkout_persistence', label: 'Checkout field persistence', description: 'Remember checkout field values for returning customers.' },
];

export default function WooCommerceTab() {
	const { settings, isActive } = useSelect( ( select ) => {
		const store = select( STORE_NAME );
		return {
			settings: store.getSettings(),
			isActive: store.isModuleActive( 'woocommerce' ),
		};
	}, [] );

	const { updateSetting } = useDispatch( STORE_NAME );

	if ( ! isActive ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __( 'The WooCommerce module is disabled or WooCommerce is not installed.', 'wp-intelligence' ) }
			</Notice>
		);
	}

	const wooSettings = settings.wpi_woocommerce || {};

	return (
		<Card
			title={ __( 'WooCommerce Optimization', 'wp-intelligence' ) }
			description={ __( 'Optimize WooCommerce asset loading and checkout experience.', 'wp-intelligence' ) }
			icon="cart"
		>
			<div className="wpi-form-fields">
				{ WOO_FIELDS.map( ( field ) => (
					<ToggleControl
						key={ field.key }
						label={ __( field.label, 'wp-intelligence' ) }
						help={ __( field.description, 'wp-intelligence' ) }
						checked={ !! wooSettings[ field.key ] }
						onChange={ ( v ) => {
							updateSetting( 'wpi_woocommerce', {
								...wooSettings,
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
