import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { TextControl, Button, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Search-and-select component that resolves post IDs to titles.
 * Replaces raw comma-separated ID inputs with a WordPress-style post picker.
 *
 * @param {Object}   props
 * @param {number[]} props.value    - Selected post IDs.
 * @param {Function} props.onChange - Callback receiving updated ID array.
 * @param {number}   [props.max=5] - Maximum selectable posts.
 * @param {string}   [props.label]
 * @param {string}   [props.help]
 */
export default function PostSelector( { value = [], onChange, max = 5, label, help } ) {
	const [ search, setSearch ] = useState( '' );
	const [ results, setResults ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ posts, setPosts ] = useState( [] );
	const [ showResults, setShowResults ] = useState( false );
	const debounceRef = useRef( null );
	const containerRef = useRef( null );

	const ids = ( value || [] ).map( Number ).filter( Boolean );

	useEffect( () => {
		if ( ids.length === 0 ) {
			setPosts( [] );
			return;
		}

		let cancelled = false;
		const includeParam = ids.join( ',' );
		apiFetch( {
			path: `/wp/v2/search?include=${ includeParam }&per_page=${ ids.length }&type=post`,
		} )
			.then( ( res ) => {
				if ( cancelled ) {
					return;
				}
				const ordered = ids
					.map( ( id ) => {
						const match = res.find( ( r ) => r.id === id );
						return match
							? { id: match.id, title: match.title, url: match.url, type: match.subtype || match.type }
							: null;
					} )
					.filter( Boolean );
				setPosts( ordered );
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setPosts( [] );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ ids.join( ',' ) ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const doSearch = useCallback(
		( query ) => {
			if ( debounceRef.current ) {
				clearTimeout( debounceRef.current );
			}
			if ( query.length < 2 ) {
				setResults( [] );
				setLoading( false );
				return;
			}
			setLoading( true );
			debounceRef.current = setTimeout( () => {
				apiFetch( {
					path: `/wp/v2/search?search=${ encodeURIComponent( query ) }&per_page=8&type=post`,
				} )
					.then( ( res ) => {
						const selected = new Set( ids );
						setResults( res.filter( ( r ) => ! selected.has( r.id ) ) );
						setLoading( false );
					} )
					.catch( () => {
						setResults( [] );
						setLoading( false );
					} );
			}, 300 );
		},
		[ ids.join( ',' ) ] // eslint-disable-line react-hooks/exhaustive-deps
	);

	const handleSearchChange = ( query ) => {
		setSearch( query );
		doSearch( query );
		setShowResults( true );
	};

	const addPost = ( post ) => {
		if ( ids.length >= max ) {
			return;
		}
		onChange( [ ...ids, post.id ] );
		setSearch( '' );
		setResults( [] );
		setShowResults( false );
	};

	const removePost = ( id ) => {
		onChange( ids.filter( ( v ) => v !== id ) );
	};

	useEffect( () => {
		const handler = ( e ) => {
			if ( containerRef.current && ! containerRef.current.contains( e.target ) ) {
				setShowResults( false );
			}
		};
		document.addEventListener( 'mousedown', handler );
		return () => document.removeEventListener( 'mousedown', handler );
	}, [] );

	return (
		<div ref={ containerRef } className="wpi-post-selector">
			{ label && (
				<div className="components-base-control__label" style={ { marginBottom: '4px' } }>
					{ label }
				</div>
			) }

			{ ids.length < max && (
				<div style={ { position: 'relative' } }>
					<TextControl
						placeholder={ __( 'Search posts and pages…', 'wp-intelligence' ) }
						value={ search }
						onChange={ handleSearchChange }
						onFocus={ () => search.length >= 2 && setShowResults( true ) }
						__nextHasNoMarginBottom
					/>

					{ showResults && ( results.length > 0 || loading ) && (
						<div
							className="wpi-post-selector__dropdown"
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
										onClick={ () => addPost( r ) }
										className="wpi-post-selector__result"
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
									</button>
								) ) }
						</div>
					) }
				</div>
			) }

			{ posts.length > 0 && (
				<ul
					style={ {
						margin: '8px 0 0',
						padding: 0,
						listStyle: 'none',
					} }
				>
					{ posts.map( ( p ) => (
						<li
							key={ p.id }
							style={ {
								display: 'flex',
								alignItems: 'center',
								justifyContent: 'space-between',
								padding: '6px 10px',
								background: '#f6f7f7',
								borderRadius: '2px',
								marginBottom: '4px',
								fontSize: '13px',
							} }
						>
							<span>
								<strong>{ p.title }</strong>
								<span
									style={ {
										color: '#757575',
										marginLeft: '6px',
										fontSize: '11px',
									} }
								>
									{ p.type }
								</span>
							</span>
							<Button
								variant="tertiary"
								isDestructive
								size="compact"
								onClick={ () => removePost( p.id ) }
								label={ __( 'Remove', 'wp-intelligence' ) }
							>
								&times;
							</Button>
						</li>
					) ) }
				</ul>
			) }

			{ ids.length >= max && (
				<p className="components-base-control__help" style={ { margin: '4px 0 0' } }>
					{ __( 'Maximum posts selected.', 'wp-intelligence' ) }
				</p>
			) }

			{ help && (
				<p className="components-base-control__help" style={ { margin: '4px 0 0' } }>
					{ help }
				</p>
			) }
		</div>
	);
}
