/**
 * Metabox Tab — adds a "Meta" tab to the editor sidebar alongside Page/Block.
 *
 * When canvas mode is active, the metaboxes region is hidden from its
 * normal position below the editor. This module moves metabox content
 * into a panel inside the sidebar, accessible via a third tab.
 */

let _tabEl = null;
let _panelEl = null;
let _metaboxSourceEl = null;
let _movedChildren = [];
let _clickCleanup = null;

function getTabBar() {
	return (
		document.querySelector( '.editor-sidebar__panel-tabs' ) ||
		document.querySelector( '.interface-complementary-area-header' )
	);
}

function getSidebarBody() {
	return document.querySelector( '.interface-complementary-area' );
}

function getMetaboxContainer() {
	return document.querySelector( '.edit-post-layout__metaboxes' );
}

function deactivateOtherTabs() {
	const tabBar = getTabBar();
	if ( ! tabBar ) {
		return;
	}
	tabBar.querySelectorAll( '[role="tab"]' ).forEach( ( tab ) => {
		tab.setAttribute( 'aria-selected', 'false' );
		tab.classList.remove( 'is-active' );
	} );
}

function hideOtherPanels() {
	const sidebar = getSidebarBody();
	if ( ! sidebar ) {
		return;
	}
	const panels = sidebar.querySelectorAll(
		'.editor-sidebar__panel-tab-content, [role="tabpanel"], .interface-complementary-area__fill'
	);
	panels.forEach( ( p ) => {
		if ( p !== _panelEl ) {
			p.style.display = 'none';
		}
	} );
}

function restoreOtherPanels() {
	const sidebar = getSidebarBody();
	if ( ! sidebar ) {
		return;
	}
	const panels = sidebar.querySelectorAll(
		'.editor-sidebar__panel-tab-content, [role="tabpanel"], .interface-complementary-area__fill'
	);
	panels.forEach( ( p ) => {
		p.style.display = '';
	} );
}

function moveMetaboxesIn() {
	if ( ! _panelEl || ! _metaboxSourceEl ) {
		return;
	}
	_movedChildren = Array.from( _metaboxSourceEl.children );
	_movedChildren.forEach( ( child ) => {
		_panelEl.appendChild( child );
	} );
}

function moveMetaboxesBack() {
	if ( ! _metaboxSourceEl || _movedChildren.length === 0 ) {
		return;
	}
	_movedChildren.forEach( ( child ) => {
		_metaboxSourceEl.appendChild( child );
	} );
	_movedChildren = [];
}

function showMetaPanel() {
	if ( ! _panelEl ) {
		return;
	}
	deactivateOtherTabs();
	hideOtherPanels();

	_tabEl?.setAttribute( 'aria-selected', 'true' );
	_tabEl?.classList.add( 'is-active' );

	moveMetaboxesIn();
	_panelEl.style.display = '';
}

function hideMetaPanel() {
	if ( _panelEl ) {
		_panelEl.style.display = 'none';
	}
	moveMetaboxesBack();

	_tabEl?.setAttribute( 'aria-selected', 'false' );
	_tabEl?.classList.remove( 'is-active' );
	restoreOtherPanels();
}

function onOtherTabClick( e ) {
	const tab = e.target.closest( '[role="tab"]' );
	if ( ! tab || tab === _tabEl ) {
		return;
	}
	hideMetaPanel();
}

export function injectMetaboxTab() {
	if ( _tabEl ) {
		return;
	}

	const tabBar = getTabBar();
	if ( ! tabBar ) {
		return;
	}

	_metaboxSourceEl = getMetaboxContainer();
	if ( ! _metaboxSourceEl || _metaboxSourceEl.children.length === 0 ) {
		return;
	}

	_tabEl = document.createElement( 'button' );
	_tabEl.className = 'components-tab-panel__tabs-item components-button wpi-metabox-tab';
	_tabEl.setAttribute( 'role', 'tab' );
	_tabEl.setAttribute( 'aria-selected', 'false' );
	_tabEl.setAttribute( 'type', 'button' );
	_tabEl.textContent = 'Meta';
	_tabEl.addEventListener( 'click', ( e ) => {
		e.preventDefault();
		const isActive = _tabEl.classList.contains( 'is-active' );
		if ( isActive ) {
			return;
		}
		showMetaPanel();
	} );

	const tabList = tabBar.querySelector( '[role="tablist"]' ) || tabBar;
	tabList.appendChild( _tabEl );

	const sidebar = getSidebarBody();
	if ( sidebar ) {
		_panelEl = document.createElement( 'div' );
		_panelEl.className = 'wpi-metabox-panel';
		_panelEl.setAttribute( 'role', 'tabpanel' );
		_panelEl.style.display = 'none';
		sidebar.appendChild( _panelEl );
	}

	tabBar.addEventListener( 'click', onOtherTabClick );
	_clickCleanup = () => tabBar.removeEventListener( 'click', onOtherTabClick );
}

export function removeMetaboxTab() {
	moveMetaboxesBack();
	restoreOtherPanels();

	if ( _clickCleanup ) {
		_clickCleanup();
		_clickCleanup = null;
	}

	if ( _tabEl ) {
		_tabEl.remove();
		_tabEl = null;
	}

	if ( _panelEl ) {
		_panelEl.remove();
		_panelEl = null;
	}

	_metaboxSourceEl = null;
	_movedChildren = [];
}
