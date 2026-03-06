import {
	getEditorIframe,
	getEditorDoc,
	state,
} from './state';

const CANVAS_MODE_FREEZE_CSS =
	'*,*::before,*::after{' +
	'animation-play-state:paused!important;' +
	'animation-delay:-0.0001s!important;' +
	'transition-property:none!important;' +
	'transition-duration:0s!important;' +
	'will-change:auto!important;' +
	'scroll-behavior:auto!important}' +
	'marquee{-webkit-animation-play-state:paused!important;overflow:hidden!important}' +
	'.nectar-blocks-marquee__inner{animation-play-state:paused!important}' +
	'video{pointer-events:none!important}' +
	'.wp-block-video video,.wp-block-cover__video-background{pointer-events:none!important}';

const STYLE_ID = 'wpi-canvas-mode-freeze';

const CANVAS_LAYOUT_ID = 'wpi-canvas-mode-layout';
const CANVAS_LAYOUT_CSS =
	'.editor-post-title,.editor-post-title__block,' +
	'.edit-post-visual-editor__post-title-wrapper,' +
	'.editor-visual-editor__post-title-wrapper{display:none!important}' +
	'.wpi-template-chrome{pointer-events:none;position:relative;opacity:.85}' +
	'.wpi-template-chrome *{pointer-events:none!important}' +
	'.wpi-template-chrome a{cursor:default!important}' +
	'.wpi-template-chrome--header{border-bottom:1px dashed rgba(0,0,0,.1)}' +
	'.wpi-template-chrome--footer{border-top:1px dashed rgba(0,0,0,.1)}';

let _boundIframe = null;
let _loadHandler = null;
let _mediaObserver = null;
let _playInterceptor = null;

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

export function applyLayoutStyle( doc ) {
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

export function removeLayoutStyle( doc ) {
	if ( ! doc ) {
		return;
	}
	const el = doc.getElementById( CANVAS_LAYOUT_ID );
	if ( el ) {
		el.remove();
	}
}

function pauseVideo( v ) {
	try {
		if ( ! v.paused ) {
			v.dataset.wpiWasPlaying = '1';
		}
		v.pause();
	} catch ( e ) {} // eslint-disable-line no-empty
}

function pauseAllMedia( doc ) {
	if ( ! doc ) {
		return;
	}
	doc.querySelectorAll( 'video' ).forEach( pauseVideo );
	doc.querySelectorAll( 'marquee' ).forEach( ( m ) => {
		try {
			m.stop();
			m.dataset.wpiWasStopped = '1';
		} catch ( e ) {} // eslint-disable-line no-empty
	} );
}

function resumeAllMedia( doc ) {
	if ( ! doc ) {
		return;
	}
	doc.querySelectorAll( 'video' ).forEach( ( v ) => {
		try {
			const shouldPlay = v.dataset.wpiWasPlaying === '1' ||
				v.hasAttribute( 'autoplay' );
			delete v.dataset.wpiWasPlaying;
			if ( shouldPlay ) {
				v.play().catch( () => {} );
			}
		} catch ( e ) {} // eslint-disable-line no-empty
	} );
	doc.querySelectorAll( 'marquee' ).forEach( ( m ) => {
		try {
			delete m.dataset.wpiWasStopped;
			m.start();
		} catch ( e ) {} // eslint-disable-line no-empty
	} );
}

function onPlayIntercept( e ) {
	if ( state.playing ) {
		return;
	}
	const v = e.target;
	if ( v?.tagName === 'VIDEO' ) {
		try { v.pause(); } catch ( ex ) {} // eslint-disable-line no-empty
	}
}

function startMediaObserver( doc ) {
	stopMediaObserver();
	if ( ! doc?.body ) {
		return;
	}

	doc.addEventListener( 'play', onPlayIntercept, true );
	_playInterceptor = doc;

	_mediaObserver = new MutationObserver( ( mutations ) => {
		if ( state.playing ) {
			return;
		}
		for ( const mutation of mutations ) {
			for ( const node of mutation.addedNodes ) {
				if ( node.nodeType !== 1 ) {
					continue;
				}
				if ( node.tagName === 'VIDEO' ) {
					pauseVideo( node );
				} else if ( node.tagName === 'MARQUEE' ) {
					try { node.stop(); } catch ( ex ) {} // eslint-disable-line no-empty
				}
				if ( node.querySelectorAll ) {
					node.querySelectorAll( 'video' ).forEach( pauseVideo );
					node.querySelectorAll( 'marquee' ).forEach( ( m ) => {
						try { m.stop(); } catch ( ex ) {} // eslint-disable-line no-empty
					} );
				}
			}
		}
	} );
	_mediaObserver.observe( doc.body, { childList: true, subtree: true } );
}

function stopMediaObserver() {
	if ( _mediaObserver ) {
		_mediaObserver.disconnect();
		_mediaObserver = null;
	}
	if ( _playInterceptor ) {
		_playInterceptor.removeEventListener( 'play', onPlayIntercept, true );
		_playInterceptor = null;
	}
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
			pauseAllMedia( doc );
			startMediaObserver( doc );
		}
	};
	iframe.addEventListener( 'load', _loadHandler );
}

export function freezeEditorAnimations() {
	const iframe = getEditorIframe();
	bindIframeLoad( iframe );
	const doc = getEditorDoc();
	if ( ! doc ) {
		return;
	}
	applyLayoutStyle( doc );
	applyFreezeStyle( doc );
	pauseAllMedia( doc );
	startMediaObserver( doc );
}

export function unfreezeEditorAnimations() {
	stopMediaObserver();
	const doc = getEditorDoc();
	removeFreezeStyle( doc );
	resumeAllMedia( doc );
}

export function cleanupEditorIframe() {
	stopMediaObserver();
	if ( _boundIframe && _loadHandler ) {
		_boundIframe.removeEventListener( 'load', _loadHandler );
	}
	_boundIframe = null;
	_loadHandler = null;

	const doc = getEditorDoc();
	removeLayoutStyle( doc );
	removeFreezeStyle( doc );
}
