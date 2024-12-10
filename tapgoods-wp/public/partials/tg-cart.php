<?php

// get id location and url cart
global $post;
// Capture the location ID from the URL if present
$local_storage_location = isset($_GET['local_storage_location']) ? sanitize_text_field($_GET['local_storage_location']) : null;

// Retrieve the current location ID
$location_id = $local_storage_location ?: tg_get_wp_location_id();
//echo esc_html($location_id);


// Check if 'local_storage_location' is present in the URL
$local_storage_location = isset($_GET['local_storage_location']) ? sanitize_text_field($_GET['local_storage_location']) : null;

// Check the cookie 'tg_user_location'
$cookie_location = isset($_COOKIE['tg_user_location']) ? sanitize_text_field($_COOKIE['tg_user_location']) : null;
$location = $cookie_location ?: ($local_storage_location ?: tg_get_wp_location_id());



$url = tg_get_cart_url($location);


?>

<button id="tg_cart" data-target="<?php echo esc_url($url); ?>" class="tapgoods tg-cart-button btn btn-primary">
    <span class="icon full-cart-icon" style="display: none;">
        <!-- SVG full cart icon -->
        <svg width="30" height="23" viewBox="0 0 30 23" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path fill-rule="evenodd" clip-rule="evenodd" d="M10.0006 20.447C10.0299 19.0666 11.1727 17.9713 12.5531 18.0006L12.5798 18.0007C13.9311 18.0154 15.0146 19.1227 14.9998 20.474C15.0002 20.5003 15 20.5267 14.9995 20.5531C14.9701 21.9335 13.8273 23.0287 12.447 22.9994C11.0666 22.9701 9.97129 21.8274 10.0006 20.447ZM19.0013 20.4203C19.0453 19.0403 20.1996 17.9573 21.5797 18.0013H21.58C22.9312 18.0161 24.0146 19.1234 23.9999 20.4747C24.0002 20.5097 23.9998 20.5447 23.9987 20.5796C23.9547 21.9597 22.8004 23.0427 21.4204 22.9987C20.0403 22.9547 18.9573 21.8003 19.0013 20.4203Z" fill="#3F2A56"/>
            <path fill-rule="evenodd" clip-rule="evenodd" d="M7.03594 3.85529L6.07816 0.807775C5.94916 0.342272 5.53101 0.0147633 5.04672 0H0V2.20302H4.19946L8.5831 16.2289C8.71503 16.6871 9.13636 17.0021 9.61455 17H25.5651C26.0492 16.9947 26.4773 16.6856 26.6334 16.2289L29.9488 5.28725C30.1319 4.70693 29.8084 4.08852 29.2262 3.90597C29.1144 3.87089 28.9977 3.8538 28.8805 3.85529H7.03594ZM18 14.25C17.5858 14.25 17.25 13.9142 17.25 13.5V11.25H15C14.5858 11.25 14.25 10.9142 14.25 10.5C14.25 10.0858 14.5858 9.75 15 9.75H17.25V7.5C17.25 7.08579 17.5858 6.75 18 6.75C18.4142 6.75 18.75 7.08579 18.75 7.5V9.75H21C21.4142 9.75 21.75 10.0858 21.75 10.5C21.75 10.9142 21.4142 11.25 21 11.25H18.75V13.5C18.75 13.9142 18.4142 14.25 18 14.25Z" fill="#3F2A56"/>
        </svg>
    </span>
    <span class="icon empty-cart-icon" style="display: none;">
        <!-- SVG empty cart icon -->
        <svg width="30" height="23" viewBox="0 0 30 23" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path fill-rule="evenodd" clip-rule="evenodd" d="M10.0006 20.447C10.0299 19.0666 11.1727 17.9713 12.5531 18.0006L12.5798 18.0007C13.9311 18.0154 15.0146 19.1227 14.9998 20.474C15.0002 20.5003 15 20.5267 14.9995 20.5531C14.9701 21.9335 13.8273 23.0287 12.447 22.9994C11.0666 22.9701 9.97129 21.8274 10.0006 20.447ZM19.0013 20.4203C19.0453 19.0403 20.1996 17.9573 21.5797 18.0013H21.58C22.9312 18.0161 24.0146 19.1234 23.9999 20.4747C24.0002 20.5097 23.9998 20.5447 23.9987 20.5796C23.9547 21.9597 22.8004 23.0427 21.4204 22.9987C20.0403 22.9547 18.9573 21.8003 19.0013 20.4203Z" fill="#3F2A56"/>
            <path fill-rule="evenodd" clip-rule="evenodd" d="M6.07816 0.807775L7.03594 3.85529H28.8805C28.9977 3.8538 29.1144 3.87089 29.2262 3.90597C29.8084 4.08852 30.1319 4.70693 29.9488 5.28725L26.6334 16.2289C26.4773 16.6856 26.0492 16.9947 25.5651 17H9.61455C9.13636 17.0021 8.71503 16.6871 8.5831 16.2289L4.19946 2.20302H0V0H5.04672C5.53101 0.0147633 5.94916 0.342272 6.07816 0.807775ZM20.1191 12.7939C20.321 12.348 20.4219 11.7718 20.4219 11.0654V9.81543C20.4219 9.10579 20.3193 8.52962 20.1143 8.08691C19.9092 7.64421 19.6243 7.31869 19.2598 7.11035C18.8984 6.89876 18.4785 6.79297 18 6.79297C17.5215 6.79297 17.0999 6.89876 16.7354 7.11035C16.3708 7.31869 16.0876 7.64421 15.8857 8.08691C15.6839 8.52962 15.583 9.10579 15.583 9.81543V11.0654C15.583 11.7718 15.6839 12.348 15.8857 12.7939C16.0908 13.2399 16.3757 13.5687 16.7402 13.7803C17.1081 13.9919 17.5312 14.0977 18.0098 14.0977C18.4883 14.0977 18.9082 13.9919 19.2695 13.7803C19.6341 13.5687 19.9173 13.2399 20.1191 12.7939ZM18.8301 8.64844C18.9049 8.88281 18.9424 9.20345 18.9424 9.61035V11.2607C18.9424 11.6709 18.9049 11.9964 18.8301 12.2373C18.7585 12.4782 18.6527 12.6507 18.5127 12.7549C18.376 12.859 18.2083 12.9111 18.0098 12.9111C17.8112 12.9111 17.6403 12.859 17.4971 12.7549C17.3538 12.6507 17.2448 12.4782 17.1699 12.2373C17.0951 11.9964 17.0576 11.6709 17.0576 11.2607V9.61035C17.0576 9.20345 17.0934 8.88281 17.165 8.64844C17.2399 8.41081 17.3473 8.24154 17.4873 8.14062C17.6305 8.03646 17.8014 7.98438 18 7.98438C18.1986 7.98438 18.3678 8.03646 18.5078 8.14062C18.6478 8.24154 18.7552 8.41081 18.8301 8.64844Z" fill="#3F2A56"/>
        </svg>
    </span>
