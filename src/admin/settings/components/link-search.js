import { useState, useCallback, useRef, useEffect } from '@wordpress/element';
import { TextControl, TextareaControl, Button, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * A textarea for URLs with an inline "Browse site" search that lets users
 * find and insert internal post permalinks instead of typing URLs by hand.
 *
 * @param {Object}   props
 * @param {string}   props.label
 * @param {string}   props.value       - Newline-separated URL string.
 * @param {Function} props.onChange
 * @param {number}   [props.rows=3]
 * @param {string}   [props.placeholder]
 * @param {string}   [props.help]
 */
export default function LinkSearch( { label, value, onChange, rows = 3, placeholder, help } ) {
	const [ searching, setSearching ] = useState( false );
	const [ query, setQuery ] = useState( '' );
	const [ results, setResults ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const debounceRef = useRef( null );
	const containerRef = useRef( null );

	const doSearch = useCallback( ( q ) => {
		if ( debounceRef.current ) {
			clearTimeout( debounceRef.current );
		}
		if ( q.length < 2 ) {
			setResults( [] );
			setLoading( false );
			return;
		}
		setLoading( true );
		debounceRef.current = setTimeout( () => {
			apiFetch( {
				path: `/wp/v2/search?search=${ encodeURIComponent( q ) }&per_page=6&type=post`,
			} )
				.then( ( res ) => {
					setResults( res );
					setLoading( false );
				} )
				.catch( () => {
					setResults( [] );
					setLoading( false );
				} );
		}, 300 );
	}, [] );

	const handleQueryChange = ( q ) => {
		setQuery( q );
		doSearch( q );
	};

	const insertUrl = ( url ) => {
		const current = ( value || '' ).trim();
		const updated = current ? current + '\n' + url : url;
		onChange( updated );
		setQuery( '' );
		setResults( [] );
		setSearching( false );
	};

	useEffect( () => {
		const handler = ( e ) => {
			if ( containerRef.current && ! containerRef.current.contains( e.target ) ) {
				setSearching( false );
			}
		};
		document.addEventListener( 'mousedown', handler );
		return () => document.removeEventListener( 'mousedown', handler );
	}, [] );

	return (
		<div ref={ containerRef } className="wpi-link-search">
			<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '4px' } }>
				{ label && (
					<div className="components-base-control__label" style={ { margin: 0 } }>
						{ label }
					</div>
				) }
				<Button
					variant="tertiary"
					size="compact"
					onClick={ () => setSearching( ! searching ) }
					style={ { fontSize: '12px' } }
				>
					{ searching
						? __( 'Close', 'wp-intelligence' )
						: __( 'Browse site', 'wp-intelligence' ) }
				</Button>
			</div>

			{ searching && (
				<div style={ { position: 'relative', marginBottom: '8px' } }>
					<TextControl
						placeholder={ __( 'Search posts and pages…', 'wp-intelligence' ) }
						value={ query }
						onChange={ handleQueryChange }
						__nextHasNoMarginBottom
					/>

					{ ( results.length > 0 || loading ) && (
						<div
							className="wpi-link-search__dropdown"
							style={ {
								position: 'absolute',
								zIndex: 100,
								background: '#fff',
								border: '1px solid #ddd',
								borderRadius: '2px',
								boxShadow: '0 2px 6px rgba(0,0,0,.1)',
								maxHeight: '200px',
								overflow: 'auto',
								width: '100%',
								top: '100%',
								marginTop: '-4px',
							} }
						>
							{ loading && (
								<div style={ { padding: '8px 12px', textAlign: 'center' } }>
									<Spinner />
								</div>
							) }
							{ ! loading &&
								results.map( ( r ) => (
									<button
										key={ r.id }
										type="button"
										onClick={ () => insertUrl( r.url ) }
										className="wpi-link-search__result"
										style={ {
											display: 'block',
											width: '100%',
											textAlign: 'left',
											padding: '8px 12px',
											border: 'none',
											background: 'none',
											cursor: 'pointer',
											borderBottom: '1px solid #f0f0f0',
											fontSize: '13px',
										} }
										onMouseEnter={ ( e ) => {
											e.currentTarget.style.background = '#f0f6fc';
										} }
										onMouseLeave={ ( e ) => {
											e.currentTarget.style.background = 'none';
										} }
									>
										<strong>{ r.title }</strong>
										<span
											style={ {
												color: '#757575',
												marginLeft: '8px',
												fontSize: '11px',
											} }
										>
											{ r.subtype || r.type }
										</span>
										<span
											style={ {
												display: 'block',
												color: '#2271b1',
												fontSize: '11px',
												marginTop: '2px',
												overflow: 'hidden',
												textOverflow: 'ellipsis',
												whiteSpace: 'nowrap',
											} }
										>
											{ r.url }
										</span>
									</button>
								) ) }
						</div>
					) }
				</div>
			) }

			<TextareaControl
				value={ value }
				onChange={ onChange }
				rows={ rows }
				placeholder={ placeholder }
				help={ help }
				__nextHasNoMarginBottom
			/>
		</div>
	);
}
