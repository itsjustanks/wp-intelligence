import { VIEWPORTS, state, refs } from './state';
import { zoomStep, fitAllFrames, syncZoomLabel } from './canvas';

let _toolbarResizeObserver = null;
let _overlayEl = null;
let _onOverlayExit = null;

export function showLoadingOverlay( onExit ) {
	if ( _overlayEl ) {
		return;
	}
	_onOverlayExit = onExit;
	_overlayEl = document.createElement( 'div' );
	_overlayEl.id = 'wpi-canvas-loading';
	_overlayEl.innerHTML =
		'<div class="wpi-canvas-loading__inner">' +
		'<svg width="24" height="24" viewBox="0 0 24 24" class="wpi-canvas-loading__spinner">' +
		'<circle cx="12" cy="12" r="10" fill="none" stroke="rgba(255,255,255,0.25)" stroke-width="2"/>' +
		'<path d="M12 2a10 10 0 0 1 10 10" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/>' +
		'</svg>' +
		'<span>Preparing canvas\u2026</span>' +
		'</div>' +
		'<button type="button" class="wpi-canvas-loading__exit">Exit Canvas Mode</button>';
	_overlayEl.querySelector( '.wpi-canvas-loading__exit' ).addEventListener( 'click', () => {
		_onOverlayExit?.();
	} );
	document.body.appendChild( _overlayEl );
}

export function hideLoadingOverlay() {
	if ( ! _overlayEl ) {
		return;
	}
	_overlayEl.classList.add( 'wpi-canvas-loading--hiding' );
	const el = _overlayEl;
	_overlayEl = null;
	_onOverlayExit = null;
	setTimeout( () => el.remove(), 300 );
}

export function showToolbar() {
	if ( refs.stripEl ) {
		refs.stripEl.style.display = '';
	}
}

export function hideToolbar() {
	if ( refs.stripEl ) {
		refs.stripEl.style.display = 'none';
	}
}

function positionToolbar() {
	if ( ! refs.stripEl || ! refs.contentEl ) {
		return;
	}
	const rect = refs.contentEl.getBoundingClientRect();
	const toolbarW = refs.stripEl.offsetWidth;
	refs.stripEl.style.left = ( rect.left + rect.width / 2 - toolbarW / 2 ) + 'px';
	refs.stripEl.style.bottom = '20px';
}

export function injectToggle( onToggle ) {
	if ( refs.toggleBtnEl ) {
		return;
	}
	refs.toggleBtnEl = document.createElement( 'button' );
	refs.toggleBtnEl.id = 'wpi-canvas-mode-toggle';
	refs.toggleBtnEl.className = 'wpi-canvas-toggle components-button has-icon';
	refs.toggleBtnEl.setAttribute( 'aria-label', 'Canvas Mode (Ctrl+Shift+M)' );
	refs.toggleBtnEl.setAttribute( 'aria-pressed', 'false' );
	refs.toggleBtnEl.setAttribute( 'type', 'button' );
	refs.toggleBtnEl.title = 'Canvas Mode';
	refs.toggleBtnEl.innerHTML =
		'<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' +
		'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>' +
		'<rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>';
	refs.toggleBtnEl.addEventListener( 'click', ( e ) => {
		e.preventDefault();
		onToggle();
	} );

	const target =
		document.querySelector( '.editor-header__settings' ) ||
		document.querySelector( '.edit-post-header__settings' );
	if ( target ) {
		target.insertBefore( refs.toggleBtnEl, target.firstChild );
	}
}

export function updateToggle() {
	if ( ! refs.toggleBtnEl ) {
		return;
	}
	refs.toggleBtnEl.classList.toggle( 'is-active', state.active );
	refs.toggleBtnEl.setAttribute(
		'aria-pressed',
		state.active ? 'true' : 'false'
	);
}

