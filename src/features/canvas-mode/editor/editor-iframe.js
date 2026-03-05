import {
	getEditorIframe,
	getEditorDoc,
	getEditorStylesWrapper,
	refs,
	state,
} from './state';

const CANVAS_MODE_FREEZE_CSS =
	'html,body{' +
	'height:auto!important;' +
	'min-height:0!important;' +
	'overflow:visible!important}' +
	'.editor-styles-wrapper,.block-editor-writing-flow,.is-root-container{' +
	'overflow:visible!important}' +
	'*,*::before,*::after{' +
	'animation-play-state:paused!important;' +
	'animation-delay:-0.0001s!important;' +
	'transition-property:none!important;' +
	'transition-duration:0s!important;' +
	'will-change:auto!important;' +
	'scroll-behavior:auto!important}' +
	'marquee{-webkit-animation-play-state:paused!important;overflow:hidden!important}' +
	'.nectar-blocks-marquee__inner{animation-play-state:paused!important}';

const STYLE_ID = 'wpi-canvas-mode-freeze';

const CANVAS_LAYOUT_ID = 'wpi-canvas-mode-layout';
const CANVAS_LAYOUT_CSS =
	'.editor-post-title,.editor-post-title__block,' +
	'.edit-post-visual-editor__post-title-wrapper,' +
	'.editor-visual-editor__post-title-wrapper{display:none!important}';

let _resizeObserver = null;
let _heightSyncTimer = null;
let _boundIframe = null;
let _loadHandler = null;

function applyFreezeStyle( doc ) {
	if ( ! doc?.head ) {
		return;
	}
	if ( doc.getElementById( STYLE_ID ) ) {
		return;
	}
	const style = doc.createElement( 'style' );
	style.id = STYLE_ID;
	style.textContent = CANVAS_MODE_FREEZE_CSS;
	doc.head.appendChild( style );
}

function removeFreezeStyle( doc ) {
	if ( ! doc ) {
		return;
	}
	const el = doc.getElementById( STYLE_ID );
	if ( el ) {
		el.remove();
	}
}

function applyLayoutStyle( doc ) {
	if ( ! doc?.head ) {
		return;
	}
	if ( doc.getElementById( CANVAS_LAYOUT_ID ) ) {
		return;
	}
	const s = doc.createElement( 'style' );
	s.id = CANVAS_LAYOUT_ID;
	s.textContent = CANVAS_LAYOUT_CSS;
	doc.head.appendChild( s );
}

function removeLayoutStyle( doc ) {
	if ( ! doc ) {
		return;
	}
	const el = doc.getElementById( CANVAS_LAYOUT_ID );
	if ( el ) {
		el.remove();
	}
}

function pauseAllVideos( doc ) {
	if ( ! doc ) {
		return;
	}
	doc.querySelectorAll( 'video' ).forEach( ( v ) => {
		try {
			v.pause();
			v.removeAttribute( 'autoplay' );
			v.autoplay = false;
		} catch ( e ) {} // eslint-disable-line no-empty
	} );
}

export function pauseMirrorVideos() {
	if ( ! refs.mirrorFrames ) {
		return;
	}
	refs.mirrorFrames.forEach( ( item ) => {
		const doc = item.iframeEl?.contentDocument;
		if ( doc ) {
			pauseAllVideos( doc );
		}
	} );
}

function bindIframeLoad( iframe ) {
	if ( ! iframe || _boundIframe === iframe ) {
		return;
	}
	if ( _boundIframe && _loadHandler ) {
		_boundIframe.removeEventListener( 'load', _loadHandler );
	}
	_boundIframe = iframe;
	_loadHandler = () => {
		const doc = iframe.contentDocument;
		applyLayoutStyle( doc );
		if ( ! state.playing ) {
			applyFreezeStyle( doc );
			pauseAllVideos( doc );
		}
		syncIframeHeight();
		observeIframeResize( doc );
	};
	iframe.addEventListener( 'load', _loadHandler );
}

