import { createElement, useState } from '@wordpress/element';
import { registerPlugin } from '@wordpress/plugins';
import domReady from '@wordpress/dom-ready';
import { subscribe } from '@wordpress/data';

import {
	state, refs,
	getContentArea, getEditorVisual,
} from './state';
import {
	initZoom,
	destroyZoom,
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
	cleanupEditorIframe,
} from './editor-iframe';
import { initResponsive, destroyResponsive, clearCustomWidth, switchToViewport } from './responsive';
import { cleanupAccessibility, reapplyAccessibility } from './accessibility';
import { injectMetaboxTab, removeMetaboxTab } from './metabox-tab';
import { resetCanvasDeviceType } from './device-override';
import { injectFrameHeader, removeFrameHeader } from './frame-header';
import { initDimensions, destroyDimensions } from './dimensions';
import { initSpacingOverlay, destroySpacingOverlay } from './spacing-overlay';
import { initPreviewFrames, removePreviewFrames, syncPreviewFrames } from './preview-frames';

let _sidebarUnsubscribe = null;
let _lastSidebarBlockId = null;

function startSidebarSync() {
	stopSidebarSync();
	const data = window.wp?.data;
	if ( ! data ) {
		return;
	}
	_sidebarUnsubscribe = data.subscribe( () => {
		if ( ! state.active ) {
			return;
		}
		const selectedId = data.select( 'core/block-editor' ).getSelectedBlockClientId();
		if ( ! selectedId || selectedId === _lastSidebarBlockId ) {
			return;
		}
		_lastSidebarBlockId = selectedId;
		const editPost = data.dispatch( 'core/edit-post' );
		if ( editPost?.openGeneralSidebar ) {
			editPost.openGeneralSidebar( 'edit-post/block' );
		}
	} );
}

function stopSidebarSync() {
	if ( _sidebarUnsubscribe ) {
		_sidebarUnsubscribe();
		_sidebarUnsubscribe = null;
	}
	_lastSidebarBlockId = null;
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

function onViewportSwitch( key ) {
	switchToViewport( key );
	if ( ! state.playing ) {
		setTimeout( () => freezeEditorAnimations(), 100 );
	}
	setTimeout( () => {
		syncPreviewFrames( onViewportSwitch );
	}, 120 );
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
	injectStrip( onViewportSwitch, deactivate, togglePlay );
	hideToolbar();
	updateToggle();

	state.viewport = 'Desktop';
	updatePills();

	const onReady = () => {
		if ( ! state.active ) {
			hideLoadingOverlay();
			return;
		}

		freezeEditorAnimations();

		initZoom();
		initResponsive();
		state.viewport = '';
		switchToViewport( 'Desktop' );
		initPreviewFrames( onViewportSwitch );
		injectFrameHeader();
		fitAllFrames( false );
		syncZoomLabel();
		reapplyAccessibility();
		initDimensions();
		initSpacingOverlay();
		startSidebarSync();
		injectMetaboxTab();
		showToolbar();
		hideLoadingOverlay();
	};
	waitForEditorReady( onReady, onReady );
}

function deactivate() {
	state.active = false;
	state.playing = false;

	state.viewport = 'Desktop';
	resetCanvasDeviceType();

	document.body.classList.remove( 'wpi-canvas-mode-active' );
	hideLoadingOverlay();

	unfreezeEditorAnimations();
	cleanupEditorIframe();
	cleanupAccessibility();
	stopSidebarSync();
	removeMetaboxTab();
	clearTimeout( refs.editorReadyTimer );
	refs.editorReadyTimer = null;
	if ( refs._editorReadyObserver ) {
		refs._editorReadyObserver.disconnect();
		refs._editorReadyObserver = null;
	}

	destroyDimensions();
	destroySpacingOverlay();
	removeFrameHeader();
	removePreviewFrames();
	destroyResponsive();
	destroyZoom();
	removeStrip();
	resetCanvas();

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