export function injectStrip( onSwitchViewport, onDeactivate ) {
	if ( refs.stripEl ) {
		return;
	}
	if ( ! refs.contentEl ) {
		return;
	}

	refs.stripEl = document.createElement( 'div' );
	refs.stripEl.id = 'wpi-canvas-toolbar';

	const pills = document.createElement( 'div' );
	pills.className = 'wpi-canvas-toolbar__group';
	VIEWPORTS.forEach( ( vp ) => {
		const btn = document.createElement( 'button' );
		btn.className =
			'wpi-canvas-toolbar__pill' +
			( vp.key === state.viewport ? ' is-active' : '' );
		btn.setAttribute( 'data-vp', vp.key );
		btn.setAttribute( 'type', 'button' );
		btn.setAttribute(
			'data-tooltip',
			vp.label + ' \u2014 ' + vp.previewWidth + 'px'
		);
		btn.textContent = vp.label;
		btn.addEventListener( 'click', () => onSwitchViewport( vp.key ) );
		pills.appendChild( btn );
	} );
	refs.stripEl.appendChild( pills );

	const divider1 = document.createElement( 'div' );
	divider1.className = 'wpi-canvas-toolbar__divider';
	refs.stripEl.appendChild( divider1 );

	const zoom = document.createElement( 'div' );
	zoom.className = 'wpi-canvas-toolbar__group';

	const zoomOutBtn = mkBtn( '\u2212', () => zoomStep( -0.1 ) );
	zoomOutBtn.setAttribute( 'data-tooltip', 'Zoom out' );
	zoomOutBtn.setAttribute( 'aria-label', 'Zoom out' );
	zoom.appendChild( zoomOutBtn );

	refs.zoomLabelEl = document.createElement( 'span' );
	refs.zoomLabelEl.className =
		'wpi-canvas-toolbar__zoom-val wpi-canvas-toolbar__btn';
	refs.zoomLabelEl.textContent = '50%';
	refs.zoomLabelEl.setAttribute( 'data-tooltip', 'Reset zoom' );
	refs.zoomLabelEl.addEventListener( 'click', () => fitAllFrames( true ) );
	zoom.appendChild( refs.zoomLabelEl );

	const zoomInBtn = mkBtn( '+', () => zoomStep( 0.1 ) );
	zoomInBtn.setAttribute( 'data-tooltip', 'Zoom in' );
	zoomInBtn.setAttribute( 'aria-label', 'Zoom in' );
	zoom.appendChild( zoomInBtn );

	refs.stripEl.appendChild( zoom );

	const divider2 = document.createElement( 'div' );
	divider2.className = 'wpi-canvas-toolbar__divider';
	refs.stripEl.appendChild( divider2 );

	const actions = document.createElement( 'div' );
	actions.className = 'wpi-canvas-toolbar__group';

	const fitBtn = mkBtn( '', () => fitAllFrames( true ) );
	fitBtn.innerHTML =
		'<svg class="wpi-canvas-toolbar__icon" viewBox="0 0 24 24">' +
		'<path d="M4 4h6v2H6v4H4V4zm16 0h-6v2h4v4h2V4z' +
		'M4 20h6v-2H6v-4H4v6zm16 0h-6v-2h4v-4h2v6z"/></svg>';
	fitBtn.setAttribute( 'aria-label', 'Fit all viewports' );
	fitBtn.setAttribute( 'data-tooltip', 'Fit all (\u2318+0)' );
	actions.appendChild( fitBtn );

	const exitBtn = mkBtn( '', onDeactivate );
	exitBtn.innerHTML =
		'<svg class="wpi-canvas-toolbar__icon" viewBox="0 0 24 24">' +
		'<path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 ' +
		'17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>';
	exitBtn.classList.add( 'wpi-canvas-toolbar__exit' );
	exitBtn.setAttribute( 'aria-label', 'Exit canvas mode' );
	exitBtn.setAttribute( 'data-tooltip', 'Exit (Esc)' );
	actions.appendChild( exitBtn );

	refs.stripEl.appendChild( actions );
	document.body.appendChild( refs.stripEl );
	positionToolbar();

	_toolbarResizeObserver = new ResizeObserver( positionToolbar );
	_toolbarResizeObserver.observe( refs.contentEl );
	window.addEventListener( 'resize', positionToolbar );

	updatePills();
}

export function removeStrip() {
	if ( _toolbarResizeObserver ) {
		_toolbarResizeObserver.disconnect();
		_toolbarResizeObserver = null;
	}
	window.removeEventListener( 'resize', positionToolbar );
	if ( refs.stripEl ) {
		refs.stripEl.remove();
		refs.stripEl = null;
		refs.zoomLabelEl = null;
	}
}

export function updatePills() {
	if ( ! refs.stripEl ) {
		return;
	}
	refs.stripEl
		.querySelectorAll( '.wpi-canvas-toolbar__pill' )
		.forEach( ( node ) => {
			node.classList.toggle(
				'is-active',
				node.getAttribute( 'data-vp' ) === state.viewport
			);
		} );
	syncZoomLabel();
}

export function injectSizeBadge() {
	// Size badge removed in favor of unified toolbar.
}

export function removeSizeBadge() {
	// Size badge removed.
}

function mkBtn( text, handler ) {
	const btn = document.createElement( 'button' );
	btn.className = 'wpi-canvas-toolbar__btn';
	btn.setAttribute( 'type', 'button' );
	btn.textContent = text;
	btn.addEventListener( 'click', handler );
	return btn;
}