function observeIframeResize( doc ) {
	if ( _resizeObserver ) {
		_resizeObserver.disconnect();
	}
	if ( ! doc?.body ) {
		return;
	}
	_resizeObserver = new ResizeObserver( () => {
		clearTimeout( _heightSyncTimer );
		_heightSyncTimer = setTimeout( syncIframeHeight, 150 );
	} );
	_resizeObserver.observe( doc.body );
}

export function freezeEditorAnimations() {
	const iframe = getEditorIframe();
	bindIframeLoad( iframe );
	const doc = getEditorDoc();
	if ( ! doc ) {
		return;
	}
	applyFreezeStyle( doc );
	pauseAllVideos( doc );
}

export function unfreezeEditorAnimations() {
	const doc = getEditorDoc();
	removeFreezeStyle( doc );
	if ( _boundIframe && _loadHandler ) {
		_boundIframe.removeEventListener( 'load', _loadHandler );
	}
	_boundIframe = null;
	_loadHandler = null;
}

export function expandEditorIframe() {
	const iframe = getEditorIframe();
	if ( ! iframe ) {
		return;
	}
	bindIframeLoad( iframe );

	iframe.style.setProperty( 'height', 'auto', 'important' );
	iframe.style.setProperty( 'min-height', '100%', 'important' );

	const container = iframe.closest( '.editor-visual-editor' ) ||
		iframe.closest( '.edit-post-visual-editor' );
	if ( container ) {
		container.style.setProperty( 'overflow', 'visible', 'important' );
	}

	const resizable = iframe.closest( '.components-resizable-box__container' );
	if ( resizable ) {
		resizable.style.setProperty( 'height', 'auto', 'important' );
		resizable.style.setProperty( 'min-height', 'auto', 'important' );
		resizable.style.setProperty( 'overflow', 'visible', 'important' );
	}

	const doc = getEditorDoc();
	applyLayoutStyle( doc );
	if ( ! state.playing ) {
		applyFreezeStyle( doc );
		pauseAllVideos( doc );
	}
	syncIframeHeight();
	observeIframeResize( doc );
}

export function restoreEditorIframe() {
	if ( _resizeObserver ) {
		_resizeObserver.disconnect();
		_resizeObserver = null;
	}
	clearTimeout( _heightSyncTimer );
	_heightSyncTimer = null;
	if ( _boundIframe && _loadHandler ) {
		_boundIframe.removeEventListener( 'load', _loadHandler );
	}
	_boundIframe = null;
	_loadHandler = null;

	const iframe = getEditorIframe();
	if ( ! iframe ) {
		return;
	}
	iframe.style.removeProperty( 'height' );
	iframe.style.removeProperty( 'min-height' );

	const container = iframe.closest( '.editor-visual-editor' ) ||
		iframe.closest( '.edit-post-visual-editor' );
	if ( container ) {
		container.style.removeProperty( 'overflow' );
	}

	const resizable = iframe.closest( '.components-resizable-box__container' );
	if ( resizable ) {
		resizable.style.removeProperty( 'height' );
		resizable.style.removeProperty( 'min-height' );
		resizable.style.removeProperty( 'overflow' );
	}

	const doc = getEditorDoc();
	removeLayoutStyle( doc );
}

function syncIframeHeight() {
	const iframe = getEditorIframe();
	if ( ! iframe ) {
		return;
	}
	const doc = iframe.contentDocument;
	if ( ! doc?.documentElement ) {
		return;
	}
	const wrapper = getEditorStylesWrapper( doc );
	const contentH = Math.max(
		wrapper?.scrollHeight || 0,
		doc.documentElement.scrollHeight,
		doc.body?.scrollHeight || 0,
		600
	);
	const currentH = iframe.getBoundingClientRect().height;
	if ( Math.abs( contentH - currentH ) > 5 ) {
		iframe.style.setProperty( 'height', contentH + 'px', 'important' );
		const vp = iframe.closest( '.wpi-canvas-frame__viewport' );
		if ( vp ) {
			vp.style.setProperty( 'height', 'auto', 'important' );
			vp.style.setProperty( 'min-height', contentH + 'px', 'important' );
		}
	}
}
