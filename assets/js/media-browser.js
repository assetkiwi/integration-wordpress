/**
 * asset.kiwi media browser — injected as a custom tab into the WordPress
 * media modal. No build step: plain ES5-compatible JS using the wp.media API.
 */
( function ( $, wp, assetkiwiMedia ) {
	'use strict';

	if ( ! wp || ! wp.media ) {
		return;
	}

	// -------------------------------------------------------------------------
	// Custom Frame State
	// -------------------------------------------------------------------------

	var AssetKiwiBrowserState = wp.media.controller.State.extend( {
		defaults: {
			id:      'assetkiwi-browser',
			title:   assetkiwiMedia.i18n.tabLabel,
			toolbar: 'assetkiwi-select',
			menu:    'default',
			content: 'assetkiwi-browser',
			router:  false,
		},
	} );

	// -------------------------------------------------------------------------
	// Content region view
	// -------------------------------------------------------------------------

	var AssetKiwiBrowserView = wp.media.View.extend( {
		className: 'assetkiwi-browser-view',
		template:  wp.template( 'assetkiwi-browser' ),

		events: {
			'submit .assetkiwi-filter-form':      'onFilter',
			'click  .assetkiwi-pager__btn':       'onPage',
			'click  .assetkiwi-asset-card':       'onSelectCard',
			'click  .assetkiwi-asset-card__btn':  'onSelectAsset',
		},

		initialize: function () {
			this.params       = { page: 1 };
			this.selectedUuid = null;
			this.selectedData = null;
		},

		render: function () {
			this.$el.html( '<p class="assetkiwi-loading">' + assetkiwiMedia.i18n.loading + '</p>' );
			this.load( this.params );
			return this;
		},

		load: function ( params ) {
			var self = this;

			var data = $.extend( { action: 'assetkiwi_browse', nonce: assetkiwiMedia.nonce }, params );

			$.get( assetkiwiMedia.ajaxUrl, data )
				.done( function ( response ) {
					if ( response.success ) {
						self.$el.html( response.data.html );
						self.collections = response.data.collections;
						self.tags        = response.data.tags;
						self.pager       = response.data.pager;
					} else {
						self.$el.html( '<p class="assetkiwi-error">' + ( response.data || 'Error loading assets.' ) + '</p>' );
					}
				} )
				.fail( function () {
					self.$el.html( '<p class="assetkiwi-error">Failed to connect to asset.kiwi.</p>' );
				} );
		},

		onFilter: function ( e ) {
			e.preventDefault();

			var $form    = $( e.target );
			var params   = { page: 1 };
			var search   = $form.find( '[name="search"]' ).val();
			var col      = $form.find( '[name="collection"]' ).val();
			var tag      = $form.find( '[name="tag"]' ).val();
			var mimeType = $form.find( '[name="mime_type"]' ).val();

			if ( search )   { params.search    = search; }
			if ( col )      { params.collection = col; }
			if ( tag )      { params.tag        = tag; }
			if ( mimeType ) { params.mime_type  = mimeType; }

			this.params = params;
			this.load( this.params );
		},

		onPage: function ( e ) {
			e.preventDefault();
			var page = parseInt( $( e.currentTarget ).data( 'page' ), 10 );
			if ( page ) {
				this.params.page = page;
				this.load( this.params );
			}
		},

		onSelectCard: function ( e ) {
			this.$( '.assetkiwi-asset-card' ).removeClass( 'is-selected' );
			$( e.currentTarget ).addClass( 'is-selected' );
			this.selectedUuid = $( e.currentTarget ).data( 'uuid' );
		},

		onSelectAsset: function ( e ) {
			e.stopPropagation();
			var $card = $( e.currentTarget ).closest( '.assetkiwi-asset-card' );
			this.$( '.assetkiwi-asset-card' ).removeClass( 'is-selected' );
			$card.addClass( 'is-selected' );
			this.selectedUuid = $card.data( 'uuid' );
			this.insertSelected();
		},

		insertSelected: function () {
			var self = this;
			var uuid = this.selectedUuid;

			if ( ! uuid ) {
				return;
			}

			var data = new FormData();
			data.append( 'action', 'assetkiwi_select_asset' );
			data.append( 'nonce', assetkiwiMedia.nonce );
			data.append( 'uuid', uuid );

			fetch( assetkiwiMedia.ajaxUrl, { method: 'POST', body: data } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( response ) {
					if ( ! response.success ) {
						alert( response.data || 'Failed to select asset.' );
						return;
					}

					self.selectedData = response.data;

					// Trigger insert on the frame's toolbar.
					self.controller.trigger( 'assetkiwi:insert', response.data );
				} );
		},
	} );

	// -------------------------------------------------------------------------
	// Toolbar view
	// -------------------------------------------------------------------------

	var AssetKiwiSelectToolbar = wp.media.view.Toolbar.extend( {
		initialize: function () {
			this.options.items = {
				insert: {
					style:    'primary',
					text:     assetkiwiMedia.i18n.insert,
					priority: 80,
					click:    this.onInsert.bind( this ),
				},
			};
			wp.media.view.Toolbar.prototype.initialize.apply( this, arguments );
		},

		onInsert: function () {
			var content = this.controller.content.get();
			if ( content && content.insertSelected ) {
				content.insertSelected();
			}
		},
	} );

	// -------------------------------------------------------------------------
	// Extend wp.media.view.MediaFrame.Select to add our tab
	// -------------------------------------------------------------------------

	var originalOpen = wp.media.view.MediaFrame.Select.prototype.open;

	wp.media.view.MediaFrame.Select = wp.media.view.MediaFrame.Select.extend( {
		initialize: function () {
			wp.media.view.MediaFrame.Select.prototype.initialize.apply( this, arguments );

			this.states.add( new AssetKiwiBrowserState() );

			this.on( 'content:render:assetkiwi-browser', this.renderAssetKiwiContent, this );
			this.on( 'toolbar:render:assetkiwi-select', this.renderAssetKiwiToolbar, this );
			this.on( 'assetkiwi:insert', this.onAssetKiwiInsert, this );
		},

		renderAssetKiwiContent: function () {
			var view = new AssetKiwiBrowserView( { controller: this } );
			this.content.set( view );
		},

		renderAssetKiwiToolbar: function () {
			this.toolbar.set( new AssetKiwiSelectToolbar( { controller: this } ) );
		},

		/**
		 * Converts DAM asset data into a wp.media.model.Attachment-like selection
		 * and triggers the standard insert flow.
		 */
		onAssetKiwiInsert: function ( assetData ) {
			var self = this;

			// Build a minimal attachment object the frame can work with.
			var attachment = new wp.media.model.Attachment( {
				id:          assetData.attachment_id,
				url:         assetData.url,
				alt:         assetData.alt,
				caption:     assetData.caption,
				width:       assetData.width,
				height:      assetData.height,
				mime:        assetData.mime_type,
				filename:    assetData.filename,
				type:        assetData.mime_type ? assetData.mime_type.split( '/' )[0] : 'image',
				subtype:     assetData.mime_type ? assetData.mime_type.split( '/' )[1] : '',
				sizes: ( function () {
					var sizes = {};
					( assetData.variants || [] ).forEach( function ( v ) {
						sizes[ v.variant_name ] = {
							url:    v.url,
							width:  v.width,
							height: v.height,
						};
					} );
					return sizes;
				}() ),
			} );

			var selection = self.state( 'library' ).get( 'selection' );
			if ( selection ) {
				selection.reset( [ attachment ] );
				self.state( 'library' ).trigger( 'select' );
			}

			// Fallback: trigger the standard insert event.
			self.trigger( 'insert', selection || [ attachment ] ).close();
		},
	} );

	// -------------------------------------------------------------------------
	// Add asset.kiwi tab to the router (secondary nav inside the modal)
	// -------------------------------------------------------------------------

	$( document ).on( 'click', '.media-router .media-menu-item', function () {
		// Handled automatically by the state system above.
	} );

	// Inject the tab button when the media modal router renders.
	wp.media.view.MediaFrame.Select = wp.media.view.MediaFrame.Select.extend( {
		browseRouter: function ( routerView ) {
			if ( this.__proto__.__proto__.browseRouter ) {
				this.__proto__.__proto__.browseRouter.apply( this, arguments );
			}
			routerView.set( {
				'assetkiwi-browser': {
					text:     assetkiwiMedia.i18n.tabLabel,
					priority: 60,
				},
			} );
		},
	} );

}( jQuery, wp, window.assetkiwiMedia || {} ) );
