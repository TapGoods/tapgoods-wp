/**
 * TapGoods Public - Complete JavaScript Functions
 * Refactored from inline scripts to proper WordPress enqueue system
 */

console.log('TapGoods: JavaScript file loaded!');

// Initialize all public functions when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    console.log('TapGoods: DOMContentLoaded - initializing modules');
    console.log('TapGoods: Current page URL:', window.location.pathname);
    console.log('TapGoods: Is tag page:', window.location.pathname.includes('/tags/'));
    // Namespace to avoid global name collisions (e.g., with themes/builders)
    window.TG = window.TG || {};
    window.TG.initLocationSelector = initLocationSelector;
    window.TG.initInventoryGrid = initInventoryGrid;
    window.TG.initProductSingle = initProductSingle;
    window.TG.initCartHandlers = initCartHandlers;
    window.TG.initFilterHandlers = initFilterHandlers;
    window.TG.initSearchHandlers = initSearchHandlers;
    window.TG.initSearchResults = initSearchResults;
    window.TG.initSignInHandlers = initSignInHandlers;
    window.TG.initSignUpHandlers = initSignUpHandlers;
    window.TG.initTagResults = initTagResults;
    window.TG.initThankYouPage = initThankYouPage;

    // Call namespaced version to ensure our implementation runs
    console.log('TapGoods: Calling TG.initLocationSelector');
    try {
        window.TG.initLocationSelector();
        console.log('TapGoods: TG.initLocationSelector call completed');
    } catch (e) {
        console.error('TapGoods: initLocationSelector threw', e);
    }

    // Attach robust delegated handlers (main doc + iframes)
    try {
        attachDelegatedLocationHandlers(document);
    } catch (e) { /* ignore */ }

    // Elementor integration: initialize when shortcode widget renders
    try {
        if (window.jQuery && window.elementorFrontend && window.elementorFrontend.hooks) {
            jQuery(window).on('elementor/frontend/init', function () {
                try {
                    // Ensure delegated handlers are attached when Elementor initializes
                    try { attachDelegatedLocationHandlers(document); } catch(_) {}
                    elementorFrontend.hooks.addAction('frontend/element_ready/shortcode.default', function ($scope) {
                        try {
                            const el = $scope && $scope[0] ? $scope[0].querySelector('#tg-location-select') : null;
                            if (el) {
                                console.log('TapGoods: Elementor shortcode rendered; initializing selector inside widget');
                                initializeWithElement(el);
                                try { attachDelegatedLocationHandlers(document); } catch(_) {}
                            }
                            // Check for cart button and initialize cart handlers
                            const cartButton = $scope && $scope[0] ? $scope[0].querySelector('#tg_cart') : null;
                            if (cartButton) {
                                console.log('TapGoods: Elementor shortcode rendered; initializing cart handlers');
                                initCartHandlers($scope[0]);
                            }
                        } catch (e) { /* ignore */ }
                    });
                    // Also listen to generic element ready
                    elementorFrontend.hooks.addAction('frontend/element_ready/global', function ($scope) {
                        try {
                            const el = $scope && $scope[0] ? $scope[0].querySelector('#tg-location-select') : null;
                            if (el) {
                                console.log('TapGoods: Elementor global element ready; initializing selector');
                                initializeWithElement(el);
                                try { attachDelegatedLocationHandlers(document); } catch(_) {}
                            }
                            // Check for cart button and initialize cart handlers
                            const cartButton = $scope && $scope[0] ? $scope[0].querySelector('#tg_cart') : null;
                            if (cartButton) {
                                console.log('TapGoods: Elementor global element ready; initializing cart handlers');
                                initCartHandlers($scope[0]);
                            }
                        } catch (e) { /* ignore */ }
                    });
                } catch (e) { /* ignore */ }
            });
            // Elementor popup support
            jQuery(document).on('elementor/popup/show', function () {
                try {
                    const el = document.getElementById('tg-location-select');
                    if (el) {
                        console.log('TapGoods: Elementor popup show; initializing selector');
                        initializeWithElement(el);
                        try { attachDelegatedLocationHandlers(document); } catch(_) {}
                    }
                    // Check for cart button and initialize cart handlers in popup
                    const cartButton = document.getElementById('tg_cart');
                    if (cartButton) {
                        console.log('TapGoods: Elementor popup show; initializing cart handlers');
                        initCartHandlers();
                    }
                } catch (e) { /* ignore */ }
            });
        }
    } catch (e) { /* ignore */ }
    // Initialize cart handlers with multiple attempts
    console.log('TapGoods: Attempting to initialize cart handlers');
    initCartHandlers();
    
    // Additional cart initialization attempts for Elementor compatibility
    setTimeout(() => {
        console.log('TapGoods: Secondary cart handlers initialization attempt');
        initCartHandlers();
    }, 100);
    
    setTimeout(() => {
        console.log('TapGoods: Tertiary cart handlers initialization attempt');
        initCartHandlers();
    }, 500);
    
    setTimeout(() => {
        console.log('TapGoods: Final cart handlers initialization attempt');
        initCartHandlers();
    }, 1000);
    
    initFilterHandlers();
    window.TG.initInventoryGrid();
    window.TG.initProductSingle();
    initSearchHandlers();
    initSearchResults();
    // Initialize category menu with subcategories (independent of inventory grid)
    console.log('TapGoods: About to call initCategoryMenu');
    try {
        initCategoryMenu();
        console.log('TapGoods: initCategoryMenu completed successfully');
    } catch(e) {
        console.error('TapGoods: Error in initCategoryMenu:', e);
    }
    initSignInHandlers();
    initSignUpHandlers();
    initTagResults();
    initThankYouPage();
});

/**
 * Location Selector - from public/partials/tg-location-select.php:32
 */
