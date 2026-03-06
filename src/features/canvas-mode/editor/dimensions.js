import { state, getEditorIframe, getEditorDoc } from './state';
import { getCanvasScale } from './canvas';

let _badgeEl = null;
let _overlayContainer = null;
let _boundDoc = null;
let _moveHandler = null;
let _leaveHandler = null;
let _storeUnsub = null;
let _selectedClientId = null;
let _rafId = 0;

function ensureOverlayContainer() {
	if ( _overlayContainer ) {
		return _overlayContainer;
	}
	_overlayContainer = document.createElement( 'div' );
	_overlayContainer.className = 'wpi-canvas-overlay-container';
	document.body.appendChild( _overlayContainer );
	return _overlayContainer;
}

function ensureBadge() {
	if ( _badgeEl ) {
		return _badgeEl;
	}
	_badgeEl = document.createElement( 'div' );
	_badgeEl.className = 'wpi-canvas-dim-badge';
	ensureOverlayContainer().appendChild( _badgeEl );
	return _badgeEl;
}

function findBlockElement( target ) {
	if ( ! target || target.nodeType !== 1 ) {
		return null;
	}
	return target.closest( '[data-block]' );
}

function mapIframeRect( iframeRect, elementRect, scale ) {
	return {
		top: iframeRect.top + elementRect.top * scale,
		left: iframeRect.left + elementRect.left * scale,
		width: elementRect.width * scale,
		height: elementRect.height * scale,
		rawWidth: elementRect.width,
		rawHeight: elementRect.height,
	};
}

function formatDimension( px, parentPx ) {
	if ( parentPx && Math.abs( px - parentPx ) < 2 ) {
		return 'Fill';
	}
	return Math.round( px );
}

function showBadge( blockEl ) {
	if ( ! blockEl || ! state.active ) {
		return;
	}

	const iframe = getEditorIframe();
	if ( ! iframe ) {
		return;
	}

	const scale = getCanvasScale();
	const iframeRect = iframe.getBoundingClientRect();
	const elRect = blockEl.getBoundingClientRect();

	const mapped = mapIframeRect( iframeRect, elRect, scale );
	const badge = ensureBadge();

	const parentEl = blockEl.parentElement?.closest( '[data-block]' );
	const parentWidth = parentEl ? parentEl.getBoundingClientRect().width : null;

	const wLabel = formatDimension( mapped.rawWidth, parentWidth );
	const hLabel = Math.round( mapped.rawHeight );
	badge.textContent = wLabel + ' \u00d7 ' + hLabel;

	badge.style.top = ( mapped.top - 22 ) + 'px';
	badge.style.left = ( mapped.left + mapped.width / 2 ) + 'px';
	badge.classList.add( 'is-visible' );
}

function hideBadge() {
	if ( _badgeEl ) {
		_badgeEl.classList.remove( 'is-visible' );
	}
}

function onMouseMove( e ) {
	if ( _selectedClientId ) {
		return;
	}
	cancelAnimationFrame( _rafId );
	_rafId = requestAnimationFrame( () => {
		const blockEl = findBlockElement( e.target );
		if ( blockEl ) {
			showBadge( blockEl );
		} else {
			hideBadge();
		}
	} );
}

function onMouseLeave() {
	if ( ! _selectedClientId ) {
		hideBadge();
	}
}

function onBlockSelected( clientId ) {
	_selectedClientId = clientId;
	if ( ! clientId ) {
		hideBadge();
		return;
	}
	const doc = getEditorDoc();
	if ( ! doc ) {
		return;
	}
	const blockEl = doc.querySelector( '[data-block="' + clientId + '"]' );
	if ( blockEl ) {
		showBadge( blockEl );
	} else {
		hideBadge();
	}
}

function startBlockSelectionSync() {
	const data = window.wp?.data;
	if ( ! data ) {
		return;
	}
	_storeUnsub = data.subscribe( () => {
		if ( ! state.active ) {
			return;
		}
		const sel = data.select( 'core/block-editor' ).getSelectedBlockClientId();
		if ( sel !== _selectedClientId ) {
			onBlockSelected( sel );
		}
	} );
}

export function initDimensions() {
	const doc = getEditorDoc();
	if ( ! doc ) {
		return;
	}

	_boundDoc = doc;
	_moveHandler = onMouseMove;
	_leaveHandler = onMouseLeave;
	doc.addEventListener( 'mousemove', _moveHandler );
	doc.addEventListener( 'mouseleave', _leaveHandler );

	startBlockSelectionSync();
}

export function destroyDimensions() {
	cancelAnimationFrame( _rafId );
	_rafId = 0;

	if ( _boundDoc ) {
		_boundDoc.removeEventListener( 'mousemove', _moveHandler );
		_boundDoc.removeEventListener( 'mouseleave', _leaveHandler );
		_boundDoc = null;
	}

	if ( _storeUnsub ) {
		_storeUnsub();
		_storeUnsub = null;
	}

	_selectedClientId = null;
	_badgeEl?.remove();
	_badgeEl = null;
	_overlayContainer?.remove();
	_overlayContainer = null;
}
