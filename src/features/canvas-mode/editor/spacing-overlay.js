import { state, getEditorIframe, getEditorDoc } from './state';
import { getCanvasScale } from './canvas';

let _containerEl = null;
let _boundDoc = null;
let _moveHandler = null;
let _leaveHandler = null;
let _rafId = 0;
let _enabled = false;

const SIDES = [ 'top', 'right', 'bottom', 'left' ];

let _marginEls = {};
let _paddingEls = {};
let _labelEls = {};

function ensureContainer() {
	if ( _containerEl ) {
		return _containerEl;
	}
	_containerEl = document.createElement( 'div' );
	_containerEl.className = 'wpi-canvas-spacing-container';

	SIDES.forEach( ( side ) => {
		const m = document.createElement( 'div' );
		m.className = 'wpi-canvas-spacing-margin wpi-canvas-spacing-margin--' + side;
		_containerEl.appendChild( m );
		_marginEls[ side ] = m;

		const ml = document.createElement( 'span' );
		ml.className = 'wpi-canvas-spacing-label wpi-canvas-spacing-label--margin-' + side;
		_containerEl.appendChild( ml );
		_labelEls[ 'margin-' + side ] = ml;

		const p = document.createElement( 'div' );
		p.className = 'wpi-canvas-spacing-padding wpi-canvas-spacing-padding--' + side;
		_containerEl.appendChild( p );
		_paddingEls[ side ] = p;

		const pl = document.createElement( 'span' );
		pl.className = 'wpi-canvas-spacing-label wpi-canvas-spacing-label--padding-' + side;
		_containerEl.appendChild( pl );
		_labelEls[ 'padding-' + side ] = pl;
	} );

	document.body.appendChild( _containerEl );
	return _containerEl;
}

function parseSpacing( computed, prop ) {
	return parseFloat( computed.getPropertyValue( prop ) ) || 0;
}

function showOverlay( blockEl ) {
	if ( ! blockEl || ! state.active || ! _enabled ) {
		return;
	}

	const iframe = getEditorIframe();
	if ( ! iframe ) {
		return;
	}

	const scale = getCanvasScale();
	const iframeRect = iframe.getBoundingClientRect();
	const elRect = blockEl.getBoundingClientRect();

	const doc = blockEl.ownerDocument;
	const computed = doc.defaultView.getComputedStyle( blockEl );

	const margin = {
		top: parseSpacing( computed, 'margin-top' ),
		right: parseSpacing( computed, 'margin-right' ),
		bottom: parseSpacing( computed, 'margin-bottom' ),
		left: parseSpacing( computed, 'margin-left' ),
	};

	const padding = {
		top: parseSpacing( computed, 'padding-top' ),
		right: parseSpacing( computed, 'padding-right' ),
		bottom: parseSpacing( computed, 'padding-bottom' ),
		left: parseSpacing( computed, 'padding-left' ),
	};

	const x = iframeRect.left + elRect.left * scale;
	const y = iframeRect.top + elRect.top * scale;
	const w = elRect.width * scale;
	const h = elRect.height * scale;

	ensureContainer();
	_containerEl.classList.add( 'is-visible' );

	const mt = margin.top * scale;
	const mr = margin.right * scale;
	const mb = margin.bottom * scale;
	const ml = margin.left * scale;
	const pt = padding.top * scale;
	const pr = padding.right * scale;
	const pb = padding.bottom * scale;
	const pl = padding.left * scale;

	positionRect( _marginEls.top, x - ml, y - mt, w + ml + mr, mt );
	positionRect( _marginEls.right, x + w, y, mr, h );
	positionRect( _marginEls.bottom, x - ml, y + h, w + ml + mr, mb );
	positionRect( _marginEls.left, x - ml, y, ml, h );

	positionRect( _paddingEls.top, x, y, w, pt );
	positionRect( _paddingEls.right, x + w - pr, y + pt, pr, h - pt - pb );
	positionRect( _paddingEls.bottom, x, y + h - pb, w, pb );
	positionRect( _paddingEls.left, x, y + pt, pl, h - pt - pb );

	setLabel( 'margin-top', margin.top, x + w / 2, y - mt / 2 );
	setLabel( 'margin-right', margin.right, x + w + mr / 2, y + h / 2 );
	setLabel( 'margin-bottom', margin.bottom, x + w / 2, y + h + mb / 2 );
	setLabel( 'margin-left', margin.left, x - ml / 2, y + h / 2 );

	setLabel( 'padding-top', padding.top, x + w / 2, y + pt / 2 );
	setLabel( 'padding-right', padding.right, x + w - pr / 2, y + h / 2 );
	setLabel( 'padding-bottom', padding.bottom, x + w / 2, y + h - pb / 2 );
	setLabel( 'padding-left', padding.left, x + pl / 2, y + h / 2 );
}

function positionRect( el, left, top, width, height ) {
	if ( ! el ) {
		return;
	}
	if ( width < 0.5 || height < 0.5 ) {
		el.style.display = 'none';
		return;
	}
	el.style.display = '';
	el.style.left = left + 'px';
	el.style.top = top + 'px';
	el.style.width = width + 'px';
	el.style.height = height + 'px';
}

function setLabel( key, value, cx, cy ) {
	const el = _labelEls[ key ];
	if ( ! el ) {
		return;
	}
	const rounded = Math.round( value );
	if ( rounded === 0 ) {
		el.style.display = 'none';
		return;
	}
	el.style.display = '';
	el.textContent = rounded + 'px';
	el.style.left = cx + 'px';
	el.style.top = cy + 'px';
}

function hideOverlay() {
	if ( _containerEl ) {
		_containerEl.classList.remove( 'is-visible' );
	}
}

function findBlockElement( target ) {
	if ( ! target || target.nodeType !== 1 ) {
		return null;
	}
	return target.closest( '[data-block]' );
}

function onMouseMove( e ) {
	cancelAnimationFrame( _rafId );
	_rafId = requestAnimationFrame( () => {
		const blockEl = findBlockElement( e.target );
		if ( blockEl ) {
			showOverlay( blockEl );
		} else {
			hideOverlay();
		}
	} );
}

function onMouseLeave() {
	hideOverlay();
}

export function isSpacingEnabled() {
	return _enabled;
}

export function toggleSpacing() {
	_enabled = ! _enabled;
	if ( ! _enabled ) {
		hideOverlay();
	}
	return _enabled;
}

export function initSpacingOverlay() {
	const doc = getEditorDoc();
	if ( ! doc ) {
		return;
	}

	_boundDoc = doc;
	_moveHandler = onMouseMove;
	_leaveHandler = onMouseLeave;
	doc.addEventListener( 'mousemove', _moveHandler );
	doc.addEventListener( 'mouseleave', _leaveHandler );
}

export function destroySpacingOverlay() {
	cancelAnimationFrame( _rafId );
	_rafId = 0;

	if ( _boundDoc ) {
		_boundDoc.removeEventListener( 'mousemove', _moveHandler );
		_boundDoc.removeEventListener( 'mouseleave', _leaveHandler );
		_boundDoc = null;
	}

	_containerEl?.remove();
	_containerEl = null;
	_marginEls = {};
	_paddingEls = {};
	_labelEls = {};
	_enabled = false;
}
