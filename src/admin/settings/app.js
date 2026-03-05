import { useEffect, useState, useCallback } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { Button, Spinner, SlotFillProvider, Popover } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from './store';
import Notices from './components/notices';
import TabBar from './components/sidebar';
import WelcomeTab from './tabs/welcome';
import ModulesTab from './tabs/modules';
import AITab from './tabs/ai';
import ComposerTab from './tabs/composer';
import ContentIntelligenceTab from './tabs/content-intelligence';
import FeaturedImageTab from './tabs/featured-image';
import VisibilityTab from './tabs/visibility';
import SecurityTab from './tabs/security';
import PerformanceTab from './tabs/performance';
import ResourceHintsTab from './tabs/resource-hints';
import WooCommerceTab from './tabs/woocommerce';
import CanvasModeTab from './tabs/canvas-mode';
import DynamicDataTab from './tabs/dynamic-data';
import './settings.css';

const TAB_COMPONENTS = {
	welcome: WelcomeTab,
	modules: ModulesTab,
	ai: AITab,
	ai_composer: ComposerTab,
	syndication: ContentIntelligenceTab,
	featured_image_ai: FeaturedImageTab,
	visibility: VisibilityTab,
	canvas_mode: CanvasModeTab,
	dynamic_data: DynamicDataTab,
	security: SecurityTab,
	performance: PerformanceTab,
	resource_hints: ResourceHintsTab,
	woocommerce: WooCommerceTab,
};

export default function SettingsApp() {
	const config = window.wpiSettingsConfig || {};
	const initialTab = config.currentTab || 'welcome';
	const [ activeTab, setActiveTab ] = useState( initialTab );

	const { fetchSettings, saveSettings } = useDispatch( STORE_NAME );
	const { hasLoaded, isSaving, isDirty, modules, moduleRegistry } = useSelect(
		( select ) => {
			const store = select( STORE_NAME );
			return {
				hasLoaded: store.hasLoaded(),
				isSaving: store.isSaving(),
				isDirty: store.isDirty(),
				modules: store.getModules(),
				moduleRegistry: store.getModuleRegistry(),
			};
		},
		[]
	);

	useEffect( () => {
		fetchSettings();
	}, [ fetchSettings ] );

	const handleSave = useCallback( () => {
		saveSettings();
	}, [ saveSettings ] );

	const handleTabChange = useCallback( ( tab ) => {
		setActiveTab( tab );
		const url = new URL( window.location );
		url.searchParams.set( 'tab', tab );
		window.history.replaceState( {}, '', url );
	}, [] );

	const tabs = buildTabs( modules, moduleRegistry );
	const TabComponent = TAB_COMPONENTS[ activeTab ];

	if ( ! hasLoaded ) {
		return (
			<div className="wpi-loading">
				<Spinner />
				<p>{ __( 'Loading settings…', 'wp-intelligence' ) }</p>
			</div>
		);
	}

	return (
		<SlotFillProvider>
			<div className="wpi-app">
				<header className="wpi-app-header">
					<div className="wpi-app-header__title">
						<h1>{ __( 'WP Intelligence', 'wp-intelligence' ) }</h1>
						<p>{ __( 'Modular AI and site optimization toolkit for WordPress.', 'wp-intelligence' ) }</p>
					</div>
					<div className="wpi-app-header__actions">
						<span className="wpi-version-pill">
							{ `v${ config.version || '0.2.0' }` }
						</span>
						{ activeTab !== 'welcome' && activeTab !== 'visibility' && activeTab !== 'dynamic_data' && (
							<Button
								variant="primary"
								isBusy={ isSaving }
								disabled={ isSaving || ! isDirty }
								onClick={ handleSave }
							>
								{ isSaving
									? __( 'Saving…', 'wp-intelligence' )
									: __( 'Save Changes', 'wp-intelligence' ) }
							</Button>
						) }
					</div>
				</header>

				<Notices />

				<div className="wpi-app-content">
					<TabBar
						tabs={ tabs }
						activeTab={ activeTab }
						onTabChange={ handleTabChange }
					/>
					<main
						className="wpi-app-main"
						role="tabpanel"
						id={ `wpi-panel-${ activeTab }` }
						aria-labelledby={ `wpi-tab-${ activeTab }` }
					>
						{ TabComponent ? (
							<TabComponent />
						) : (
							<p>{ __( 'Unknown tab.', 'wp-intelligence' ) }</p>
						) }
					</main>
				</div>
			</div>
			<Popover.Slot />
		</SlotFillProvider>
	);
}

function buildTabs( modules, registry ) {
	const tabs = [
		{ id: 'welcome', label: __( 'Welcome', 'wp-intelligence' ), icon: 'admin-home' },
		{ id: 'modules', label: __( 'Modules', 'wp-intelligence' ), icon: 'admin-plugins' },
		{ id: 'ai', label: __( 'AI Provider', 'wp-intelligence' ), icon: 'cloud' },
		{ id: 'ai_composer', label: __( 'Composer', 'wp-intelligence' ), icon: 'edit-page' },
		{ id: 'syndication', label: __( 'Content Intelligence', 'wp-intelligence' ), icon: 'welcome-write-blog' },
	];

	const conditionalTabs = [
		{ id: 'featured_image_ai', label: __( 'Featured Image AI', 'wp-intelligence' ), icon: 'format-image' },
		{ id: 'visibility', label: __( 'Visibility', 'wp-intelligence' ), icon: 'visibility' },
		{ id: 'canvas_mode', label: __( 'Canvas Mode', 'wp-intelligence' ), icon: 'grid-view' },
		{ id: 'dynamic_data', label: __( 'Dynamic Data', 'wp-intelligence' ), icon: 'database' },
		{ id: 'security', label: __( 'Security', 'wp-intelligence' ), icon: 'shield' },
		{ id: 'performance', label: __( 'Performance', 'wp-intelligence' ), icon: 'performance' },
		{ id: 'resource_hints', label: __( 'Resource Hints', 'wp-intelligence' ), icon: 'admin-links' },
		{ id: 'woocommerce', label: __( 'WooCommerce', 'wp-intelligence' ), icon: 'cart' },
	];

	for ( const tab of conditionalTabs ) {
		if ( modules[ tab.id ] || registry[ tab.id ] ) {
			tabs.push( tab );
		}
	}

	return tabs;
}
