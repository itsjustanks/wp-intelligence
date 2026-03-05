import { Button, Icon } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function Sidebar( { tabs, activeTab, onTabChange } ) {
	return (
		<nav className="wpi-sidebar" role="tablist" aria-label={ __( 'Settings sections', 'wp-intelligence' ) }>
			<ul className="wpi-sidebar__list">
				{ tabs.map( ( tab ) => (
					<li key={ tab.id } className="wpi-sidebar__item">
						<Button
							className={ `wpi-sidebar__link ${ activeTab === tab.id ? 'is-active' : '' }` }
							role="tab"
							aria-selected={ activeTab === tab.id }
							onClick={ () => onTabChange( tab.id ) }
						>
							<Icon icon={ tab.icon } size={ 20 } />
							<span>{ tab.label }</span>
						</Button>
					</li>
				) ) }
			</ul>
		</nav>
	);
}
