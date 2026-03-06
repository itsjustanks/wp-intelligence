import {
	createElement,
	useState,
	useRef,
	useEffect,
	useCallback,
	useMemo,
} from '@wordpress/element';
import { useBlockProps, BlockControls } from '@wordpress/block-editor';
import { ToolbarGroup, ToolbarButton } from '@wordpress/components';
import { code as codeIcon, seen as previewIcon } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';

const DEBOUNCE_MS = 350;

let cachedTags = null;

function normalizeTags( raw ) {
	return ( raw || [] ).map( ( t ) => ( {
		tag: t.tag,
		label: t.label || t.tag,
		group: t.group || t.source || '',
		snippet: t.snippet || '',
	} ) );
}

function useMergeTags() {
	const [ tags, setTags ] = useState( () => {
		if ( cachedTags ) {
			return cachedTags;
		}
		const localized = window.wpiDynamicDataConfig?.tags;
		if ( localized?.length ) {
			cachedTags = normalizeTags( localized );
			return cachedTags;
		}
		return [];
	} );

	useEffect( () => {
		if ( cachedTags?.length ) {
			return;
		}
		apiFetch( { path: '/wpi-dynamic-data/v1/tags' } ).then( ( res ) => {
			cachedTags = normalizeTags( res?.tags );
			setTags( cachedTags );
		} ).catch( () => {} );
	}, [] );

	return tags;
}

function useAutocomplete( textareaRef, tags, content, setAttributes, schedulePreview ) {
	const [ open, setOpen ] = useState( false );
	const [ query, setQuery ] = useState( '' );
	const [ idx, setIdx ] = useState( 0 );
	const [ pos, setPos ] = useState( { top: 0, left: 0 } );
	const startRef = useRef( -1 );

	const filtered = useMemo( () => {
		if ( ! query ) {
			return tags;
		}
		const q = query.toLowerCase();
		return tags.filter(
			( t ) => t.tag.toLowerCase().includes( q ) || t.label.toLowerCase().includes( q )
		);
	}, [ tags, query ] );

	const close = useCallback( () => {
		setOpen( false );
		setQuery( '' );
		setIdx( 0 );
		startRef.current = -1;
	}, [] );

	const accept = useCallback(
		( item ) => {
			const ta = textareaRef.current;
			if ( ! ta ) {
				return;
			}
			const before = content.substring( 0, startRef.current );
			const after = content.substring( ta.selectionStart );
			const insertText = item.snippet || ( '{{' + item.tag + '}}' );
			const next = before + insertText + after;
			setAttributes( { content: next } );
			schedulePreview( next );
			close();

			const cursorTarget = item.snippet
				? before.length + insertText.indexOf( '\n' ) + 3
				: before.length + insertText.length;

			requestAnimationFrame( () => {
				ta.focus();
				ta.selectionStart = cursorTarget;
				ta.selectionEnd = cursorTarget;
			} );
		},
		[ content, close, setAttributes, schedulePreview, textareaRef ]
	);

	const onInput = useCallback(
		( e ) => {
			const ta = e.target;
			const cursor = ta.selectionStart;
			const text = ta.value;

			const slice = text.substring( Math.max( 0, cursor - 80 ), cursor );
			const trigger = slice.lastIndexOf( '{{' );

			if ( trigger === -1 || slice.indexOf( '}}', trigger ) !== -1 ) {
				if ( open ) {
					close();
				}
				return;
			}

			const absStart = Math.max( 0, cursor - 80 ) + trigger;
			const partial = text.substring( absStart + 2, cursor );

			if ( /\s{2,}/.test( partial ) ) {
				if ( open ) {
					close();
				}
				return;
			}

			startRef.current = absStart;
			setQuery( partial );
			setIdx( 0 );

			const rect = ta.getBoundingClientRect();
			const lineH = parseFloat( getComputedStyle( ta ).lineHeight ) || 20;
			const lines = text.substring( 0, cursor ).split( '\n' );
			const row = lines.length;
			const topOffset = ( row * lineH ) - ta.scrollTop + 4;

			setPos( {
				top: Math.min( topOffset, rect.height - 40 ),
				left: 18,
			} );
			setOpen( true );
		},
		[ open, close ]
	);

	const onAcKeyDown = useCallback(
		( e ) => {
			if ( ! open || filtered.length === 0 ) {
				return false;
			}

			if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				setIdx( ( i ) => ( i + 1 ) % filtered.length );
				return true;
			}
			if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				setIdx( ( i ) => ( i - 1 + filtered.length ) % filtered.length );
				return true;
			}
			if ( e.key === 'Enter' || e.key === 'Tab' ) {
				e.preventDefault();
				accept( filtered[ idx ] );
				return true;
			}
			if ( e.key === 'Escape' ) {
				e.preventDefault();
				close();
				return true;
			}
			return false;
		},
		[ open, filtered, idx, accept, close ]
	);

	return { open, filtered, idx, pos, onInput, onAcKeyDown, accept, close };
}

