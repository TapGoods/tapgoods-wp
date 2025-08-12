/**
 * TapGoods Cart Initialization Script
 * Enqueued specifically when cart shortcode is rendered
 */

(function() {
    'use strict';
    
    console.log('TapGoods Cart Init: Script loaded');
    
    function initCartIcons() {
        const fullCartIcon = document.querySelector("#tg_cart .full-cart-icon");
        const emptyCartIcon = document.querySelector("#tg_cart .empty-cart-icon");
        const cartStatus = localStorage.getItem("cart");
        
        console.log('TapGoods Cart Init: initCartIcons - cart status:', cartStatus, 'fullCartIcon:', !!fullCartIcon, 'emptyCartIcon:', !!emptyCartIcon);
        
        if (fullCartIcon && emptyCartIcon) {
            if (cartStatus === "1") {
                fullCartIcon.style.display = "inline-block";
                emptyCartIcon.style.display = "none";
                console.log('TapGoods Cart Init: Showing full cart icon');
            } else {
                fullCartIcon.style.display = "none";
                emptyCartIcon.style.display = "inline-block";
                console.log('TapGoods Cart Init: Showing empty cart icon');
            }
            return true;
        }
        return false;
    }
    
    function initCartClick() {
        const cartButton = document.getElementById('tg_cart');
        const cartUrl = window.tapgoodsCartData ? window.tapgoodsCartData.cartUrl : '';
        
        console.log('TapGoods Cart Init: initCartClick - button:', !!cartButton, 'cartUrl:', cartUrl);
        
        if (cartButton && cartUrl) {
            cartButton.addEventListener('click', function(e) {
                e.preventDefault();
                console.log('TapGoods Cart Init: Button clicked, navigating to:', cartUrl);
                window.location.href = cartUrl;
            });
            return true;
        }
        return false;
    }
    
    function initLocationCookie() {
        const savedLocation = localStorage.getItem('tg_user_location');
        if (savedLocation) {
            document.cookie = `tg_user_location=${savedLocation}; path=/;`;
            console.log('TapGoods Cart Init: Set location cookie:', savedLocation);
        }
    }
    
    function attemptInitialization() {
        console.log('TapGoods Cart Init: Attempting initialization');
        
        const iconsInitialized = initCartIcons();
        const clickInitialized = initCartClick();
        initLocationCookie();
        
        if (iconsInitialized && clickInitialized) {
            console.log('TapGoods Cart Init: Successfully initialized');
            return true;
        }
        
        console.log('TapGoods Cart Init: Initialization incomplete, will retry');
        return false;
    }
    
    // Try immediate execution
    if (!attemptInitialization()) {
        // If immediate fails, try when DOM is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                console.log('TapGoods Cart Init: DOM ready attempt');
                if (!attemptInitialization()) {
                    // Final attempt with small delay for Elementor
                    setTimeout(function() {
                        console.log('TapGoods Cart Init: Delayed attempt for Elementor');
                        attemptInitialization();
                    }, 100);
                }
            });
        } else {
            // DOM already ready, try with small delay
            setTimeout(function() {
                console.log('TapGoods Cart Init: Delayed attempt');
                attemptInitialization();
            }, 100);
        }
    }
    
})();