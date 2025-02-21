<?php
global $wp;
// Check if the 'nos' attribute is set; default to false if not
$nos = isset($atts['nos']) ? filter_var($atts['nos'], FILTER_VALIDATE_BOOLEAN) : false;

// Define options for the "posts per page" dropdown
$posts_per_page_options = apply_filters(
    'tg_per_page_options',
    array('12', '24', '48') // Default options
);

// Get the 'per_page_default' attribute or set it to '12' if not present
$tg_per_page = isset($atts['per_page_default']) 
    ? (int) preg_replace('/[^0-9]/', '', trim($atts['per_page_default'], '“”"')) // Clean the value by removing non-numeric characters and quotes, then convert to integer
    : 12; // Default value is 12 if the attribute is not set



// Check if 'local_storage_location' is present in the URL
$local_storage_location = isset( $_GET['local_storage_location'] ) ? sanitize_text_field( wp_unslash( $_GET['local_storage_location'] ) ) : null;

// Check the cookie 'tg_user_location'
$cookie_location = isset( $_COOKIE['tg_user_location'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['tg_user_location'] ) ) : null;
$location_id = $cookie_location ?: ($local_storage_location ?: tg_get_wp_location_id());

// Get the value of show_pricing from the shortcode attributes

$show_pricing = true; // default value

if (isset($atts['show_pricing'])) {
    $cleaned_value = strtolower($atts['show_pricing']);
    $show_pricing = (strpos($cleaned_value, 'false') !== false) ? false : true;
}


// Get the base URL for adding items to the cart
$base_url = tg_get_add_to_cart_url( $location_id );

// Sanitize category to remove any unwanted characters
$category = isset($atts['category']) ? preg_replace('/^(category=)?["“”]?|["“”]?$/', '', $atts['category']) : ''; 

$tags_from_url = isset($_GET['tg_tags']) ? sanitize_text_field(wp_unslash($_GET['tg_tags'])) : '';
$tags_from_atts = isset($atts['tags']) ? sanitize_text_field($atts['tags']) : '';


$tags = !empty($tags_from_url) ? $tags_from_url : $tags_from_atts;


// Get the current URL
$current_url = trailingslashit(home_url($wp->request, 'raw'));

if (!empty($_SERVER['QUERY_STRING'])) {
    $query_string = isset($_SERVER['QUERY_STRING']) ? sanitize_text_field(wp_unslash($_SERVER['QUERY_STRING'])) : '';
    $query_string = sanitize_text_field($query_string); // 
    $current_url .= '?' . $query_string;
}


// Action hook before rendering the search form
do_action('tg_before_search_form');
?>



<!-- Search container -->
<div id="tg-search-container" class="container mb-5">
    <form id="tg-search-form">
        <input type="hidden" name="post_type" value="tg_inventory">
        <input id="tg-search" class="form-control form-control-lg" name="s" type="text" placeholder="Search" aria-label="Search">

        <?php if (!$nos): ?>
            <select id="tg-per-page" name="per-page" class="number-select">
                <?php foreach ($posts_per_page_options as $option) : ?>
                    <option value="<?php echo esc_attr($option); ?>" <?php echo ($option == $tg_per_page) ? 'selected' : ''; ?>>
                        <?php echo esc_html($option); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <input type="hidden" name="tg_location_id" value="<?php echo esc_attr($location_id); ?>">
        <input type="hidden" name="category" value="<?php echo esc_attr($category); ?>">
        <input type="hidden" name="tags" value="<?php echo esc_attr($atts['tags'] ?? ''); ?>">
        <input type="hidden" name="per_page_default" value="<?php echo esc_attr($tg_per_page); ?>">
    </form>

    <div id="tg-results-container" class="tapgoods-results row mt-4"></div>
    <div id="tg-pagination-container" class="pagination-container mt-4"></div>
</div>

<script>
    
const showPricing = <?php echo json_encode((bool) $show_pricing); ?>;

