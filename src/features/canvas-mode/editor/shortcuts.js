import { state, refs, isTypingTarget } from './state';
import { fitAllFrames } from './canvas';

export function bindShortcuts( onToggle, onDeactivate, onTogglePlay ) {
	document.addEventListener( 'keydown', ( e ) => {
		if ( ( e.metaKey || e.ctrlKey ) && e.shiftKey && e.code === 'KeyM' ) {
			e.preventDefault();
			e.stopPropagation();
			onToggle();
			return;
		}

		if ( ! state.active ) {
			return;
		}

		if ( e.code === 'KeyP' && ! isTypingTarget( e.target ) &&
			! e.metaKey && ! e.ctrlKey && ! e.altKey && ! e.shiftKey ) {
			e.preventDefault();
			onTogglePlay?.();
			return;
		}

		if ( e.code === 'Space' && ! isTypingTarget( e.target ) ) {
			e.preventDefault();
			state.spaceHeld = true;
			refs.contentEl?.classList.add( 'wpi-canvas-space-pan' );
			return;
		}

		if ( e.code === 'Escape' ) {
			e.preventDefault();
			onDeactivate();
		}

		if ( ( e.metaKey || e.ctrlKey ) && e.code === 'Digit0' ) {
			e.preventDefault();
			fitAllFrames( true );
		}
	} );

	document.addEventListener( 'keyup', ( e ) => {
		if ( ! state.active ) {
			return;
		}
		if ( e.code === 'Space' ) {
			state.spaceHeld = false;
			refs.contentEl?.classList.remove( 'wpi-canvas-space-pan' );
		}
	} );
}
