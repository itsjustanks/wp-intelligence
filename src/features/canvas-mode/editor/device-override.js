/**
 * Canvas device state helpers.
 *
 * dispatchPreviewDevice — sets WP native preview device type (Desktop/Tablet/Mobile).
 * resetCanvasDeviceType — cleanup on canvas deactivation.
 */

export function dispatchPreviewDevice( deviceType ) {
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

export function resetCanvasDeviceType() {
	dispatchPreviewDevice( 'Desktop' );
}
