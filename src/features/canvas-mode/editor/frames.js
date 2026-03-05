import {
	VIEWPORTS,
	state, refs,
	viewportByKey, getEditorVisual, getEditorStylesWrapper,
} from './state';
import { pauseMirrorVideos } from './editor-iframe';

const MIRROR_PREVIEW_CSS =
	'#wpadminbar{display:none!important}' +
	'html{margin-top:0!important;padding-top:0!important}' +
	'body{pointer-events:none!important;cursor:default!important;caret-color:transparent!important}' +
	'*::selection{background:transparent!important}';

const MIN_MIRROR_HEIGHT = 400;

function getPreviewUrl() {
	try {
		const select = window.wp?.data?.select;
		if ( ! select ) {
			return null;
		}
		return select( 'core/editor' ).getEditedPostPreviewLink() || null;
	} catch ( e ) {
		return null;
	}
}

export function waitForEditorReady( onReady, onTimeout ) {
	clearInterval( refs.editorReadyTimer );
	let attempts = 0;
	const maxAttempts = 80;
	refs.editorReadyTimer = setInterval( () => {
		attempts++;
		if ( getEditorStylesWrapper() ) {
			clearInterval( refs.editorReadyTimer );
			refs.editorReadyTimer = null;
			onReady();
			return;
		}
		if ( attempts >= maxAttempts ) {
			clearInterval( refs.editorReadyTimer );
			refs.editorReadyTimer = null;
			onTimeout?.();
		}
	}, 50 );
}

function autoResizeMirror( item ) {
	try {
		const doc = item.iframeEl.contentDocument;
		if ( ! doc || ! doc.documentElement ) {
			return;
		}
		const h = Math.max(
			MIN_MIRROR_HEIGHT,
			doc.documentElement.scrollHeight,
			doc.body?.scrollHeight || 0
		);
		item.iframeEl.style.height = h + 'px';
		const vp = item.iframeEl.closest( '.wpi-canvas-frame__viewport' );
		if ( vp ) {
			vp.style.height = h + 'px';
		}
	} catch ( e ) {} // eslint-disable-line no-empty
}

export function resizeMirrorFrames() {
	refs.mirrorFrames.forEach( autoResizeMirror );
}

function syncMirrorBodies() {
	const url = getPreviewUrl();
	if ( ! url ) {
		return;
	}
	refs.mirrorFrames.forEach( ( item ) => {
		if ( item.iframeEl ) {
			item.iframeEl.src = url;
		}
	} );
}

export function stopMirrorObserver() {
	if ( refs.mirrorObserver ) {
		refs.mirrorObserver.disconnect();
		refs.mirrorObserver = null;
	}
	clearTimeout( refs.mirrorSyncTimer );
}

export function startMirrorObserver() {
	// Mirrors use frontend preview URLs; refreshed on post save.
}

const PLAY_SVG =
	'<svg width="10" height="10" viewBox="0 0 24 24"><polygon points="6,4 20,12 6,20" fill="currentColor"/></svg>';
const PAUSE_SVG =
	'<svg width="10" height="10" viewBox="0 0 24 24">' +
	'<rect x="5" y="4" width="4" height="16" fill="currentColor"/>' +
	'<rect x="15" y="4" width="4" height="16" fill="currentColor"/></svg>';

function createFrameLabel( vp, options ) {
	const label = document.createElement( 'div' );
	label.className = 'wpi-canvas-frame__label';
	label.innerHTML =
		'<span class="wpi-canvas-frame__name">' +
		vp.label +
		'</span>' +
		'<span class="wpi-canvas-frame__dims">' +
		vp.previewWidth +
		'px</span>';

	if ( options?.showPlay ) {
		const btn = document.createElement( 'button' );
		btn.className = 'wpi-canvas-frame__play-btn' +
			( state.playing ? ' is-playing' : '' );
		btn.type = 'button';
		btn.setAttribute( 'aria-label',
			state.playing ? 'Pause preview' : 'Play preview' );
		btn.setAttribute( 'data-tooltip',
			state.playing ? 'Pause (P)' : 'Preview (P)' );
		btn.innerHTML = state.playing ? PAUSE_SVG : PLAY_SVG;
		btn.addEventListener( 'click', ( e ) => {
			e.stopPropagation();
			options.onTogglePlay?.();
		} );
		refs.playBtnEl = btn;
		label.appendChild( btn );
	}

	return label;
}

export function updatePlayButton() {
	if ( ! refs.playBtnEl ) {
		return;
	}
	refs.playBtnEl.innerHTML = state.playing ? PAUSE_SVG : PLAY_SVG;
	refs.playBtnEl.classList.toggle( 'is-playing', state.playing );
	refs.playBtnEl.setAttribute( 'aria-label',
		state.playing ? 'Pause preview' : 'Play preview' );
	refs.playBtnEl.setAttribute( 'data-tooltip',
		state.playing ? 'Pause (P)' : 'Preview (P)' );
}

function ensureCanvasRow() {
	if ( refs.canvasRowEl ) {
		return;
	}
	if ( ! refs.editorVisualEl ) {
		refs.editorVisualEl = getEditorVisual();
	}
	if ( ! refs.editorVisualEl ) {
		return;
	}
	if ( ! refs.originalParentEl ) {
		refs.originalParentEl = refs.editorVisualEl.parentNode;
		refs.originalNextSiblingEl = refs.editorVisualEl.nextSibling;
	}
	refs.canvasRowEl = document.createElement( 'div' );
	refs.canvasRowEl.id = 'wpi-canvas-row';
	refs.originalParentEl.insertBefore(
		refs.canvasRowEl,
		refs.editorVisualEl
	);
}

