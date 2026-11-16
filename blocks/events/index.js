/**
 * Upcoming Events block (editor side). Rendered on the server by
 * Outthink_Events_Display::render(), so the editor preview matches the front end.
 * Plain JS with WordPress globals; no build step.
 */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, RangeControl, CheckboxControl, Spinner } = wp.components;
	const { useSelect } = wp.data;
	const { __ } = wp.i18n;
	const ServerSideRender = wp.serverSideRender;

	function CategoryPicker( { selected, onChange } ) {
		const terms = useSelect(
			( select ) =>
				select( 'core' ).getEntityRecords( 'taxonomy', 'event-category', { per_page: -1, hide_empty: false } ),
			[]
		);

		if ( terms === null ) {
			return el( Spinner );
		}
		if ( ! terms.length ) {
			return el( 'p', null, __( 'No event categories yet.', 'outthink-events' ) );
		}

		return el(
			Fragment,
			null,
			el( 'p', { className: 'components-base-control__help' }, __( 'Leave all unchecked to show every category.', 'outthink-events' ) ),
			terms.map( ( term ) =>
				el( CheckboxControl, {
					key: term.id,
					label: term.name,
					checked: selected.includes( term.slug ),
					onChange: ( checked ) =>
						onChange( checked ? [ ...selected, term.slug ] : selected.filter( ( slug ) => slug !== term.slug ) ),
					__nextHasNoMarginBottom: true,
				} )
			)
		);
	}

	registerBlockType( 'outthink/events', {
		edit( { attributes, setAttributes } ) {
			const blockProps = useBlockProps();

			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Events', 'outthink-events' ) },
						el( RangeControl, {
							label: __( 'Number of events', 'outthink-events' ),
							help: __( '0 shows all upcoming events.', 'outthink-events' ),
							value: attributes.limit,
							min: 0,
							max: 50,
							onChange: ( value ) => setAttributes( { limit: value || 0 } ),
							__nextHasNoMarginBottom: true,
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Categories', 'outthink-events' ), initialOpen: attributes.category.length > 0 },
						el( CategoryPicker, {
							selected: attributes.category,
							onChange: ( category ) => setAttributes( { category } ),
						} )
					)
				),
				el( ServerSideRender, {
					block: 'outthink/events',
					attributes,
					skipBlockSupportAttributes: true,
				} )
			);
		},
		save: () => null,
	} );
} )( window.wp );