</button>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const fullCartIcon = document.querySelector(".full-cart-icon");
    const emptyCartIcon = document.querySelector(".empty-cart-icon");
    const cartStatus = localStorage.getItem("cart");

    const savedLocation = localStorage.getItem('tg_user_location');
    if (savedLocation) {
        document.cookie = `tg_user_location=${savedLocation}; path=/;`;
        console.log('Cookie tg_user_location set:', savedLocation);
    }


    
    // Check if cart is active (1) or empty (0)
    if (cartStatus === "1") {
        fullCartIcon.style.display = "inline-block";
        emptyCartIcon.style.display = "none";
    } else {
        fullCartIcon.style.display = "none";
        emptyCartIcon.style.display = "inline-block";
    }
});


const savedLocation = localStorage.getItem('tg_user_location');
console.log('Saved location:', savedLocation);

if (savedLocation) {
        // Enviar el valor al servidor mediante AJAX
        fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'tg_set_local_storage_location', // Acción definida en PHP
                local_storage_location: savedLocation,   // Valor de localStorage
            }),
        })
        .then((response) => response.json())
        .then((data) => {
            if (data.success) {
                // Mostrar el valor en el contenedor
                const output = document.getElementById('location-output');
                if (output) {
                    output.textContent = `Location ID: ${data.data}`;
                }
            } else {
                console.error('Error setting location:', data.data);
            }
        })
        .catch((error) => console.error('Error sending location to server:', error));
    }

</script>
