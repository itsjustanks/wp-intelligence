import {
	CANVAS_PADDING,
	state, refs,
} from './state';
import { syncFrameHeader } from './frame-header';

const MIN_SCALE = 0.15;
const MAX_SCALE = 2;
const DEFAULT_SCALE = 0.9;

let scale = DEFAULT_SCALE;
let rafId = 0;
let smoothTimer = null;
let panActive = false;
let panStartX = 0;
let panStartY = 0;
let panScrollX0 = 0;
let panScrollY0 = 0;

function clamp( v, lo, hi ) {
	return v < lo ? lo : v > hi ? hi : v;
}

function flush() {
	rafId = 0;
	if ( ! refs.editorVisualEl ) {
		return;
	}
	refs.editorVisualEl.style.transform = 'scale(' + scale + ')';
	syncZoomLabel();
	syncFrameHeader( scale );
}

function render() {
	if ( ! rafId ) {
		rafId = requestAnimationFrame( flush );
	}
}

function renderNow() {
	cancelAnimationFrame( rafId );
	rafId = 0;
	flush();
}

function beginSmooth() {
	if ( ! refs.editorVisualEl ) {
		return;
	}
	clearTimeout( smoothTimer );
	refs.editorVisualEl.classList.add( 'wpi-canvas-animating' );
}

function endSmooth() {
	smoothTimer = setTimeout( () => {
		refs.editorVisualEl?.classList.remove( 'wpi-canvas-animating' );
	}, 400 );
}

function applyScale( smooth ) {
	if ( smooth ) {
		beginSmooth();
	}
	renderNow();
	if ( smooth ) {
		endSmooth();
	}
}

function computeFitScale() {
	if ( ! refs.contentEl || ! refs.editorVisualEl ) {
		return DEFAULT_SCALE;
	}
	const availW = Math.max( 360, refs.contentEl.clientWidth );
	const editorW = ( state.customWidth || refs.editorVisualEl.offsetWidth || 1440 ) + CANVAS_PADDING * 2;
	const maxFit = clamp( availW / editorW, MIN_SCALE, MAX_SCALE );
	return clamp( Math.min( DEFAULT_SCALE, maxFit ), MIN_SCALE, MAX_SCALE );
}

/* ── Public lifecycle ──────────────────────── */

export function initZoom() {
	if ( ! refs.editorVisualEl || ! refs.contentEl ) {
		return;
	}

	scale = computeFitScale();
	renderNow();

	refs.contentEl.addEventListener( 'wheel', onWheel, { passive: false } );
	refs.contentEl.addEventListener( 'pointerdown', onPanDown );
	refs.contentEl.addEventListener( 'pointermove', onPanMove );
	refs.contentEl.addEventListener( 'pointerup', onPanUp );
	refs.contentEl.addEventListener( 'pointercancel', onPanUp );
}

export function destroyZoom() {
	cancelAnimationFrame( rafId );
	rafId = 0;
	clearTimeout( smoothTimer );
	panActive = false;
	if ( refs.contentEl ) {
		refs.contentEl.removeEventListener( 'wheel', onWheel );
		refs.contentEl.removeEventListener( 'pointerdown', onPanDown );
		refs.contentEl.removeEventListener( 'pointermove', onPanMove );
		refs.contentEl.removeEventListener( 'pointerup', onPanUp );
		refs.contentEl.removeEventListener( 'pointercancel', onPanUp );
		refs.contentEl.classList.remove( 'wpi-canvas-is-panning', 'wpi-canvas-space-pan' );
	}
}

/* ── Wheel: Cmd/Ctrl zoom only ─────────────── */

function onWheel( e ) {
	if ( ! refs.contentEl || ! state.active ) {
		return;
	}

	if ( ! ( e.ctrlKey || e.metaKey ) ) {
		return;
	}

	e.preventDefault();

	scale = clamp(
		scale * Math.pow( 0.995, e.deltaY ),
		MIN_SCALE,
		MAX_SCALE
	);
	render();
}

/* ── Pointer pan (middle-click or Space+drag) ── */

function shouldPan( e ) {
	if ( e.button === 1 ) {
		return true;
	}
	if ( e.button === 0 && ( state.spaceHeld || e.altKey ) ) {
		return true;
	}
	return false;
}

function onPanDown( e ) {
	if ( ! shouldPan( e ) ) {
		return;
	}
	panActive = true;
	panStartX = e.clientX;
	panStartY = e.clientY;
	panScrollX0 = refs.contentEl.scrollLeft;
	panScrollY0 = refs.contentEl.scrollTop;
	refs.contentEl.classList.add( 'wpi-canvas-is-panning' );
	try {
		refs.contentEl.setPointerCapture( e.pointerId );
	} catch ( _ex ) {} // eslint-disable-line no-empty
	e.preventDefault();
}

function onPanMove( e ) {
	if ( ! panActive ) {
		return;
	}
	refs.contentEl.scrollLeft = panScrollX0 - ( e.clientX - panStartX );
	refs.contentEl.scrollTop = panScrollY0 - ( e.clientY - panStartY );
	e.preventDefault();
}

function onPanUp( e ) {
	if ( ! panActive ) {
		return;
	}
	panActive = false;
	refs.contentEl.classList.remove( 'wpi-canvas-is-panning' );
	try {
		refs.contentEl.releasePointerCapture( e.pointerId );
	} catch ( _ex ) {} // eslint-disable-line no-empty
}

/* ── Zoom label ────────────────────────────── */

export function syncZoomLabel() {
	if ( refs.zoomLabelEl ) {
		refs.zoomLabelEl.textContent = Math.round( scale * 100 ) + '%';
	}
}

/* ── Fit editor in viewport ────────────────── */

export function fitAllFrames( smooth = false ) {
	scale = computeFitScale();
	applyScale( smooth );
}

/* ── Refit: recalculate scale ──────────────── */

export function refitCanvas( smooth = true ) {
	scale = computeFitScale();
	applyScale( smooth );
}

export function recenterX() {
	// centering handled by CSS margin:auto
}

/* ── Zoom step (toolbar +/−) ───────────────── */

export function zoomStep( delta ) {
	scale = clamp( scale + delta, MIN_SCALE, MAX_SCALE );
	applyScale( true );
}

/* ── Reset ─────────────────────────────────── */

export function resetCanvas() {
	cancelAnimationFrame( rafId );
	rafId = 0;
	clearTimeout( smoothTimer );
	scale = DEFAULT_SCALE;
	if ( refs.editorVisualEl ) {
		refs.editorVisualEl.style.transform = '';
		refs.editorVisualEl.classList.remove( 'wpi-canvas-animating' );
	}
}

export function getCanvasScale() {
	return scale;
}

export { MIN_SCALE, MAX_SCALE };
