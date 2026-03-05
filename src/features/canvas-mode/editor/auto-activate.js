export function shouldAutoActivate() {
	const config = window.wpiCanvasModeConfig;
	if ( ! config?.defaultPostTypes?.length ) {
		return false;
	}
	try {
		const postType = window.wp.data.select( 'core/editor' ).getCurrentPostType();
		return postType ? config.defaultPostTypes.includes( postType ) : false;
	} catch {
		return false;
	}
}
