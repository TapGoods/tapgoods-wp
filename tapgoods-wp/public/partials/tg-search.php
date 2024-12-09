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
if (isset($atts['per_page_default'])) {
    // extract the value
    $tg_per_page  = str_replace('per_page_default=', '', $atts['per_page_default']);
    
    // vonvert to integer
    $tg_per_page  = intval($tg_per_page );
} else {
    // ddefault value
    $tg_per_page  = 12; 
}

// Get the default location ID from the settings
$default_location_id = get_option('tg_default_location');

// Get the base URL for adding items to the cart
$base_url = tg_get_add_to_cart_url( $default_location_id );

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
document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("tg-search");
    const resultsContainer = document.querySelector(".tapgoods.tapgoods-inventory.row.row-cols-lg-3.row-cols-md-1.row-cols-sm-1");
    const paginationContainer = document.querySelector(".pagination.justify-content-center.align-items-center");
    const placeholderImage = "<?php echo esc_url(plugin_dir_url(__FILE__) . 'assets/img/placeholder.png'); ?>";
    const categories = "<?php echo esc_js($category); ?>";
    const tags = "<?php echo esc_js($atts['tags'] ?? ''); ?>";
    const perPage = "<?php echo esc_js($tg_per_page); ?>";
    const locationId = "<?php echo esc_js($location_id); ?>";
    const redirectUrl = "<?php echo esc_js($current_url); ?>";
    const baseurl = "<?php echo esc_js($base_url); ?>";

    // Prevent "Enter" from submitting the form
    searchInput.addEventListener("keydown", function (event) {
        if (event.key === "Enter") {
            event.preventDefault();
        }
    });

    // Fetch results dynamically
    searchInput.addEventListener("input", function () {
        const query = searchInput.value.trim();
        if (query.length === 0) {
            // Reset to default results if no search query
            fetchResults(null, 1, true);
        } else {
            fetchResults(query, 1, false);
        }
    });

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
        resultsContainer.innerHTML = ""; // Clear results
        if (!data || data.length === 0) {
            resultsContainer.innerHTML = "<p>No items found.</p>";
            return;
        }

        data.forEach(item => {
            const imgUrl = item.img_url || placeholderImage;
            resultsContainer.innerHTML += 
                `<div id="tg-item-${item.tg_id}" class="col item" data-tgId="${item.tg_id}">
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
                            <button 
                                type="button" 
                                data-item-id="${item.tg_id}" 
                                class="btn btn-primary add-cart" 
                                data-base-url="${baseurl}"
                                data-redirect-url="${redirectUrl}">
                                Add
                            </button>
                        </div>
                    </div>
                </div>`;
        });

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

    // First Page
    paginationContainer.innerHTML += createPageItem("First", currentPage <= 1, 1, "dashicons dashicons-controls-skipback");

    // Previous Page
    paginationContainer.innerHTML += createPageItem("Previous", currentPage <= 1, currentPage - 1, "dashicons dashicons-controls-back");

    // Current Page and Total Pages
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

    // Next Page
    paginationContainer.innerHTML += createPageItem("Next", currentPage >= totalPages, currentPage + 1, "dashicons dashicons-controls-forward");

    // Last Page
    paginationContainer.innerHTML += createPageItem("Last", currentPage >= totalPages, totalPages, "dashicons dashicons-controls-skipforward");

    // Attach event listeners for each page link
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
        const addButtons = document.querySelectorAll(".add-cart");
        addButtons.forEach(button => {
            button.addEventListener("click", function () {
                const itemId = this.getAttribute("data-item-id");
                const baseUrl = this.getAttribute("data-base-url");
                const redirectUrl = decodeURIComponent(this.getAttribute("data-redirect-url"));
                const qtyInput = document.getElementById(`qty-${itemId}`);
                const quantity = qtyInput && qtyInput.value ? qtyInput.value : 1;

                // Update localStorage
                let cartData = JSON.parse(localStorage.getItem("cartdata")) || {};
                if (!cartData[locationId]) cartData[locationId] = {};
                cartData[locationId][itemId] = quantity;
                localStorage.setItem("cartdata", JSON.stringify(cartData));

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
                    localStorage.setItem("cartdata", JSON.stringify(cartData));
                }, 10000);

                // Construct the final URL
                const finalUrl = `${baseUrl}?itemId=${itemId}&itemType=items&quantity=${quantity}&redirectUrl=${redirectUrl}`;

                // Redirect to the final URL
                window.location.href = finalUrl;
            });
        });
    }

    // Restore button states on page load
    const cartData = JSON.parse(localStorage.getItem("cartdata")) || {};
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
                localStorage.setItem("cartdata", JSON.stringify(cartData));
            }, 10000);
        }
    });
});

</script>