function initLocationSelector(rootDoc) {
    console.log('TapGoods: Initializing location selector');
    const doc = rootDoc && rootDoc.getElementById ? rootDoc : document;

    const maxAttempts = 30;
    const delayMs = 100;
    let attempts = 0;

    const getCookie = (name) => {
        const cookies = document.cookie.split('; ');
        for (let cookie of cookies) {
            const [key, value] = cookie.split('=');
            if (key === name) return value;
        }
        return null;
    };

    const setCookie = (name, value, days = 30) => {
        const expires = new Date(Date.now() + days * 24 * 60 * 60 * 1000).toUTCString();
        document.cookie = `${name}=${value}; expires=${expires}; path=/`;
    };

    const tryInitialize = () => {
        const selectElement = doc.getElementById('tg-location-select');
        if (!selectElement) {
            if (attempts < maxAttempts) {
                attempts += 1;
                return setTimeout(tryInitialize, delayMs);
            }
            console.log('TapGoods: Location selector element not found after retries, attaching observer');
            // Fallback: observar el body por si el selector se inyecta dinámicamente
            const root = doc.body;
            if (root && typeof MutationObserver !== 'undefined') {
                const observer = new MutationObserver(() => {
                    const el = doc.getElementById('tg-location-select');
                    if (el) {
                        observer.disconnect();
                        initializeWithElement(el);
                    }
                });
                observer.observe(root, { childList: true, subtree: true });
            } else {
                console.warn('TapGoods: MutationObserver not available or root missing');
            }
            return;
        }
        initializeWithElement(selectElement);
    };

    tryInitialize();

    // Delegated change handler to survive DOM replacements (main document)
    if (!window.__tgLocationDelegated) {
        document.addEventListener('change', function (event) {
            const target = event.target;
            if (!(target instanceof Element)) return;
            const select = target.closest('#tg-location-select');
            if (!select) return;
            const value = select.value;
            if (!value) return;
            console.log('TapGoods: Delegated location change to:', value);
            const expires = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toUTCString();
            document.cookie = `tg_user_location=${value}; expires=${expires}; path=/`;
            try { localStorage.setItem('tg_user_location', value); } catch (e) {}
            location.reload();
        }, true);
        window.__tgLocationDelegated = true;
        console.log('TapGoods: Delegated change handler attached');
    }

    // Also scan same-origin iframes and attach handlers
    try {
        const iframes = document.querySelectorAll('iframe');
        if (iframes.length > 0) {
            console.log('TapGoods: Scanning iframes for location selector:', iframes.length);
        }
        iframes.forEach((frame) => {
            // Attach on load and immediate if already loaded
            const attach = () => {
                try {
                    const idoc = frame.contentDocument || frame.contentWindow?.document;
                    if (!idoc) return;
                    const sel = idoc.getElementById('tg-location-select');
                    if (sel) {
                        console.log('TapGoods: Location selector found inside iframe');
                        // Attach change handler within iframe doc
                        idoc.addEventListener('change', function (event) {
                            const t = event.target;
                            if (!(t instanceof idoc.defaultView.Element)) return;
                            const s = t.closest('#tg-location-select');
                            if (!s) return;
                            const val = s.value;
                            if (!val) return;
                            console.log('TapGoods: Delegated (iframe) location change to:', val);
                            const expires = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toUTCString();
                            document.cookie = `tg_user_location=${val}; expires=${expires}; path=/`;
                            try { localStorage.setItem('tg_user_location', val); } catch (e) {}
                            location.reload();
                        }, true);
                    } else {
                        // Observe iframe doc for dynamic injection
                        if (idoc.body && typeof MutationObserver !== 'undefined') {
                            const obs = new MutationObserver(() => {
                                const sel2 = idoc.getElementById('tg-location-select');
                                if (sel2) {
                                    obs.disconnect();
                                    console.log('TapGoods: Iframe selector appeared, initializing');
                                    initializeWithElement(sel2);
                                }
                            });
                            obs.observe(idoc.body, { childList: true, subtree: true });
                        }
                    }
                } catch (e) {
                    // Cross-origin or access denied; ignore
                }
            };
            if (frame.complete || frame.readyState === 'complete') {
                attach();
            } else {
                frame.addEventListener('load', attach, { once: true });
            }
        });
    } catch (e) {
        // ignore
    }

    function initializeWithElement(selectElement) {
        console.log('TapGoods: Location selector found', selectElement);

        const storedLocation = getCookie('tg_user_location') || localStorage.getItem('tg_user_location');
        console.log('TapGoods: Stored location:', storedLocation);

        const canUseStored = storedLocation && selectElement.querySelector(`option[value="${storedLocation}"]`);
        const defaultLoc = (typeof tg_public_vars !== 'undefined' && tg_public_vars.default_location) ? String(tg_public_vars.default_location) : null;
        const canUseDefault = defaultLoc && selectElement.querySelector(`option[value="${defaultLoc}"]`);

        if (canUseStored) {
            console.log('TapGoods: Setting stored location:', storedLocation);
            window.__tgProgrammaticChange = true;
            const changed = applySelectValue(selectElement, storedLocation);
            setCookie('tg_user_location', storedLocation);
            try { localStorage.setItem('tg_user_location', storedLocation); } catch(_){}
            setTimeout(() => { delete window.__tgProgrammaticChange; }, 50);
        } else if (canUseDefault) {
            console.log('TapGoods: Setting default location:', defaultLoc);
            window.__tgProgrammaticChange = true;
            const changed = applySelectValue(selectElement, defaultLoc);
            setCookie('tg_user_location', defaultLoc);
            localStorage.setItem('tg_user_location', defaultLoc);
            setTimeout(() => { delete window.__tgProgrammaticChange; }, 50);
        } else {
            // Fallback: si no hay stored/default válidos, seleccionar la primera opción con value
            const firstOption = selectElement.querySelector('option[value]:not([value=""])');
            if (firstOption) {
                console.log('TapGoods: Falling back to first available location:', firstOption.value);
                window.__tgProgrammaticChange = true;
                const changed = applySelectValue(selectElement, firstOption.value);
                setCookie('tg_user_location', firstOption.value);
                localStorage.setItem('tg_user_location', firstOption.value);
                setTimeout(() => { delete window.__tgProgrammaticChange; }, 50);
            } else {
                console.warn('TapGoods: No valid location options available in selector');
            }
        }

        selectElement.addEventListener('change', function (event) {
            const selectedLocation = this.value;
            console.log('TapGoods: Location changed to:', selectedLocation);
            if (selectedLocation) {
                setCookie('tg_user_location', selectedLocation);
                localStorage.setItem('tg_user_location', selectedLocation);
                // Solo recargar si el evento es de usuario
                if (event && event.isTrusted && !window.__tgProgrammaticChange) {
                    console.log('TapGoods: Location saved, reloading page');
                    location.reload();
                }
            }
        });

        window.__tgLocationInit = true;
        console.log('TapGoods: Location selector initialization completed');
    }
}

function applySelectValue(selectElement, value) {
    try {
        if (String(selectElement.value) === String(value)) {
            return false;
        }
        // Set the value
        selectElement.value = String(value);
        // Normalize selected attributes to reflect the current value
        const options = selectElement.querySelectorAll('option');
        options.forEach(opt => {
            if (opt.value === String(value)) {
                opt.selected = true;
                opt.setAttribute('selected', 'selected');
            } else {
                opt.selected = false;
                opt.removeAttribute('selected');
            }
        });
        // Ensure selectedIndex is correct
        const idx = Array.from(options).findIndex(o => o.value === String(value));
        if (idx >= 0) selectElement.selectedIndex = idx;
        return true;
    } catch (e) { return false; }
}

// Attach delegated change handlers for location select in a document, and hook into iframes
function attachDelegatedLocationHandlers(doc) {
    if (!doc || !doc.body) return;
    if (!doc.__tgDelegatedBound) {
        doc.addEventListener('change', function (e) {
            const t = e.target;
            const Elem = (doc.defaultView || window).Element;
            if (!t || !(t instanceof Elem)) return;
            const sel = t.closest && t.closest('#tg-location-select');
            if (!sel) return;
            const val = sel.value;
            if (!val) return;
            document.cookie = 'tg_user_location=' + val + ';path=/;max-age=' + (60 * 60 * 24 * 30);
            try { localStorage.setItem('tg_user_location', String(val)); } catch(_){ }
            console.log('TapGoods: delegated change →', val);
            // Si el evento no es de usuario o está suprimida la recarga, no recargar
            if (e && !e.isTrusted) return;
            if (window.__tgSuppressReload) return;
            location.href = location.href;
        }, true);
        doc.__tgDelegatedBound = true;
        console.log('TapGoods: delegated change handler attached on', doc === document ? 'main document' : 'iframe doc');
    }

    // For main document: bind existing iframes and observe for new iframes
    if (doc === document) {
        const bindIframe = (frame) => {
            try {
                const idoc = frame.contentDocument || frame.contentWindow?.document;
                if (idoc) {
                    attachDelegatedLocationHandlers(idoc);
                    // If selector is already present inside iframe, initialize
                    const el = idoc.getElementById('tg-location-select');
                    if (el) initializeWithElement(el);
                }
            } catch(_){ /* cross-origin or not ready */ }
        };

        // Bind current iframes
        document.querySelectorAll('iframe').forEach(f => {
            if (f.complete || f.readyState === 'complete') {
                bindIframe(f);
            } else {
                f.addEventListener('load', () => bindIframe(f), { once: true });
            }
        });

        // Observe for new iframes added to the DOM
        if (typeof MutationObserver !== 'undefined') {
            const obs = new MutationObserver((mutations) => {
                mutations.forEach(m => {
                    m.addedNodes && m.addedNodes.forEach(node => {
                        if (node && node.tagName === 'IFRAME') {
                            bindIframe(node);
                        }
                        if (node && node.querySelectorAll) {
                            node.querySelectorAll('iframe').forEach(f => bindIframe(f));
                        }
                    });
                });
            });
            try { obs.observe(document.body, { childList: true, subtree: true }); } catch(_) {}
        }
    }
}

