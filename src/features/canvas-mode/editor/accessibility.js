import { getEditorDoc } from './state';

const SVG_NS = 'http://www.w3.org/2000/svg';
const FILTER_CONTAINER_ID = 'wpi-canvas-a11y-filters';
const APPLIED_ATTR = 'data-wpi-a11y-filter';

export const A11Y_MODES = [
	{ key: 'normal', label: 'Normal Vision' },
	{ key: 'protanopia', label: 'Protanopia', matrix: '0.567 0.433 0 0 0  0.558 0.442 0 0 0  0 0.242 0.758 0 0  0 0 0 1 0' },
	{ key: 'deuteranopia', label: 'Deuteranopia', matrix: '0.625 0.375 0 0 0  0.7 0.3 0 0 0  0 0.3 0.7 0 0  0 0 0 1 0' },
	{ key: 'tritanopia', label: 'Tritanopia', matrix: '0.95 0.05 0 0 0  0 0.433 0.567 0 0  0 0.475 0.525 0 0  0 0 0 1 0' },
	{ key: 'achromatopsia', label: 'Achromatopsia', matrix: '0.299 0.587 0.114 0 0  0.299 0.587 0.114 0 0  0.299 0.587 0.114 0 0  0 0 0 1 0' },
];

let _currentMode = 'normal';

function ensureFilters( doc ) {
	if ( ! doc?.documentElement ) {
		return;
	}
	if ( doc.getElementById( FILTER_CONTAINER_ID ) ) {
		return;
	}

	const svg = doc.createElementNS( SVG_NS, 'svg' );
	svg.setAttribute( 'id', FILTER_CONTAINER_ID );
	svg.setAttribute( 'width', '0' );
	svg.setAttribute( 'height', '0' );
	svg.style.position = 'absolute';
	svg.style.pointerEvents = 'none';

	const defs = doc.createElementNS( SVG_NS, 'defs' );

	for ( const mode of A11Y_MODES ) {
		if ( ! mode.matrix ) {
			continue;
		}
		const filter = doc.createElementNS( SVG_NS, 'filter' );
		filter.setAttribute( 'id', 'wpi-a11y-' + mode.key );

		const feMatrix = doc.createElementNS( SVG_NS, 'feColorMatrix' );
		feMatrix.setAttribute( 'type', 'matrix' );
		feMatrix.setAttribute( 'values', mode.matrix );

		filter.appendChild( feMatrix );
		defs.appendChild( filter );
	}

	svg.appendChild( defs );
	doc.body.appendChild( svg );
}

function applyFilter( doc, mode ) {
	if ( ! doc?.documentElement ) {
		return;
	}

	const wrapper =
		doc.querySelector( '.editor-styles-wrapper.block-editor-writing-flow' ) ||
		doc.querySelector( '.editor-styles-wrapper' );

	if ( ! wrapper ) {
		return;
	}

	if ( mode === 'normal' || ! mode ) {
		wrapper.style.filter = '';
		wrapper.removeAttribute( APPLIED_ATTR );
	} else {
		ensureFilters( doc );
		wrapper.style.filter = 'url(#wpi-a11y-' + mode + ')';
		wrapper.setAttribute( APPLIED_ATTR, mode );
	}
}

export function setAccessibilityMode( mode ) {
	_currentMode = mode;
	const doc = getEditorDoc();
	applyFilter( doc, mode );
}

export function getAccessibilityMode() {
	return _currentMode;
}

export function cleanupAccessibility() {
	const doc = getEditorDoc();
	if ( doc ) {
		applyFilter( doc, 'normal' );
		const svgEl = doc.getElementById( FILTER_CONTAINER_ID );
		if ( svgEl ) {
			svgEl.remove();
		}
	}
	_currentMode = 'normal';
}

export function reapplyAccessibility() {
	if ( _currentMode !== 'normal' ) {
		const doc = getEditorDoc();
		applyFilter( doc, _currentMode );
	}
}
