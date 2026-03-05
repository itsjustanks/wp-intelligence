import { createElement, useState } from '@wordpress/element';
import { registerPlugin } from '@wordpress/plugins';
import domReady from '@wordpress/dom-ready';
import { subscribe } from '@wordpress/data';

import {
	state, refs,
	getContentArea, getEditorVisual,
} from './state';
import {
	initPanzoom,
	destroyPanzoom,
	fitAllFrames,
	focusViewportFrame,
	resetCanvas,
	syncZoomLabel,
} from './canvas';
import {
	waitForEditorReady,
	rebuildCanvasFrames,
	syncMirrorBodies,
	stopMirrorObserver,
	removeCanvasRow,
	updatePlayButton,
} from './frames';
import {
	injectToggle, updateToggle,
	injectStrip, removeStrip,
	updatePills,
	showLoadingOverlay, hideLoadingOverlay,
	showToolbar, hideToolbar,
} from './ui';
import { bindShortcuts } from './shortcuts';
import { shouldAutoActivate } from './auto-activate';
import {
	freezeEditorAnimations, unfreezeEditorAnimations,
	expandEditorIframe, restoreEditorIframe,
} from './editor-iframe';

let _saveWatcher = null;

function startSaveWatcher() {
	if ( _saveWatcher ) {
		return;
	}
	let wasSaving = false;
	_saveWatcher = subscribe( () => {
		if ( ! state.active ) {
			return;
		}
		try {
			const saving = window.wp.data.select( 'core/editor' ).isSavingPost();
			if ( wasSaving && ! saving ) {
				setTimeout( syncMirrorBodies, 800 );
			}
			wasSaving = saving;
		} catch ( e ) {} // eslint-disable-line no-empty
	} );
}

function stopSaveWatcher() {
	_saveWatcher?.();
	_saveWatcher = null;
}

function togglePlay() {
	state.playing = ! state.playing;
	if ( state.playing ) {
		unfreezeEditorAnimations();
	} else {
		freezeEditorAnimations();
	}
	updatePlayButton();
}

function switchViewport( key ) {
	state.viewport = key;
	updatePills();
	focusViewportFrame( key, true );
}

function activate() {
	state.active = true;

	showLoadingOverlay( deactivate );

	refs.contentEl = getContentArea();
	refs.editorVisualEl = getEditorVisual();
	if ( ! refs.contentEl || ! refs.editorVisualEl ) {
		state.active = false;
		hideLoadingOverlay();
		return;
	}

	document.body.classList.add( 'wpi-canvas-mode-active' );
	injectStrip( switchViewport, deactivate );
	hideToolbar();
	updateToggle();

	const onReady = () => {
		freezeEditorAnimations();
		expandEditorIframe();

		rebuildCanvasFrames( switchViewport, { onTogglePlay: togglePlay } ).then( () => {
			initPanzoom();
			fitAllFrames( false );
			syncZoomLabel();
			showToolbar();
			hideLoadingOverlay();
			startSaveWatcher();

			setTimeout( () => {
				freezeEditorAnimations();
				expandEditorIframe();
			}, 400 );
		} );
	};
	waitForEditorReady( onReady, onReady );
}

function deactivate() {
	state.active = false;
	state.playing = false;
	state.viewport = 'Desktop';
	document.body.classList.remove( 'wpi-canvas-mode-active' );

	unfreezeEditorAnimations();
	restoreEditorIframe();
	stopMirrorObserver();
	stopSaveWatcher();
	clearInterval( refs.editorReadyTimer );
	refs.editorReadyTimer = null;
	clearTimeout( refs.rebuildTimer );

	destroyPanzoom();
	removeStrip();
	resetCanvas();
	removeCanvasRow();

	refs.contentEl?.classList.remove(
		'wpi-canvas-space-pan',
		'wpi-canvas-is-panning'
	);
	state.spaceHeld = false;
	refs.contentEl = null;
	updateToggle();
}

function toggle() {
	if ( state.active ) {
		deactivate();
	} else {
		activate();
	}
}

/* ── More menu item ─────────────────────────── */

const PluginMoreMenuItem =
	window.wp?.editor?.PluginMoreMenuItem ||
	window.wp?.editPost?.PluginMoreMenuItem;

if ( PluginMoreMenuItem ) {
	registerPlugin( 'wpi-canvas-mode', {
		render() {
			const [ , forceUpdate ] = useState( false );
			return createElement(
				PluginMoreMenuItem,
				{
					icon: 'grid-view',
					onClick() {
						toggle();
						forceUpdate( ( v ) => ! v );
					},
				},
				state.active ? 'Exit Canvas Mode' : 'Canvas Mode'
			);
		},
	} );
}

/* ── Keyboard shortcuts ─────────────────────── */

bindShortcuts( toggle, deactivate, togglePlay );

/* ── DOM ready: inject toggle + auto-activate ── */

domReady( () => {
	let toggleInjected = false;
	let autoActivated = false;

	const unsubscribe = subscribe( () => {
		const header =
			document.querySelector( '.editor-header__settings' ) ||
			document.querySelector( '.edit-post-header__settings' );
		if ( ! header ) {
			return;
		}

		if ( ! toggleInjected ) {
			toggleInjected = true;
			injectToggle( toggle );
		}

		if ( ! autoActivated && shouldAutoActivate() ) {
			autoActivated = true;
			setTimeout( () => {
				if ( ! state.active ) {
					activate();
				}
			}, 100 );
			unsubscribe();
			return;
		}

		if ( toggleInjected && ! shouldAutoActivate() ) {
			unsubscribe();
		}
	} );
} );
