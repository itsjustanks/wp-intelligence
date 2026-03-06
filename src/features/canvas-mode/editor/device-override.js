/**
 * Canvas viewport state.
 *
 * This is intentionally visual-only. We mirror the active viewport on the body
 * so CSS or integrations can respond, but we do not call WordPress preview
 * device APIs here because those trigger iframe-based editor mode.
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

export function setCanvasDeviceType( key ) {
	setBodyDeviceClass( key );
}

export function resetCanvasDeviceType() {
	setBodyDeviceClass( 'Desktop' );
}
