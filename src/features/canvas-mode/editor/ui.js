import { VIEWPORTS, state, refs, viewportByKey } from './state';
import { zoomStep, fitCanvas } from './canvas';
import { applyWidthFromInput } from './responsive';
import { A11Y_MODES, setAccessibilityMode, getAccessibilityMode } from './accessibility';
import { dispatchPreviewDevice } from './device-override';

let _contentResizeObserver = null;
let _contentResizeTimer = null;
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

export function injectStrip( onDeactivate ) {
	if ( refs.stripEl ) {
		return;
	}
	if ( ! refs.contentEl ) {
		return;
	}

	refs.stripEl = document.createElement( 'div' );
	refs.stripEl.id = 'wpi-canvas-toolbar';

	refs.widthDisplayEl = document.createElement( 'input' );
	refs.widthDisplayEl.className = 'wpi-canvas-toolbar__width';
	refs.widthDisplayEl.type = 'text';
	refs.widthDisplayEl.inputMode = 'numeric';
	refs.widthDisplayEl.setAttribute( 'aria-label', 'Viewport width' );
	refs.widthDisplayEl.setAttribute( 'data-tooltip', 'Type a width in px' );
	refs.widthDisplayEl.value = viewportByKey( state.viewport ).previewWidth + 'px';
	refs.widthDisplayEl.addEventListener( 'focus', () => {
		const raw = parseInt( refs.widthDisplayEl.value, 10 );
		if ( raw ) {
			refs.widthDisplayEl.value = raw;
			refs.widthDisplayEl.select();
		}
	} );
	refs.widthDisplayEl.addEventListener( 'blur', () => {
		const px = parseInt( refs.widthDisplayEl.value, 10 );
		if ( px && px >= 280 ) {
			const applied = applyWidthFromInput( px );
			refs.widthDisplayEl.value = applied + 'px';
		} else {
			syncWidthDisplay();
		}
	} );
	refs.widthDisplayEl.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Enter' ) {
			e.preventDefault();
			refs.widthDisplayEl.blur();
		}
		if ( e.key === 'Escape' ) {
			e.preventDefault();
			syncWidthDisplay();
			refs.widthDisplayEl.blur();
		}
		e.stopPropagation();
	} );
	refs.stripEl.appendChild( refs.widthDisplayEl );

	const divider0 = document.createElement( 'div' );
	divider0.className = 'wpi-canvas-toolbar__divider';
	refs.stripEl.appendChild( divider0 );

	const pills = document.createElement( 'div' );
	pills.className = 'wpi-canvas-toolbar__group';
	VIEWPORTS.forEach( ( vp ) => {
		const btn = document.createElement( 'button' );
		btn.className =
			'wpi-canvas-toolbar__pill' +
			( vp.key === state.viewport ? ' is-active' : '' );
		btn.setAttribute( 'data-vp', vp.key );
		btn.setAttribute( 'type', 'button' );
		btn.setAttribute( 'data-tooltip', vp.label );
		btn.textContent = vp.label;
		btn.addEventListener( 'click', () => {
			state.viewport = vp.key;
			dispatchPreviewDevice( vp.key );
			updatePills();
			syncWidthDisplay();
			fitCanvas();
		} );
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
	refs.zoomLabelEl.addEventListener( 'click', () => fitCanvas() );
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

	const fitBtn = mkBtn( '', () => fitCanvas() );
	fitBtn.innerHTML =
		'<svg class="wpi-canvas-toolbar__icon" viewBox="0 0 24 24">' +
		'<path d="M4 4h6v2H6v4H4V4zm16 0h-6v2h4v4h2V4z' +
		'M4 20h6v-2H6v-4H4v6zm16 0h-6v-2h4v-4h2v6z"/></svg>';
	fitBtn.setAttribute( 'aria-label', 'Fit to viewport' );
	fitBtn.setAttribute( 'data-tooltip', 'Fit (\u2318+0)' );
	actions.appendChild( fitBtn );

	const a11yWrap = document.createElement( 'div' );
	a11yWrap.className = 'wpi-canvas-toolbar__a11y-wrap';
	const a11yBtn = mkBtn( '', () => {
		a11yMenu.classList.toggle( 'is-open' );
	} );
	a11yBtn.innerHTML =
		'<svg class="wpi-canvas-toolbar__icon" viewBox="0 0 24 24">' +
		'<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>';
	a11yBtn.setAttribute( 'aria-label', 'Accessibility preview' );
	a11yBtn.setAttribute( 'data-tooltip', 'Accessibility' );
	a11yBtn.classList.toggle( 'is-active', getAccessibilityMode() !== 'normal' );
	refs.a11yBtnEl = a11yBtn;

	const a11yMenu = document.createElement( 'div' );
	a11yMenu.className = 'wpi-canvas-toolbar__a11y-menu';
	A11Y_MODES.forEach( ( mode ) => {
		const item = document.createElement( 'button' );
		item.className = 'wpi-canvas-toolbar__a11y-item';
		item.setAttribute( 'type', 'button' );
		item.textContent = mode.label;
		if ( mode.key === getAccessibilityMode() ) {
			item.classList.add( 'is-selected' );
		}
		item.addEventListener( 'click', () => {
			setAccessibilityMode( mode.key );
			a11yMenu.querySelectorAll( '.wpi-canvas-toolbar__a11y-item' ).forEach( ( n ) => {
				n.classList.toggle( 'is-selected', n.textContent === mode.label );
			} );
			a11yBtn.classList.toggle( 'is-active', mode.key !== 'normal' );
			a11yMenu.classList.remove( 'is-open' );
		} );
		a11yMenu.appendChild( item );
	} );

	a11yWrap.appendChild( a11yBtn );
	a11yWrap.appendChild( a11yMenu );
	actions.appendChild( a11yWrap );

	document.addEventListener( 'click', ( e ) => {
		if ( ! a11yWrap.contains( e.target ) ) {
			a11yMenu.classList.remove( 'is-open' );
		}
	} );

	const chatBtn = document.createElement( 'button' );
	chatBtn.className = 'wpi-canvas-toolbar__ask-ai';
	chatBtn.setAttribute( 'type', 'button' );
	chatBtn.setAttribute( 'aria-label', 'Ask AI' );
	chatBtn.innerHTML =
		'<svg class="wpi-canvas-toolbar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' +
		'<path d="M12 3v2m0 14v2m-7-9H3m18 0h-2m-1.5-6.5L16 7m-8-1.5L6.5 7m11 11L16 17M8 18.5L6.5 17"/>' +
		'<circle cx="12" cy="12" r="3" fill="currentColor" stroke="none"/>' +
		'</svg>' +
		'<span>Ask AI</span>';
	chatBtn.addEventListener( 'click', () => {
		if ( typeof window.wpiAiChatToggle === 'function' ) {
			window.wpiAiChatToggle();
		}
	} );
	actions.appendChild( chatBtn );

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
	refs.contentEl.appendChild( refs.stripEl );

	_contentResizeObserver = new ResizeObserver( () => {
		if ( ! state.active ) {
			return;
		}
		clearTimeout( _contentResizeTimer );
		_contentResizeTimer = setTimeout( () => {
			fitCanvas();
		}, 80 );
	} );
	_contentResizeObserver.observe( refs.contentEl );

	updatePills();
}

export function removeStrip() {
	clearTimeout( _contentResizeTimer );
	if ( _contentResizeObserver ) {
		_contentResizeObserver.disconnect();
		_contentResizeObserver = null;
	}
	if ( refs.stripEl ) {
		refs.stripEl.remove();
		refs.stripEl = null;
		refs.zoomLabelEl = null;
		refs.a11yBtnEl = null;
	}
}

export function syncWidthDisplay( widthPx ) {
	if ( ! refs.widthDisplayEl ) {
		return;
	}
	if ( document.activeElement === refs.widthDisplayEl ) {
		return;
	}
	if ( widthPx !== undefined ) {
		refs.widthDisplayEl.value = Math.round( widthPx ) + 'px';
	} else {
		const vp = viewportByKey( state.viewport );
		const w = state.customWidth || vp.previewWidth;
		refs.widthDisplayEl.value = Math.round( w ) + 'px';
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
}

function mkBtn( text, handler ) {
	const btn = document.createElement( 'button' );
	btn.className = 'wpi-canvas-toolbar__btn';
	btn.setAttribute( 'type', 'button' );
	btn.textContent = text;
	btn.addEventListener( 'click', handler );
	return btn;
}
