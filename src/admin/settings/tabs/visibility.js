import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function VisibilityTab() {
	const containerRef = useRef( null );

	useEffect( () => {
		if ( ! containerRef.current ) {
			return;
		}
		const existing = document.getElementById( 'block-visibility__plugin-settings' );
		if ( existing ) {
			containerRef.current.appendChild( existing );
			existing.style.display = '';
		}
	}, [] );

	return (
		<div className="wpi-visibility-tab">
			<div ref={ containerRef }>
				<div id="block-visibility__plugin-settings"></div>
			</div>
			<p className="wpi-visibility-note">
				{ __( 'Block Visibility settings are rendered by the Block Visibility module.', 'wp-intelligence' ) }
			</p>
		</div>
	);
}
