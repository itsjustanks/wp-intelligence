import { Button, Icon } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function TabBar( { tabs, activeTab, onTabChange } ) {
	return (
		<nav
			className="wpi-tabbar"
			aria-label={ __( 'Settings sections', 'wp-intelligence' ) }
		>
			<ul className="wpi-tabbar__list" role="tablist">
				{ tabs.map( ( tab ) => (
					<li key={ tab.id } className="wpi-tabbar__item">
						<Button
							id={ `wpi-tab-${ tab.id }` }
							className={ `wpi-tabbar__link ${
								activeTab === tab.id ? 'is-active' : ''
							}` }
							role="tab"
							aria-controls={ `wpi-panel-${ tab.id }` }
							aria-selected={ activeTab === tab.id }
							onClick={ () => onTabChange( tab.id ) }
						>
							<Icon icon={ tab.icon } size={ 16 } />
							<span>{ tab.label }</span>
						</Button>
					</li>
				) ) }
			</ul>
		</nav>
	);
}
