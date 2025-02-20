(function($, window, document, undefined) {
    'use strict';

    $(document).ready(function() {

        // Ensure Bootstrap is loaded
        if (typeof bootstrap === 'undefined') {
            let script = document.createElement('script');
            script.src = "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js";
            script.onload = function() {
                setTimeout(() => {
                }, 100);
            };
            document.head.appendChild(script);
        } 
        

        // Setup the search form interactivity
        if ($('#tg-search-form').length > 0) {
            const searchForm = $('#tg-search-form');
            const searchInput = $('#tg-search');
            const suggestionList = $('#suggestion-list');
            let shouldBlur = false;

            searchForm.on('submit', { input: searchInput }, tapgoods_search);

            $('#tg-search').on('focus', function() { 
                suggestionList.parent('div').show();
                $('#tg-search').on('blur', function(e) {
                    setTimeout(function() {
                        if (shouldBlur) {
                            suggestionList.parent('div').hide();
                        }
                    }, 200);
                });
                $(document).click(function(e) {
                    if ($(e.target).is(suggestionList) || $(e.target).is(searchInput)) {
                        shouldBlur = false;
                        return;
                    }
                    shouldBlur = true;
                });
            });

            let value = '';
            searchInput.keyup(
                delay(function(e) {
                    let current = $(e.target).val();

                    if ('' === current) {
                        suggestionList.html('');
                        return false;
                    }

                    if (current.length < 3 || current === value) {
                        return false;
                    }

                    suggestionList.parent('div').show();
                    let data = searchForm.serialize() + '&action=tapgoods_search';

                    $.ajax({
                        type: "POST",
                        url: tg_ajax.ajaxurl,
                        data: data,
                        success: function(response) {
                            console.log(response);
                            if ('' !== response.data) {
                                const list = response.data;
                                suggestionList.html(list);
                                suggestionList.parent('div').removeAttr('hidden');
                            }
                        }
                    });
                }, 300));
        }

        document.addEventListener("DOMContentLoaded", function () {
            const perPageSelect = document.getElementById("tg-per-page");
        
            if (perPageSelect) {
                perPageSelect.addEventListener("change", function (e) {
                    const perPage = e.target.value;
                    document.cookie = `tg-per-page=${perPage};path=/;`;
                    console.log("Updated cookie:", document.cookie);
                    window.location.reload();
                });
            }
        });
        
        

        if ($('#tg-dates-selector').length > 0) {
            $('#tg-dates-selector input').on('change', function() {
                let startDateVal = $('input[name="eventStartDate"]').val().split('T')[0]; 
                let endDateVal = $('input[name="eventEndDate"]').val().split('T')[0]; 
                let startTimeVal = $('input[name="eventStartTime"]').val();
                let endTimeVal = $('input[name="eventEndTime"]').val();

                let eventStart = startDateVal + 'T' + startTimeVal;
                let eventEnd = endDateVal + 'T' + endTimeVal;

                document.cookie = "tg-eventStart=" + eventStart + ";expires=0;domain=" + tg_ajax.domain + ";path=/";
                document.cookie = "tg-eventEnd=" + eventEnd + ";expires=0;domain=" + tg_ajax.domain + ";path=/";
                update_cart_url();
            });
        }

        if ($('#tg_cart').length > 0) {
            const cartBtn = $('#tg_cart');
            
            cartBtn.on('click', function(e) {
                console.log('Cart button clicked');
                e.preventDefault();
                
                const url = $(this).data('target'); // Obtener la URL desde el data-target en el momento del clic
                console.log('Cart URL on click:', url);
    
                if (url && url !== '#') {
                    window.open(url, '_self');
                } else {
                    console.error('Cart URL is invalid:', url);
                }
            });
        }        

        if ($('input.qty-input').length > 0) {
            $('input.qty-input').keyup(
                delay(function(e) {
                    let value = e.target.value;
                    if (isNaN(value)) {
                        return;
                    }

                    // Get the base URL of the adjacent button
                    let button = $(e.target).siblings('button');
                    let url = button.data('target');

                    // Check if the URL is valid before continuing
                    if (!url || url === '#') {
                        console.error('Invalid URL in data-target:', url);
                        return;
                    }

                    try {
                        let urlObj = new URL(url, window.location.origin);
                        let params = new URLSearchParams(urlObj.search);
                        params.set('quantity', value);
                        urlObj.search = params.toString();

                        // Update the data-target attribute with the new URL
                        button.attr('data-target', urlObj.toString());
                    } catch (error) {
                        console.error('Error constructing URL:', error);
                    }
                }, 300)
            );
        }

        if ($('button.add-cart').length > 0) {
            $('button.add-cart').on('click', function(e) {
             //   e.preventDefault();
                const url = e.target.dataset.target;
                if (url && url !== '#') {
                    window.open(url, '_self');
                    document.cookie = "tg_has_items=1;expires=0;domain=" + tg_ajax.domain + ";path=/";
                } else {
                    console.error('Add to Cart URL is invalid:', url);
                }
            });
        }

        if ($('#tg-carousel').length > 0) {

            if (typeof bootstrap !== 'undefined') {
                const myCarouselElement = document.querySelector('#tg-carousel');
                const carousel = new bootstrap.Carousel(myCarouselElement);
            } else {
                let script = document.createElement('script');
                script.src = "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js";
            }

            
            $('.thumbnail-btn').each(function() {
                $(this).on('click', function(e) {
                    carousel.to($(this).data('cindex'));
                });
            });
        }

        function update_cart_url() {
            if ($('#tg_cart').length > 0) {
                let eventStart = tgGetCookie('tg-eventStart');
                let eventEnd = tgGetCookie('tg-eventEnd');
                let url = $('#tg_cart').data('target');
        
                if (!url || url === '#') {
                    console.error('Cart URL is invalid:', url);
                    return;
                }
        
                try {
                    let urlObj = new URL(url, window.location.origin);
                    let params = new URLSearchParams(urlObj.search);
        
                    // Date format validation
                    const datePattern = /^\d{4}-\d{2}-\d{2}$/;
                    if (datePattern.test(eventStart.split('T')[0]) && datePattern.test(eventEnd.split('T')[0])) {
                        params.set('eventStart', eventStart);
                        params.set('eventEnd', eventEnd);
                    } else {
                        console.warn('Invalid date format for eventStart or eventEnd');
                    }
        
                    urlObj.search = params.toString();
                    $('#tg_cart').attr('data-target', urlObj.toString());
                } catch (error) {
                    console.error('Error updating Cart URL:', error);
                }
            }
        }
        

        function tapgoods_search(event) {
            // event.preventDefault();
            // console.log(event);
        }

        function delay(fn, ms) {
            let timer = 0;
            return function(...args) {
                clearTimeout(timer);
                timer = setTimeout(fn.bind(this, ...args), ms || 0);
            }
        }

        function tgGetCookie(name) {
            let value = (name = (document.cookie + ';').match(new RegExp(name + '=.*;'))) && name[0].split(/=|;/)[1];
            return value === null ? false : value;
        }
    });
})(jQuery, window, document);
