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
import { loadTemplateChrome, cleanupTemplateChrome } from './template-chrome';
import { injectMetaboxTab, removeMetaboxTab } from './metabox-tab';

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

	const currentViewport = getViewportForDeviceType( getEditorDeviceType() );
	state.viewport = currentViewport.key;
	updatePills();

	const onReady = () => {
		if ( ! state.active ) {
			hideLoadingOverlay();
			return;
		}

		freezeEditorAnimations();

		initZoom();
		initResponsive();
		fitAllFrames( false );
		syncZoomLabel();
		reapplyAccessibility();
		loadTemplateChrome();
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

	const desktopVp = viewportByKey( 'Desktop' );
	applyDeviceSwitch( desktopVp );
	state.viewport = 'Desktop';

	document.body.classList.remove( 'wpi-canvas-mode-active' );
	hideLoadingOverlay();

	unfreezeEditorAnimations();
	cleanupEditorIframe();
	cleanupAccessibility();
	cleanupTemplateChrome();
	stopSidebarSync();
	removeMetaboxTab();
	clearTimeout( refs.editorReadyTimer );
	refs.editorReadyTimer = null;
	if ( refs._editorReadyObserver ) {
		refs._editorReadyObserver.disconnect();
		refs._editorReadyObserver = null;
	}

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
