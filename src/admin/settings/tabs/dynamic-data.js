import { useState, useCallback, useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import {
	Button,
	Notice,
	TextControl,
	SelectControl,
	Spinner,
	__experimentalInputControl as InputControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { STORE_NAME } from '../store';
import Card from '../components/card';

const REST_NS = 'wpi-dynamic-data/v1';

export default function DynamicDataTab() {
	const isActive = useSelect(
		( select ) => select( STORE_NAME ).isModuleActive( 'dynamic_data' ),
		[]
	);

	if ( ! isActive ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'The Dynamic Data module is disabled. Enable it on the Modules tab.',
					'wp-intelligence'
				) }
			</Notice>
		);
	}

	return (
		<>
			<WebhookManager />
			<MergeTagReference />
		</>
	);
}

function WebhookManager() {
	const [ webhooks, setWebhooks ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ testResults, setTestResults ] = useState( {} );

	const loadWebhooks = useCallback( () => {
		setLoading( true );
		apiFetch( { path: `${ REST_NS }/sources` } )
			.then( ( res ) => {
				const wh = ( res.sources || [] ).filter( ( s ) => s.type === 'webhook' );
				setWebhooks( wh );
				setError( '' );
			} )
			.catch( () => setError( __( 'Failed to load data sources.', 'wp-intelligence' ) ) )
			.finally( () => setLoading( false ) );
	}, [] );

	useEffect( () => {
		loadWebhooks();
	}, [ loadWebhooks ] );

	const handleDelete = useCallback(
		( name ) => {
			if ( ! window.confirm( __( 'Delete webhook "', 'wp-intelligence' ) + name + '"?' ) ) {
				return;
			}
			apiFetch( { path: `${ REST_NS }/webhooks/${ name }`, method: 'DELETE' } )
				.then( () => loadWebhooks() )
				.catch( () => setError( __( 'Failed to delete webhook.', 'wp-intelligence' ) ) );
		},
		[ loadWebhooks ]
	);

	const handleTest = useCallback( ( name ) => {
		setTestResults( ( prev ) => ( { ...prev, [ name ]: 'testing' } ) );
		apiFetch( {
			path: `${ REST_NS }/test`,
			method: 'POST',
			data: { name, url: '' },
		} )
			.then( ( res ) => {
				setTestResults( ( prev ) => ( {
					...prev,
					[ name ]: res.success
						? __( 'OK', 'wp-intelligence' ) + ` (${ ( res.fields || [] ).length } fields)`
						: res.error || __( 'Failed', 'wp-intelligence' ),
				} ) );
			} )
			.catch( () => {
				setTestResults( ( prev ) => ( {
					...prev,
					[ name ]: __( 'Connection error', 'wp-intelligence' ),
				} ) );
			} );
	}, [] );

	return (
		<Card
			title={ __( 'Webhook Data Sources', 'wp-intelligence' ) }
			description={ __(
				'Define external API endpoints to pre-fetch data. The fetched data can be used in merge tags and block visibility conditions.',
				'wp-intelligence'
			) }
			icon="cloud"
		>
			{ error && (
				<Notice status="error" isDismissible={ false } className="wpi-dd-notice">
					{ error }
				</Notice>
			) }

			{ loading && <Spinner /> }

			{ ! loading && webhooks && webhooks.length > 0 && (
				<table className="wpi-dd-table">
					<thead>
						<tr>
							<th>{ __( 'Name', 'wp-intelligence' ) }</th>
							<th>{ __( 'Label', 'wp-intelligence' ) }</th>
							<th>{ __( 'Type', 'wp-intelligence' ) }</th>
							<th>{ __( 'Actions', 'wp-intelligence' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ webhooks.map( ( wh ) => (
							<tr key={ wh.name }>
								<td>
									<code>{ wh.name }</code>
								</td>
								<td>{ wh.label || wh.name }</td>
								<td>{ wh.type }</td>
								<td className="wpi-dd-actions">
									<Button
										variant="secondary"
										size="small"
										isBusy={ testResults[ wh.name ] === 'testing' }
										onClick={ () => handleTest( wh.name ) }
									>
										{ __( 'Test', 'wp-intelligence' ) }
									</Button>
									<Button
										variant="secondary"
										size="small"
										isDestructive
										onClick={ () => handleDelete( wh.name ) }
									>
										{ __( 'Delete', 'wp-intelligence' ) }
									</Button>
									{ testResults[ wh.name ] &&
										testResults[ wh.name ] !== 'testing' && (
											<span className="wpi-dd-test-result">
												{ testResults[ wh.name ] }
											</span>
										) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ ! loading && ( ! webhooks || webhooks.length === 0 ) && (
				<p className="wpi-dd-empty">
					{ __( 'No webhook data sources configured yet.', 'wp-intelligence' ) }
				</p>
			) }

			<AddWebhookForm onCreated={ loadWebhooks } />
		</Card>
	);
}

function AddWebhookForm( { onCreated } ) {
	const [ expanded, setExpanded ] = useState( false );
	const [ name, setName ] = useState( '' );
	const [ label, setLabel ] = useState( '' );
	const [ url, setUrl ] = useState( '' );
	const [ method, setMethod ] = useState( 'GET' );
	const [ authType, setAuthType ] = useState( 'none' );
	const [ authValue, setAuthValue ] = useState( '' );
	const [ cacheTtl, setCacheTtl ] = useState( '300' );
	const [ status, setStatus ] = useState( '' );
	const [ saving, setSaving ] = useState( false );

	const reset = useCallback( () => {
		setName( '' );
		setLabel( '' );
		setUrl( '' );
		setMethod( 'GET' );
		setAuthType( 'none' );
		setAuthValue( '' );
		setCacheTtl( '300' );
		setStatus( '' );
	}, [] );

	const handleAdd = useCallback( () => {
		if ( ! name || ! url ) {
			setStatus( __( 'Name and URL are required.', 'wp-intelligence' ) );
			return;
		}
		setSaving( true );
		setStatus( '' );
		apiFetch( {
			path: `${ REST_NS }/webhooks`,
			method: 'POST',
			data: {
				name,
				label,
				url,
				method,
				auth_type: authType,
				auth_value: authValue,
				cache_ttl: parseInt( cacheTtl, 10 ) || 300,
			},
		} )
			.then( ( res ) => {
				if ( res.success ) {
					reset();
					setExpanded( false );
					onCreated();
				} else {
					setStatus( res.message || __( 'Error creating webhook.', 'wp-intelligence' ) );
				}
			} )
			.catch( ( err ) => {
				setStatus( err.message || __( 'Error creating webhook.', 'wp-intelligence' ) );
			} )
			.finally( () => setSaving( false ) );
	}, [ name, label, url, method, authType, authValue, cacheTtl, reset, onCreated ] );

	const handleTest = useCallback( () => {
		if ( ! url ) {
			setStatus( __( 'URL is required.', 'wp-intelligence' ) );
			return;
		}
		setStatus( __( 'Testing…', 'wp-intelligence' ) );
		apiFetch( {
			path: `${ REST_NS }/test`,
			method: 'POST',
			data: {
				name: name || 'test',
				url,
				method,
				auth_type: authType,
				auth_value: authValue,
			},
		} )
			.then( ( res ) => {
				if ( res.success ) {
					setStatus(
						__( 'Success! Found ', 'wp-intelligence' ) +
							( res.fields ? res.fields.length : 0 ) +
							__( ' fields.', 'wp-intelligence' )
					);
				} else {
					setStatus( res.error || __( 'Test failed.', 'wp-intelligence' ) );
				}
			} )
			.catch( () => setStatus( __( 'Connection error.', 'wp-intelligence' ) ) );
	}, [ name, url, method, authType, authValue ] );

	if ( ! expanded ) {
		return (
			<div className="wpi-dd-add-trigger">
				<Button variant="secondary" onClick={ () => setExpanded( true ) }>
					{ __( '+ Add Webhook Data Source', 'wp-intelligence' ) }
				</Button>
			</div>
		);
	}

	return (
		<div className="wpi-dd-add-form">
			<h4>{ __( 'Add Webhook Data Source', 'wp-intelligence' ) }</h4>
			<div className="wpi-dd-form-grid">
				<TextControl
					label={ __( 'Name (slug)', 'wp-intelligence' ) }
					help={ __(
						'Lowercase letters, numbers, underscores. Used in merge tags as {{name.field}}.',
						'wp-intelligence'
					) }
					value={ name }
					onChange={ setName }
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={ __( 'Label', 'wp-intelligence' ) }
					value={ label }
					onChange={ setLabel }
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={ __( 'Endpoint URL', 'wp-intelligence' ) }
					help={ __( 'Supports {{url.param}} for dynamic URL parameters.', 'wp-intelligence' ) }
					value={ url }
					onChange={ setUrl }
					type="url"
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={ __( 'Method', 'wp-intelligence' ) }
					value={ method }
					options={ [
						{ label: 'GET', value: 'GET' },
						{ label: 'POST', value: 'POST' },
					] }
					onChange={ setMethod }
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={ __( 'Authentication', 'wp-intelligence' ) }
					value={ authType }
					options={ [
						{ label: __( 'None', 'wp-intelligence' ), value: 'none' },
						{ label: __( 'Bearer Token', 'wp-intelligence' ), value: 'bearer' },
						{ label: __( 'Basic Auth', 'wp-intelligence' ), value: 'basic' },
						{ label: __( 'API Key Header', 'wp-intelligence' ), value: 'api_key' },
					] }
					onChange={ setAuthType }
					__nextHasNoMarginBottom
				/>
				{ authType !== 'none' && (
					<TextControl
						label={ __( 'Auth Credential', 'wp-intelligence' ) }
						help={ __(
							'Token, user:password, or API key depending on auth type.',
							'wp-intelligence'
						) }
						value={ authValue }
						onChange={ setAuthValue }
						type="password"
						__nextHasNoMarginBottom
					/>
				) }
				<TextControl
					label={ __( 'Cache Duration (seconds)', 'wp-intelligence' ) }
					value={ cacheTtl }
					onChange={ setCacheTtl }
					type="number"
					min={ 0 }
					max={ 86400 }
					__nextHasNoMarginBottom
				/>
			</div>

			{ status && (
				<p className="wpi-dd-status">{ status }</p>
			) }

			<div className="wpi-dd-form-actions">
				<Button variant="primary" isBusy={ saving } onClick={ handleAdd }>
					{ __( 'Add Webhook', 'wp-intelligence' ) }
				</Button>
				<Button variant="secondary" onClick={ handleTest }>
					{ __( 'Test Connection', 'wp-intelligence' ) }
				</Button>
				<Button
					variant="tertiary"
					onClick={ () => {
						reset();
						setExpanded( false );
					} }
				>
					{ __( 'Cancel', 'wp-intelligence' ) }
				</Button>
			</div>
		</div>
	);
}

function MergeTagReference() {
	const [ tags, setTags ] = useState( null );
	const [ loading, setLoading ] = useState( true );

	useEffect( () => {
		apiFetch( { path: `${ REST_NS }/tags` } )
			.then( ( res ) => setTags( res.tags || [] ) )
			.catch( () => setTags( [] ) )
			.finally( () => setLoading( false ) );
	}, [] );

	const groups = {};
	if ( tags ) {
		tags.forEach( ( tag ) => {
			const group = tag.group || __( 'Other', 'wp-intelligence' );
			if ( ! groups[ group ] ) {
				groups[ group ] = [];
			}
			groups[ group ].push( tag );
		} );
	}

	return (
		<Card
			title={ __( 'Available Merge Tags', 'wp-intelligence' ) }
			description={ __(
				'Use these merge tags in your block content. They will be replaced with dynamic values on the frontend. Type {{ in any text block to trigger autocomplete.',
				'wp-intelligence'
			) }
			icon="tag"
		>
			{ loading && <Spinner /> }

			{ ! loading && tags && tags.length === 0 && (
				<p className="wpi-dd-empty">
					{ __( 'No merge tags available.', 'wp-intelligence' ) }
				</p>
			) }

			{ ! loading &&
				Object.keys( groups ).map( ( groupName ) => (
					<div key={ groupName } className="wpi-dd-tag-group">
						<h4 className="wpi-dd-group-title">{ groupName }</h4>
						<table className="wpi-dd-table wpi-dd-tags-table">
							<tbody>
								{ groups[ groupName ].map( ( tag ) => (
									<tr key={ tag.tag }>
										<td>
											<code>{ `{{${ tag.tag }}}` }</code>
										</td>
										<td>
											{ tag.label }
											{ tag.clientSide && (
												<span className="wpi-dd-badge">
													{ __( 'client-side', 'wp-intelligence' ) }
												</span>
											) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) ) }
		</Card>
	);
}
