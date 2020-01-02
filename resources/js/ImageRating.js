/**
 * JavaScript for the ImageRating extension
 *
 * @file
 */
/* global mw, ImageRating */
window.ImageRating = {
	posted: 0,
	category_counter: 0,

	addCategory: function( page ) {
		if ( document.getElementById( 'category-' + page ).value && !ImageRating.posted ) {
			var category_text = document.getElementById( 'category-' + page ).value;
			ImageRating.posted = 1;

			( new mw.Api() ).postWithToken( 'csrf', {
				action: 'imagerating',
				format: 'json',
				pageId: page,
				categories: encodeURIComponent( category_text )
			} ).done( function( data ) {
				ImageRating.posted = 0;

				if ( data.imagerating.result === 'busy' ) {
					setTimeout( 'ImageRating.addCategory(' + page + ')', 200 );
					return 0;
				}

				// Inject new category box into section
				var categories = category_text.split( ',' );
				for ( var x = 0; x <= categories.length - 1; x++ ) {
					var category = categories[x].replace( /^\s*/, '' ).replace( /\s*$/, '' );

					// create new button and inject it
					var $el = $( '<div></div>' )
						.attr( 'id', 'new-' + ImageRating.category_counter )
						.addClass( 'category-button' )
						.hide()
						.html( mw.msg( 'imagerating-category', category ) );
					$el.insertBefore( '#image-categories-container-end-' + page );

					// Allow clicking of new button to go to category page
					$( '#new-' + ImageRating.category_counter ).on( 'click', function () {
						var title_to = mw.config.get( 'wgArticlePath' ).replace(
							'$1',
							mw.config.get( 'wgFormattedNamespaces' )[14] + ':' + mw.msg( 'imagerating-category', category )
						);
						window.location = title_to;
					} );

					// apply mouse events to the new button
					$el.on( {
						'mouseover': function () {
							$( this ).css( 'background-color', '#FFFCA9' );
						},
						'mouseout': function () {
							$( this ).css( 'background-color', '' );
						}
					} );

					$el.show( 2000 );

					ImageRating.category_counter++;
				}

				document.getElementById( 'category-' + page ).value = '';
			} );
		}
	},

	/**
	 * Detect when the Enter key was pressed by the user.
	 *
	 * @param {Event} e Event object
	 * @param {Number} page The page we're currently on
	 * @return {Boolean} False when enter was pressed, otherwise true
	 */
	detEnter: function( e, page ) {
		var keycode;
		if ( window.event ) {
			keycode = window.event.keyCode;
		} else if ( e ) {
			keycode = e.which;
		} else {
			return true;
		}

		if ( keycode == 13 ) {
			ImageRating.addCategory( page );
			return false;
		} else {
			return true;
		}
	}
};

$( function () {
	$( 'div.image-categories-add input[type="text"]' ).on( 'keypress', function ( event ) {
		ImageRating.detEnter( event, $( this ).attr( 'id' ).replace( /category-/, '' ) );
	} );

	$( 'div.image-categories-add input[type="button"]' ).on( 'click', function () {
		ImageRating.addCategory( $( this ).parent().parent().attr( 'id' ).replace( /image-categories-container-/, '' ) );
	} );
} );
