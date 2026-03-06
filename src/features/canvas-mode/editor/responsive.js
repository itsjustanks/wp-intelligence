import {
	VIEWPORTS,
	state,
	getEditorVisual, viewportByKey,
} from './state';
import { refitCanvas, syncZoomLabel } from './canvas';
import { syncWidthDisplay, updatePills } from './ui';
import { setCanvasDeviceType } from './device-override';

const SNAP_THRESHOLD = 16;
const HANDLE_WIDTH = 8;

let _leftHandle = null;
let _rightHandle = null;
let _snapIndicator = null;
let _dragging = false;
let _dragSide = null;
let _dragStartX = 0;
let _dragStartWidth = 0;

function getBreakpoints() {
	return VIEWPORTS.map( ( vp ) => ( {
		key: vp.key,
		label: vp.label,
		width: vp.previewWidth,
	} ) );
}

function findSnapBreakpoint( width ) {
	const bps = getBreakpoints();
	for ( const bp of bps ) {
		if ( Math.abs( width - bp.width ) <= SNAP_THRESHOLD ) {
			return bp;
		}
	}
	return null;
}

function clampWidth( w ) {
	return Math.max( 280, Math.min( 2560, Math.round( w ) ) );
}

function applyWidth( width ) {
	const ev = getEditorVisual();
	if ( ! ev ) {
		return;
	}

	state.customWidth = width;

	ev.style.width = width + 'px';
	ev.style.maxWidth = width + 'px';

	syncWidthDisplay( width );

	const snap = findSnapBreakpoint( width );
	showSnapIndicator( snap );
}

function showSnapIndicator( bp ) {
	if ( ! _snapIndicator ) {
		return;
	}
	if ( bp ) {
		_snapIndicator.textContent = bp.label;
		_snapIndicator.classList.add( 'is-visible' );
	} else {
		_snapIndicator.classList.remove( 'is-visible' );
	}
}

function clearCustomWidth() {
	const ev = getEditorVisual();

	state.customWidth = null;

	if ( ev ) {
		ev.style.width = '';
		ev.style.maxWidth = '';
	}
}

function supportsManualResize() {
	return state.viewport === 'Desktop';
}

function onPointerDown( e ) {
	if ( ! state.active || ! supportsManualResize() ) {
		return;
	}
	_dragging = true;
	_dragSide = e.currentTarget === _leftHandle ? 'left' : 'right';
	_dragStartX = e.clientX;

	const ev = getEditorVisual();
	_dragStartWidth = state.customWidth || ev?.offsetWidth || 1440;

	document.body.classList.add( 'wpi-canvas-resizing' );
	e.currentTarget.classList.add( 'is-dragging' );

	try {
		e.currentTarget.setPointerCapture( e.pointerId );
	} catch ( _ex ) {} // eslint-disable-line no-empty

	e.preventDefault();
	e.stopPropagation();
}

function onPointerMove( e ) {
	if ( ! _dragging || ! supportsManualResize() ) {
		return;
	}

	const delta = e.clientX - _dragStartX;

	let newWidth;
	if ( _dragSide === 'right' ) {
		newWidth = _dragStartWidth + delta;
	} else {
		newWidth = _dragStartWidth - delta;
	}

	newWidth = clampWidth( newWidth );

	const snap = findSnapBreakpoint( newWidth );
	if ( snap ) {
		newWidth = snap.width;
	}

	applyWidth( newWidth );
	e.preventDefault();
}

function onPointerUp( e ) {
	if ( ! _dragging ) {
		return;
	}
	_dragging = false;
	document.body.classList.remove( 'wpi-canvas-resizing' );
	_leftHandle?.classList.remove( 'is-dragging' );
	_rightHandle?.classList.remove( 'is-dragging' );

	try {
		e.currentTarget.releasePointerCapture( e.pointerId );
	} catch ( _ex ) {} // eslint-disable-line no-empty

	showSnapIndicator( null );

	const matchedVp = VIEWPORTS.find(
		( vp ) => vp.previewWidth === state.customWidth
	);
	if ( matchedVp ) {
		state.viewport = matchedVp.key;
		updatePills();
	}

	refitCanvas( true );
}

function createHandle( side ) {
	const el = document.createElement( 'div' );
	el.className = 'wpi-canvas-resize-handle wpi-canvas-resize-handle--' + side;
	el.addEventListener( 'pointerdown', onPointerDown );
	return el;
}

export function initResponsive() {
	const ev = getEditorVisual();
	if ( ! ev ) {
		return;
	}

	_leftHandle = createHandle( 'left' );
	_rightHandle = createHandle( 'right' );

	ev.appendChild( _leftHandle );
	ev.appendChild( _rightHandle );

	_snapIndicator = document.createElement( 'div' );
	_snapIndicator.className = 'wpi-canvas-snap-indicator';
	ev.appendChild( _snapIndicator );

	document.addEventListener( 'pointermove', onPointerMove );
	document.addEventListener( 'pointerup', onPointerUp );
	document.addEventListener( 'pointercancel', onPointerUp );
}

export function destroyResponsive() {
	document.removeEventListener( 'pointermove', onPointerMove );
	document.removeEventListener( 'pointerup', onPointerUp );
	document.removeEventListener( 'pointercancel', onPointerUp );

	_leftHandle?.remove();
	_rightHandle?.remove();
	_snapIndicator?.remove();
	_leftHandle = null;
	_rightHandle = null;
	_snapIndicator = null;

	clearCustomWidth();
	_dragging = false;
}

export function switchToViewport( key ) {
	const vp = viewportByKey( key );
	if ( ! vp || key === state.viewport ) {
		return;
	}

	state.viewport = key;
	setCanvasDeviceType( vp.key );
	if ( key === 'Desktop' ) {
		applyWidth( state.customWidth || vp.previewWidth );
	} else {
		clearCustomWidth();
	}
	updatePills();
	setTimeout( () => refitCanvas( true ), key === 'Desktop' ? 50 : 250 );
}

export function applyWidthFromInput( px ) {
	const w = clampWidth( parseInt( px, 10 ) || 1440 );
	applyWidth( w );

	const matchedVp = VIEWPORTS.find( ( vp ) => vp.previewWidth === w );
	if ( matchedVp ) {
		state.viewport = matchedVp.key;
		updatePills();
	}

	return w;
}

export { clearCustomWidth };
