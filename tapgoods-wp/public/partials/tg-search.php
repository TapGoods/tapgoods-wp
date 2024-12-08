<?php
global $wp;
// Check if the 'nos' attribute is set; default to false if not
$nos = isset($atts['nos']) ? filter_var($atts['nos'], FILTER_VALIDATE_BOOLEAN) : false;

// Define options for the "posts per page" dropdown
$posts_per_page_options = apply_filters(
    'tg_per_page_options',
    array('12', '24', '48') // Default options
);

// Get the 'tg-per-page' value from the cookie or fallback to the default option
$tg_per_page = (isset($_COOKIE['tg-per-page'])) 
    ? sanitize_text_field(wp_unslash($_COOKIE['tg-per-page'])) 
    : get_option('tg_per_page', '12');

// Sanitize category to remove any unwanted characters
$category = isset($atts['category']) ? preg_replace('/^(category=)?["“”]?|["“”]?$/', '', $atts['category']) : ''; 

// Get the default or selected location ID from the cookie or fallback
$location_id = isset($_COOKIE['tg_location_id']) ? sanitize_text_field($_COOKIE['tg_location_id']) : get_option('tg_default_location');

// Get the current URL
$current_url = home_url(add_query_arg(array(), $wp->request)); 

// Action hook before rendering the search form
do_action('tg_before_search_form');
?>

<!-- Search container -->
<div id="tg-search-container" class="container mb-5">
    <form id="tg-search-form">
        <!-- Search input -->
        <input type="hidden" name="post_type" value="tg_inventory">
        <input id="tg-search" class="form-control form-control-lg" name="s" type="text" placeholder="Search" aria-label=".form-control-lg example">

        <!-- Posts per page dropdown -->
        <?php if (!$nos): ?>
            <select id="tg-per-page" name="per-page" class="number-select">
                <?php foreach ($posts_per_page_options as $option) : ?>
                    <option value="<?php echo esc_attr($option); ?>" <?php echo ($option == $tg_per_page) ? 'selected' : ''; ?>>
                        <?php echo esc_html($option); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <!-- Hidden input for location ID -->
        <input type="hidden" name="tg_location_id" value="<?php echo esc_attr($location_id); ?>">

        <!-- Hidden inputs for category and tags -->
        <input type="hidden" name="category" value="<?php echo esc_attr($category); ?>"> <!-- Sanitized category -->
        <input type="hidden" name="tags" value="<?php echo esc_attr($atts['tags'] ?? ''); ?>">
        <input type="hidden" name="per_page_default" value="<?php echo esc_attr($atts['per_page_default'] ?? '12'); ?>">

    </form>

    <div id="tg-results-container" class="tapgoods-results row mt-4"></div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("tg-search");
    const resultsContainer = document.querySelector(".tapgoods.tapgoods-inventory.row.row-cols-lg-3.row-cols-md-1.row-cols-sm-1");

    // Path to the placeholder image
    const placeholderImage = "<?php echo esc_url(plugin_dir_url(__FILE__) . 'assets/img/placeholder.jpg'); ?>";

    // Categories, tags, and results per page from shortcode attributes
    const categories = "<?php echo esc_js($category); ?>";
    const tags = "<?php echo esc_js($atts['tags'] ?? ''); ?>";
    const perPage = "<?php echo esc_js($tg_per_page); ?>";
    const locationId = "<?php echo esc_js($location_id); ?>";
    const redirectUrl = "<?php echo esc_js($current_url); ?>";

    // Prevent "Enter" key from submitting the form
    searchInput.addEventListener("keydown", function (event) {
        if (event.key === "Enter") {
            event.preventDefault();
        }
    });

    // Fetch results when typing in the search input
    searchInput.addEventListener("input", function () {
        fetchResults(searchInput.value.trim());
    });

    // Fetch results from the server
    function fetchResults(query) {
        fetch("<?php echo esc_url(admin_url('admin-ajax.php')); ?>", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({
                action: "tg_search",
                s: query,
                tg_location_id: locationId,
                tg_tags: tags,
                tg_categories: categories,
                per_page_default: perPage,
            }),
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                console.error("Server error:", data.message);
                return;
            }
            updateGrid(data.data);
        })
        .catch(error => console.error("Fetch error:", error));
    }

    // Update the grid with fetched results
    function updateGrid(data) {
        resultsContainer.innerHTML = ""; // Clear current results

        if (!data || data.length === 0) {
            resultsContainer.innerHTML = "<p>No items found.</p>";
            return;
        }

        data.forEach(item => {
            const imgUrl = item.img_url || placeholderImage;

            resultsContainer.innerHTML += `
                <div id="tg-item-${item.tg_id}" class="col item" data-tgId="${item.tg_id}">
                    <div class="item-wrap">
                        <figure>
                            <a class="d-block" href="${item.url}">
                                <img src="${imgUrl}" alt="${item.title}" onerror="this.onerror=null;this.src='${placeholderImage}';">
                            </a>
                        </figure>
                        <div class="price mb-2">${item.price || ''}</div>
                        <a class="d-block item-name mb-2" href="${item.url}">
                            <strong>${item.title}</strong>
                        </a>
                        <div class="add-to-cart">
                            <input class="qty-input form-control round" type="text" placeholder="Qty" id="qty-${item.tg_id}">
                            <a href="#" class="btn btn-primary add-to-cart-btn" data-item-id="${item.tg_id}">Add</a>
                        </div>
                    </div>
                </div>`;
        });

        attachAddToCartListeners();
    }

    // Attach event listeners to dynamically handle "Add" buttons
    function attachAddToCartListeners() {
        const addButtons = document.querySelectorAll(".add-to-cart-btn");
        addButtons.forEach(button => {
            button.addEventListener("click", function (e) {
                e.preventDefault();
                const itemId = this.getAttribute("data-item-id");
                const qtyInput = document.getElementById(`qty-${itemId}`);
                const quantity = qtyInput && qtyInput.value ? qtyInput.value : 1;

                // Build the final URL dynamically
                const finalUrl = `https://kdzyxktugudogqpvlsnt.preprod.tapgoods.dev/addToCart?itemId=${itemId}&itemType=items&quantity=${quantity}&redirectUrl=${redirectUrl}`;

                // Redirect to the final URL
                window.location.href = finalUrl;
            });
        });
    }
});


</script>