/**
 * Cart Handlers - from public/partials/tg-cart.php:45
 */
function initCartHandlers(context) {
    const searchContext = context || document;
    // Update cart icon based on localStorage status
    const fullCartIcon = searchContext.querySelector('.full-cart-icon');
    const emptyCartIcon = searchContext.querySelector('.empty-cart-icon');
    const cartStatus = localStorage.getItem('cart');
    
    console.log('TapGoods: initCartHandlers - cart status:', cartStatus, 'fullCartIcon:', !!fullCartIcon, 'emptyCartIcon:', !!emptyCartIcon);
    
    // If cart status is null, default to empty cart
    const isCartActive = cartStatus === '1';
    
    if (fullCartIcon && emptyCartIcon) {
        if (isCartActive) {
            fullCartIcon.style.display = 'inline-block';
            emptyCartIcon.style.display = 'none';
            console.log('TapGoods: Showing full cart icon');
        } else {
            fullCartIcon.style.display = 'none';
            emptyCartIcon.style.display = 'inline-block';
            console.log('TapGoods: Showing empty cart icon');
        }
    } else {
        console.warn('TapGoods: Cart icons not found in context');
        // Fallback: try to find icons in the entire document if context search failed
        if (searchContext !== document) {
            const fallbackFullCart = document.querySelector('.full-cart-icon');
            const fallbackEmptyCart = document.querySelector('.empty-cart-icon');
            if (fallbackFullCart && fallbackEmptyCart) {
                console.log('TapGoods: Using fallback document-wide cart icons');
                if (isCartActive) {
                    fallbackFullCart.style.display = 'inline-block';
                    fallbackEmptyCart.style.display = 'none';
                } else {
                    fallbackFullCart.style.display = 'none';
                    fallbackEmptyCart.style.display = 'inline-block';
                }
            }
        }
    }

    // Update cart, sign-in, and sign-up links to selected location
    const savedLocation = localStorage.getItem('tg_user_location');
    if (savedLocation) {
        document.cookie = `tg_user_location=${savedLocation}; path=/`;
    }

    // Update cart button href using data-target base and event params already in PHP
    const cartButton = document.getElementById('tg_cart');
    if (cartButton) {
        // data-target comes from PHP using tapgrein_get_cart_url($location)
        const baseUrl = cartButton.getAttribute('data-target');
        if (baseUrl && savedLocation) {
            // keep as-is, PHP already builds correct URL using cookie
            cartButton.addEventListener('click', function (e) {
                e.preventDefault();
                window.location.href = baseUrl;
            });
        }
    }
}

/**
 * Filter Handlers - from public/partials/tg-filter.php:54
 */
function initFilterHandlers() {
    const filterForm = document.querySelector('.tapgoods-filter-form');
    if (filterForm) {
        // Filter functionality will be populated via wp_add_inline_script
        console.log('TapGoods: Filter handlers initialized');
    }
}

/**
 * Inventory Grid - from public/partials/tg-inventory-grid.php:271
 */
function initInventoryGrid() {
    const inventoryContainers = document.querySelectorAll('.tapgoods-inventory');
    if (inventoryContainers.length === 0) {
        console.log('TapGoods: No inventory grids found on this page');
        return;
    }
    
    console.log('TapGoods: Initializing inventory grids', inventoryContainers.length);
    
    // Get location ID from cookie/localStorage
    const getCookie = (name) => {
        const cookies = document.cookie.split('; ');
        for (let cookie of cookies) {
            const [key, value] = cookie.split('=');
            if (key === name) return value;
        }
        return null;
    };
    const locationId = getCookie('tg_user_location') || localStorage.getItem('tg_user_location');
    
    // Sync location with cookie
    const savedLocation = localStorage.getItem('tg_user_location');
    if (savedLocation) {
        document.cookie = `tg_user_location=${savedLocation}; path=/;`;
    }
    
    // Initialize each inventory container
    inventoryContainers.forEach(container => {
        updateCartItemsOnLoad(container, locationId);
        setupInventoryCartButtons(container, locationId);
    });
    
    // Setup search and pagination
    setupInventorySearch();
    setupInventoryPagination();
}

/**
 * Update cart items display on page load
 */
function updateCartItemsOnLoad(container, locationId) {
    if (!locationId) return;
    
    const cartData = JSON.parse(localStorage.getItem("cartData")) || {};
    if (cartData[locationId]) {
        Object.keys(cartData[locationId]).forEach(itemId => {
            const quantity = cartData[locationId][itemId];
            
            // Update quantity inputs
            const qtyInput = container.querySelector(`#qty-${itemId}`);
            if (qtyInput) {
                qtyInput.value = quantity;
            }
            
            // Update button to "Added" with green color
            const button = container.querySelector(`.add-cart[data-item-id="${itemId}"]`);
            if (button) {
                button.textContent = "Added";
                button.style.setProperty("background-color", "green", "important");
                button.disabled = true;
                
                // Setup auto-reset timer
                setTimeout(() => {
                    resetCartButton(button, qtyInput, itemId, locationId);
                }, 10000);
            }
        });
    }
}

/**
 * The quantity a card starts with, and returns to after a successful add.
 *
 * The same value is rendered server side (value="1" in the templates), because a
 * storefront page is cached per URL with no device or visitor variance: the
 * default has to be in the cached HTML, and only a visitor's own cart quantity
 * may overwrite it, client side.
 */
const TG_DEFAULT_QTY = '1';

/**
 * The largest quantity the field will accept.
 *
 * Four digits covers any real rental line -- the biggest in the fixture
 * catalogues and on the live sites are in the hundreds -- and it is the cheapest
 * way to keep JavaScript's number formatting out of the storefront URL: without
 * a ceiling, "999999999999999999999" parses to a finite number whose String() is
 * "1e+21", and that is what would be sent as &quantity=. The storefront remains
 * the authority on what it will actually accept.
 */
const TG_MAX_QTY = 9999;

/**
 * Read a quantity field and say whether it can be added.
 *
 * Returns { valid: true, quantity: <int> } or { valid: false, message: <string> }.
 * Whole numbers only: parseInt() used to accept "1.5" and quietly add 1, and
 * isNaN() accepted "-1" only to have the caller's <= 0 check reject it with a
 * message that did not say what was wrong.
 */
function tgReadQuantity(qtyInput) {
    const raw = (qtyInput && typeof qtyInput.value === 'string') ? qtyInput.value.trim() : '';

    if (raw === '') {
        return { valid: false, message: 'Enter a quantity of 1 or more.' };
    }

    if (!/^\d+$/.test(raw)) {
        return { valid: false, message: 'Enter a whole number, 1 or more.' };
    }

    const quantity = parseInt(raw, 10);

    if (quantity < 1) {
        return { valid: false, message: 'Enter a quantity of 1 or more.' };
    }

    if (quantity > TG_MAX_QTY) {
        return { valid: false, message: 'Enter a quantity of ' + TG_MAX_QTY + ' or less.' };
    }

    return { valid: true, quantity: quantity };
}

/**
 * Each field's message element, held by reference rather than looked up by id.
 *
 * Two [tapgoods-inventory] grids on one page render the same item twice, so
 * "qty-11001" is not unique and document.getElementById() answers with whichever
 * grid comes first: dismissing the second grid's message used to remove the
 * first grid's instead, and leave its own on screen. A WeakMap cannot be fooled
 * by a duplicate id, and lets a removed card's entry be collected.
 */
