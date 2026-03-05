import { useSelect, useDispatch } from '@wordpress/data';
import {
	SelectControl,
	TextControl,
	TextareaControl,
	CheckboxControl,
	RangeControl,
	ColorIndicator,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useCallback } from '@wordpress/element';
import { STORE_NAME } from '../store';
import Card from '../components/card';

export default function FeaturedImageTab() {
	const { settings, isActive } = useSelect( ( select ) => {
		const store = select( STORE_NAME );
		return {
			settings: store.getSettings(),
			isActive: store.isModuleActive( 'featured_image_ai' ),
		};
	}, [] );

	const { updateNestedSetting } = useDispatch( STORE_NAME );

	const fia = settings.featured_image_ai || {};
	const imageStyle = fia.image_style || 'photo-realistic';
	const aspectRatio = fia.aspect_ratio || 'landscape';
	const brandColors = fia.brand_colors || '';
	const customInstructions = fia.custom_instructions || '';
	const overlayShowTitle = ( fia.overlay_show_title || '0' ) === '1';
	const overlayPosition = fia.overlay_position || 'bottom';
	const overlayBgColor = fia.overlay_bg_color || '#000000';
	const overlayTextColor = fia.overlay_text_color || '#ffffff';
	const overlayOpacity = parseInt( fia.overlay_opacity || '70', 10 );

	const updateFIA = useCallback(
		( key, value ) => updateNestedSetting( 'featured_image_ai', key, value ),
		[ updateNestedSetting ]
	);

	if ( ! isActive ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __( 'The Featured Image AI module is disabled. Enable it on the Modules tab.', 'wp-intelligence' ) }
			</Notice>
		);
	}

	return (
		<div className="wpi-featured-image-tab">
			<Card
				title={ __( 'Image Generation Defaults', 'wp-intelligence' ) }
				description={ __( 'These defaults apply when generating featured images. Authors can override per-post in the editor.', 'wp-intelligence' ) }
				icon="format-image"
			>
				<div className="wpi-form-fields">
					<SelectControl
						label={ __( 'Image Style', 'wp-intelligence' ) }
						value={ imageStyle }
						options={ [
							{ value: 'photo-realistic', label: __( 'Photo-realistic', 'wp-intelligence' ) },
							{ value: 'illustration', label: __( 'Illustration', 'wp-intelligence' ) },
							{ value: 'flat-design', label: __( 'Flat Design', 'wp-intelligence' ) },
							{ value: 'abstract', label: __( 'Abstract', 'wp-intelligence' ) },
							{ value: '3d-render', label: __( '3D Render', 'wp-intelligence' ) },
							{ value: 'watercolor', label: __( 'Watercolor', 'wp-intelligence' ) },
							{ value: 'minimal', label: __( 'Minimal', 'wp-intelligence' ) },
							{ value: 'cinematic', label: __( 'Cinematic', 'wp-intelligence' ) },
							{ value: 'digital-art', label: __( 'Digital Art', 'wp-intelligence' ) },
						] }
						onChange={ ( v ) => updateFIA( 'image_style', v ) }
						__nextHasNoMarginBottom
					/>

					<SelectControl
						label={ __( 'Aspect Ratio', 'wp-intelligence' ) }
						value={ aspectRatio }
						options={ [
							{ value: 'landscape', label: __( 'Landscape (1792x1024)', 'wp-intelligence' ) },
							{ value: 'portrait', label: __( 'Portrait (1024x1792)', 'wp-intelligence' ) },
							{ value: 'square', label: __( 'Square (1024x1024)', 'wp-intelligence' ) },
						] }
						onChange={ ( v ) => updateFIA( 'aspect_ratio', v ) }
						__nextHasNoMarginBottom
					/>

					<TextControl
						label={ __( 'Brand Colors', 'wp-intelligence' ) }
						value={ brandColors }
						onChange={ ( v ) => updateFIA( 'brand_colors', v ) }
						placeholder={ __( 'e.g. navy blue (#1a237e), gold (#ffd700), white', 'wp-intelligence' ) }
						help={ __( 'Brand colors the AI should incorporate into generated images.', 'wp-intelligence' ) }
						__nextHasNoMarginBottom
					/>

					<TextareaControl
						label={ __( 'Custom Instructions', 'wp-intelligence' ) }
						value={ customInstructions }
						onChange={ ( v ) => updateFIA( 'custom_instructions', v ) }
						rows={ 3 }
						placeholder={ __( 'e.g. Always include a subtle geometric pattern in the background.', 'wp-intelligence' ) }
						help={ __( 'Additional instructions included in every generation prompt.', 'wp-intelligence' ) }
						__nextHasNoMarginBottom
					/>
				</div>
			</Card>

			<Card title={ __( 'Title Overlay Preset', 'wp-intelligence' ) } icon="admin-customizer">
				<div className="wpi-form-fields">
					<CheckboxControl
						label={ __( 'Overlay the post title on the generated image', 'wp-intelligence' ) }
						help={ __( 'When enabled, the post title is rendered onto the image with a semi-transparent background band.', 'wp-intelligence' ) }
						checked={ overlayShowTitle }
						onChange={ ( v ) => updateFIA( 'overlay_show_title', v ? '1' : '0' ) }
						__nextHasNoMarginBottom
					/>

					<SelectControl
						label={ __( 'Title position', 'wp-intelligence' ) }
						value={ overlayPosition }
						options={ [
							{ value: 'bottom', label: __( 'Bottom', 'wp-intelligence' ) },
							{ value: 'center', label: __( 'Center', 'wp-intelligence' ) },
							{ value: 'top', label: __( 'Top', 'wp-intelligence' ) },
						] }
						onChange={ ( v ) => updateFIA( 'overlay_position', v ) }
						__nextHasNoMarginBottom
					/>

					<div className="wpi-color-field">
						<label>{ __( 'Overlay background', 'wp-intelligence' ) }</label>
						<div className="wpi-color-field__control">
							<input
								type="color"
								value={ overlayBgColor }
								onChange={ ( e ) => updateFIA( 'overlay_bg_color', e.target.value ) }
							/>
							<ColorIndicator colorValue={ overlayBgColor } />
							<span>{ overlayBgColor }</span>
						</div>
					</div>

					<div className="wpi-color-field">
						<label>{ __( 'Text color', 'wp-intelligence' ) }</label>
						<div className="wpi-color-field__control">
							<input
								type="color"
								value={ overlayTextColor }
								onChange={ ( e ) => updateFIA( 'overlay_text_color', e.target.value ) }
							/>
							<ColorIndicator colorValue={ overlayTextColor } />
							<span>{ overlayTextColor }</span>
						</div>
					</div>

					<RangeControl
						label={ __( 'Overlay opacity', 'wp-intelligence' ) }
						value={ overlayOpacity }
						onChange={ ( v ) => updateFIA( 'overlay_opacity', String( v ) ) }
						min={ 0 }
						max={ 100 }
						step={ 5 }
						help={ __( '0% = fully transparent, 100% = fully opaque.', 'wp-intelligence' ) }
						__nextHasNoMarginBottom
					/>
				</div>
			</Card>
		</div>
	);
}
