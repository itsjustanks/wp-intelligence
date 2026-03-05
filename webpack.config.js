const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		settings: path.resolve( __dirname, 'src/admin/settings/index.js' ),
		'canvas-mode-editor': path.resolve( __dirname, 'src/features/canvas-mode/editor/index.js' ),
		'html-canvas-editor': path.resolve( __dirname, 'src/features/html-canvas/editor/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
};
