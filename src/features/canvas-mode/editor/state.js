export const VIEWPORTS = [
	{
		key: 'Desktop',
		label: 'Desktop',
		wpDevice: 'Desktop',
		previewWidth: 1440,
	},
	{
		key: 'Tablet',
		label: 'Tablet',
		wpDevice: 'Tablet',
		previewWidth: 1024,
	},
	{
		key: 'Mobile',
		label: 'Phone',
		wpDevice: 'Mobile',
		previewWidth: 375,
	},
];

export const CANVAS_PADDING = 56;

export const state = {
	active: false,
	viewport: 'Desktop',
	spaceHeld: false,
	playing: false,
	customWidth: null,
	a11yMode: 'normal',
};

export const refs = {
	toggleBtnEl: null,
	playBtnEl: null,
	stripEl: null,
	zoomLabelEl: null,
	contentEl: null,
	editorVisualEl: null,
	workspaceEl: null,
	widthDisplayEl: null,
	a11yBtnEl: null,
	inspectBtnEl: null,
	editorReadyTimer: null,
	_editorReadyObserver: null,
};

export function viewportByKey( key ) {
	for ( let i = 0; i < VIEWPORTS.length; i++ ) {
		if ( VIEWPORTS[ i ].key === key ) {
			return VIEWPORTS[ i ];
		}
	}
	return VIEWPORTS[ 0 ];
}

export function getContentArea() {
	return (
		document.querySelector( '.interface-interface-skeleton__content' ) ||
		document.querySelector( '.edit-post-layout__content' )
	);
}

export function getEditorVisual() {
	return (
		document.querySelector( '.editor-visual-editor' ) ||
		document.querySelector( '.edit-post-visual-editor' )
	);
}

export function getEditorIframe() {
	return document.querySelector( 'iframe[name="editor-canvas"]' );
}

export function getEditorDoc() {
	const iframe = getEditorIframe();
	if ( ! iframe || ! iframe.contentDocument || ! iframe.contentDocument.body ) {
		return null;
	}
	return iframe.contentDocument;
}

export function getEditorStylesWrapper( doc ) {
	const sourceDoc = doc || getEditorDoc();
	if ( ! sourceDoc ) {
		return null;
	}
	return (
		sourceDoc.querySelector( '.editor-styles-wrapper.block-editor-writing-flow' ) ||
		sourceDoc.querySelector( '.editor-styles-wrapper' )
	);
}

export function getEditorDeviceType() {
	try {
		const data = window.wp?.data;
		if ( ! data ) {
			return 'Desktop';
		}
		const editorSelect = data.select( 'core/editor' );
		if ( editorSelect?.__experimentalGetPreviewDeviceType ) {
			return editorSelect.__experimentalGetPreviewDeviceType() || 'Desktop';
		}
		if ( editorSelect?.getDeviceType ) {
			return editorSelect.getDeviceType() || 'Desktop';
		}
	} catch ( _e ) {} // eslint-disable-line no-empty
	return 'Desktop';
}

export function getViewportForDeviceType( deviceType ) {
	for ( let i = 0; i < VIEWPORTS.length; i++ ) {
		if ( VIEWPORTS[ i ].wpDevice === deviceType ) {
			return VIEWPORTS[ i ];
		}
	}
	return VIEWPORTS[ 0 ];
}

export function isTypingTarget( target ) {
	if ( ! target ) {
		return false;
	}
	if ( target.isContentEditable ) {
		return true;
	}
	return !! target.closest(
		'input, textarea, select, [contenteditable="true"], [role="textbox"], .block-editor-rich-text__editable'
	);
}
