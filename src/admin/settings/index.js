import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import SettingsApp from './app';
import './store';

domReady( () => {
	const root = document.getElementById( 'wpi-settings-root' );
	if ( root ) {
		createRoot( root ).render( <SettingsApp /> );
	}
} );
