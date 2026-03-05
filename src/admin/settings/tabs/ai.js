import { useState, useCallback } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	TextControl,
	SelectControl,
	Button,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';
import Card from '../components/card';

export default function AITab() {
	const config = window.wpiSettingsConfig || {};
	const { settings } = useSelect( ( select ) => ( {
		settings: select( STORE_NAME ).getSettings(),
	} ), [] );

	const { updateSetting } = useDispatch( STORE_NAME );
	const [ showKey, setShowKey ] = useState( false );

	const apiKey = settings.api_key || '';
	const model = settings.model || 'gpt-5.2';

	const models = config.availableModels || {
		'gpt-5.2': 'GPT-5.2',
		'gpt-5.1': 'GPT-5.1',
		'gpt-4.1': 'GPT-4.1',
		'gpt-4.1-mini': 'GPT-4.1 Mini',
		'gpt-4o': 'GPT-4o',
		'gpt-4o-mini': 'GPT-4o Mini',
	};

	const modelOptions = Object.entries( models ).map( ( [ value, label ] ) => ( {
		value,
		label,
	} ) );

	const handleKeyChange = useCallback(
		( value ) => updateSetting( 'api_key', value ),
		[ updateSetting ]
	);

	const handleModelChange = useCallback(
		( value ) => updateSetting( 'model', value ),
		[ updateSetting ]
	);

	if ( config.hasNativeAI ) {
		return (
			<Card title={ __( 'AI Runtime', 'wp-intelligence' ) } icon="cloud">
				<Notice status="success" isDismissible={ false }>
					{ __( 'WordPress native AI Client is available on this site.', 'wp-intelligence' ) }
				</Notice>
				<p className="wpi-settings-card__description" style={ { marginTop: '12px' } }>
					{ __( 'Provider credentials are managed in Settings → AI Credentials. WP Intelligence modules will use that runtime automatically.', 'wp-intelligence' ) }
				</p>
			</Card>
		);
	}

	return (
		<Card
			title={ __( 'AI Provider', 'wp-intelligence' ) }
			description={ __( 'Enter your AI provider credentials. On WordPress 7.0+, this is managed in Settings → AI Credentials instead.', 'wp-intelligence' ) }
			icon="cloud"
		>
			<div className="wpi-form-fields">
				<div className="wpi-field-row">
					<TextControl
						label={ __( 'OpenAI API Key', 'wp-intelligence' ) }
						value={ apiKey }
						onChange={ handleKeyChange }
						type={ showKey ? 'text' : 'password' }
						autoComplete="off"
						__nextHasNoMarginBottom
					/>
					<Button
						variant="secondary"
						size="compact"
						onClick={ () => setShowKey( ! showKey ) }
						className="wpi-toggle-key-btn"
					>
						{ showKey ? __( 'Hide', 'wp-intelligence' ) : __( 'Show', 'wp-intelligence' ) }
					</Button>
				</div>

				<SelectControl
					label={ __( 'Model', 'wp-intelligence' ) }
					value={ model }
					options={ modelOptions }
					onChange={ handleModelChange }
					__nextHasNoMarginBottom
				/>
			</div>
		</Card>
	);
}