const tgQuantityErrorBoxes = new WeakMap();

let tgQuantityErrorSeq = 0;

/**
 * The id of this field's message, stable across repeated errors so the field's
 * aria-describedby keeps pointing at the message that is actually on screen.
 * Numbered, never derived from the input's id, which is not unique on a page
 * holding the same item in two grids.
 */
function tgQuantityErrorId(qtyInput) {
    if (!qtyInput.dataset.tgErrorId) {
        tgQuantityErrorSeq += 1;
        qtyInput.dataset.tgErrorId = 'tg-qty-error-' + tgQuantityErrorSeq;
    }

    return qtyInput.dataset.tgErrorId;
}

/**
 * Show a dismissible validation message beside a quantity field.
 *
 * Replaces alert() (WPB-168). A native alert blocks the page, cannot be styled,
 * and -- because the same button used to carry several click handlers -- arrived
 * once per handler, which is what made it feel impossible to close.
 */
function tgShowQuantityError(qtyInput, message) {
    if (!qtyInput) return;

    const wrapper = qtyInput.closest('.add-to-cart') || qtyInput.closest('.quantity-select') || qtyInput.parentElement;
    if (!wrapper) return;

    // Never stack two messages on one field, however often Add is pressed.
    tgClearQuantityError(qtyInput);

    const box = document.createElement('div');
    box.id = tgQuantityErrorId(qtyInput);
    box.className = 'tg-qty-error';
    box.setAttribute('role', 'alert');
    // Styled here rather than in the stylesheets, so a validation message needs
    // no say in the card layout rules. gridColumn is for the product page, where
    // the message lands in .summary.col, a two-column grid, and has to claim the
    // full row; on a grid card the parent is .item-wrap, which is display:block,
    // and the property is simply inert.
    box.style.gridColumn = '1 / -1';
    box.style.display = 'flex';
    box.style.alignItems = 'center';
    box.style.gap = '6px';
    box.style.margin = '4px 0 0';
    box.style.color = '#b32d2e';
    box.style.fontSize = '12px';
    box.style.lineHeight = '1.4';

    const text = document.createElement('span');
    text.className = 'tg-qty-error-text';
    text.textContent = message;
    box.appendChild(text);

    const dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.className = 'tg-qty-error-dismiss';
    dismiss.setAttribute('aria-label', 'Dismiss');
    dismiss.textContent = '×';
    // setProperty(..., 'important') because the theme stylesheet paints every
    // button inside .add-to-cart with the primary colour, !important included.
    dismiss.style.setProperty('background-color', 'transparent', 'important');
    dismiss.style.setProperty('color', 'inherit', 'important');
    dismiss.style.setProperty('border', '0', 'important');
    dismiss.style.setProperty('padding', '0', 'important');
    dismiss.style.setProperty('line-height', '1', 'important');
    // 24x24 is the WCAG 2.5.8 target minimum. It only ever applies while a
    // message is showing, so the card itself is not resized by it.
    dismiss.style.setProperty('min-width', '24px', 'important');
    dismiss.style.setProperty('min-height', '24px', 'important');
    dismiss.style.setProperty('display', 'inline-flex', 'important');
    dismiss.style.setProperty('align-items', 'center', 'important');
    dismiss.style.setProperty('justify-content', 'center', 'important');
    dismiss.style.setProperty('flex', '0 0 auto', 'important');
    dismiss.style.cursor = 'pointer';
    dismiss.addEventListener('click', function() {
        tgClearQuantityError(qtyInput);
        qtyInput.focus();
    });
    box.appendChild(dismiss);

    wrapper.insertAdjacentElement('afterend', box);
    tgQuantityErrorBoxes.set(qtyInput, box);

    qtyInput.setAttribute('aria-invalid', 'true');
    qtyInput.setAttribute('aria-describedby', box.id);
    qtyInput.focus();

    // Clear the message as soon as the field holds something addable again.
    if (!qtyInput.dataset.tgQtyWatch) {
        qtyInput.dataset.tgQtyWatch = '1';
        qtyInput.addEventListener('input', function() {
            if (tgReadQuantity(qtyInput).valid) {
                tgClearQuantityError(qtyInput);
            }
        });
    }
}

/**
 * Remove a field's validation message, if it has one.
 */
function tgClearQuantityError(qtyInput) {
    if (!qtyInput) return;

    const box = tgQuantityErrorBoxes.get(qtyInput);
    if (box && box.parentNode) {
        box.parentNode.removeChild(box);
    }
    tgQuantityErrorBoxes.delete(qtyInput);

    qtyInput.removeAttribute('aria-invalid');
    qtyInput.removeAttribute('aria-describedby');
}

/**
 * Setup cart buttons for inventory grid
 */
function setupInventoryCartButtons(container, locationId) {
    const addButtons = container.querySelectorAll('.add-cart');
    addButtons.forEach(button => {
        // The location a later setup call resolved still wins, but the handler
        // itself is bound once and only once.
        if (locationId) {
            button.dataset.tgLocationId = locationId;
        }

        // Every level of the grid carries .tapgoods-inventory -- #tg-shop, the row
        // wrapper and each card -- and initInventoryGrid() loops over all of them,
        // so a button used to collect one handler per ancestor, times the number of
        // times init ran (six on a plain shop page). Six handlers meant six
        // alert()s per click: the "error you cannot close" of WPB-168.
        if (button.dataset.tgCartBound) return;
        button.dataset.tgCartBound = '1';

        button.addEventListener('click', function(event) {
            handleInventoryAddToCart(event, button.dataset.tgLocationId || locationId);
        });
    });
}

/**
 * Handle add to cart for inventory grid items
 */
function handleInventoryAddToCart(event, locationId) {
    event.preventDefault();
    
    const button = event.currentTarget;
    const itemId = button.getAttribute("data-item-id");
    // Resolve the grid from the button, never from a page-wide id. Every
    // [tapgoods-inventory] renders the same id="tg-inventory-grid", so a
    // getElementById() lookup always answers with the FIRST grid on the page and
    // a button in any other grid cannot find its own quantity input (WPB-180).
    const container = button.closest('.tapgoods-inventory') || button.closest('#tg-inventory-grid') || document;
    const qtyInput = container.querySelector(`#qty-${itemId}`);
    
    if (!qtyInput) {
        // Nothing a visitor can do about this one, so it goes to the console
        // rather than into a blocking dialog. WPB-180 is what stops it happening.
        console.error('TapGoods: quantity input missing for item', itemId);
        return;
    }

    const parsedQty = tgReadQuantity(qtyInput);
    if (!parsedQty.valid) {
        tgShowQuantityError(qtyInput, parsedQty.message);
        return;
    }

    tgClearQuantityError(qtyInput);
    const quantity = parsedQty.quantity;

    // Update localStorage with cart data
    const cartData = JSON.parse(localStorage.getItem("cartData")) || {};
    if (!cartData[locationId]) {
        cartData[locationId] = {};
    }
    cartData[locationId][itemId] = quantity;
    localStorage.setItem("cartData", JSON.stringify(cartData));
    
    // Set cart status to active
    localStorage.setItem("cart", "1");
    
    // Update button to "Added" with green color
    button.textContent = "Added";
    button.style.setProperty("background-color", "green", "important");
    button.disabled = true;
    
    // Reset after 10 seconds
    setTimeout(() => {
        resetCartButton(button, qtyInput, itemId, locationId);
    }, 10000);
    
    // Navegar hacia la URL externa para evitar CORS (no usar fetch)
    const baseTarget = button.getAttribute("data-target");
    if (baseTarget) {
        try {
            const urlObj = new URL(baseTarget);
            urlObj.searchParams.set('quantity', String(quantity)); // asegurar un solo quantity
            window.location.href = urlObj.toString();
        } catch (e) {
            // Fallback si no es una URL absoluta
            const sep = baseTarget.includes('?') ? '&' : '?';
            window.location.href = `${baseTarget}${sep}quantity=${encodeURIComponent(String(quantity))}`;
        }
    }
}

