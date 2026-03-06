import { createElement, useState } from '@wordpress/element';
import { registerPlugin } from '@wordpress/plugins';
import domReady from '@wordpress/dom-ready';
import { subscribe } from '@wordpress/data';

import {
	state, refs,
	getContentArea, getEditorVisual,
	viewportByKey, applyDeviceSwitch,
	getEditorDeviceType, getViewportForDeviceType,
} from './state';
import {
	initPanzoom,
	destroyPanzoom,
	fitAllFrames,
	resetCanvas,
	syncZoomLabel,
} from './canvas';
import {
	waitForEditorReady,
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
	cleanupEditorIframe, startHeightSync,
} from './editor-iframe';
import { initResponsive, destroyResponsive, clearCustomWidth } from './responsive';
import { cleanupAccessibility, reapplyAccessibility } from './accessibility';
import { loadTemplateChrome, cleanupTemplateChrome } from './template-chrome';

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
	if ( key === state.viewport ) {
		return;
	}

	const vp = viewportByKey( key );
	state.viewport = key;
	updatePills();

	applyDeviceSwitch( vp );

	if ( ! state.playing ) {
		setTimeout( () => freezeEditorAnimations(), 100 );
	}

	setTimeout( () => fitAllFrames( true ), 150 );
	syncZoomLabel();
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
	injectStrip( switchViewport, deactivate, togglePlay );
	hideToolbar();
	updateToggle();

	const currentViewport = getViewportForDeviceType( getEditorDeviceType() );
	state.viewport = currentViewport.key;
	updatePills();

	const onReady = () => {
		if ( ! state.active ) {
			hideLoadingOverlay();
			return;
		}

		freezeEditorAnimations();

		startHeightSync();
		initPanzoom();
		initResponsive();
		fitAllFrames( false );
		syncZoomLabel();
		reapplyAccessibility();
		loadTemplateChrome();
		showToolbar();
		hideLoadingOverlay();
	};
	waitForEditorReady( onReady, onReady );
}

function deactivate() {
	state.active = false;
	state.playing = false;

	const desktopVp = viewportByKey( 'Desktop' );
	applyDeviceSwitch( desktopVp );
	state.viewport = 'Desktop';

	document.body.classList.remove( 'wpi-canvas-mode-active' );
	hideLoadingOverlay();

	unfreezeEditorAnimations();
	cleanupEditorIframe();
	cleanupAccessibility();
	cleanupTemplateChrome();
	clearTimeout( refs.editorReadyTimer );
	refs.editorReadyTimer = null;
	if ( refs._editorReadyObserver ) {
		refs._editorReadyObserver.disconnect();
		refs._editorReadyObserver = null;
	}

	destroyResponsive();
	destroyPanzoom();
	removeStrip();
	resetCanvas();

	refs.contentEl?.classList.remove(
		'wpi-canvas-space-pan',
		'wpi-canvas-is-panning'
	);
	state.spaceHeld = false;
	refs.contentEl = null;
	refs.editorVisualEl = null;
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
