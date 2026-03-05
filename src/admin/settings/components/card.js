import { Icon } from '@wordpress/components';

export default function Card( { title, description, icon, children } ) {
	return (
		<section className="wpi-settings-card">
			{ title && (
				<div className="wpi-settings-card__header">
					<h2>
						{ icon && <Icon icon={ icon } size={ 20 } /> }
						{ title }
					</h2>
				</div>
			) }
			<div className="wpi-settings-card__body">
				{ description && (
					<p className="wpi-settings-card__description">{ description }</p>
				) }
				{ children }
			</div>
		</section>
	);
}