/**
 * Reset cart button and remove from localStorage
 */
function resetCartButton(button, qtyInput, itemId, locationId) {
    const cartData = JSON.parse(localStorage.getItem("cartData")) || {};
    
    if (cartData[locationId] && cartData[locationId][itemId]) {
        delete cartData[locationId][itemId];
        if (Object.keys(cartData[locationId]).length === 0) {
            delete cartData[locationId];
        }
        localStorage.setItem("cartData", JSON.stringify(cartData));
    }
    
    button.textContent = "Add";
    button.style.removeProperty("background-color");
    button.disabled = false;

    if (qtyInput) {
        // Back to the default, not to an empty field (WPB-168).
        qtyInput.value = TG_DEFAULT_QTY;
    }
}

/**
 * Setup inventory search functionality
 */
function setupInventorySearch() {
    const searchForm = document.querySelector(".tapgoods-search-form");
    if (searchForm) {
        searchForm.addEventListener("submit", function(event) {
            event.preventDefault();
            
            const urlParams = new URLSearchParams(window.location.search);
            
            // Get search input value
            const searchInput = searchForm.querySelector("input[name='s']");
            if (searchInput && searchInput.value.trim() !== '') {
                urlParams.set('s', searchInput.value.trim());
            } else {
                urlParams.delete('s');
            }
            
            // Keep category and tags
            const currentCategory = urlParams.get('category');
            const currentTags = urlParams.get('tg_tags');
            if (currentCategory) urlParams.set('category', currentCategory);
            if (currentTags) urlParams.set('tg_tags', currentTags);
            
            window.location.search = urlParams.toString();
        });
    }
}

/**
 * Initialize category menu with subcategories
 */
function initCategoryMenu() {
    console.log('TapGoods: Initializing category menu');
    const subcategoryToggles = document.querySelectorAll(".subcategory-toggle");
    const subcategoryLinks = document.querySelectorAll(".subcategory-link");
    const categoryLinks = document.querySelectorAll(".category-link");

    console.log('TapGoods: Found', subcategoryToggles.length, 'subcategory toggles');
    console.log('TapGoods: Found', subcategoryLinks.length, 'subcategory links');
    console.log('TapGoods: Found', categoryLinks.length, 'category links');

    // Handle subcategory toggle clicks
    subcategoryToggles.forEach(toggle => {
        toggle.addEventListener("click", function(event) {
            event.preventDefault();
            event.stopPropagation();

            const categoryId = this.getAttribute("data-category-id");
            const subcategoryList = document.querySelector(`.subcategory-list[data-parent-category="${categoryId}"]`);
            const toggleIcon = this.querySelector(".toggle-icon");

            console.log('TapGoods: Toggle clicked for category', categoryId);

            if (subcategoryList) {
                const isVisible = subcategoryList.style.display !== "none" && subcategoryList.style.display !== "";
                subcategoryList.style.display = isVisible ? "none" : "block";
                toggleIcon.textContent = isVisible ? "▶" : "▼";
                console.log('TapGoods: Subcategory list toggled to', subcategoryList.style.display);
            }
        });
    });

    // Handle subcategory clicks
    subcategoryLinks.forEach(link => {
        link.addEventListener("click", function(event) {
            event.preventDefault();

            const selectedTag = this.getAttribute("data-tag-id");
            if (!selectedTag) return;

            console.log('TapGoods: Subcategory clicked:', selectedTag);

            const urlParams = new URLSearchParams(window.location.search);
            // Remove 'tag-' prefix if present for cleaner URL
            const cleanTag = selectedTag.startsWith('tag-') ? selectedTag.substring(4) : selectedTag;
            urlParams.set('tags', cleanTag);
            urlParams.delete('category'); // Clear category filter when selecting tag
            urlParams.delete('paged'); // Reset pagination

            window.location.search = urlParams.toString();
        });
    });

    // Handle category clicks
    categoryLinks.forEach(link => {
        link.addEventListener("click", function(event) {
            event.preventDefault();

            const selectedCategory = this.getAttribute("data-category-id");
            console.log('TapGoods: Category clicked:', selectedCategory);

            // If All Categories or empty category is clicked, reset filters to show all
            if (selectedCategory === null || selectedCategory === "") {
                const urlParams = new URLSearchParams(window.location.search);
                urlParams.delete('category');
                urlParams.delete('tags');
                urlParams.delete('paged');
                window.location.search = urlParams.toString();
                return;
            }

            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('category', selectedCategory);
            urlParams.delete('tags'); // Clear tag filter when selecting category
            urlParams.delete('paged'); // Reset pagination

            window.location.search = urlParams.toString();
        });
    });
}

/**
 * Setup inventory pagination functionality
 */
function setupInventoryPagination() {
    const categoryLinks = document.querySelectorAll(".category-link");
    const subcategoryLinks = document.querySelectorAll(".subcategory-link");
    const subcategoryToggles = document.querySelectorAll(".subcategory-toggle");
    const paginationLinks = document.querySelectorAll(".pagination a");

    // Category and subcategory functionality is now handled by initCategoryMenu()
    // This function now only handles pagination

    // Handle category clicks (keeping for backward compatibility)
    //
    // Three separate handlers end up bound to the same .category-link: this one,
    // initCategoryMenu()'s, and tg-filter.php's inline one. They all navigate, so
    // whichever runs last decides the URL -- and this one was the only one that
    // left ?tags= in place. Now that a tag filter is actually reachable
    // (WPB-166), that meant clicking a category from a tag-filtered grid gave you
    // category AND tag, which is not what either of the other two do.
    categoryLinks.forEach(link => {
        link.addEventListener("click", function(event) {
            event.preventDefault();
            
            const selectedCategory = this.getAttribute("data-category-id");
            // If All Categories or empty category is clicked, reset filters to show all
            if (selectedCategory === null || selectedCategory === "") {
                const urlParams = new URLSearchParams(window.location.search);
                urlParams.delete('category');
                urlParams.delete('tags');
                urlParams.delete('paged');
                window.location.search = urlParams.toString();
                return;
            }
            
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('category', selectedCategory);
            urlParams.delete('tags'); // A category click replaces the tag filter.
            urlParams.delete('paged'); // Reset pagination
            
            window.location.search = urlParams.toString();
        });
    });
    
    // Handle pagination clicks
    paginationLinks.forEach(link => {
        link.addEventListener("click", function(event) {
            event.preventDefault();
            
            const url = new URL(this.href);
            const paged = url.searchParams.get('paged');
            
            const urlParams = new URLSearchParams(window.location.search);
            if (paged) {
                urlParams.set('paged', paged);
            }
            
            // Keep category and tags in pagination
            const currentCategory = urlParams.get('category');
            const currentTags = urlParams.get('tg_tags');
            if (currentCategory) urlParams.set('category', currentCategory);
            if (currentTags) urlParams.set('tg_tags', currentTags);
            
            window.location.search = urlParams.toString();
        });
    });
}

/**
 * Product Single Page - from public/partials/tg-product-single.php:124
 */
function initProductSingle() {
    console.log('TapGoods: Initializing product single page');
    
    const addButton = document.querySelector(".add-cart");
    const quantityInput = document.querySelector(".qty-input");
    
    if (!addButton || !quantityInput) {
        console.log('TapGoods: Product single elements not found on this page');
        return;
    }
    
    const itemId = addButton.getAttribute("data-item-id");
    const locationId = addButton.getAttribute("data-location-id");
    
    if (!itemId || !locationId) {
        console.warn("TapGoods: Required attributes missing from product elements");
        return;
    }
    
    console.log('TapGoods: Product single elements found', {itemId, locationId});
    
    // Initialize cart data and location management
    initProductCartData(itemId, locationId, addButton, quantityInput);
    
    // Setup add to cart functionality
    setupAddToCartButton(addButton, quantityInput, itemId, locationId);
    
    // Initialize image carousel functionality
    initImageCarousel();
}

