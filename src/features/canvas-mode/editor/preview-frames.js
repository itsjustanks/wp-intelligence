import { VIEWPORTS, state, refs } from './state';

let _workspaceEl = null;
let _activeFrameEl = null;
let _previewRailEl = null;
let _previewFrames = [];
let _originalParent = null;
let _originalNextSibling = null;

function getPreviewUrl() {
	try {
		const data = window.wp?.data;
		if ( ! data ) {
			return '';
		}
		const editor = data.select( 'core/editor' );
		return (
			editor?.getEditedPostPreviewLink?.() ||
			editor?.getCurrentPostAttribute?.( 'link' ) ||
			''
		);
	} catch ( _e ) {
		return '';
	}
}

function syncPreviewFrameHeight( iframe, shell ) {
	try {
		const doc = iframe.contentDocument;
		if ( ! doc?.documentElement ) {
			return;
		}
		const h = Math.max(
			doc.documentElement.scrollHeight || 0,
			doc.body?.scrollHeight || 0
		);
		if ( ! h ) {
			return;
		}
		iframe.style.height = h + 'px';
		shell.style.height = h + 'px';
	} catch ( _e ) {} // eslint-disable-line no-empty
}

function onPreviewLoad( iframe, shell ) {
	try {
		const doc = iframe.contentDocument;
		if ( ! doc?.head ) {
			return;
		}
		const style = doc.createElement( 'style' );
		style.textContent =
			'#wpadminbar,.site-editor-header,.editor-styles-wrapper__top-toolbar{display:none!important}' +
			'html{margin-top:0!important;padding-top:0!important}' +
			'body{margin-top:0!important;padding-top:0!important;overflow:hidden!important}';
		doc.head.appendChild( style );
		syncPreviewFrameHeight( iframe, shell );
		shell.classList.remove( 'is-loading' );
		const observer = new MutationObserver( () => syncPreviewFrameHeight( iframe, shell ) );
		observer.observe( doc.body, {
			childList: true,
			subtree: true,
			attributes: true,
			attributeFilter: [ 'style', 'class' ],
		} );
		iframe._wpiObserver = observer;
	} catch ( _e ) {} // eslint-disable-line no-empty
}

function destroyPreviewFrames() {
	_previewFrames.forEach( ( item ) => {
		try {
			item.iframe?._wpiObserver?.disconnect?.();
		} catch ( _e ) {} // eslint-disable-line no-empty
		item.frame.remove();
	} );
	_previewFrames = [];
	_previewRailEl?.remove();
	_previewRailEl = null;
}

function buildPreviewFrame( viewport, onFocusViewport ) {
	const frame = document.createElement( 'button' );
	frame.className = 'wpi-canvas-preview-frame';
	frame.type = 'button';
	frame.setAttribute( 'data-vp', viewport.key );
	frame.setAttribute( 'aria-label', 'Focus ' + viewport.label + ' viewport' );

	const header = document.createElement( 'div' );
	header.className = 'wpi-canvas-preview-frame__header';
	header.textContent = viewport.label + ' · ' + viewport.previewWidth + 'px';
	frame.appendChild( header );

	const shell = document.createElement( 'div' );
	shell.className = 'wpi-canvas-preview-frame__shell';
	shell.style.width = viewport.previewWidth + 'px';
	shell.classList.add( 'is-loading' );
	frame.appendChild( shell );

	const loader = document.createElement( 'div' );
	loader.className = 'wpi-canvas-preview-frame__loader';
	loader.textContent = 'Loading preview...';
	shell.appendChild( loader );

	const iframe = document.createElement( 'iframe' );
	iframe.className = 'wpi-canvas-preview-frame__iframe';
	iframe.loading = 'lazy';
	iframe.tabIndex = -1;
	iframe.setAttribute( 'aria-hidden', 'true' );
	iframe.style.width = viewport.previewWidth + 'px';
	iframe.src = getPreviewUrl();
	iframe.addEventListener( 'load', () => onPreviewLoad( iframe, shell ) );
	shell.appendChild( iframe );

	frame.addEventListener( 'click', () => onFocusViewport?.( viewport.key ) );

	_previewFrames.push( { viewport, frame, iframe, shell } );
	return frame;
}

export function initPreviewFrames( onFocusViewport ) {
	if ( _workspaceEl || ! refs.contentEl || ! refs.editorVisualEl ) {
		return;
	}

	_originalParent = refs.editorVisualEl.parentNode;
	_originalNextSibling = refs.editorVisualEl.nextSibling;

	_workspaceEl = document.createElement( 'div' );
	_workspaceEl.className = 'wpi-canvas-workspace';
	refs.workspaceEl = _workspaceEl;

	_activeFrameEl = document.createElement( 'div' );
	_activeFrameEl.className = 'wpi-canvas-active-frame';

	_previewRailEl = document.createElement( 'div' );
	_previewRailEl.className = 'wpi-canvas-preview-rail';

	_originalParent.insertBefore( _workspaceEl, refs.editorVisualEl );
	_workspaceEl.appendChild( _activeFrameEl );
	_activeFrameEl.appendChild( refs.editorVisualEl );
	_workspaceEl.appendChild( _previewRailEl );

	VIEWPORTS.filter( ( vp ) => vp.key !== state.viewport ).forEach( ( viewport ) => {
		_previewRailEl.appendChild( buildPreviewFrame( viewport, onFocusViewport ) );
	} );
}

export function syncPreviewFrames( onFocusViewport ) {
	if ( ! _workspaceEl || ! _previewRailEl ) {
		return;
	}
	destroyPreviewFrames();
	_workspaceEl.appendChild( _previewRailEl = document.createElement( 'div' ) );
	_previewRailEl.className = 'wpi-canvas-preview-rail';

	VIEWPORTS.filter( ( vp ) => vp.key !== state.viewport ).forEach( ( viewport ) => {
		_previewRailEl.appendChild( buildPreviewFrame( viewport, onFocusViewport ) );
	} );
}

export function removePreviewFrames() {
	destroyPreviewFrames();
	if ( refs.editorVisualEl && _originalParent ) {
		if ( _originalNextSibling ) {
			_originalParent.insertBefore( refs.editorVisualEl, _originalNextSibling );
		} else {
			_originalParent.appendChild( refs.editorVisualEl );
		}
	}
	_workspaceEl?.remove();
	_workspaceEl = null;
	refs.workspaceEl = null;
	_activeFrameEl = null;
	_originalParent = null;
	_originalNextSibling = null;
}