document.addEventListener("DOMContentLoaded", function () {
    window.locationId = "<?php echo esc_js($location_id); ?>";
    const searchInput = document.getElementById("tg-search");
    const resultsContainer = document.querySelector(".tapgoods.tapgoods-inventory.row.row-cols-lg-3.row-cols-md-2.row-cols-sm-2");
    const paginationContainer = document.querySelector(".pagination.justify-content-center.align-items-center");
    const placeholderImage = "<?php echo esc_url(plugin_dir_url(__FILE__) . 'assets/img/placeholder.png'); ?>";
    const categories = "<?php echo esc_js($category); ?>";
    const tags = "<?php echo esc_js($tags); ?>";
    const perPage = "<?php echo esc_js($tg_per_page); ?>";
    const locationId = "<?php echo esc_js($location_id); ?>";
    const redirectUrl = "<?php echo esc_js($current_url); ?>";
    const baseurl = "<?php echo esc_js($base_url); ?>";

    window.fetchResults = function(query, page = 1, isDefault = false) {
        const params = new URLSearchParams({
            action: "tg_search",
            s: query,
            tg_location_id: window.locationId, // Usar el locationId global
            tg_tags: tags,
            tg_categories: categories,
            per_page_default: perPage,
            paged: page,
            default: isDefault ? "true" : "false",
        });

        fetch("<?php echo esc_url(admin_url('admin-ajax.php')); ?>", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: params,
        })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    console.error("Server error:", data.message);
                    return;
                }
                updateGrid(data.data.results);
                updatePagination(data.data.total_pages, data.data.current_page);
            })
            .catch(error => console.error("Fetch error:", error));
    };

    // Fetch image URL using item ID
    function fetchImage(itemId) {
        return fetch("<?php echo esc_url(admin_url('admin-ajax.php')); ?>", {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded",
            },
            body: new URLSearchParams({
                action: "get_image_by_item_id",
                item_id: itemId,
            }),
        })
            .then((response) => response.json())
            .then((data) => {
                if (!data.success) {
                    return placeholderImage;
                }
                return data.data.image_url;
            })
            .catch((error) => {
                return placeholderImage;
            });
    }

    // Prevent "Enter" from submitting the form
    searchInput.addEventListener("keydown", function (event) {
        if (event.key === "Enter") {
            event.preventDefault();
        }
    });

    // Fetch search results dynamically
    searchInput.addEventListener("input", function () {
        const query = searchInput.value.trim();
        if (query.length === 0) {
            fetchResults(null, 1, true); // Fetch default results
        } else {
            fetchResults(query, 1, false); // Fetch filtered results
        }
    });

    // Fetch results from the backend
    function fetchResults(query, page = 1, isDefault = false) {
        const params = new URLSearchParams({
            action: "tg_search",
            s: query,
            tg_location_id: locationId,
            tg_tags: tags,
            tg_categories: categories,
            per_page_default: perPage,
            paged: page,
            default: isDefault ? "true" : "false",
        });

        fetch("<?php echo esc_url(admin_url('admin-ajax.php')); ?>", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: params,
        })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    console.error("Server error:", data.message);
                    return;
                }
                updateGrid(data.data.results);
                updatePagination(data.data.total_pages, data.data.current_page);
            })
            .catch(error => console.error("Fetch error:", error));
    }

    // Update the results grid
    function updateGrid(data) {
    resultsContainer.innerHTML = ""; // Clear previous results
    if (!data || data.length === 0) {
        resultsContainer.innerHTML = "<p>No items found.</p>";
        return;
    }

    const seenIds = new Set(); // To avoid duplicates

    data.forEach((item) => {
        if (seenIds.has(item.tg_id)) {
            return;
        }
        seenIds.add(item.tg_id);
        const showPricing = <?php echo json_encode((bool) $show_pricing); ?>;
        console.log(showPricing);
        let priceHtml = '';
        if (showPricing==true){
            priceHtml = `<div class="price mb-2">${item.price || ''}</div>`;
            
        }

        const placeholder = placeholderImage; // Default placeholder image
        const itemUrl = !showPricing 
        ? `${item.url}${item.url.includes('?') ? '&' : '?'}nprice=true` 
        : item.url;

        const addToCartUrl = `${baseurl}?itemId=${item.tg_id}&itemType=items&quantity=1&redirectUrl=${encodeURIComponent(redirectUrl)}`;
        // Render the HTML with placeholder
        resultsContainer.innerHTML += `
            <div id="tg-item-${item.tg_id}" class="col item" data-tgId="${item.tg_id}">
                <div class="item-wrap">
                    <figure>
                        <a class="d-block" href="${itemUrl}">
                            <img 
                            width="254" 
                            height="150" 
                            id="img-${item.tg_id}" 
                            src="${placeholder}" 
                            alt="${item.title}" 
                            style="width: 254px; height: 150px; object-fit: cover; max-width: none; max-height: none;">
                        </a>
                    </figure>
                    ${priceHtml}
                    <a class="d-block item-name mb-2" href="${itemUrl}">
                        <strong>${item.title}</strong>
                    </a>
                    <div class="add-to-cart">
                        <input class="qty-input form-control round" type="text" placeholder="Qty" id="qty-${item.tg_id}">
                        <button 
                            type="button" 
                            data-target="${addToCartUrl}"
                            data-item-id="${item.tg_id}" 
                             data-base-url="${baseurl}"
                    data-redirect-url="${redirectUrl}"
                            class="btn btn-primary add-cart">
                            Add
                        </button>
                    </div>
                </div>
            </div>`;

        // Fetch the image URL asynchronously
        fetchImage(item.tg_id).then(imgUrl => {
            const imgElement = document.getElementById(`img-${item.tg_id}`);
            if (imgElement) {
                imgElement.src = imgUrl;
            }
        });
    });

    // Attach event listeners to buttons after rendering
    attachAddToCartListeners();
}

    // Update pagination links
    function updatePagination(totalPages, currentPage) {
        paginationContainer.innerHTML = ""; // Clear pagination
        if (totalPages <= 1) return;

        const createPageItem = (label, isDisabled, page = null, icon = null) => {
            const disabledClass = isDisabled ? "disabled" : "";
            const iconHtml = icon ? `<span class="${icon}"></span>` : label;
            const href = page ? `#` : "javascript:void(0);";
            return `
                <li class="page-item ${disabledClass}">
                    <a class="page-link" href="${href}" data-page="${page || ''}">${iconHtml}</a>
                </li>`;
        };

        paginationContainer.innerHTML += createPageItem("First", currentPage <= 1, 1, "dashicons dashicons-controls-skipback");
        paginationContainer.innerHTML += createPageItem("Previous", currentPage <= 1, currentPage - 1, "dashicons dashicons-controls-back");
        paginationContainer.innerHTML += `
            <li class="page-item current-page">
                <a class="page-link">${currentPage}</a>
            </li>
            <li class="page-item disabled">
                <a class="page-link">of</a>
            </li>
            <li class="page-item disabled">
                <a class="page-link">${totalPages}</a>
            </li>`;
        paginationContainer.innerHTML += createPageItem("Next", currentPage >= totalPages, currentPage + 1, "dashicons dashicons-controls-forward");
        paginationContainer.innerHTML += createPageItem("Last", currentPage >= totalPages, totalPages, "dashicons dashicons-controls-skipforward");

        document.querySelectorAll(".page-link").forEach(link => {
            link.addEventListener("click", function (e) {
                e.preventDefault();
                const page = parseInt(this.dataset.page, 10);
                if (!isNaN(page)) {
                    const query = searchInput.value.trim();
                    fetchResults(query || null, page, query.length === 0);
                }
            });
        });
    }