/**
 * Initialize cart data and location for product page
 */
function initProductCartData(itemId, locationId, addButton, quantityInput) {
    const cartData = JSON.parse(localStorage.getItem("cartData")) || {};
    
    // Sync location with cookie
    const savedLocation = localStorage.getItem('tg_user_location');
    if (savedLocation) {
        document.cookie = `tg_user_location=${savedLocation}; path=/`;
        console.log('TapGoods: Location saved to cookie:', savedLocation);
    }
    
    // Load stored quantity for current item and location
    if (cartData[locationId] && cartData[locationId][itemId]) {
        quantityInput.value = cartData[locationId][itemId];
        updateCartButton(addButton, true);
    }
    
    // Update cart icon status
    updateCartIcon(cartData, locationId);
}

/**
 * Setup add to cart button functionality
 */
function setupAddToCartButton(addButton, quantityInput, itemId, locationId) {
    // initProductSingle() runs twice on a product page -- once from the module's
    // own DOMContentLoaded and once from the inline script Tapgoods_Enqueue adds --
    // so without this the button carried two handlers and an invalid quantity
    // produced two alerts (WPB-168).
    if (addButton.dataset.tgCartBound) return;
    addButton.dataset.tgCartBound = '1';

    addButton.addEventListener("click", function (event) {
        event.preventDefault();

        const url = this.getAttribute("data-target");

        // Validate quantity
        const parsedQty = tgReadQuantity(quantityInput);
        if (!parsedQty.valid) {
            tgShowQuantityError(quantityInput, parsedQty.message);
            return;
        }

        tgClearQuantityError(quantityInput);
        const quantity = parsedQty.quantity;

        if (!locationId || !itemId) {
            console.error("TapGoods: Invalid locationId or itemId:", { locationId, itemId });
            return;
        }
        
        // Add to cart data
        addToCartData(itemId, locationId, quantity);
        
        // Send request to server
        sendAddToCartRequest(url, quantity, addButton, quantityInput, itemId, locationId);
    });
}

/**
 * Add item to cart data in localStorage
 */
function addToCartData(itemId, locationId, quantity) {
    const cartData = JSON.parse(localStorage.getItem("cartData")) || {};
    
    // Initialize location if needed
    if (!cartData[locationId]) {
        cartData[locationId] = {};
    }
    
    // Add item to cart data
    cartData[locationId][itemId] = quantity;
    
    try {
        localStorage.setItem("cartData", JSON.stringify(cartData));
        localStorage.setItem("cart", "1"); // Mark cart as active
        console.log("TapGoods: Item added to localStorage:", cartData);
    } catch (e) {
        console.error("TapGoods: Error saving to localStorage:", e);
    }
}

/**
 * Send add to cart request to server
 */
function sendAddToCartRequest(url, quantity, addButton, quantityInput, itemId, locationId) {
    updateCartButton(addButton, true);
    // Evitar CORS: navegar a la URL externa en lugar de fetch
    try {
        const u = new URL(url);
        u.searchParams.set('quantity', String(quantity));
        window.location.href = u.toString();
    } catch (e) {
        const sep = url.includes('?') ? '&' : '?';
        window.location.href = `${url}${sep}quantity=${encodeURIComponent(String(quantity))}`;
    }
}

/**
 * Update cart button appearance
 */
function updateCartButton(button, isAdded) {
    if (isAdded) {
        button.style.backgroundColor = "green";
        button.textContent = "Added";
        
        setTimeout(() => {
            // Reset button
            button.style.backgroundColor = "";
            button.textContent = "Add";
            
            const quantityInput = document.querySelector(".qty-input");
            if (quantityInput) {
                // Back to the default, not to an empty field (WPB-168).
                quantityInput.value = TG_DEFAULT_QTY;
            }

            // Remove from cart data
            removeFromCartData(button);
        }, 10000);
    }
}

/**
 * Remove item from cart data
 */
function removeFromCartData(button) {
    const itemId = button.getAttribute("data-item-id");
    const locationId = button.getAttribute("data-location-id");
    
    if (!itemId || !locationId) return;
    
    const cartData = JSON.parse(localStorage.getItem("cartData")) || {};
    
    if (cartData[locationId] && cartData[locationId][itemId]) {
        delete cartData[locationId][itemId];
        
        // Remove location if empty
        if (Object.keys(cartData[locationId]).length === 0) {
            delete cartData[locationId];
        }
        
        localStorage.setItem("cartData", JSON.stringify(cartData));
        console.log(`TapGoods: Item ${itemId} removed from cartData`);
    }
}

/**
 * Update cart icon status
 */
function updateCartIcon(cartData, locationId) {
    const cartIcon = document.getElementById("tg_cart");
    const isCartActive = localStorage.getItem("cart") === "1";
    
    if (cartIcon) {
        if (isCartActive) {
            cartIcon.classList.add("has-items");
        } else {
            cartIcon.classList.remove("has-items");
        }
    }
}

/**
 * Reload product data dynamically
 */
function reloadProductData() {
    fetch(window.location.href, {
        method: "GET",
        headers: {
            "X-Requested-With": "XMLHttpRequest",
        },
    })
    .then((response) => response.text())
    .then((html) => {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, "text/html");
        
        // Update date selector
        const newDatesSelector = doc.querySelector("#tg-dates-selector");
        const datesSelector = document.querySelector("#tg-dates-selector");
        if (newDatesSelector && datesSelector) {
            datesSelector.innerHTML = newDatesSelector.innerHTML;
        }
        
        // Update cart section
        const newCartSection = doc.querySelector(".quantity-select");
        const cartSection = document.querySelector(".quantity-select");
        if (newCartSection && cartSection) {
            cartSection.innerHTML = newCartSection.innerHTML;
            reinitializeProductEventListeners();
        }
    })
    .catch((error) => console.error("TapGoods: Error reloading product data:", error));
}

/**
 * Reinitialize event listeners after dynamic content reload
 */
function reinitializeProductEventListeners() {
    const updatedAddButton = document.querySelector(".add-cart");
    const updatedQuantityInput = document.querySelector(".qty-input");
    
    if (updatedAddButton && updatedQuantityInput) {
        const itemId = updatedAddButton.getAttribute("data-item-id");
        const locationId = updatedAddButton.getAttribute("data-location-id");
        
        if (itemId && locationId) {
            setupAddToCartButton(updatedAddButton, updatedQuantityInput, itemId, locationId);
        }
    }
}

/**
 * Search Handlers - from public/partials/tg-search.php:92
 */
function initSearchHandlers() {
    // A page may hold several [tapgoods-inventory] shortcodes, each with its own
    // search box and grid (WPB-180). Bind every search box to the grid it sits
    // in; a document-wide lookup would only ever find the first one.
    document.querySelectorAll('[id="tg-search"]').forEach(initSearchInstance);
}

