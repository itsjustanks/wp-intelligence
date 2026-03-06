/**
 * Native preview device switching for canvas mode.
 *
 * Desktop stays on the fast custom canvas. Tablet and Mobile intentionally
 * use WordPress's native preview mode so viewport-specific block controls work.
 */

const BODY_DEVICE_CLASSES = [
	'wpi-canvas-device-desktop',
	'wpi-canvas-device-tablet',
	'wpi-canvas-device-mobile',
];

function setBodyDeviceClass( key ) {
	BODY_DEVICE_CLASSES.forEach( ( cls ) => document.body.classList.remove( cls ) );
	document.body.classList.add( 'wpi-canvas-device-' + key.toLowerCase() );
}

function dispatchPreviewDevice( deviceType ) {
	const data = window.wp?.data;
	if ( ! data ) {
		return;
	}
	const editPost = data.dispatch( 'core/edit-post' );
	if ( editPost?.__experimentalSetPreviewDeviceType ) {
		editPost.__experimentalSetPreviewDeviceType( deviceType );
		return;
	}
	const editorDispatch = data.dispatch( 'core/editor' );
	if ( editorDispatch?.setDeviceType ) {
		editorDispatch.setDeviceType( deviceType );
	} else if ( editorDispatch?.__experimentalSetPreviewDeviceType ) {
		editorDispatch.__experimentalSetPreviewDeviceType( deviceType );
	}
}

export function setCanvasDeviceType( key ) {
	setBodyDeviceClass( key );
	dispatchPreviewDevice( key );
}

export function resetCanvasDeviceType() {
	setBodyDeviceClass( 'Desktop' );
	dispatchPreviewDevice( 'Desktop' );
}
