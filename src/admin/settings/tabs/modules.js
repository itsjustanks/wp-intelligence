import { useState, useMemo } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { ToggleControl, SearchControl, Icon } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';
import Card from '../components/card';

export default function ModulesTab() {
	const [ search, setSearch ] = useState( '' );

	const { modules, moduleRegistry } = useSelect( ( select ) => {
		const store = select( STORE_NAME );
		return {
			modules: store.getModules(),
			moduleRegistry: store.getModuleRegistry(),
		};
	}, [] );

	const { toggleModule } = useDispatch( STORE_NAME );

	const grouped = useMemo( () => {
		const groups = {};
		for ( const [ id, config ] of Object.entries( moduleRegistry ) ) {
			const category = config.category || 'General';
			if ( ! groups[ category ] ) {
				groups[ category ] = [];
			}
			groups[ category ].push( { id, ...config } );
		}
		return Object.entries( groups ).sort( ( a, b ) => a[ 0 ].localeCompare( b[ 0 ] ) );
	}, [ moduleRegistry ] );

	const filterModules = ( moduleList ) => {
		if ( ! search.trim() ) {
			return moduleList;
		}
		const q = search.toLowerCase();
		return moduleList.filter(
			( m ) =>
				m.title.toLowerCase().includes( q ) ||
				m.description.toLowerCase().includes( q ) ||
				m.id.toLowerCase().includes( q )
		);
	};

	return (
		<Card
			title={ __( 'Feature Modules', 'wp-intelligence' ) }
			description={ __( 'Toggle features on or off. Disabled modules have zero runtime overhead.', 'wp-intelligence' ) }
			icon="admin-plugins"
		>
			<div className="wpi-modules-search">
				<SearchControl
					value={ search }
					onChange={ setSearch }
					placeholder={ __( 'Search modules…', 'wp-intelligence' ) }
				/>
			</div>

			{ grouped.map( ( [ category, categoryModules ] ) => {
				const filtered = filterModules( categoryModules );
				if ( ! filtered.length ) {
					return null;
				}
				return (
					<div key={ category } className="wpi-module-category">
						<h3 className="wpi-module-category__title">{ category }</h3>
						<div className="wpi-module-grid">
							{ filtered.map( ( mod ) => (
								<div
									key={ mod.id }
									className={ `wpi-module-card ${ mod.dependency_met === false ? 'is-disabled' : '' }` }
								>
									<div className="wpi-module-card__header">
										<Icon icon={ mod.icon || 'admin-generic' } size={ 20 } />
										<h4>{ mod.title }</h4>
									</div>
									<p className="wpi-module-card__description">{ mod.description }</p>
									{ mod.dependency_met === false ? (
										<p className="wpi-module-card__error">
											{ __( 'Required dependency not detected.', 'wp-intelligence' ) }
										</p>
									) : mod.is_forced ? (
										<div className="wpi-module-card__toggle">
											<ToggleControl
												checked={ true }
												disabled={ true }
												label={ __( 'Required by theme', 'wp-intelligence' ) }
												__nextHasNoMarginBottom
											/>
										</div>
									) : (
										<div className="wpi-module-card__toggle">
											<ToggleControl
												checked={ !! modules[ mod.id ] }
												onChange={ () => toggleModule( mod.id ) }
												label={ __( 'Enable', 'wp-intelligence' ) }
												__nextHasNoMarginBottom
											/>
										</div>
									) }
								</div>
							) ) }
						</div>
					</div>
				);
			} ) }
		</Card>
	);
}
