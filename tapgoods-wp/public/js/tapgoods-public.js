(function( $, window, document, undefined ) {
	'use strict';

	$( document ).ready(function() {

		// Setup the search form interactivity
		if( $('#tg-search-form').length>0 ) {
			const searchForm = $('#tg-search-form');
			const searchInput = $('#tg-search');
			const suggestionList = $('#suggestion-list');
			let shouldBlur = false;
	
			searchForm.on( 'submit', { input: searchInput }, tapgoods_search);

			$('#tg-search').on( 'focus', function() { 
				suggestionList.parent('div').show();
				$('#tg-search').on( 'blur', function(e) {
					setTimeout( function() {
						if ( shouldBlur ) {
							suggestionList.parent('div').hide();
						}
					}, 200);
				});
				$(document).click(function(e) {
					if ($(e.target).is( suggestionList ) || $(e.target).is( searchInput )) {
						shouldBlur = false;
						return;
					}
					shouldBlur = true;
				});
			});

			let value = ''
			searchInput.keyup(
				delay( function (e) {
					let current = $(e.target).val();

					if ( '' === current ) {
						suggestionList.html( '' );
						return false;
					}

					if ( current.length < 3 || current === value ) {
						return false;
					}

					suggestionList.parent('div').show();
					let data = searchForm.serialize() + '&action=tapgoods_search';
	
					$.ajax({
						type: "POST",
						url: tg_ajax.ajaxurl,
						data: data,
						success: function( response ) {
							console.log( response );
							if ( '' !== response.data ) {
								const list = response.data;
								suggestionList.html( list );
								suggestionList.parent('div').removeAttr('hidden');
							}
						}
					});
				}, 300));
		}

		if ( $('#tg-per-page').length > 0 ) {
			$('#tg-per-page').on( 'change', function(e){
				let perPage = e.target.value;
				console.log( perPage );
				document.cookie = "tg-per-page="+perPage+";expires=0;domain="+tg_ajax.domain+";path=/"
				window.location.reload();
			});
		}

		if ( $('#tg_cart').length > 0 ) {
			const cartBtn = $('#tg_cart');
			const url = tg_ajax.cart_url;
			const cartCookie = ( tgGetCookie('tg_has_items') );
			
			if ( '1' === cartCookie ) {
				cartBtn.addClass( 'has-items' );
			}

			cartBtn.on( 'click', function(e) {
				console.log( 'cart click' );
				e.preventDefault();
				window.open( url, '_self' );
			});
		}

		if ( $('input.qty-input').length > 0 ) {
			$('input.qty-input').keyup(
				delay(
					function(e) {
						let value = e.target.value
						if ( isNaN( value ) ) {
							return;
						}

						let url = new URL( $(e.target).siblings('button').data('target') );
						let params = new URLSearchParams( url.search );
						params.set( 'quantity', value );
						url.search = params;
						$(e.target).siblings('button').attr('data-target', url);
					}
				)
			)
		}

		if ( $('button.add-cart').length > 0 ) {
			$('button.add-cart').on( 'click', function(e) {
				e.preventDefault();
				window.open( e.target.dataset.target, '_self' );
				document.cookie = "tg_has_items=1;expires=0;domain="+tg_ajax.domain+";path=/";
			});
		}

		if( $('#tg-carousel').length > 0 ) {
			// console.log('init carousel');
			const myCarouselElement = document.querySelector('#tg-carousel');
			const carousel = new bootstrap.Carousel( myCarouselElement );
			// console.log(carousel);

			$('.thumbnail-btn').each( function(){
				$(this).on( 'click', function(e) {
					carousel.to( $(this).data('cindex'));
				})
			});
		}

		function tapgoods_search( event ) {
			// event.preventDefault();
			// console.log( event );
		}

		function delay(fn, ms) {
			let timer = 0
			return function(...args) {
				clearTimeout(timer);
				timer = setTimeout(fn.bind(this, ...args), ms || 0);
			}
		}

		function tgGetCookie(name) {
			return (name = (document.cookie + ';').match(new RegExp(name + '=.*;'))) && name[0].split(/=|;/)[1];
		}
	});
})( jQuery, window, document );
