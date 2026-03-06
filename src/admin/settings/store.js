import { createReduxStore, register } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

const STORE_NAME = 'wpi/settings';

const DEFAULT_STATE = {
	settings: {},
	modules: {},
	moduleRegistry: {},
	isSaving: false,
	hasLoaded: false,
	isDirty: false,
};

const actions = {
	setSettings( settings ) {
		return { type: 'SET_SETTINGS', settings };
	},
	setModules( modules ) {
		return { type: 'SET_MODULES', modules };
	},
	setModuleRegistry( registry ) {
		return { type: 'SET_MODULE_REGISTRY', registry };
	},
	updateSetting( key, value ) {
		return { type: 'UPDATE_SETTING', key, value };
	},
	updateNestedSetting( group, key, value ) {
		return { type: 'UPDATE_NESTED_SETTING', group, key, value };
	},
	toggleModule( id ) {
		return { type: 'TOGGLE_MODULE', id };
	},
	setSaving( isSaving ) {
		return { type: 'SET_SAVING', isSaving };
	},
	setLoaded( hasLoaded ) {
		return { type: 'SET_LOADED', hasLoaded };
	},
	setDirty( isDirty ) {
		return { type: 'SET_DIRTY', isDirty };
	},
	saveSettings:
		() =>
		async ( { select, dispatch, registry } ) => {
			const settings = select.getSettings();
			const modules = select.getModules();
			dispatch.setSaving( true );
			try {
				const result = await apiFetch( {
					path: '/wp-intelligence/v1/settings',
					method: 'POST',
					data: { settings, modules },
				} );
				if ( result.settings ) {
					dispatch.setSettings( result.settings );
				}
				if ( result.modules ) {
					dispatch.setModules( result.modules );
				}
				dispatch.setDirty( false );
				registry
					.dispatch( 'core/notices' )
					.createNotice( 'success', 'Settings saved.', {
						type: 'snackbar',
						isDismissible: true,
					} );
			} catch ( error ) {
				registry
					.dispatch( 'core/notices' )
					.createNotice(
						'error',
						error.message || 'Failed to save settings.',
						{ type: 'snackbar', isDismissible: true }
					);
			}
			dispatch.setSaving( false );
		},
	fetchSettings:
		() =>
		async ( { dispatch, registry } ) => {
			try {
				const result = await apiFetch( {
					path: '/wp-intelligence/v1/settings',
				} );
				if ( result.settings ) {
					dispatch.setSettings( result.settings );
				}
				if ( result.modules ) {
					dispatch.setModules( result.modules );
				}
				if ( result.module_registry ) {
					dispatch.setModuleRegistry( result.module_registry );
				}
				dispatch.setLoaded( true );
			} catch ( error ) {
				registry
					.dispatch( 'core/notices' )
					.createNotice(
						'error',
						'Failed to load settings.',
						{ type: 'snackbar', isDismissible: true }
					);
			}
		},
};

function reducer( state = DEFAULT_STATE, action ) {
	switch ( action.type ) {
		case 'SET_SETTINGS':
			return { ...state, settings: action.settings };
		case 'SET_MODULES':
			return { ...state, modules: action.modules };
		case 'SET_MODULE_REGISTRY':
			return { ...state, moduleRegistry: action.registry };
		case 'UPDATE_SETTING':
			return {
				...state,
				isDirty: true,
				settings: { ...state.settings, [ action.key ]: action.value },
			};
		case 'UPDATE_NESTED_SETTING':
			return {
				...state,
				isDirty: true,
				settings: {
					...state.settings,
					[ action.group ]: {
						...( state.settings[ action.group ] || {} ),
						[ action.key ]: action.value,
					},
				},
			};
		case 'TOGGLE_MODULE':
			return {
				...state,
				isDirty: true,
				modules: {
					...state.modules,
					[ action.id ]: ! state.modules[ action.id ],
				},
			};
		case 'SET_SAVING':
			return { ...state, isSaving: action.isSaving };
		case 'SET_LOADED':
			return { ...state, hasLoaded: action.hasLoaded };
		case 'SET_DIRTY':
			return { ...state, isDirty: action.isDirty };
		default:
			return state;
	}
}

const selectors = {
	getSettings( state ) {
		return state.settings;
	},
	getSetting( state, key ) {
		return state.settings[ key ];
	},
	getNestedSetting( state, group, key ) {
		const g = state.settings[ group ];
		return g ? g[ key ] : undefined;
	},
	getModules( state ) {
		return state.modules;
	},
	isModuleActive( state, id ) {
		return !! state.modules[ id ];
	},
	getModuleRegistry( state ) {
		return state.moduleRegistry;
	},
	isSaving( state ) {
		return state.isSaving;
	},
	hasLoaded( state ) {
		return state.hasLoaded;
	},
	isDirty( state ) {
		return state.isDirty;
	},
};

const store = createReduxStore( STORE_NAME, {
	reducer,
	actions,
	selectors,
} );

register( store );

export { STORE_NAME };
export default store;
