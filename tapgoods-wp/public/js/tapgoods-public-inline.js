/**
 * TapGoods Public Inline Scripts
 * 
 * Centralizado script handling para el frontend público
 */

// Location selector functionality
function initLocationSelector() {
    document.addEventListener('DOMContentLoaded', function () {
        console.log('TapGoods: Initializing location selector');
        const selectElement = document.getElementById('tg-location-select');
        
        if (!selectElement) {
            console.log('TapGoods: No location selector found');
            return; // Exit if no location selector
        }
        
        console.log('TapGoods: Location selector found', selectElement);

        // Priority: Cookie -> LocalStorage -> Default Option
        const getCookie = (name) => {
            const cookies = document.cookie.split('; ');
            for (let cookie of cookies) {
                const [key, value] = cookie.split('=');
                if (key === name) {
                    return value;
                }
            }
            return null;
        };

        const setCookie = (name, value, days = 30) => {
            const expires = new Date(Date.now() + days * 24 * 60 * 60 * 1000).toUTCString();
            document.cookie = `${name}=${value}; expires=${expires}; path=/`;
        };

        const getStoredLocation = () => {
            return getCookie('tg_user_location') || localStorage.getItem('tg_user_location');
        };

        const setStoredLocation = (value) => {
            setCookie('tg_user_location', value);
            localStorage.setItem('tg_user_location', value);
        };

        // Set initial value and save to cookie for consistency
        const storedLocation = getStoredLocation();
        console.log('TapGoods: Stored location:', storedLocation);
        console.log('TapGoods: Available tg_public_vars:', typeof tg_public_vars !== 'undefined' ? tg_public_vars : 'undefined');
        
        if (storedLocation && selectElement.querySelector(`option[value="${storedLocation}"]`)) {
            console.log('TapGoods: Setting stored location:', storedLocation);
            selectElement.value = storedLocation;
            // Make sure cookie is set for server-side reading
            setCookie('tg_user_location', storedLocation);
        } else if (typeof tg_public_vars !== 'undefined' && tg_public_vars.default_location) {
            console.log('TapGoods: Setting default location:', tg_public_vars.default_location);
            selectElement.value = tg_public_vars.default_location;
            // Save default location to localStorage and cookie
            setStoredLocation(tg_public_vars.default_location);
        }

        // Handle location change
        selectElement.addEventListener('change', function () {
            const selectedLocation = this.value;
            console.log('TapGoods: Location changed to:', selectedLocation);
            if (selectedLocation) {
                // Save to localStorage and cookie
                setStoredLocation(selectedLocation);
                console.log('TapGoods: Location saved, reloading page');
                
                // Reload to apply changes (like original system)
                location.reload();
            }
        });
    });
}

// Cart functionality
function initCartHandlers() {
    // Add to cart functionality would go here
    // This depends on your specific cart implementation
}

// Sign up form functionality
function initSignUpForm() {
    // Sign up form handlers would go here
    // This depends on your specific form implementation
}

// Initialize all public functionality
document.addEventListener('DOMContentLoaded', function() {
    initLocationSelector();
    initCartHandlers();
    initSignUpForm();
});