// Attach event listeners for Add buttons
function attachAddToCartListeners() {
    const addButtons = document.querySelectorAll(".add-cart.btn.btn-primary");
    addButtons.forEach(button => {
        button.addEventListener("click", function () {
            const itemId = this.getAttribute("data-item-id");
            const baseUrl = this.getAttribute("data-base-url");
            const redirectUrl = decodeURIComponent(this.getAttribute("data-redirect-url"));
            const qtyInput = document.getElementById(`qty-${itemId}`);
            const quantity = qtyInput && qtyInput.value ? qtyInput.value : 1;

            // Update localStorage
            let cartData = JSON.parse(localStorage.getItem("cartData")) || {};
            if (!cartData[locationId]) cartData[locationId] = {};
            cartData[locationId][itemId] = quantity;
            localStorage.setItem("cartData", JSON.stringify(cartData));

            // Set cart status to active
            localStorage.setItem("cart", "1"); // Mark the cart as active

            // Change button to "Added" and green color
            this.innerText = "Added";
            this.style.backgroundColor = "green";
            this.disabled = true;

            // Clear after 10 seconds
            setTimeout(() => {
                this.innerText = "Add";
                this.style.backgroundColor = "";
                this.disabled = false;
                if (qtyInput) qtyInput.value = "";
                delete cartData[locationId][itemId];
                if (Object.keys(cartData[locationId]).length === 0) delete cartData[locationId];
                localStorage.setItem("cartData", JSON.stringify(cartData));
            }, 10000);

            // Construct the final URL
            const finalUrl = `${baseUrl}?itemId=${itemId}&itemType=items&quantity=${quantity}&redirectUrl=${redirectUrl}`;

            // Redirect to the final URL
            window.location.href = finalUrl;
        });
    });
}


// Restore button states on page load
const cartData = JSON.parse(localStorage.getItem("cartData")) || {};
Object.keys(cartData[locationId] || {}).forEach(itemId => {
    const button = document.querySelector(`button[data-item-id="${itemId}"]`);
    if (button) {
        button.innerText = "Added";
        button.style.backgroundColor = "green";
        button.disabled = true;

        setTimeout(() => {
            button.innerText = "Add";
            button.style.backgroundColor = "";
            button.disabled = false;
            delete cartData[locationId][itemId];
            if (Object.keys(cartData[locationId]).length === 0) delete cartData[locationId];
            localStorage.setItem("cartData", JSON.stringify(cartData));
        }, 10000);
    }
});
});
</script>
