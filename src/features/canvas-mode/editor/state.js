export const VIEWPORTS = [
	{
		key: 'Desktop',
		label: 'Desktop',
		width: null,
		wpDevice: 'Desktop',
		nectarIdx: 0,
		previewWidth: 1440,
		previewHeight: 900,
	},
	{
		key: 'Tablet',
		label: 'Tablet',
		width: 1024,
		wpDevice: 'Tablet',
		nectarIdx: 1,
		previewWidth: 1024,
		previewHeight: 1366,
	},
	{
		key: 'Mobile',
		label: 'Phone',
		width: 375,
		wpDevice: 'Mobile',
		nectarIdx: 2,
		previewWidth: 375,
		previewHeight: 812,
	},
];

export const FRAME_GAP = 48;
export const ROW_PADDING_X = 56;

export const state = {
	active: false,
	viewport: 'Desktop',
	spaceHeld: false,
	playing: false,
};

export const refs = {
	toggleBtnEl: null,
	playBtnEl: null,
	stripEl: null,
	zoomLabelEl: null,
	sizeBadgeEl: null,
	contentEl: null,
	editorVisualEl: null,
	originalParentEl: null,
	originalNextSiblingEl: null,
	canvasRowEl: null,
	mirrorFrames: [],
	mirrorObserver: null,
	mirrorSyncTimer: null,
	rebuildTimer: null,
	editorReadyTimer: null,
	panzoomInstance: null,
	backdropEl: null,
	topSpacerEl: null,
	bottomSpacerEl: null,
	contentPushObserver: null,
	contentPushTimer: null,
	autosaveTimer: null,
};

export function getContentSelector() {
	return window.wpiCanvasModeConfig?.contentSelector || '.entry-content';
}

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

export function getHeaderMount() {
	return (
		document.getElementById( 'nectar-responsive-device-toolbar__wrapper' ) ||
		document.querySelector( '.editor-header__toolbar' ) ||
		document.querySelector( '.edit-post-header__toolbar' )
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

export function applyDeviceSwitch( vp ) {
	const nectarWrapper = document.getElementById( 'nectar-responsive-device-toolbar__wrapper' );
	if ( nectarWrapper ) {
		const links = nectarWrapper.querySelectorAll( 'a' );
		if ( links[ vp.nectarIdx ] ) {
			links[ vp.nectarIdx ].click();
			return;
		}
	}
	try {
		const editorDispatch = window.wp?.data?.dispatch?.( 'core/editor' );
		if ( editorDispatch?.__experimentalSetPreviewDeviceType ) {
			editorDispatch.__experimentalSetPreviewDeviceType( vp.wpDevice );
		} else if ( editorDispatch?.setDeviceType ) {
			editorDispatch.setDeviceType( vp.wpDevice );
		}
	} catch ( e ) {} // eslint-disable-line no-empty
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