function initSearchInstance(searchInput) {
    // initSearchHandlers() runs more than once on some pages (tag results call it
    // again); binding twice would fire two requests per keystroke.
    if (searchInput.dataset.tgSearchBound) return;
    searchInput.dataset.tgSearchBound = '1';

    // Everything below is scoped to this shortcode instance, never to document.
    const root = searchInput.closest('.tapgoods-inventory') || document;
    const form = searchInput.form || root;
    const resultsContainer = root.querySelector('#tg-results-container');
    const getGridSection = () => root.querySelector('#tg-inventory-grid-container');
    // Helper to always fetch the current grid element (avoid stale references after replace)
    const getInventoryGrid = () => {
        const gridContainer = getGridSection();
        return gridContainer ? gridContainer.querySelector('#tg-inventory-grid') : root.querySelector('#tg-inventory-grid');
    };
    
    // Location ID value from hidden field if present
    const locationField = form.querySelector('input[name="tg_location_id"]');
    const locationId = locationField ? locationField.value : (getCookie('tg_user_location') || localStorage.getItem('tg_user_location'));
    const tagsField = form.querySelector('input[name="tags"]');
    const categoriesField = form.querySelector('input[name="category"]');
    const perPageField = form.querySelector('input[name="per_page_default"]');
    const perPage = perPageField ? perPageField.value : 12;
    const showPricingField = form.querySelector('input[name="show_pricing"]');
    const showPricing = showPricingField ? showPricingField.value === 'true' : true;
    
    // Prevent Enter submit
    searchInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') event.preventDefault();
    });
    
    // Real-time search with debounce
    let tgSearchTimer;
    searchInput.addEventListener('input', function () {
        clearTimeout(tgSearchTimer);
        tgSearchTimer = setTimeout(() => {
        const query = searchInput.value.trim();
        if (query.length === 0) {
            // Clear grid first then load default results
            const container = getInventoryGrid();
            if (container) container.innerHTML = '';
            fetchResults(null, 1, true);
        } else {
            fetchResults(query, 1, false);
        }
        }, 200);
    });
    
    function fetchResults(query, page = 1, isDefault = false) {
        const params = new URLSearchParams({
            action: 'tg_search_grid',
            s: query,
            tg_location_id: locationId || '',
            tg_tags: tagsField ? tagsField.value : '',
            tg_categories: categoriesField ? categoriesField.value : '',
            per_page_default: perPage,
            paged: page,
            default: isDefault ? 'true' : 'false',
            redirect_url: window.location.href,
            show_pricing: showPricing ? 'true' : 'false'
        });
        console.log('TapGoods: Search request with show_pricing:', showPricing);
        
        fetch(tg_public_vars.ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params,
        })
        .then(response => response.json())
        .then(data => {
            if (!data || !data.success || !data.data) return;
            // Always update ONLY the grid
            let inventoryGrid = getInventoryGrid();
            if (inventoryGrid) {
                if (typeof data.data.html === 'string' && data.data.html.length > 0) {
                    // Replace only the grid block preserving section and aside
                    const newGrid = document.createElement('div');
                    newGrid.id = 'tg-inventory-grid';
                    newGrid.innerHTML = data.data.html;
                    inventoryGrid.replaceWith(newGrid);
                    // Update reference after replace
                    const updatedGrid = getInventoryGrid() || newGrid;
                    // keep processing so pagination renders
                    inventoryGrid = updatedGrid;
                } else if (Array.isArray(data.data.results)) {
                    inventoryGrid.innerHTML = buildInventoryGridHtml(data.data.results, showPricing);
                }
                // One pass over the refreshed grid, in the order a first page load
                // uses: restore what this visitor already has in the cart, then
                // bind. The restore used to be missing here, so after a search or a
                // page change an item already in the cart at 5 came back showing the
                // freshly defaulted 1 with an enabled "Add" -- and one click on that
                // sends quantity=1 (WPB-168). Once per refresh, not once per
                // container that happens to match .tapgoods-inventory.
                updateCartItemsOnLoad(inventoryGrid, locationId);
                setupInventoryCartButtons(inventoryGrid, locationId);
            }
            // Render/update pagination below the grid (abajo del todo)
            let gridSection = getGridSection();
            if (!gridSection) {
                // fallback: take the parent of the grid
                const currentGrid = getInventoryGrid();
                gridSection = currentGrid ? currentGrid.parentElement : null;
            }
            if (gridSection && typeof data.data.total_pages !== 'undefined' && typeof data.data.current_page !== 'undefined') {
                renderGridPagination(gridSection, data.data.total_pages, data.data.current_page);
            }
            // Do not render any HTML strings into results list; keep categories and search box intact
            if (resultsContainer) resultsContainer.innerHTML = '';
        })
        .catch(err => console.error('TapGoods: search error', err));
    }

    function renderPagination(container, totalPages, currentPage) {
        if (!container) return;
        container.innerHTML = '';
        if (!totalPages || totalPages <= 1) return;

        const ul = document.createElement('ul');
        ul.className = 'pagination justify-content-center align-items-center';

        const createPageItem = (label, page, disabled, iconClass) => {
            const li = document.createElement('li');
            li.className = `page-item ${disabled ? 'disabled' : ''}`;
            const a = document.createElement('a');
            a.className = 'page-link';
            a.href = '#';
            if (iconClass) {
                const span = document.createElement('span');
                span.className = iconClass;
                a.appendChild(span);
            } else {
                a.textContent = String(label);
            }
            if (!disabled && page) a.dataset.page = String(page);
            li.appendChild(a);
            return li;
        };

        // First, Prev
        ul.appendChild(createPageItem('', 1, currentPage <= 1, 'dashicons dashicons-controls-skipback'));
        ul.appendChild(createPageItem('', currentPage - 1, currentPage <= 1, 'dashicons dashicons-controls-back'));

        // Current page indicator: "current of total"
        const currentLi = document.createElement('li');
        currentLi.className = 'page-item current-page';
        const currentA = document.createElement('a');
        currentA.className = 'page-link';
        currentA.textContent = String(currentPage);
        currentLi.appendChild(currentA);
        ul.appendChild(currentLi);

        const ofLi = document.createElement('li');
        ofLi.className = 'page-item disabled';
        const ofA = document.createElement('a');
        ofA.className = 'page-link';
        ofA.textContent = 'of';
        ofLi.appendChild(ofA);
        ul.appendChild(ofLi);

        const totalLi = document.createElement('li');
        totalLi.className = 'page-item disabled';
        const totalA = document.createElement('a');
        totalA.className = 'page-link';
        totalA.textContent = String(totalPages);
        totalLi.appendChild(totalA);
        ul.appendChild(totalLi);

        // Next, Last
        ul.appendChild(createPageItem('', currentPage + 1, currentPage >= totalPages, 'dashicons dashicons-controls-forward'));
        ul.appendChild(createPageItem('', totalPages, currentPage >= totalPages, 'dashicons dashicons-controls-skipforward'));

        container.appendChild(ul);

        // Wire up clicks to use AJAX search
        container.querySelectorAll('.page-link[data-page]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const page = parseInt(link.getAttribute('data-page'), 10);
                if (!isNaN(page)) {
                    const query = (searchInput.value || '').trim();
                    fetchResults(query || null, page, query.length === 0);
                }
            });
        });
    }

    function renderGridPagination(gridSection, totalPages, currentPage) {
        // Ensure a dedicated container exists under the grid
        let paginationContainer = gridSection.querySelector('#tg-inventory-pagination');
        if (!paginationContainer) {
            paginationContainer = document.createElement('div');
            paginationContainer.id = 'tg-inventory-pagination';
            gridSection.appendChild(paginationContainer);
        }
        renderPagination(paginationContainer, totalPages, currentPage);
    }
}

