/**
 * Device type override for canvas mode.
 *
 * Goal:
 * - keep WordPress in Desktop mode so it never switches to iframe preview
 * - still toggle responsive sidebar controls when available
 *
 * Strategy:
 * - patch core/editor preview-device dispatchers to no-op while canvas mode is active
 * - click Nectar's responsive toolbar links when present, so its sidebar state updates
 * - also mirror the desired device type into block-editor settings for any controls
 *   that read the private deviceType setting directly
 */

import { state } from './state';

let _deviceTypeKey = null;
let _patchedDispatch = null;

const DEVICE_TO_NECTAR_INDEX = {
	Desktop: 0,
	Tablet: 1,
	Mobile: 2,
};

function findDeviceTypeKey() {
	if ( _deviceTypeKey ) {
		return _deviceTypeKey;
	}
	try {
		const data = window.wp?.data;
		if ( ! data ) {
			return null;
		}
		const settings = data.select( 'core/block-editor' ).getSettings();
		if ( ! settings ) {
			return null;
		}
		for ( const sym of Object.getOwnPropertySymbols( settings ) ) {
			if ( String( sym ) === 'Symbol(deviceTypeKey)' ) {
				_deviceTypeKey = sym;
				return sym;
			}
		}
	} catch ( e ) {} // eslint-disable-line no-empty
	return null;
}

function setBlockEditorDeviceType( deviceType ) {
	const data = window.wp?.data;
	if ( ! data ) {
		return;
	}
	const symKey = findDeviceTypeKey();
	if ( ! symKey ) {
		return;
	}
	data.dispatch( 'core/block-editor' ).updateSettings( {
		[ symKey ]: deviceType,
	} );
}

function patchEditorPreviewDispatch() {
	if ( _patchedDispatch ) {
		return;
	}
	const data = window.wp?.data;
	if ( ! data ) {
		return;
	}
	const dispatch = data.dispatch( 'core/editor' );
	if ( ! dispatch ) {
		return;
	}

	_patchedDispatch = {
		target: dispatch,
		setDeviceType: dispatch.setDeviceType,
		experimental: dispatch.__experimentalSetPreviewDeviceType,
	};

	if ( typeof dispatch.setDeviceType === 'function' ) {
		dispatch.setDeviceType = ( deviceType ) => {
			if ( state.active ) {
				return { type: 'WPI_BLOCKED_SET_DEVICE_TYPE', deviceType };
			}
			return _patchedDispatch.setDeviceType.call( dispatch, deviceType );
		};
	}

	if ( typeof dispatch.__experimentalSetPreviewDeviceType === 'function' ) {
		dispatch.__experimentalSetPreviewDeviceType = ( deviceType ) => {
			if ( state.active ) {
				return { type: 'WPI_BLOCKED_SET_PREVIEW_DEVICE_TYPE', deviceType };
			}
			return _patchedDispatch.experimental.call( dispatch, deviceType );
		};
	}
}

function unpatchEditorPreviewDispatch() {
	if ( ! _patchedDispatch ) {
		return;
	}
	const { target, setDeviceType, experimental } = _patchedDispatch;
	if ( typeof setDeviceType === 'function' ) {
		target.setDeviceType = setDeviceType;
	}
	if ( typeof experimental === 'function' ) {
		target.__experimentalSetPreviewDeviceType = experimental;
	}
	_patchedDispatch = null;
}

function clickNectarResponsiveDevice( deviceType ) {
	const wrapper = document.getElementById( 'nectar-responsive-device-toolbar__wrapper' );
	if ( ! wrapper ) {
		return false;
	}
	const links = wrapper.querySelectorAll( 'a' );
	const idx = DEVICE_TO_NECTAR_INDEX[ deviceType ];
	if ( idx === undefined || ! links[ idx ] ) {
		return false;
	}
	links[ idx ].click();
	return true;
}

export function setCanvasDeviceType( key ) {
	patchEditorPreviewDispatch();
	setBlockEditorDeviceType( key );
	clickNectarResponsiveDevice( key );
}

export function resetCanvasDeviceType() {
	setBlockEditorDeviceType( 'Desktop' );
	clickNectarResponsiveDevice( 'Desktop' );
	unpatchEditorPreviewDispatch();
}
