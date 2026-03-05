import { registerBlockType } from '@wordpress/blocks';
import Edit from './edit';
import './editor.css';

registerBlockType( 'wpi/html-canvas', {
	edit: Edit,
	save: () => null,
} );
