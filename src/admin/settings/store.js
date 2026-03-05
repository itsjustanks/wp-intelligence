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
	*saveSettings() {
		const state = yield { type: 'GET_STATE' };
		yield actions.setSaving( true );
		try {
			const result = yield {
				type: 'API_FETCH',
				request: {
					path: '/wp-intelligence/v1/settings',
					method: 'POST',
					data: {
						settings: state.settings,
						modules: state.modules,
					},
				},
			};
			if ( result.settings ) {
				yield actions.setSettings( result.settings );
			}
			if ( result.modules ) {
				yield actions.setModules( result.modules );
			}
			yield actions.setDirty( false );
			yield {
				type: 'DISPATCH_NOTICE',
				notice: {
					status: 'success',
					content: 'Settings saved.',
					isDismissible: true,
				},
			};
		} catch ( error ) {
			yield {
				type: 'DISPATCH_NOTICE',
				notice: {
					status: 'error',
					content: error.message || 'Failed to save settings.',
					isDismissible: true,
				},
			};
		}
		yield actions.setSaving( false );
	},
	*fetchSettings() {
		try {
			const result = yield {
				type: 'API_FETCH',
				request: { path: '/wp-intelligence/v1/settings' },
			};
			if ( result.settings ) {
				yield actions.setSettings( result.settings );
			}
			if ( result.modules ) {
				yield actions.setModules( result.modules );
			}
			if ( result.module_registry ) {
				yield actions.setModuleRegistry( result.module_registry );
			}
			yield actions.setLoaded( true );
		} catch ( error ) {
			yield {
				type: 'DISPATCH_NOTICE',
				notice: {
					status: 'error',
					content: 'Failed to load settings.',
					isDismissible: true,
				},
			};
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

const controls = {
	API_FETCH( action ) {
		return apiFetch( action.request );
	},
	GET_STATE() {
		return store.__unstableOriginalGetState();
	},
	DISPATCH_NOTICE( action ) {
		const { dispatch } = require( '@wordpress/data' );
		dispatch( 'core/notices' ).createNotice(
			action.notice.status,
			action.notice.content,
			{
				type: 'snackbar',
				isDismissible: action.notice.isDismissible,
			}
		);
	},
};

const store = createReduxStore( STORE_NAME, {
	reducer,
	actions,
	selectors,
	controls,
} );

register( store );

export { STORE_NAME };
export default store;
