import {
	VIEWPORTS, FRAME_GAP, ROW_PADDING_X,
	state, refs,
} from './state';

const MIN_SCALE = 0.15;
const MAX_SCALE = 2;
const MIRROR_HEIGHT_CAP = 5000;

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
	if ( ! refs.canvasRowEl ) {
		return;
	}
	refs.canvasRowEl.style.transform =
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
	if ( ! refs.canvasRowEl ) {
		return;
	}
	clearTimeout( smoothTimer );
	refs.canvasRowEl.classList.add( 'wpi-canvas-animating' );
}

function endSmooth() {
	smoothTimer = setTimeout( () => {
		refs.canvasRowEl?.classList.remove( 'wpi-canvas-animating' );
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
	if ( ! refs.canvasRowEl || ! refs.contentEl ) {
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

/* ── Fit all viewports ─────────────────────── */

function measureFrames() {
	let totalWidth = ROW_PADDING_X * 2;
	let maxHeight = 0;
	const frameEls = refs.canvasRowEl?.querySelectorAll(
		'.wpi-canvas-frame'
	);

	if ( frameEls && frameEls.length ) {
		frameEls.forEach( ( el, i ) => {
			totalWidth += el.offsetWidth;
			if ( i > 0 ) {
				totalWidth += FRAME_GAP;
			}
			let h = el.offsetHeight;
			if ( h > MIRROR_HEIGHT_CAP ) {
				const vpKey = el.getAttribute( 'data-vp' );
				const vpDef = VIEWPORTS.find( ( v ) => v.key === vpKey );
				h = vpDef ? vpDef.previewHeight + 60 : MIRROR_HEIGHT_CAP;
			}
			maxHeight = Math.max( maxHeight, h );
		} );
		maxHeight += 60;
	} else {
		let fw = 0;
		VIEWPORTS.forEach( ( vp ) => {
			fw += vp.previewWidth;
		} );
		totalWidth += fw + FRAME_GAP * ( VIEWPORTS.length - 1 );
		maxHeight =
			Math.max(
				...VIEWPORTS.map( ( vp ) => vp.previewHeight )
			) + 48 + 96 + 60;
	}

	return { totalWidth, maxHeight };
}

export function fitAllFrames( smooth = false ) {
	if ( ! refs.contentEl ) {
		return;
	}

	const { totalWidth, maxHeight } = measureFrames();
	const availW = Math.max( 360, refs.contentEl.clientWidth );
	const availH = Math.max( 300, refs.contentEl.clientHeight );
	const fitScale = clamp(
		Math.min( availW / totalWidth, availH / maxHeight ),
		MIN_SCALE,
		MAX_SCALE
	);

	const scaledW = totalWidth * fitScale;
	const scaledH = maxHeight * fitScale;

	cam.s = fitScale;
	cam.x = Math.max( 0, ( availW - scaledW ) / 2 );
	cam.y = Math.max( 0, ( availH - scaledH ) / 2 );
	applyCamera( smooth );
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

/* ── Scroll live frame into view ───────────── */

export function scrollActiveFrameIntoView( center ) {
	if ( ! refs.canvasRowEl || ! refs.contentEl ) {
		return;
	}
	const liveFrame = refs.canvasRowEl.querySelector(
		'.wpi-canvas-frame--live'
	);
	if ( ! liveFrame || ! center ) {
		return;
	}

	const availW = refs.contentEl.clientWidth;
	const availH = refs.contentEl.clientHeight;

	cam.x =
		availW / 2 -
		( liveFrame.offsetLeft + liveFrame.offsetWidth / 2 ) * cam.s;
	cam.y = Math.max(
		0,
		( availH - liveFrame.offsetHeight * cam.s ) / 2
	);
	applyCamera( true );
}

/* ── Focus a single viewport frame ─────────── */

export function focusViewportFrame( key, smooth = true ) {
	if ( ! refs.canvasRowEl || ! refs.contentEl ) {
		return;
	}

	const targetFrame = refs.canvasRowEl.querySelector(
		`[data-vp="${ key }"]`
	);
	if ( ! targetFrame ) {
		return;
	}

	let targetVp = VIEWPORTS[ 0 ];
	for ( let i = 0; i < VIEWPORTS.length; i++ ) {
		if ( VIEWPORTS[ i ].key === key ) {
			targetVp = VIEWPORTS[ i ];
			break;
		}
	}

	const availW = Math.max( 360, refs.contentEl.clientWidth );
	const availH = Math.max( 300, refs.contentEl.clientHeight );
	const insetX = 72;
	const insetY = 88;
	const frameWidth = targetVp.previewWidth;
	const frameHeight = targetVp.previewHeight + 48;

	const usableW = Math.max( 240, availW - insetX * 2 );
	const usableH = Math.max( 220, availH - insetY * 2 );
	const focusScale = clamp(
		Math.min( usableW / frameWidth, usableH / frameHeight ),
		MIN_SCALE,
		MAX_SCALE
	);

	const centerX = targetFrame.offsetLeft + targetFrame.offsetWidth / 2;
	const centerY = targetFrame.offsetTop + frameHeight / 2;

	cam.s = focusScale;
	cam.x = availW / 2 - centerX * focusScale;
	cam.y = availH / 2 - centerY * focusScale;
	applyCamera( smooth );
}

/* ── Reset ─────────────────────────────────── */

export function resetCanvas() {
	cancelAnimationFrame( rafId );
	rafId = 0;
	clearTimeout( smoothTimer );
	cam.x = 0;
	cam.y = 0;
	cam.s = MIN_SCALE;
	if ( refs.canvasRowEl ) {
		refs.canvasRowEl.style.transform = '';
	}
}

export { MIN_SCALE, MAX_SCALE };
