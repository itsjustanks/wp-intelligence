import { refs } from './state';

let _stageEl = null;
let _frameEl = null;
let _originalParent = null;
let _originalNextSibling = null;

export function initEditorFrame() {
	if ( _frameEl || ! refs.editorVisualEl ) {
		return;
	}

	_originalParent = refs.editorVisualEl.parentNode;
	_originalNextSibling = refs.editorVisualEl.nextSibling;

	_stageEl = document.createElement( 'div' );
	_stageEl.className = 'wpi-canvas-stage';

	_frameEl = document.createElement( 'div' );
	_frameEl.className = 'wpi-canvas-active-frame';

	_originalParent.insertBefore( _stageEl, refs.editorVisualEl );
	_stageEl.appendChild( _frameEl );
	_frameEl.appendChild( refs.editorVisualEl );
	refs.frameShellEl = _frameEl;
}

export function removeEditorFrame() {
	if ( refs.editorVisualEl && _originalParent ) {
		if ( _originalNextSibling ) {
			_originalParent.insertBefore( refs.editorVisualEl, _originalNextSibling );
		} else {
			_originalParent.appendChild( refs.editorVisualEl );
		}
	}

	_stageEl?.remove();
	_stageEl = null;
	_frameEl = null;
	refs.frameShellEl = null;
	_originalParent = null;
	_originalNextSibling = null;
}
