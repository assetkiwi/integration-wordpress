/**
 * AssetKiwi Asset — Gutenberg block.
 *
 * Vanilla JS, no JSX, no build step required.
 * Uses wp.element.createElement (aliased as `el`) throughout.
 */
( function ( blocks, element, blockEditor, components, i18n, apiFetch ) {
	'use strict';

	var el         = element.createElement;
	var __         = i18n.__;
	var useState   = element.useState;
	var useEffect  = element.useEffect;
	var Fragment   = element.Fragment;

	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps     = blockEditor.useBlockProps;
	var PanelBody         = components.PanelBody;
	var SelectControl     = components.SelectControl;
	var Button            = components.Button;
	var Placeholder       = components.Placeholder;
	var Spinner           = components.Spinner;
	var TextControl       = components.TextControl;

	var cfg = window.assetkiwiBlock || {};
	var t   = cfg.i18n || {};

	// -------------------------------------------------------------------------
	// Asset picker modal (reuses the native wp.media library)
	// -------------------------------------------------------------------------

	function openAssetPicker( onSelect ) {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}

		var frame = window.wp.media( {
			title:    t.selectAsset || 'Select Asset',
			multiple: false,
		} );

		frame.on( 'assetkiwi:insert', function ( assetData ) {
			onSelect( assetData );
		} );

		// Also respond to standard library selection (fallback for non-DAM images).
		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			if ( attachment.id ) {
				// Retrieve DAM UUID from attachment meta via REST.
				fetch( '/wp-json/wp/v2/media/' + attachment.id )
					.then( function ( r ) { return r.json(); } )
					.then( function ( media ) {
						var uuid = media.meta && media.meta._assetkiwi_uuid;
						if ( uuid ) {
							onSelect( {
								uuid:          uuid,
								url:           attachment.url,
								alt:           attachment.alt,
								width:         attachment.width,
								height:        attachment.height,
								mime_type:     attachment.mime,
								attachment_id: attachment.id,
								variants:      [],
							} );
						}
					} );
			}
		} );

		frame.open();
	}

	// -------------------------------------------------------------------------
	// Block
	// -------------------------------------------------------------------------

	blocks.registerBlockType( 'assetkiwi/asset', {
		title:       t.blockTitle       || 'AssetKiwi Asset',
		description: t.blockDescription || 'Embed an asset from asset.kiwi.',
		category:    'media',
		icon:        'format-image',
		supports: {
			html:    false,
			align:   [ 'left', 'center', 'right', 'wide', 'full' ],
		},

		attributes: {
			uuid:       { type: 'string', default: '' },
			variantName:{ type: 'string', default: '' },
			url:        { type: 'string', default: '' },
			alt:        { type: 'string', default: '' },
			width:      { type: 'integer', default: 0 },
			height:     { type: 'integer', default: 0 },
			caption:    { type: 'string', default: '' },
		},

		edit: function ( props ) {
			var attributes  = props.attributes;
			var setAttrs    = props.setAttributes;

			var blockProps  = useBlockProps();
			var assetState  = useState( null );
			var asset       = assetState[0];
			var setAsset    = assetState[1];
			var loadingState = useState( false );
			var loading     = loadingState[0];
			var setLoading  = loadingState[1];

			// Load asset data when UUID is already set (e.g. after reload).
			useEffect( function () {
				if ( ! attributes.uuid || asset ) {
					return;
				}

				setLoading( true );

				var formData = new FormData();
				formData.append( 'action', 'assetkiwi_block_get_asset' );
				formData.append( 'nonce', cfg.nonce );
				formData.append( 'uuid', attributes.uuid );

				fetch( cfg.ajaxUrl, { method: 'POST', body: formData } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( response ) {
						if ( response.success ) {
							setAsset( response.data );
						}
					} )
					.finally( function () { setLoading( false ); } );
			}, [ attributes.uuid ] );

			function onPickAsset() {
				openAssetPicker( function ( data ) {
					setAttrs( {
						uuid:        data.uuid || '',
						url:         data.url || '',
						alt:         data.alt || '',
						width:       data.width || 0,
						height:      data.height || 0,
						variantName: '',
					} );

					// Fetch full asset for variants.
					if ( data.uuid ) {
						var fd = new FormData();
						fd.append( 'action', 'assetkiwi_block_get_asset' );
						fd.append( 'nonce', cfg.nonce );
						fd.append( 'uuid', data.uuid );

						fetch( cfg.ajaxUrl, { method: 'POST', body: fd } )
							.then( function ( r ) { return r.json(); } )
							.then( function ( response ) {
								if ( response.success ) {
									setAsset( response.data );
								}
							} );
					}
				} );
			}

			// Derive display URL from chosen variant or fall back to original.
			var displayUrl = attributes.url;
			if ( attributes.variantName && asset && asset.variants ) {
				asset.variants.forEach( function ( v ) {
					if ( v.variant_name === attributes.variantName ) {
						displayUrl = v.url;
					}
				} );
			}

			// Inspector controls.
			var variantOptions = [ { value: '', label: t.original || 'Original' } ];
			if ( asset && asset.variants ) {
				asset.variants.forEach( function ( v ) {
					variantOptions.push( { value: v.variant_name, label: v.variant_name } );
				} );
			}

			var inspector = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: t.blockTitle || 'AssetKiwi Asset', initialOpen: true },
					el( Button, {
						isSecondary: true,
						onClick: onPickAsset,
					}, attributes.uuid ? ( t.changeAsset || 'Change Asset' ) : ( t.selectAsset || 'Select Asset' ) ),
					attributes.uuid && el( SelectControl, {
						label:    t.variant || 'Variant',
						value:    attributes.variantName,
						options:  variantOptions,
						onChange: function ( val ) {
							setAttrs( { variantName: val } );
						},
					} ),
					el( TextControl, {
						label:    t.altText || 'Alt text',
						value:    attributes.alt,
						onChange: function ( val ) { setAttrs( { alt: val } ); },
					} )
				)
			);

			// Block content.
			var content;

			if ( loading ) {
				content = el( Spinner );
			} else if ( ! attributes.uuid ) {
				content = el(
					Placeholder,
					{ icon: 'format-image', label: t.blockTitle || 'AssetKiwi Asset' },
					el( Button, { isPrimary: true, onClick: onPickAsset }, t.selectAsset || 'Select Asset' )
				);
			} else {
				content = el(
					'figure',
					blockProps,
					displayUrl && el( 'img', {
						src:    displayUrl,
						alt:    attributes.alt,
						width:  attributes.width  || undefined,
						height: attributes.height || undefined,
						style:  { maxWidth: '100%', height: 'auto' },
					} ),
					attributes.caption && el( 'figcaption', null, attributes.caption )
				);
			}

			return el( Fragment, null, inspector, content );
		},

		save: function ( props ) {
			var attributes = props.attributes;
			var blockProps = useBlockProps.save();

			var displayUrl = attributes.url;

			// On save we always use the stored URL; variant resolution happens server-side
			// if needed, or the URL was already resolved to the variant on select.

			if ( ! displayUrl ) {
				return null;
			}

			return el(
				'figure',
				blockProps,
				el( 'img', {
					src:                  displayUrl,
					alt:                  attributes.alt,
					width:                attributes.width  || undefined,
					height:               attributes.height || undefined,
					'data-assetkiwi-uuid': attributes.uuid,
					className:            attributes.uuid ? 'assetkiwi-asset' : undefined,
				} ),
				attributes.caption && el( 'figcaption', null, attributes.caption )
			);
		},
	} );

}(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n,
	window.wp.apiFetch
) );
