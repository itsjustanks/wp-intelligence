import {
	state, refs,
	getEditorVisual, getEditorStylesWrapper,
} from './state';

export function waitForEditorReady( onReady, onTimeout ) {
	clearTimeout( refs.editorReadyTimer );
	if ( refs._editorReadyObserver ) {
		refs._editorReadyObserver.disconnect();
		refs._editorReadyObserver = null;
	}

	if ( getEditorStylesWrapper() ) {
		onReady();
		return;
	}

	const container = getEditorVisual() || document.body;
	const observer = new MutationObserver( () => {
		if ( getEditorStylesWrapper() ) {
			observer.disconnect();
			refs._editorReadyObserver = null;
			clearTimeout( refs.editorReadyTimer );
			refs.editorReadyTimer = null;
			onReady();
		}
	} );
	refs._editorReadyObserver = observer;
	observer.observe( container, { childList: true, subtree: true } );

	refs.editorReadyTimer = setTimeout( () => {
		observer.disconnect();
		refs._editorReadyObserver = null;
		refs.editorReadyTimer = null;
		onTimeout?.();
	}, 4000 );
}

const PLAY_SVG =
	'<svg width="10" height="10" viewBox="0 0 24 24"><polygon points="6,4 20,12 6,20" fill="currentColor"/></svg>';
const PAUSE_SVG =
	'<svg width="10" height="10" viewBox="0 0 24 24">' +
	'<rect x="5" y="4" width="4" height="16" fill="currentColor"/>' +
	'<rect x="15" y="4" width="4" height="16" fill="currentColor"/></svg>';

export function updatePlayButton() {
	if ( ! refs.playBtnEl ) {
		return;
	}
	refs.playBtnEl.innerHTML = state.playing ? PAUSE_SVG : PLAY_SVG;
	refs.playBtnEl.classList.toggle( 'is-playing', state.playing );
	refs.playBtnEl.setAttribute( 'aria-label',
		state.playing ? 'Pause preview' : 'Play preview' );
	refs.playBtnEl.setAttribute( 'data-tooltip',
		state.playing ? 'Pause (P)' : 'Preview (P)' );
}

export { PLAY_SVG, PAUSE_SVG };
