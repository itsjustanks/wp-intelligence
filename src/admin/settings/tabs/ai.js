import { useState, useCallback, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	TextControl,
	TextareaControl,
	SelectControl,
	CheckboxControl,
	Button,
	Notice,
	Spinner,
	DropZone,
	__experimentalNumberControl as NumberControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { STORE_NAME } from '../store';
import Card from '../components/card';

export default function AITab() {
	const config = window.wpiSettingsConfig || {};
	const { settings } = useSelect( ( select ) => ( {
		settings: select( STORE_NAME ).getSettings(),
	} ), [] );

	const { updateSetting, updateNestedSetting } = useDispatch( STORE_NAME );
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

	const mcpEnabled = settings.mcp_context_enabled === '1' || settings.mcp_context_enabled === true;
	const mcpUrl = settings.mcp_server_url || '';
	const mcpCacheTtl = settings.mcp_cache_ttl || 3600;
	const vectorStoreId = settings.vector_store_id || '';

	const syn = settings.syndication || {};
	const brandContext = syn.brand_context || '';
	const trainingUrls = syn.training_urls || '';
	const examplePostIds = ( syn.example_post_ids || [] ).join( ', ' );

	const [ vsFiles, setVsFiles ] = useState( [] );
	const [ vsLoading, setVsLoading ] = useState( false );
	const [ uploading, setUploading ] = useState( false );
	const [ vsCreating, setVsCreating ] = useState( false );
	const [ fileNotice, setFileNotice ] = useState( null );

	const updateSyn = useCallback(
		( key, value ) => updateNestedSetting( 'syndication', key, value ),
		[ updateNestedSetting ]
	);

	const fetchVsFiles = useCallback( async ( storeId ) => {
		if ( ! storeId ) {
			setVsFiles( [] );
			return;
		}
		setVsLoading( true );
		try {
			const result = await apiFetch( {
				path: `/wp-intelligence/v1/vector-stores/${ storeId }/files`,
			} );
			setVsFiles( result.data || [] );
		} catch {
			setVsFiles( [] );
		}
		setVsLoading( false );
	}, [] );

	useEffect( () => {
		if ( vectorStoreId ) {
			fetchVsFiles( vectorStoreId );
		}
	}, [ vectorStoreId, fetchVsFiles ] );

	const handleCreateVectorStore = useCallback( async () => {
		setVsCreating( true );
		setFileNotice( null );
		try {
			const result = await apiFetch( {
				path: '/wp-intelligence/v1/vector-stores',
				method: 'POST',
				data: { name: 'WP Intelligence Context' },
			} );
			if ( result.id ) {
				updateSetting( 'vector_store_id', result.id );
				setFileNotice( { status: 'success', msg: __( 'Vector store created.', 'wp-intelligence' ) } );
			}
		} catch ( err ) {
			setFileNotice( { status: 'error', msg: err.message || __( 'Failed to create vector store.', 'wp-intelligence' ) } );
		}
		setVsCreating( false );
	}, [ updateSetting ] );

	const handleFileUpload = useCallback( async ( files ) => {
		if ( ! files || files.length === 0 ) {
			return;
		}
		setUploading( true );
		setFileNotice( null );

		for ( const file of files ) {
			const formData = new FormData();
			formData.append( 'file', file );
			if ( vectorStoreId ) {
				formData.append( 'vector_store_id', vectorStoreId );
			}

			try {
				await apiFetch( {
					path: '/wp-intelligence/v1/files',
					method: 'POST',
					body: formData,
					headers: {},
				} );
				setFileNotice( { status: 'success', msg: file.name + __( ' uploaded.', 'wp-intelligence' ) } );
			} catch ( err ) {
				setFileNotice( { status: 'error', msg: `${ file.name }: ${ err.message || 'Upload failed' }` } );
			}
		}

		if ( vectorStoreId ) {
			await fetchVsFiles( vectorStoreId );
		}
		setUploading( false );
	}, [ vectorStoreId, fetchVsFiles ] );

	const handleDeleteFile = useCallback( async ( fileId ) => {
		setFileNotice( null );
		try {
			await apiFetch( {
				path: `/wp-intelligence/v1/files/${ fileId }`,
				method: 'DELETE',
			} );
			if ( vectorStoreId ) {
				await fetchVsFiles( vectorStoreId );
			}
			setFileNotice( { status: 'success', msg: __( 'File deleted.', 'wp-intelligence' ) } );
		} catch ( err ) {
			setFileNotice( { status: 'error', msg: err.message || __( 'Failed to delete file.', 'wp-intelligence' ) } );
		}
	}, [ vectorStoreId, fetchVsFiles ] );

	const renderProviderCard = () => {
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
	};

	return (
		<div className="wpi-ai-tab">
			{ renderProviderCard() }

			<Card
				title={ __( 'Context Provider (MCP)', 'wp-intelligence' ) }
				description={ __( 'Connect to an MCP server to enrich AI features with brand context, proof points, and guidelines.', 'wp-intelligence' ) }
				icon="database"
			>
				<div className="wpi-form-fields">
					<CheckboxControl
						label={ __( 'Load context from an MCP server for all AI features', 'wp-intelligence' ) }
						checked={ mcpEnabled }
						onChange={ ( v ) => updateSetting( 'mcp_context_enabled', v ? '1' : '0' ) }
						help={ __( 'When disabled, AI features fall back to theme context directory files.', 'wp-intelligence' ) }
						__nextHasNoMarginBottom
					/>

					<TextControl
						label={ __( 'MCP Server URL', 'wp-intelligence' ) }
						value={ mcpUrl }
						onChange={ ( v ) => updateSetting( 'mcp_server_url', v ) }
						type="url"
						placeholder={ __( 'e.g. http://localhost:3000/api/mcp', 'wp-intelligence' ) }
						help={ __( 'Streamable HTTP endpoint of your MCP context server. Leave empty to use file-based context only.', 'wp-intelligence' ) }
						__nextHasNoMarginBottom
					/>

					<NumberControl
						label={ __( 'Cache TTL (seconds)', 'wp-intelligence' ) }
						value={ mcpCacheTtl }
						onChange={ ( v ) => updateSetting( 'mcp_cache_ttl', parseInt( v, 10 ) || 3600 ) }
						min={ 60 }
						max={ 86400 }
						step={ 60 }
						help={ __( 'How long to cache context from the MCP server. Default: 3600 (1 hour).', 'wp-intelligence' ) }
						__nextHasNoMarginBottom
					/>
				</div>
			</Card>

			<Card
				title={ __( 'Content Training', 'wp-intelligence' ) }
				description={ __( 'Persistent brand context applied to all AI features — Composer, Content Intelligence, Chat, and Featured Image.', 'wp-intelligence' ) }
				icon="welcome-learn-more"
			>
				<div className="wpi-form-fields">
					<TextareaControl
						label={ __( 'Brand context & guidelines', 'wp-intelligence' ) }
						value={ brandContext }
						onChange={ ( v ) => updateSyn( 'brand_context', v ) }
						rows={ 5 }
						placeholder={ __( 'e.g.\n- Always use our full brand name\n- Professional but accessible tone\n- Australian English spelling', 'wp-intelligence' ) }
						help={ __( 'Tone of voice, key messaging, terminology rules. Injected into every AI prompt across all features.', 'wp-intelligence' ) }
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

			{ ! config.hasNativeAI && (
				<Card
					title={ __( 'File Search & Context Files', 'wp-intelligence' ) }
					description={ __( 'Upload documents for AI file search. Files are stored in an OpenAI vector store and available to all AI features via the Responses API.', 'wp-intelligence' ) }
					icon="media-document"
				>
					<div className="wpi-form-fields">
						{ fileNotice && (
							<Notice
								status={ fileNotice.status }
								isDismissible
								onDismiss={ () => setFileNotice( null ) }
							>
								{ fileNotice.msg }
							</Notice>
						) }

						{ ! vectorStoreId ? (
							<div className="wpi-vs-setup">
								<p>{ __( 'No vector store configured. Create one to enable file search across all AI features.', 'wp-intelligence' ) }</p>
								<Button
									variant="primary"
									onClick={ handleCreateVectorStore }
									isBusy={ vsCreating }
									disabled={ vsCreating || ! apiKey }
								>
									{ vsCreating
										? __( 'Creating…', 'wp-intelligence' )
										: __( 'Create Vector Store', 'wp-intelligence' ) }
								</Button>
								{ ! apiKey && (
									<p className="wpi-notice-inline">{ __( 'Set your API key above first.', 'wp-intelligence' ) }</p>
								) }
							</div>
						) : (
							<>
								<TextControl
									label={ __( 'Vector Store ID', 'wp-intelligence' ) }
									value={ vectorStoreId }
									onChange={ ( v ) => updateSetting( 'vector_store_id', v ) }
									help={ __( 'The OpenAI vector store used for file_search. Auto-populated when you create one above.', 'wp-intelligence' ) }
									__nextHasNoMarginBottom
								/>

								<div className="wpi-file-upload-zone" style={ {
									border: '2px dashed #c3c4c7',
									borderRadius: '4px',
									padding: '20px',
									textAlign: 'center',
									position: 'relative',
									marginTop: '8px',
								} }>
									<DropZone
										onFilesDrop={ handleFileUpload }
									/>
									{ uploading ? (
										<Spinner />
									) : (
										<>
											<p style={ { margin: '0 0 8px' } }>
												{ __( 'Drop files here or click to upload', 'wp-intelligence' ) }
											</p>
											<input
												type="file"
												accept=".txt,.md,.csv,.json,.pdf,.docx,.html"
												multiple
												onChange={ ( e ) => handleFileUpload( Array.from( e.target.files ) ) }
												style={ { marginBottom: '4px' } }
											/>
											<p className="description" style={ { margin: '4px 0 0' } }>
												{ __( 'Supported: .txt, .md, .csv, .json, .pdf, .docx, .html (max 20 MB)', 'wp-intelligence' ) }
											</p>
										</>
									) }
								</div>

								{ vsLoading ? (
									<Spinner />
								) : vsFiles.length > 0 && (
									<div className="wpi-vs-files" style={ { marginTop: '12px' } }>
										<h4 style={ { margin: '0 0 8px' } }>
											{ __( 'Files in vector store', 'wp-intelligence' ) }
											<span style={ { fontWeight: 'normal', color: '#757575', marginLeft: '4px' } }>
												({ vsFiles.length })
											</span>
										</h4>
										<ul style={ { margin: 0, padding: 0, listStyle: 'none' } }>
											{ vsFiles.map( ( f ) => (
												<li key={ f.id } style={ {
													display: 'flex',
													alignItems: 'center',
													justifyContent: 'space-between',
													padding: '6px 0',
													borderBottom: '1px solid #f0f0f0',
												} }>
													<code style={ { fontSize: '12px' } }>{ f.id }</code>
													<Button
														variant="tertiary"
														isDestructive
														size="compact"
														onClick={ () => handleDeleteFile( f.id ) }
													>
														{ __( 'Delete', 'wp-intelligence' ) }
													</Button>
												</li>
											) ) }
										</ul>
									</div>
								) }
							</>
						) }
					</div>
				</Card>
			) }

			<McpServerCard
				settings={ settings }
				updateSetting={ updateSetting }
			/>
		</div>
	);
}

function McpServerCard( { settings, updateSetting } ) {
	const mcpServerEnabled = settings.mcp_server_enabled === '1' || settings.mcp_server_enabled === true;
	const mcpServerToken = settings.mcp_server_token || '';
	const [ showToken, setShowToken ] = useState( false );
	const [ copied, setCopied ] = useState( false );

	const siteUrl = window.location.origin;
	const mcpEndpoint = `${ siteUrl }/wp-json/wp-intelligence/v1/mcp`;

	const configSnippet = JSON.stringify( {
		mcpServers: {
			'wp-intelligence': {
				url: mcpEndpoint,
				headers: {
					Authorization: `Bearer ${ mcpServerToken || 'YOUR_TOKEN_HERE' }`,
				},
			},
		},
	}, null, 2 );

	const handleGenerateToken = useCallback( () => {
		const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		let token = 'wpi_';
		for ( let i = 0; i < 40; i++ ) {
			token += chars.charAt( Math.floor( Math.random() * chars.length ) );
		}
		updateSetting( 'mcp_server_token', token );
	}, [ updateSetting ] );

	const handleCopyConfig = useCallback( () => {
		navigator.clipboard.writeText( configSnippet ).then( () => {
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} );
	}, [ configSnippet ] );

	return (
		<Card
			title={ __( 'MCP Server', 'wp-intelligence' ) }
			description={ __( 'Expose your trained context as an MCP server so external AI tools (ChatGPT, Claude, Cursor) can access your brand knowledge, content, and proof points.', 'wp-intelligence' ) }
			icon="networking"
		>
			<div className="wpi-form-fields">
				<CheckboxControl
					label={ __( 'Enable MCP Server', 'wp-intelligence' ) }
					checked={ mcpServerEnabled }
					onChange={ ( v ) => updateSetting( 'mcp_server_enabled', v ? '1' : '0' ) }
					help={ __( 'When enabled, external AI clients can connect to this site via MCP.', 'wp-intelligence' ) }
					__nextHasNoMarginBottom
				/>

				{ mcpServerEnabled && (
					<>
						<div className="wpi-field-row">
							<TextControl
								label={ __( 'API Token', 'wp-intelligence' ) }
								value={ mcpServerToken }
								onChange={ ( v ) => updateSetting( 'mcp_server_token', v ) }
								type={ showToken ? 'text' : 'password' }
								autoComplete="off"
								help={ __( 'Bearer token for authenticating MCP requests.', 'wp-intelligence' ) }
								__nextHasNoMarginBottom
							/>
							<Button
								variant="secondary"
								size="compact"
								onClick={ () => setShowToken( ! showToken ) }
								className="wpi-toggle-key-btn"
							>
								{ showToken ? __( 'Hide', 'wp-intelligence' ) : __( 'Show', 'wp-intelligence' ) }
							</Button>
							<Button
								variant="secondary"
								size="compact"
								onClick={ handleGenerateToken }
							>
								{ __( 'Generate', 'wp-intelligence' ) }
							</Button>
						</div>

						<div style={ { marginTop: '12px' } }>
							<h4 style={ { margin: '0 0 8px' } }>{ __( 'MCP Endpoint', 'wp-intelligence' ) }</h4>
							<code style={ { display: 'block', padding: '8px 12px', background: '#f0f0f0', borderRadius: '4px', fontSize: '13px', wordBreak: 'break-all' } }>
								{ mcpEndpoint }
							</code>
						</div>

						<div style={ { marginTop: '12px' } }>
							<h4 style={ { margin: '0 0 8px' } }>
								{ __( 'Client Configuration', 'wp-intelligence' ) }
								<Button
									variant="tertiary"
									size="compact"
									onClick={ handleCopyConfig }
									style={ { marginLeft: '8px' } }
								>
									{ copied ? __( 'Copied!', 'wp-intelligence' ) : __( 'Copy', 'wp-intelligence' ) }
								</Button>
							</h4>
							<pre style={ {
								padding: '12px',
								background: '#1e1e1e',
								color: '#d4d4d4',
								borderRadius: '4px',
								fontSize: '12px',
								overflow: 'auto',
								maxHeight: '200px',
								margin: 0,
							} }>
								{ configSnippet }
							</pre>
							<p className="description" style={ { marginTop: '4px' } }>
								{ __( 'Add this to your AI client\'s MCP configuration (e.g. Claude Desktop, Cursor, ChatGPT).', 'wp-intelligence' ) }
							</p>
						</div>

						<div style={ { marginTop: '12px' } }>
							<h4 style={ { margin: '0 0 4px' } }>{ __( 'Available Tools', 'wp-intelligence' ) }</h4>
							<ul style={ { margin: 0, padding: 0, listStyle: 'none', fontSize: '13px' } }>
								{ [
									[ 'get_brand_voice', __( 'Brand voice and tone guidelines', 'wp-intelligence' ) ],
									[ 'get_context', __( 'Site context documents', 'wp-intelligence' ) ],
									[ 'get_context_for_task', __( 'Task-specific context bundles', 'wp-intelligence' ) ],
									[ 'get_proof_points', __( 'Verified proof points', 'wp-intelligence' ) ],
									[ 'get_safe_claims', __( 'Verified safe claims', 'wp-intelligence' ) ],
									[ 'get_site_info', __( 'Site metadata', 'wp-intelligence' ) ],
									[ 'search_posts', __( 'Search published content', 'wp-intelligence' ) ],
									[ 'get_post', __( 'Get full post content', 'wp-intelligence' ) ],
								].map( ( [ name, desc ] ) => (
									<li key={ name } style={ { padding: '3px 0', display: 'flex', gap: '8px' } }>
										<code style={ { fontSize: '12px', flexShrink: 0 } }>{ name }</code>
										<span style={ { color: '#757575' } }>{ desc }</span>
									</li>
								) ) }
							</ul>
						</div>
					</>
				) }
			</div>
		</Card>
	);
}
