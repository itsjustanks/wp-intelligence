import { useSelect, useDispatch } from '@wordpress/data';
import { SnackbarList } from '@wordpress/components';
import { store as noticesStore } from '@wordpress/notices';

export default function Notices() {
	const notices = useSelect(
		( select ) =>
			select( noticesStore )
				.getNotices()
				.filter( ( n ) => n.type === 'snackbar' ),
		[]
	);
	const { removeNotice } = useDispatch( noticesStore );

	if ( ! notices.length ) {
		return null;
	}

	return (
		<div className="wpi-notices">
			<SnackbarList
				notices={ notices }
				onRemove={ removeNotice }
			/>
		</div>
	);
}
