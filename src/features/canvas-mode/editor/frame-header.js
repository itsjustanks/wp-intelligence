import { VIEWPORTS, state, refs } from './state';

const VP_RANGES = {
	Desktop: '1400px and up',
	Tablet: '800\u20131399px',
	Mobile: '0\u2013799px',
};

let _headerEl = null;
let _labelEl = null;
let _widthEl = null;

export function injectFrameHeader() {
	if ( _headerEl || ! refs.editorVisualEl ) {
		return;
	}

	_headerEl = document.createElement( 'div' );
	_headerEl.className = 'wpi-canvas-frame-header';

	_labelEl = document.createElement( 'span' );
	_labelEl.className = 'wpi-canvas-frame-header__label';

	_widthEl = document.createElement( 'span' );
	_widthEl.className = 'wpi-canvas-frame-header__width';

	_headerEl.appendChild( _labelEl );
	_headerEl.appendChild( _widthEl );

	refs.editorVisualEl.appendChild( _headerEl );

	syncFrameHeader();
}

export function removeFrameHeader() {
	_headerEl?.remove();
	_headerEl = null;
	_labelEl = null;
	_widthEl = null;
}

export function syncFrameHeader( scale ) {
	if ( ! _headerEl || ! _labelEl || ! _widthEl ) {
		return;
	}

	const vp = VIEWPORTS.find( ( v ) => v.key === state.viewport ) || VIEWPORTS[ 0 ];
	const range = VP_RANGES[ vp.key ] || vp.previewWidth + 'px';
	_labelEl.textContent = vp.label + ' \u2014 ' + range;

	const w = state.customWidth || vp.previewWidth;
	_widthEl.textContent = Math.round( w ) + 'px';

	if ( scale && scale > 0 ) {
		const inv = 1 / scale;
		_headerEl.style.transform = 'scale(' + inv + ')';
		_headerEl.style.transformOrigin = 'top left';
	}
}