function AutocompleteDropdown( { items, activeIdx, pos, onSelect } ) {
	const listRef = useRef( null );

	useEffect( () => {
		if ( ! listRef.current ) {
			return;
		}
		const active = listRef.current.children[ activeIdx ];
		if ( active ) {
			active.scrollIntoView( { block: 'nearest' } );
		}
	}, [ activeIdx ] );

	if ( items.length === 0 ) {
		return null;
	}

	return createElement(
		'div',
		{
			className: 'wpi-hc-ac',
			style: { top: pos.top + 'px', left: pos.left + 'px' },
		},
		createElement(
			'ul',
			{ ref: listRef, className: 'wpi-hc-ac__list', role: 'listbox' },
			items.slice( 0, 40 ).map( ( item, i ) =>
				createElement(
					'li',
					{
						key: item.tag,
						role: 'option',
						'aria-selected': i === activeIdx,
						className: 'wpi-hc-ac__item' + ( i === activeIdx ? ' wpi-hc-ac__item--active' : '' ) + ( item.snippet ? ' wpi-hc-ac__item--snippet' : '' ),
						onMouseDown: ( e ) => {
							e.preventDefault();
							onSelect( item );
						},
					},
					item.snippet
						? createElement( 'span', { className: 'wpi-hc-ac__badge' }, '#if' )
						: null,
					createElement( 'span', { className: 'wpi-hc-ac__tag' }, item.snippet ? item.tag : '{{' + item.tag + '}}' ),
					createElement( 'span', { className: 'wpi-hc-ac__label' }, item.label )
				)
			)
		)
	);
}

const SPLIT_ICON = createElement(
	'svg',
	{ xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: 24, height: 24 },
	createElement( 'path', {
		fill: 'currentColor',
		d: 'M18 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2Zm-6 14H6V6h6v12Zm6 0h-4.5V6H18v12Z',
	} )
);

function buildSrcdoc( html ) {
	if ( ! html ) {
		return '';
	}
	const script =
		'<script>(function(){function r(){var h=Math.max(document.documentElement.scrollHeight,'
		+ 'document.body.scrollHeight);parent.postMessage({wpiHC:1,h:h},"*")}'
		+ 'if(typeof ResizeObserver!=="undefined"){new ResizeObserver(r).observe(document.documentElement)}'
		+ 'else{setInterval(r,400)}r()})()</script>';

	if ( html.includes( '</body>' ) ) {
		return html.replace( '</body>', script + '</body>' );
	}
	return html + script;
}

