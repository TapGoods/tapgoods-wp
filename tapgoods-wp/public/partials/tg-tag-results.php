<?php
$plugin_url = str_replace('/partials', '', plugin_dir_url(__FILE__)) . 'css/';
global $wp;

// Get the tag from url
$request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
$segments = explode('/', $request_uri);
$tag = '';

if (count($segments) > 1 && $segments[0] === 'tags') {
    $tag = sanitize_text_field($segments[1]);
}

// Generate shortcode with tag from url
$shortcode = '[tapgoods-inventory per_page_default="14"';
if (!empty($tag)) {
    $shortcode .= ' tags="' . esc_attr($tag) . '"';
}
$shortcode .= ']';
?>

<!-- ✅ JavaScript will dynamically load CSS files -->
<script>
document.addEventListener("DOMContentLoaded", function () {
    function loadCSS(href) {
        const link = document.createElement("link");
        link.rel = "stylesheet";
        link.href = href;
        link.type = "text/css";
        link.media = "all";
        document.head.appendChild(link);
    }

    // ✅ Dynamically load styles to avoid WordPress enqueue restrictions
    loadCSS("<?php echo esc_url($plugin_url); ?>tapgoods-public.css?ver=0.1.0");
    loadCSS("<?php echo esc_url($plugin_url); ?>tapgoods-custom.css?ver=0.1.0");
    loadCSS("<?php echo esc_url($plugin_url); ?>global-styles.css?ver=0.1.0");
});
</script>

<br />
<div id="tg-shop" class="tapgoods tapgoods-inventory container-fluid">
    <div class="container shop">
        <div class="row align-items-start">
            <section class="col-sm-12 col-xs-12" id="tg-inventory-grid-container">
                <div id="tg-inventory-grid">
                    <?php echo do_shortcode($shortcode); ?>
                </div>
            </section>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    function handleAddToCart(event) {
        event.preventDefault();

        const button = event.currentTarget;
        const itemId = button.getAttribute("data-item-id");
        const container = button.closest(".tapgoods-inventory");
        const qtyInput = container.querySelector(`#qty-${itemId}`);

        if (!qtyInput) {
            alert("Quantity input field is missing.");
            return;
        }

        const quantityValue = qtyInput.value.trim();
        if (!quantityValue || isNaN(quantityValue) || parseInt(quantityValue, 10) <= 0) {
            alert("Please enter a valid quantity.");
            return;
        }

        const quantity = parseInt(quantityValue, 10);

        // 🔥 Ensure the correct redirect URL
        const redirectUrl = encodeURIComponent(window.location.href);
        const addToCartUrl = `https://hooli2.preprod.tapgoods.dev/addToCart?itemId=${itemId}&itemType=items&quantity=${quantity}&redirectUrl=${redirectUrl}`;

        console.log("Fetching:", addToCartUrl); // Debugging: Check if the URL is correct

        fetch(addToCartUrl, { 
            method: "GET", 
            credentials: "include", 
            mode: "cors"
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`Error ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            console.log("Added to cart:", data);
            button.textContent = "Added";
            button.style.setProperty("background-color", "green", "important");
            button.disabled = true;
        })
        .catch(error => console.error("Fetch error:", error));
    }

    // ✅ Ensure event listeners are not duplicated
    document.querySelectorAll(".add-cart").forEach(button => {
        button.removeEventListener("click", handleAddToCart); // Prevent duplicate event bindings
        button.addEventListener("click", handleAddToCart);
    });
});
</script>