// Build inventory grid cards from results array
function buildInventoryGridHtml(items, showPricing = true) {
    if (!items || items.length === 0) {
        return '<p>No items found.</p>';
    }
    let html = '';
    items.forEach((item) => {
        const priceHtml = (showPricing && item.price) ? `<div class="price mb-2">${item.price}</div>` : '';
        let itemUrl = item.url || '#';
        
        // Add nprice=true parameter if pricing is disabled
        if (!showPricing) {
            const separator = itemUrl.includes('?') ? '&' : '?';
            itemUrl += `${separator}nprice=true`;
        }
        
        const imgUrl = item.img_url || (tg_public_vars && tg_public_vars.plugin_url ? tg_public_vars.plugin_url + 'public/partials/assets/img/placeholder.png' : '');
        html += `
        <div id="tg-item-${item.tg_id}" class="col item">
            <div class="item-wrap">
                <figure>
                    <a class="d-block" href="${itemUrl}">
                        <img width="254" height="150" src="${imgUrl}" alt="${item.title || ''}" style="width:254px;height:150px;object-fit:cover;max-width:none;max-height:none;">
                    </a>
                </figure>
                ${priceHtml}
                <a class="d-block item-name mb-2" href="${itemUrl}"><strong>${item.title || ''}</strong></a>
                <div class="add-to-cart">
                    <input class="qty-input form-control round" type="text" inputmode="numeric" placeholder="Qty" value="1" id="qty-${item.tg_id}">
                    <button type="button" data-item-id="${item.tg_id}" class="btn btn-primary add-cart">Add</button>
                </div>
            </div>
        </div>`;
    });
    return html;
}

/**
 * Search Results - from public/partials/tg-search-results.php:127
 */
function initSearchResults() {
    const searchResults = document.querySelector('.tapgoods-search-results');
    if (searchResults) {
        // Search results functionality will be populated via wp_add_inline_script
        console.log('TapGoods: Search results initialized');
    }
}

/**
 * Sign In Handlers - from public/partials/tg-sign-in.php:16
 */
function initSignInHandlers() {
    const signInForm = document.querySelector('.tapgoods-sign-in-form');
    if (signInForm) {
        // Sign in functionality will be populated via wp_add_inline_script
        console.log('TapGoods: Sign in handlers initialized');
    }
}

/**
 * Sign Up Handlers - from public/partials/tg-sign-up.php:16
 */
function initSignUpHandlers() {
    const signUpForm = document.querySelector('.tapgoods-sign-up-form');
    if (signUpForm) {
        // Sign up functionality will be populated via wp_add_inline_script
        console.log('TapGoods: Sign up handlers initialized');
    }
}

/**
 * Tag Results - from public/partials/tg-tag-results.php:24,55
 */
function initTagResults() {
    console.log('TapGoods: initTagResults called');
    console.log('TapGoods: Current URL:', window.location.pathname);
    
    // Check if we're on a tag page (URL contains /tags/)
    const isTagPage = window.location.pathname.includes('/tags/');
    const inventoryContainers = document.querySelectorAll('.tapgoods-inventory');
    
    console.log('TapGoods: isTagPage:', isTagPage);
    console.log('TapGoods: inventoryContainers found:', inventoryContainers.length);
    
    if (isTagPage && inventoryContainers.length > 0) {
        console.log('TapGoods: Tag page detected, initializing inventory grid functionality');
        
        // Tag pages use the same structure as inventory pages, so initialize the same functionality
        console.log('TapGoods: Calling initInventoryGrid()');
        initInventoryGrid();
        
        console.log('TapGoods: Calling initFilterHandlers()');
        initFilterHandlers();
        
        console.log('TapGoods: Calling initSearchHandlers()');
        initSearchHandlers();
        
        console.log('TapGoods: Tag results initialized with full inventory functionality');
    } else if (inventoryContainers.length > 0) {
        console.log('TapGoods: Generic tag results initialized');
    } else {
        console.log('TapGoods: No inventory containers found or not a tag page');
    }
}

/**
 * Thank You Page - from public/partials/tg-thankyou.php:11
 */
function initThankYouPage() {
    const thankYouPage = document.querySelector('.tapgoods-thank-you');
    if (thankYouPage) {
        // Thank you page functionality will be populated via wp_add_inline_script
        console.log('TapGoods: Thank you page initialized');
    }
}

/**
 * Image Carousel - Initialize Bootstrap carousel with thumbnail navigation
 */
function initImageCarousel() {
    console.log('TapGoods: initImageCarousel called');
    
    // Try to find carousel element with retry logic
    function findCarouselElement(retries = 0) {
        const carouselElement = document.querySelector('#tg-carousel');
        if (!carouselElement && retries < 3) {
            console.log('TapGoods: Carousel element not found, retrying in 100ms (attempt ' + (retries + 1) + ')');
            setTimeout(() => findCarouselElement(retries + 1), 100);
            return;
        }
        
        if (!carouselElement) {
            console.log('TapGoods: No image carousel found on this page after retries');
            return;
        }
        
        console.log('TapGoods: Found carousel element, initializing');
        setupCarousel(carouselElement);
    }
    
    function setupCarousel(carouselElement) {
        let carousel;
        
        // Function to update active thumbnail
        function updateActiveThumbnail(index) {
            const thumbnails = document.querySelectorAll('.thumbnail-btn');
            console.log('TapGoods: Updating active thumbnail to index:', index, 'Found thumbnails:', thumbnails.length);
            thumbnails.forEach(thumb => thumb.classList.remove('active'));
            const activeThumb = document.querySelector('.thumbnail-btn[data-cindex="' + index + '"]');
            if (activeThumb) {
                activeThumb.classList.add('active');
                console.log('TapGoods: Set active thumbnail for index:', index);
            }
        }
        
        // Function to initialize carousel events
        function initCarouselEvents(element) {
            element.addEventListener('slide.bs.carousel', function (event) {
                console.log('TapGoods: Carousel sliding to index:', event.to);
                updateActiveThumbnail(event.to);
            });
        }
        
        // Function to setup thumbnail click handlers
        function setupThumbnailHandlers() {
            const thumbnailButtons = document.querySelectorAll('.thumbnail-btn');
            console.log('TapGoods: Setting up thumbnail handlers for', thumbnailButtons.length, 'buttons');
            
            thumbnailButtons.forEach(function(button, index) {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const targetIndex = parseInt(this.getAttribute('data-cindex'));
                    console.log('TapGoods: Thumbnail clicked - button index:', index, 'target index:', targetIndex);
                    
                    if (!isNaN(targetIndex)) {
                        // Update active state immediately
                        updateActiveThumbnail(targetIndex);
                        
                        // Navigate to image if carousel is ready
                        if (carousel) {
                            console.log('TapGoods: Navigating carousel to index:', targetIndex);
                            carousel.to(targetIndex);
                        } else {
                            console.error('TapGoods: Carousel not initialized yet');
                        }
                    } else {
                        console.error('TapGoods: Invalid thumbnail index:', targetIndex);
                    }
                });
            });
        }
        
        // Initialize carousel based on Bootstrap availability
        function initBootstrapCarousel() {
            if (typeof bootstrap !== 'undefined') {
                carousel = new bootstrap.Carousel(carouselElement);
                initCarouselEvents(carouselElement);
                setupThumbnailHandlers();
                console.log('TapGoods: Carousel initialized with existing Bootstrap');
            } else {
                // Load Bootstrap if not available
                console.log('TapGoods: Bootstrap not found, loading...');
                const script = document.createElement('script');
                script.src = (tg_public_vars?.plugin_url || '') + 'assets/js/bootstrap.bundle.min.js';
                script.onload = function () {
                    carousel = new bootstrap.Carousel(carouselElement);
                    initCarouselEvents(carouselElement);
                    setupThumbnailHandlers();
                    console.log('TapGoods: Carousel initialized after loading Bootstrap');
                };
                script.onerror = function() {
                    console.error('TapGoods: Failed to load Bootstrap');
                    // Fallback: setup thumbnail handlers anyway
                    setupThumbnailHandlers();
                };
                document.head.appendChild(script);
            }
        }
        
        // Start initialization
        initBootstrapCarousel();
    }
    
    // Start the process
    findCarouselElement();
}