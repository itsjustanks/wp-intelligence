/**
 * Template Chrome — renders the page's header/footer around the editor
 * content area inside the editor iframe, giving a true preview of the
 * final page layout.
 *
 * Fetches rendered HTML from the ai-composer/v1/template-chrome REST endpoint,
 * then injects read-only header/footer containers into the editor iframe.
 */

import { getEditorDoc, getEditorStylesWrapper } from './state';

const HEADER_ID = 'wpi-template-header';
const FOOTER_ID = 'wpi-template-footer';
const STYLES_ID = 'wpi-template-chrome-styles';

let _loaded = false;
let _chromeData = null;

function getPostId() {
	try {
		const data = window.wp?.data;
		if ( ! data ) {
			return 0;
		}
		return data.select( 'core/editor' ).getCurrentPostId() || 0;
	} catch ( e ) {
		return 0;
	}
}

async function fetchChrome( postId ) {
	const config = window.wpiCanvasModeConfig || {};
	const endpoint = config.templateChromeEndpoint;
	const nonce = config.templateChromeNonce;

	if ( ! endpoint || ! postId ) {
		return null;
	}

	try {
		const url = endpoint + '?post_id=' + postId;
		const resp = await fetch( url, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': nonce },
		} );

		if ( ! resp.ok ) {
			return null;
		}

		return await resp.json();
	} catch ( e ) {
		return null;
	}
}

function injectChromeStyles( doc ) {
	if ( ! doc?.head || ! _chromeData?.head_styles ) {
		return;
	}
	if ( doc.getElementById( STYLES_ID ) ) {
		return;
	}

	const container = doc.createElement( 'div' );
	container.id = STYLES_ID;
	container.innerHTML = _chromeData.head_styles;

	const links = container.querySelectorAll( 'link[rel="stylesheet"]' );
	const styles = container.querySelectorAll( 'style' );

	links.forEach( ( link ) => {
		const clone = doc.createElement( 'link' );
		clone.rel = 'stylesheet';
		clone.href = link.href;
		clone.media = link.media || 'all';
		doc.head.appendChild( clone );
	} );

	styles.forEach( ( style ) => {
		const clone = doc.createElement( 'style' );
		clone.textContent = style.textContent;
		doc.head.appendChild( clone );
	} );
}

function injectChrome( doc ) {
	if ( ! doc || ! _chromeData ) {
		return;
	}

	const wrapper = getEditorStylesWrapper( doc );
	if ( ! wrapper ) {
		return;
	}

	removeChrome( doc );

	injectChromeStyles( doc );

	if ( _chromeData.header ) {
		const headerEl = doc.createElement( 'div' );
		headerEl.id = HEADER_ID;
		headerEl.className = 'wpi-template-chrome wpi-template-chrome--header';
		headerEl.innerHTML = _chromeData.header;
		wrapper.insertBefore( headerEl, wrapper.firstChild );
	}

	if ( _chromeData.footer ) {
		const footerEl = doc.createElement( 'div' );
		footerEl.id = FOOTER_ID;
		footerEl.className = 'wpi-template-chrome wpi-template-chrome--footer';
		footerEl.innerHTML = _chromeData.footer;
		wrapper.appendChild( footerEl );
	}
}

function removeChrome( doc ) {
	if ( ! doc ) {
		return;
	}
	doc.getElementById( HEADER_ID )?.remove();
	doc.getElementById( FOOTER_ID )?.remove();
}

export async function loadTemplateChrome() {
	if ( _loaded ) {
		const doc = getEditorDoc();
		if ( doc && _chromeData ) {
			injectChrome( doc );
		}
		return;
	}

	const postId = getPostId();
	if ( ! postId ) {
		return;
	}

	_chromeData = await fetchChrome( postId );
	_loaded = true;

	if ( ! _chromeData ) {
		return;
	}

	const doc = getEditorDoc();
	if ( doc ) {
		injectChrome( doc );
	}
}

export function cleanupTemplateChrome() {
	const doc = getEditorDoc();
	removeChrome( doc );
}

export function isTemplateChromeLoaded() {
	return _loaded && _chromeData !== null;
}
