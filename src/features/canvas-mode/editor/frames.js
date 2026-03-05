import {
	VIEWPORTS,
	state, refs,
	viewportByKey, getEditorVisual, getEditorStylesWrapper,
	getContentSelector, getEditorIframe,
} from './state';
import { pauseMirrorVideos } from './editor-iframe';

const MIRROR_PREVIEW_CSS =
	'#wpadminbar{display:none!important}' +
	'html{margin-top:0!important;padding-top:0!important}' +
	'body{pointer-events:none!important;cursor:default!important;caret-color:transparent!important}' +
	'*::selection{background:transparent!important}';

const MIN_MIRROR_HEIGHT = 400;

function injectContentListener( doc ) {
	if ( ! doc?.body ) {
		return;
	}
	const primary = getContentSelector();
	const fallbacks = [
		'.entry-content', '.post-content', '.content-inner',
		'article .entry', '.type-page .content', 'main article',
	];
	const selectors = [ primary ];
	fallbacks.forEach( function( s ) {
		if ( selectors.indexOf( s ) === -1 ) {
			selectors.push( s );
		}
	} );

	const script = doc.createElement( 'script' );
	script.textContent =
		'(function(){' +
		'var sels=' + JSON.stringify( selectors ) + ';' +
		'function findTarget(){' +
		'for(var i=0;i<sels.length;i++){' +
		'var el=document.querySelector(sels[i]);' +
		'if(el)return el;}return null;}' +
		'window.addEventListener("message",function(e){' +
		'if(!e.data||e.data.type!=="wpi-content")return;' +
		'var t=findTarget();if(t)t.innerHTML=e.data.html;});' +
		'})();';
	doc.body.appendChild( script );
}

function findContentArea( doc ) {
	const primary = getContentSelector();
	const selectors = [ primary, '.entry-content', '.post-content',
		'.content-inner', 'article .entry', '.type-page .content',
		'main article' ];
	for ( let i = 0; i < selectors.length; i++ ) {
		const el = doc.querySelector( selectors[ i ] );
		if ( el ) {
			return el;
		}
	}
	return null;
}

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
	refs.backdropEl = null;
	refs.topSpacerEl = null;
	refs.bottomSpacerEl = null;
}

function setupBackdrop( viewport, vp ) {
	const previewUrl = getPreviewUrl();
	if ( ! previewUrl ) {
		return;
	}

	const backdrop = document.createElement( 'iframe' );
	backdrop.className = 'wpi-canvas-frame__backdrop';
	backdrop.setAttribute( 'title', 'Page chrome' );
	backdrop.setAttribute( 'tabindex', '-1' );
	backdrop.setAttribute( 'aria-hidden', 'true' );
	backdrop.style.width = vp.previewWidth + 'px';
	backdrop.style.height = vp.previewHeight + 'px';
	refs.backdropEl = backdrop;

	backdrop.addEventListener( 'load', () => {
		try {
			const doc = backdrop.contentDocument;
			if ( ! doc?.body ) {
				return;
			}

			const style = doc.createElement( 'style' );
			style.textContent = MIRROR_PREVIEW_CSS;
			doc.head.appendChild( style );

			injectContentListener( doc );

			const contentArea = findContentArea( doc );
			if ( ! contentArea ) {
				return;
			}

			const rect = contentArea.getBoundingClientRect();
			const headerH = rect.top;
			const pageH = Math.max(
				doc.documentElement.scrollHeight,
				doc.body.scrollHeight
			);
			const footerH = Math.max( 0, pageH - rect.bottom );

			contentArea.style.visibility = 'hidden';

			backdrop.style.height = pageH + 'px';

			if ( refs.topSpacerEl ) {
				refs.topSpacerEl.style.height = headerH + 'px';
			}
			if ( refs.bottomSpacerEl ) {
				refs.bottomSpacerEl.style.height = footerH + 'px';
			}

			syncBackdropContentHeight();
		} catch ( e ) {} // eslint-disable-line no-empty
	} );

	backdrop.addEventListener( 'error', () => {
		backdrop.remove();
		refs.backdropEl = null;
	} );

	backdrop.src = previewUrl;
	viewport.insertBefore( backdrop, viewport.firstChild );
}

function syncBackdropContentHeight() {
	if ( ! refs.backdropEl ) {
		return;
	}
	try {
		const doc = refs.backdropEl.contentDocument;
		if ( ! doc ) {
			return;
		}
		const contentArea = findContentArea( doc );
		if ( ! contentArea ) {
			return;
		}

		const editorIframe = getEditorIframe();
		const editorH = editorIframe
			? Math.max( 600, editorIframe.getBoundingClientRect().height )
			: 600;

		contentArea.style.minHeight = editorH + 'px';

		const pageH = Math.max(
			doc.documentElement.scrollHeight,
			doc.body.scrollHeight
		);
		refs.backdropEl.style.height = pageH + 'px';

		const rect = contentArea.getBoundingClientRect();
		if ( refs.bottomSpacerEl ) {
			refs.bottomSpacerEl.style.height =
				Math.max( 0, pageH - rect.bottom ) + 'px';
		}
	} catch ( e ) {} // eslint-disable-line no-empty
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
	viewport.style.position = 'relative';

	const topSpacer = document.createElement( 'div' );
	topSpacer.className = 'wpi-canvas-frame__spacer wpi-canvas-frame__spacer--top';
	refs.topSpacerEl = topSpacer;

	const bottomSpacer = document.createElement( 'div' );
	bottomSpacer.className = 'wpi-canvas-frame__spacer wpi-canvas-frame__spacer--bottom';
	refs.bottomSpacerEl = bottomSpacer;

	refs.editorVisualEl.parentNode?.removeChild( refs.editorVisualEl );

	viewport.appendChild( topSpacer );
	viewport.appendChild( refs.editorVisualEl );
	viewport.appendChild( bottomSpacer );
	frame.appendChild( viewport );

	setupBackdrop( viewport, vp );

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

				injectContentListener( mirrorDoc );
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

export { syncMirrorBodies, syncBackdropContentHeight, getPreviewUrl };
