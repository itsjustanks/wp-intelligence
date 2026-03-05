import { useSelect, useDispatch } from '@wordpress/data';
import { ToggleControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';
import Card from '../components/card';

const SECURITY_FIELDS = [
	{ key: 'disable_xmlrpc', label: 'Disable XML-RPC', description: 'Prevents XML-RPC requests to reduce attack surface.' },
	{ key: 'disable_feeds', label: 'Disable RSS feeds', description: 'Removes all RSS/Atom feeds from the site.' },
	{ key: 'remove_version', label: 'Remove WordPress version', description: 'Strips the WordPress version from headers and source.' },
	{ key: 'remove_rsd_link', label: 'Remove RSD link', description: 'Removes the Really Simple Discovery link from the head.' },
	{ key: 'remove_wlwmanifest', label: 'Remove wlwmanifest link', description: 'Removes the Windows Live Writer manifest link.' },
	{ key: 'remove_shortlink', label: 'Remove shortlink', description: 'Removes the shortlink from the head.' },
	{ key: 'disable_file_editor', label: 'Disable file editor', description: 'Disables the built-in theme/plugin file editor.' },
];

export default function SecurityTab() {
	const { settings, isActive } = useSelect( ( select ) => {
		const store = select( STORE_NAME );
		return {
			settings: store.getSettings(),
			isActive: store.isModuleActive( 'security' ),
		};
	}, [] );

	const { updateSetting } = useDispatch( STORE_NAME );

	if ( ! isActive ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __( 'The Security module is disabled. Enable it on the Modules tab.', 'wp-intelligence' ) }
			</Notice>
		);
	}

	const securitySettings = settings.wpi_security || {};

	return (
		<Card
			title={ __( 'Security Hardening', 'wp-intelligence' ) }
			description={ __( 'Enable security hardening options to reduce your site\'s attack surface.', 'wp-intelligence' ) }
			icon="shield"
		>
			<div className="wpi-form-fields">
				{ SECURITY_FIELDS.map( ( field ) => (
					<ToggleControl
						key={ field.key }
						label={ __( field.label, 'wp-intelligence' ) }
						help={ __( field.description, 'wp-intelligence' ) }
						checked={ !! securitySettings[ field.key ] }
						onChange={ ( v ) => {
							updateSetting( 'wpi_security', {
								...securitySettings,
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
