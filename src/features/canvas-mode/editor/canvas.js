import {
	CANVAS_PADDING,
	state, refs,
} from './state';

const MIN_SCALE = 0.15;
const MAX_SCALE = 2;
const DEFAULT_SCALE = 0.9;

const cam = { x: 0, y: 0, s: MIN_SCALE };

let rafId = 0;
let panActive = false;
let panStartX = 0;
let panStartY = 0;
let panCamX0 = 0;
let panCamY0 = 0;
let smoothTimer = null;

function clamp( v, lo, hi ) {
	return v < lo ? lo : v > hi ? hi : v;
}

function flush() {
	rafId = 0;
	if ( ! refs.editorVisualEl ) {
		return;
	}
	refs.editorVisualEl.style.transform =
		'matrix(' + cam.s + ',0,0,' + cam.s + ',' + cam.x + ',' + cam.y + ')';
	syncZoomLabel();
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

function applyCamera( smooth ) {
	if ( smooth ) {
		beginSmooth();
	}
	renderNow();
	if ( smooth ) {
		endSmooth();
	}
}

/* ── Public lifecycle ──────────────────────── */

export function initPanzoom() {
	if ( ! refs.editorVisualEl || ! refs.contentEl ) {
		return;
	}

	cam.s = MIN_SCALE;
	cam.x = 0;
	cam.y = 0;
	renderNow();
	fitAllFrames( false );

	refs.contentEl.addEventListener( 'pointerdown', onPointerDown );
	refs.contentEl.addEventListener( 'pointermove', onPointerMove );
	refs.contentEl.addEventListener( 'pointerup', onPointerUp );
	refs.contentEl.addEventListener( 'pointercancel', onPointerUp );
	refs.contentEl.addEventListener( 'wheel', onWheel, { passive: false } );
}

export function destroyPanzoom() {
	cancelAnimationFrame( rafId );
	rafId = 0;
	clearTimeout( smoothTimer );
	if ( refs.contentEl ) {
		refs.contentEl.removeEventListener( 'pointerdown', onPointerDown );
		refs.contentEl.removeEventListener( 'pointermove', onPointerMove );
		refs.contentEl.removeEventListener( 'pointerup', onPointerUp );
		refs.contentEl.removeEventListener( 'pointercancel', onPointerUp );
		refs.contentEl.removeEventListener( 'wheel', onWheel );
	}
}

/* ── Pointer pan ───────────────────────────── */

function shouldPan( e ) {
	if ( e.button === 1 ) {
		return true;
	}
	if ( e.button === 0 && ( state.spaceHeld || e.altKey ) ) {
		return true;
	}
	return false;
}

function onPointerDown( e ) {
	if ( ! shouldPan( e ) ) {
		return;
	}
	panActive = true;
	panStartX = e.clientX;
	panStartY = e.clientY;
	panCamX0 = cam.x;
	panCamY0 = cam.y;
	refs.contentEl.classList.add( 'wpi-canvas-is-panning' );
	try {
		refs.contentEl.setPointerCapture( e.pointerId );
	} catch ( _ex ) {} // eslint-disable-line no-empty
	e.preventDefault();
}

function onPointerMove( e ) {
	if ( ! panActive ) {
		return;
	}
	cam.x = panCamX0 + ( e.clientX - panStartX );
	cam.y = panCamY0 + ( e.clientY - panStartY );
	render();
	e.preventDefault();
}

function onPointerUp( e ) {
	if ( ! panActive ) {
		return;
	}
	panActive = false;
	refs.contentEl.classList.remove( 'wpi-canvas-is-panning' );
	try {
		refs.contentEl.releasePointerCapture( e.pointerId );
	} catch ( _ex ) {} // eslint-disable-line no-empty
}

/* ── Wheel: trackpad pan + pinch/ctrl zoom ── */

function onWheel( e ) {
	if ( ! refs.contentEl ) {
		return;
	}
	e.preventDefault();

	if ( e.ctrlKey || e.metaKey ) {
		const rect = refs.contentEl.getBoundingClientRect();
		const cx = e.clientX - rect.left;
		const cy = e.clientY - rect.top;

		const oldS = cam.s;
		const newS = clamp(
			cam.s * Math.pow( 0.995, e.deltaY ),
			MIN_SCALE,
			MAX_SCALE
		);

		cam.x = cx - ( cx - cam.x ) * ( newS / oldS );
		cam.y = cy - ( cy - cam.y ) * ( newS / oldS );
		cam.s = newS;
	} else {
		cam.x -= e.deltaX;
		cam.y -= e.deltaY;
	}
	render();
}

/* ── Zoom label ────────────────────────────── */

export function syncZoomLabel() {
	if ( refs.zoomLabelEl ) {
		refs.zoomLabelEl.textContent = Math.round( cam.s * 100 ) + '%';
	}
}

/* ── Fit editor in viewport ────────────────── */

export function fitAllFrames( smooth = false ) {
	if ( ! refs.contentEl || ! refs.editorVisualEl ) {
		return;
	}

	const availW = Math.max( 360, refs.contentEl.clientWidth );
	const editorW = ( refs.editorVisualEl.offsetWidth || 1440 ) + CANVAS_PADDING * 2;
	const maxFit = clamp( availW / editorW, MIN_SCALE, MAX_SCALE );
	const targetScale = clamp( Math.min( DEFAULT_SCALE, maxFit ), MIN_SCALE, MAX_SCALE );

	const scaledW = editorW * targetScale;

	cam.s = targetScale;
	cam.x = Math.max( 0, ( availW - scaledW ) / 2 );
	cam.y = 24;
	applyCamera( smooth );
}

/* ── Recenter horizontally (keeps scale + Y) ── */

export function recenterX( editorWidth ) {
	if ( ! refs.contentEl ) {
		return;
	}
	const availW = Math.max( 360, refs.contentEl.clientWidth );
	const scaledW = ( editorWidth + CANVAS_PADDING * 2 ) * cam.s;
	cam.x = Math.max( 0, ( availW - scaledW ) / 2 );
	renderNow();
}

/* ── Zoom step (toolbar +/−) ───────────────── */

export function zoomStep( delta ) {
	if ( ! refs.contentEl ) {
		return;
	}
	const cx = refs.contentEl.clientWidth / 2;
	const cy = refs.contentEl.clientHeight / 2;
	const oldS = cam.s;
	const newS = clamp( cam.s + delta, MIN_SCALE, MAX_SCALE );

	cam.x = cx - ( cx - cam.x ) * ( newS / oldS );
	cam.y = cy - ( cy - cam.y ) * ( newS / oldS );
	cam.s = newS;
	applyCamera( true );
}

/* ── Reset ─────────────────────────────────── */

export function resetCanvas() {
	cancelAnimationFrame( rafId );
	rafId = 0;
	clearTimeout( smoothTimer );
	cam.x = 0;
	cam.y = 0;
	cam.s = MIN_SCALE;
	if ( refs.editorVisualEl ) {
		refs.editorVisualEl.style.transform = '';
		refs.editorVisualEl.classList.remove( 'wpi-canvas-animating' );
	}
}

export { MIN_SCALE, MAX_SCALE };