export default function Edit( { attributes, setAttributes, isSelected } ) {
	const { content } = attributes;
	const [ viewMode, setViewMode ] = useState( 'split' );
	const [ preview, setPreview ] = useState( content );
	const iframeRef = useRef( null );
	const timerRef = useRef( null );
	const textareaRef = useRef( null );

	const blockProps = useBlockProps( {
		className: 'wpi-hc-block',
	} );

	const schedulePreview = useCallback(
		( val ) => {
			clearTimeout( timerRef.current );
			timerRef.current = setTimeout( () => setPreview( val ), DEBOUNCE_MS );
		},
		[]
	);

	useEffect( () => () => clearTimeout( timerRef.current ), [] );

	const mergeTags = useMergeTags();
	const ac = useAutocomplete( textareaRef, mergeTags, content, setAttributes, schedulePreview );

	const onChange = ( e ) => {
		const val = e.target.value;
		setAttributes( { content: val } );
		schedulePreview( val );
		ac.onInput( e );
	};

	const onKeyDown = ( e ) => {
		if ( ac.onAcKeyDown( e ) ) {
			return;
		}
		e.stopPropagation();
		if ( e.key === 'Tab' ) {
			e.preventDefault();
			const ta = e.target;
			const start = ta.selectionStart;
			const end = ta.selectionEnd;
			const val = ta.value;
			const next = val.substring( 0, start ) + '  ' + val.substring( end );
			setAttributes( { content: next } );
			schedulePreview( next );
			requestAnimationFrame( () => {
				ta.selectionStart = start + 2;
				ta.selectionEnd = start + 2;
			} );
		}
	};

	useEffect( () => {
		const handler = ( e ) => {
			if (
				e.data &&
				e.data.wpiHC &&
				iframeRef.current &&
				e.source === iframeRef.current.contentWindow
			) {
				iframeRef.current.style.height = Math.max( 200, e.data.h ) + 'px';
			}
		};

		const win = iframeRef.current?.ownerDocument?.defaultView || window;
		win.addEventListener( 'message', handler );
		return () => win.removeEventListener( 'message', handler );
	}, [ preview ] );

	const showCode = viewMode === 'code' || viewMode === 'split';
	const showPreview = viewMode === 'preview' || viewMode === 'split';

	return createElement(
		'div',
		blockProps,
		createElement(
			BlockControls,
			null,
			createElement(
				ToolbarGroup,
				null,
				createElement( ToolbarButton, {
					icon: codeIcon,
					label: 'Code',
					isPressed: viewMode === 'code',
					onClick: () => setViewMode( 'code' ),
				} ),
				createElement( ToolbarButton, {
					icon: SPLIT_ICON,
					label: 'Split',
					isPressed: viewMode === 'split',
					onClick: () => setViewMode( 'split' ),
				} ),
				createElement( ToolbarButton, {
					icon: previewIcon,
					label: 'Preview',
					isPressed: viewMode === 'preview',
					onClick: () => setViewMode( 'preview' ),
				} )
			)
		),
		createElement(
			'div',
			{ className: 'wpi-hc-editor wpi-hc-editor--' + viewMode },
			showCode &&
				createElement(
					'div',
					{ className: 'wpi-hc-code' },
					createElement( 'textarea', {
						ref: textareaRef,
						className: 'wpi-hc-textarea',
						value: content,
						onChange,
						onKeyDown,
						onBlur: () => setTimeout( ac.close, 150 ),
						placeholder: 'Write HTML, CSS, and JavaScript\u2026',
						spellCheck: false,
						autoComplete: 'off',
						autoCorrect: 'off',
						autoCapitalize: 'off',
						'data-gramm': 'false',
					} ),
					ac.open && createElement( AutocompleteDropdown, {
						items: ac.filtered,
						activeIdx: ac.idx,
						pos: ac.pos,
						onSelect: ac.accept,
					} )
				),
			showPreview &&
				createElement(
					'div',
					{ className: 'wpi-hc-preview' },
					content
						? createElement( 'iframe', {
							ref: iframeRef,
							srcDoc: buildSrcdoc( preview ),
							sandbox: 'allow-scripts',
							title: 'HTML Canvas Preview',
							className: 'wpi-hc-iframe',
						} )
						: createElement(
							'div',
							{ className: 'wpi-hc-empty' },
							createElement(
								'p',
								null,
								'Write some HTML to see a live preview'
							)
						)
				)
		)
	);
}