function restoreEditorVisual() {
	if ( ! refs.editorVisualEl || ! refs.originalParentEl ) {
		return;
	}
	if (
		refs.originalNextSiblingEl?.parentNode === refs.originalParentEl
	) {
		refs.originalParentEl.insertBefore(
			refs.editorVisualEl,
			refs.originalNextSiblingEl
		);
	} else {
		refs.originalParentEl.appendChild( refs.editorVisualEl );
	}
}

export function removeCanvasRow() {
	restoreEditorVisual();
	if ( refs.canvasRowEl ) {
		refs.canvasRowEl.remove();
		refs.canvasRowEl = null;
	}
	refs.mirrorFrames = [];
}

function createLiveFrame( vp, options ) {
	const frame = document.createElement( 'section' );
	frame.className = 'wpi-canvas-frame wpi-canvas-frame--live is-active';
	frame.style.setProperty( '--wpi-frame-width', vp.previewWidth + 'px' );
	frame.setAttribute( 'data-vp', vp.key );

	const labelEl = createFrameLabel( vp, {
		showPlay: true,
		onTogglePlay: options?.onTogglePlay,
	} );
	labelEl.addEventListener( 'click', ( e ) => {
		if ( ! e.target.closest( '.wpi-canvas-frame__play-btn' ) ) {
			options?.onSwitchViewport?.( vp.key );
		}
	} );
	frame.appendChild( labelEl );

	const viewport = document.createElement( 'div' );
	viewport.className = 'wpi-canvas-frame__viewport';
	viewport.style.width = vp.previewWidth + 'px';
	viewport.style.minHeight = vp.previewHeight + 'px';

	refs.editorVisualEl.parentNode?.removeChild( refs.editorVisualEl );
	viewport.appendChild( refs.editorVisualEl );
	frame.appendChild( viewport );
	return frame;
}

function createMirrorFrame( vp, previewUrl, onSwitch, onLoaded ) {
	const frame = document.createElement( 'button' );
	frame.className = 'wpi-canvas-frame wpi-canvas-frame--mirror';
	frame.style.setProperty( '--wpi-frame-width', vp.previewWidth + 'px' );
	frame.setAttribute( 'data-vp', vp.key );
	frame.setAttribute( 'type', 'button' );
	frame.setAttribute(
		'aria-label',
		'View ' + vp.label + ' viewport'
	);
	frame.appendChild( createFrameLabel( vp ) );

	const viewport = document.createElement( 'div' );
	viewport.className = 'wpi-canvas-frame__viewport';
	viewport.style.width = vp.previewWidth + 'px';

	const iframe = document.createElement( 'iframe' );
	iframe.className = 'wpi-canvas-frame__mirror';
	iframe.setAttribute( 'title', vp.label + ' preview' );
	iframe.style.width = vp.previewWidth + 'px';
	iframe.style.height = '8000px';
	if ( previewUrl ) {
		iframe.src = previewUrl;
	}

	const mirrorItem = { key: vp.key, iframeEl: iframe };
	refs.mirrorFrames.push( mirrorItem );

	iframe.addEventListener( 'load', () => {
		try {
			const mirrorDoc = iframe.contentDocument;
			if ( mirrorDoc?.head ) {
				const style = mirrorDoc.createElement( 'style' );
				style.setAttribute( 'data-wpi-canvas-mirror', '' );
				style.textContent = MIRROR_PREVIEW_CSS;
				mirrorDoc.head.appendChild( style );
			}
		} catch ( e ) {} // eslint-disable-line no-empty
		setTimeout( () => autoResizeMirror( mirrorItem ), 300 );
		onLoaded?.();
	} );

	viewport.appendChild( iframe );
	frame.appendChild( viewport );
	frame.addEventListener( 'click', () => onSwitch?.( vp.key ) );

	return frame;
}

export function rebuildCanvasFrames( onSwitchViewport, options = {} ) {
	ensureCanvasRow();
	if ( ! refs.canvasRowEl || ! refs.editorVisualEl ) {
		return Promise.resolve();
	}

	const oldChildren = Array.from( refs.canvasRowEl.children );
	refs.mirrorFrames = [];
	refs.playBtnEl = null;

	const activeVp = viewportByKey( state.viewport );

	oldChildren.forEach( ( child ) => child.remove() );
	refs.canvasRowEl.appendChild( createLiveFrame( activeVp, {
		...options,
		onSwitchViewport,
	} ) );

	return new Promise( ( resolve ) => {
		resolve();

		setTimeout( () => {
			if ( ! state.active || ! refs.canvasRowEl ) {
				return;
			}
			const previewUrl = getPreviewUrl();
			const otherVps = VIEWPORTS.filter( ( vp ) => vp.key !== activeVp.key );
			let loaded = 0;
			const total = otherVps.length;

			otherVps.forEach( ( vp ) => {
				const frame = createMirrorFrame( vp, previewUrl, onSwitchViewport, () => {
					loaded++;
					if ( loaded >= total ) {
						setTimeout( () => {
							resizeMirrorFrames();
							pauseMirrorVideos();
						}, 200 );
					}
				} );
				refs.canvasRowEl.appendChild( frame );
			} );
		}, 60 );
	} );
}

export { syncMirrorBodies };
