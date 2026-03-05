import { useSelect } from '@wordpress/data';
import { Button, Icon } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';
import Card from '../components/card';

export default function WelcomeTab() {
	const config = window.wpiSettingsConfig || {};
	const { settings } = useSelect( ( select ) => ( {
		settings: select( STORE_NAME ).getSettings(),
	} ), [] );

	const hasApiKey = !! ( settings.api_key || config.hasNativeAI );
	const hasModulesConfigured = true;

	const steps = [
		{
			id: 'modules',
			title: __( 'Choose your modules', 'wp-intelligence' ),
			description: __( 'Enable only the features your site needs for a clean, lean setup.', 'wp-intelligence' ),
			done: hasModulesConfigured,
			action: __( 'Configure Modules', 'wp-intelligence' ),
			tab: 'modules',
			icon: 'admin-plugins',
		},
		{
			id: 'ai',
			title: __( 'Configure AI', 'wp-intelligence' ),
			description: __( 'Set your provider credentials and model once, then reuse them across all AI features.', 'wp-intelligence' ),
			done: hasApiKey,
			action: __( 'Open AI Settings', 'wp-intelligence' ),
			tab: 'ai',
			icon: 'cloud',
		},
		{
			id: 'content',
			title: __( 'Set up content workflows', 'wp-intelligence' ),
			description: __( 'Enable Content Intelligence and define post types for AI-assisted rewriting.', 'wp-intelligence' ),
			done: false,
			action: __( 'Review Content Intelligence', 'wp-intelligence' ),
			tab: 'syndication',
			icon: 'welcome-write-blog',
		},
	];

	const completedCount = steps.filter( ( s ) => s.done ).length;
	const progress = Math.round( ( completedCount / steps.length ) * 100 );

	function navigateToTab( tab ) {
		const url = new URL( window.location );
		url.searchParams.set( 'tab', tab );
		window.location.href = url.toString();
	}

	return (
		<div className="wpi-welcome-tab">
			<Card>
				<div className="wpi-welcome-hero">
					<div className="wpi-welcome-hero__content">
						<h2>{ __( 'Welcome to WP Intelligence', 'wp-intelligence' ) }</h2>
						<p>{ __( 'AI-powered composition, content intelligence, and site optimization — all in one modular toolkit.', 'wp-intelligence' ) }</p>
					</div>
					<div className="wpi-welcome-hero__progress">
						<div className="wpi-progress-ring" role="progressbar" aria-valuenow={ progress } aria-valuemin="0" aria-valuemax="100">
							<svg viewBox="0 0 36 36" className="wpi-progress-ring__svg">
								<path
									className="wpi-progress-ring__bg"
									d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
								/>
								<path
									className="wpi-progress-ring__fill"
									strokeDasharray={ `${ progress }, 100` }
									d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
								/>
							</svg>
							<span className="wpi-progress-ring__text">{ `${ completedCount }/${ steps.length }` }</span>
						</div>
					</div>
				</div>
			</Card>

			<Card title={ __( 'Getting Started', 'wp-intelligence' ) } icon="flag">
				<ol className="wpi-setup-steps">
					{ steps.map( ( step ) => (
						<li key={ step.id } className={ `wpi-setup-step ${ step.done ? 'is-done' : '' }` }>
							<div className="wpi-setup-step__icon">
								<Icon icon={ step.done ? 'yes-alt' : step.icon } size={ 24 } />
							</div>
							<div className="wpi-setup-step__content">
								<strong>{ step.title }</strong>
								<p>{ step.description }</p>
								<Button
									variant="secondary"
									size="compact"
									onClick={ () => navigateToTab( step.tab ) }
								>
									{ step.action }
								</Button>
							</div>
						</li>
					) ) }
				</ol>
			</Card>

			<Card title={ __( 'Quick Links', 'wp-intelligence' ) } icon="admin-links">
				<div className="wpi-quick-links">
					<a href="https://whole-code.github.io/wp-intelligence/" target="_blank" rel="noopener noreferrer" className="wpi-quick-link">
						<Icon icon="book" size={ 20 } />
						<span>{ __( 'Documentation', 'wp-intelligence' ) }</span>
					</a>
					<a href="https://github.com/whole-code/wp-intelligence/issues" target="_blank" rel="noopener noreferrer" className="wpi-quick-link">
						<Icon icon="editor-help" size={ 20 } />
						<span>{ __( 'Support & Issues', 'wp-intelligence' ) }</span>
					</a>
					<a href="https://github.com/whole-code/wp-intelligence" target="_blank" rel="noopener noreferrer" className="wpi-quick-link">
						<Icon icon="code-standards" size={ 20 } />
						<span>{ __( 'GitHub Repository', 'wp-intelligence' ) }</span>
					</a>
				</div>
			</Card>
		</div>
	);
}
