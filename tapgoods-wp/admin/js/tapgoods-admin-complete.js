/**
 * TapGoods Admin - Complete JavaScript Functions
 * Refactored from inline scripts to proper WordPress enqueue system
 */

// Initialize all admin functions when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    initAdminPermalinks();
    initAdminConnection();
    initAdminShortcodes();
    initAdminOptions();
    initAdminStatus();
    initTinyMCEEditor();
    initColorPicker();
    initTagFocus();
    initSyncButton();
    initInventorySync();
});

/**
 * Admin Permalinks - from admin/class-tapgoods-admin-permalinks.php:129
 */
function initAdminPermalinks() {
    if (typeof tapgoodsPermalinks !== 'undefined') {
        // Admin permalinks functionality will be added via wp_add_inline_script
        console.log('TapGoods: Admin permalinks initialized');
    }
}

/**
 * Admin Connection Handling - from admin/partials/tapgoods-admin-connection.php
 */
function initAdminConnection() {
    const connectForm = document.getElementById('tapgrein_connection_form');
    const connectButton = document.getElementById('tapgrein_update_connection');
    const connectInput = document.getElementById('tapgoods_api_key');
    const syncButton = document.getElementById('tapgrein_api_sync');
    
    if (!connectForm || !connectButton || !connectInput || !syncButton) {
        console.log('TapGoods: Connection form elements not found on this page');
        return;
    }
    
    console.log('TapGoods: Initializing admin connection');
    
    // Check if we need to show sync success message
    checkForSyncSuccess();
    
    // Store original values
    const originalApiKey = connectInput.value;
    connectInput.setAttribute('data-original', originalApiKey);
    connectButton.setAttribute('data-original', connectButton.textContent);
    
    // Set initial state based on button text (CONNECTED means API is connected)
    const isConnected = connectButton.textContent.trim() === 'CONNECTED';
    console.log('TapGoods: Initial connection state:', isConnected);
    
    if (!isConnected) {
        // Ensure sync button is hidden if not connected
        syncButton.style.display = 'none';
        syncButton.setAttribute('hidden', 'hidden');
    } else {
        // Ensure sync button is visible if connected
        syncButton.style.display = 'inline-block';
        syncButton.removeAttribute('hidden');
    }
    
    // Handle input changes for the API key
    connectInput.addEventListener('input', function(e) {
        if (e.target.value === e.target.getAttribute('data-original')) {
            connectButton.disabled = true;
            connectButton.textContent = connectButton.getAttribute('data-original');
            if (e.target.value !== '') {
                syncButton.style.display = 'inline-block';
                syncButton.removeAttribute('hidden');
            }
        } else {
            connectButton.disabled = false;
            connectButton.textContent = 'CONNECT';
            syncButton.style.display = 'none';
            syncButton.setAttribute('hidden', 'hidden');
        }
    });
    
    // Form submission event
    connectForm.addEventListener('submit', function(e) {
        e.preventDefault();
        handleConnection(connectButton, connectInput, syncButton);
    });
    
    // Sync button handler
    syncButton.addEventListener('click', function(e) {
        e.preventDefault();
        handleSync(syncButton);
    });
}

/**
 * Handle API connection
 */
function handleConnection(connectButton, connectInput, syncButton) {
    connectButton.disabled = true;
    connectButton.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> CONNECTING';
    
    const formData = new FormData(document.getElementById('tapgrein_connection_form'));
    formData.append('action', 'tapgrein_update_connection');
    
    console.log('TapGoods: Submitting connection request');
    
    fetch(tg_admin_vars.ajaxurl, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(response => {
        console.log('TapGoods: Connection response:', response);
        
        if (response.success) {
            connectButton.disabled = true;
            connectButton.textContent = 'CONNECTED';
            connectInput.setAttribute('data-original', connectInput.value);
            connectInput.setAttribute('value', connectInput.value);
            
            // Show sync button - remove hidden attribute and display style
            syncButton.style.display = 'inline-block';
            syncButton.removeAttribute('hidden');
            
            // Show success message in response data
            if (response.data) {
                showConnectionNotice(response.data, 'success');
            }
            
            // Show visual feedback that connection succeeded
            showSuccessMessage('API connection successful!');
            
        } else {
            connectButton.disabled = false;
            connectButton.textContent = 'CONNECT';
            syncButton.style.display = 'none';
            syncButton.setAttribute('hidden', 'hidden');
            
            // Show error message
            if (response.data) {
                showConnectionNotice(response.data, 'error');
            }
        }
    })
    .catch(error => {
        console.error('TapGoods: Connection error:', error);
        connectButton.disabled = false;
        connectButton.textContent = 'CONNECT';
        syncButton.style.display = 'none';
        syncButton.setAttribute('hidden', 'hidden');
        showSuccessMessage('Connection failed. Please try again.', 'error');
    });
}

/**
 * Handle API sync
 */
function handleSync(syncButton) {
    const nonce = document.getElementById('_tgnonce_connection').value;
    const statusEl = document.getElementById('tapgrein_connection_test');
    
    // Show sync progress modal
    showSyncProgressModal();
    
    // Prevent accidental page close during sync
    enableSyncProtection();
    
    syncButton.disabled = true;
    syncButton.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> WORKING';
    
    // Update modal status
    updateSyncStatus('Connecting to TapGoods API...');
    
    const url = `${tg_admin_vars.ajaxurl}?action=tapgrein_api_sync&_tgnonce_connection=${nonce}`;
    
    // Start the sync process
    setTimeout(() => {
        updateSyncStatus('Fetching categories and tags...');
    }, 1000);
    
    setTimeout(() => {
        updateSyncStatus('Processing inventory items...');
    }, 2500);
    
    setTimeout(() => {
        updateSyncStatus('Updating location data...');
    }, 4000);
    
    setTimeout(() => {
        updateSyncStatus('Finalizing synchronization...');
    }, 5500);
    
    fetch(url)
    .then(response => response.json())
    .then(response => {
        console.log('TapGoods: Sync response:', response);
        
        if (response.success) {
            updateSyncStatus('Synchronization completed successfully!');
            setTimeout(() => {
                disableSyncProtection(); // Remove page close protection
                
                // Reload page with success parameter to show all updated data
                const url = new URL(window.location.href);
                url.searchParams.set('sync_success', '1');
                window.location.href = url.toString();
            }, 1500);
        } else {
            const errorMessage = response.data || 'Synchronization failed. Please try again.';
            updateSyncStatus('Synchronization failed: ' + errorMessage);
            setTimeout(() => {
                hideSyncProgressModal();
                disableSyncProtection(); // Remove page close protection
                syncButton.disabled = false;
                syncButton.textContent = 'SYNC';
                
                if (statusEl) {
                    showConnectionNotice(errorMessage, 'error');
                }
            }, 2000);
        }
    })
    .catch(error => {
        console.error('TapGoods: Sync error:', error);
        const errorMessage = error.message || 'Connection error occurred. Please check your internet connection and try again.';
        updateSyncStatus(errorMessage);
        
        setTimeout(() => {
            hideSyncProgressModal();
            disableSyncProtection(); // Remove page close protection
            syncButton.disabled = false;
            syncButton.textContent = 'SYNC';
            
            if (statusEl) {
                showConnectionNotice('Synchronization failed. Please try again.', 'error');
            }
        }, 2000);
    });
}

/**
 * Show sync progress modal
 */
function showSyncProgressModal() {
    const modal = document.getElementById('syncProgressModal');
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden'; // Prevent scrolling
        console.log('TapGoods: Sync progress modal shown');
    }
}

/**
 * Hide sync progress modal
 */
function hideSyncProgressModal() {
    const modal = document.getElementById('syncProgressModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = ''; // Restore scrolling
        console.log('TapGoods: Sync progress modal hidden');
    }
}

/**
 * Update sync status message
 */
function updateSyncStatus(message) {
    const statusEl = document.getElementById('syncProgressStatus');
    if (statusEl) {
        statusEl.innerHTML = `<p>${message}</p>`;
        console.log('TapGoods: Sync status updated:', message);
    }
}

/**
 * Enable page close protection during sync
 */
function enableSyncProtection() {
    window.addEventListener('beforeunload', syncProtectionHandler);
    console.log('TapGoods: Sync protection enabled');
}

/**
 * Disable page close protection after sync
 */
function disableSyncProtection() {
    window.removeEventListener('beforeunload', syncProtectionHandler);
    console.log('TapGoods: Sync protection disabled');
}

/**
 * Handler for sync protection
 */
function syncProtectionHandler(e) {
    const message = 'Synchronization is in progress. Are you sure you want to leave? This may interrupt the process.';
    e.preventDefault();
    e.returnValue = message;
    return message;
}

/**
 * Check for sync success parameter and show message
 */
function checkForSyncSuccess() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('sync_success') === '1') {
        // Remove the parameter from URL to avoid showing message on refresh
        const url = new URL(window.location.href);
        url.searchParams.delete('sync_success');
        history.replaceState({}, document.title, url.toString());
        
        // Show success message
        setTimeout(() => {
            showSuccessMessage('Synchronization completed successfully! Your inventory and locations have been updated.', 'success');
        }, 500);
        
        console.log('TapGoods: Sync success message displayed');
    }
}

/**
 * Show connection notice
 */
function showConnectionNotice(message, type = 'success') {
    const statusEl = document.getElementById('tapgrein_connection_test');
    if (statusEl) {
        statusEl.innerHTML = `<div class="notice notice-${type} is-dismissible"><p>${message}</p></div>`;
    }
}

/**
 * Admin Connection Success Messages - from admin/partials/tapgoods-admin-connection.php:48
 */
function showSuccessMessage(message = null, type = 'success') {
    let messageElement = document.querySelector('.tapgoods-success');
    
    // If no existing element and we have a message, create one
    if (!messageElement && message) {
        messageElement = document.createElement('div');
        messageElement.className = `tapgoods-${type}`;
        messageElement.style.cssText = `
            background: ${type === 'success' ? '#d4edda' : '#f8d7da'};
            border: 1px solid ${type === 'success' ? '#c3e6cb' : '#f5c6cb'};
            color: ${type === 'success' ? '#155724' : '#721c24'};
            padding: 15px;
            margin: 10px 0;
            border-radius: 4px;
            display: block;
        `;
        
        // Insert after the form
        const form = document.getElementById('tapgrein_connection_form');
        if (form && form.parentNode) {
            form.parentNode.insertBefore(messageElement, form.nextSibling);
        }
    }
    
    if (messageElement) {
        if (message) {
            messageElement.textContent = message;
        }
        messageElement.style.display = 'block';
        setTimeout(function() {
            if (messageElement.parentNode) {
                messageElement.style.display = 'none';
            }
        }, 5000);
    }
}

/**
 * Admin Shortcodes Copy Functions - from admin/partials/tapgoods-admin-shortcodes.php:221
 */
function initAdminShortcodes() {
    // Copy shortcode to clipboard function
    window.copyText = function(elementId) {
        const copyText = document.getElementById(elementId);
        if (copyText) {
            copyText.select();
            copyText.setSelectionRange(0, 99999); // For mobile devices
            document.execCommand("copy");
            
            // Show feedback
            const button = document.querySelector(`button[onclick="copyText('${elementId}')"]`);
            if (button) {
                const originalText = button.innerHTML;
                button.innerHTML = 'Copied!';
                button.style.background = '#28a745';
                setTimeout(function() {
                    button.innerHTML = originalText;
                    button.style.background = '';
                }, 1500);
            }
        }
    };
}

/**
 * Admin Options Location Storage - from admin/partials/tapgoods-options.php:29
 */
function initAdminOptions() {
    console.log('TapGoods: initAdminOptions called');
    let isSetup = false;
    
    // Try to find the location select element with some delay
    function setupLocationSelect() {
        if (isSetup) return; // Avoid duplicate setup
        
        const locationSelect = document.getElementById('selected_location');
        console.log('TapGoods: Looking for selected_location element...', locationSelect);
        
        if (!locationSelect) {
            console.log('TapGoods: Location select not found on this page');
            return;
        }
        
        console.log('TapGoods: Location select found! Setting up auto-load functionality');
        console.log('TapGoods: Current selected value:', locationSelect.value);
        
        isSetup = true;
        
        // Handle location selection change
        locationSelect.addEventListener('change', function(e) {
            const selectedLocationId = e.target.value;
            console.log('TapGoods: Change event fired! Selected location ID:', selectedLocationId);
            
            if (selectedLocationId) {
                console.log('TapGoods: Valid location selected, auto-loading details...');
                autoLoadLocationDetails();
            } else {
                console.log('TapGoods: No valid location selected');
            }
        });
        
        // Test: Add a click listener as well to make sure events work
        locationSelect.addEventListener('click', function() {
            console.log('TapGoods: Location select clicked');
        });
        
        console.log('TapGoods: Event listeners added to location select');
    }
    
    // Listen for tab show events (Bootstrap tab activation)
    document.addEventListener('shown.bs.tab', function(e) {
        console.log('TapGoods: Tab shown event:', e.target);
        if (e.target.getAttribute('data-bs-target') === '#tapgrein-options') {
            console.log('TapGoods: Multi Location tab activated, setting up location select');
            setTimeout(setupLocationSelect, 100);
        }
    });
    
    // Also listen for simple tab clicks
    const optionsTab = document.getElementById('nav-options-tab');
    if (optionsTab) {
        optionsTab.addEventListener('click', function() {
            console.log('TapGoods: Options tab clicked');
            setTimeout(setupLocationSelect, 200);
        });
    }
    
    // Try immediately, then with delays
    setupLocationSelect();
    setTimeout(setupLocationSelect, 100);
    setTimeout(setupLocationSelect, 500);
    
    // Use MutationObserver to detect when the element appears
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.type === 'childList' && !isSetup) {
                setupLocationSelect();
            }
        });
    });
    
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
}

/**
 * Auto-load location details via AJAX when dropdown changes
 */
function autoLoadLocationDetails() {
    console.log('TapGoods: autoLoadLocationDetails called');
    
    const locationSelect = document.getElementById('selected_location');
    if (!locationSelect) {
        console.error('TapGoods: Location select not found');
        return;
    }
    
    const selectedLocationId = locationSelect.value;
    console.log('TapGoods: Loading details for location:', selectedLocationId);
    
    // Show loading state
    showLocationDetailsLoading();
    
    // Create form data for the request
    const formData = new FormData();
    formData.append('action', 'load_location_details');
    formData.append('location_id', selectedLocationId);
    formData.append('nonce', tg_admin_vars.sync_nonce || '');
    
    fetch(tg_admin_vars.ajaxurl, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(response => {
        console.log('TapGoods: Location details response:', response);
        
        if (response.success && response.data) {
            updateLocationDetailsSection(response.data);
        } else {
            console.error('TapGoods: Failed to load location details:', response);
            showLocationDetailsError('Failed to load location details. Please try again.');
        }
    })
    .catch(error => {
        console.error('TapGoods: Error loading location details:', error);
        showLocationDetailsError('Connection error. Please try again.');
    });
}

/**
 * Show loading state for location details
 */
function showLocationDetailsLoading() {
    const container = getLocationDetailsContainer();
    if (container) {
        container.innerHTML = `
            <h2 class="mb-4 pt-4 mt-5">Location Details</h2>
            <div class="loading-spinner">
                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                Loading location details...
            </div>
        `;
    }
}

/**
 * Show error message in location details section
 */
function showLocationDetailsError(message) {
    const container = getLocationDetailsContainer();
    if (container) {
        container.innerHTML = `
            <h2 class="mb-4 pt-4 mt-5">Location Details</h2>
            <div class="alert alert-danger">
                ${message}
            </div>
        `;
    }
}

/**
 * Update the location details section with new data
 */
function updateLocationDetailsSection(data) {
    const container = getLocationDetailsContainer();
    if (container) {
        container.innerHTML = data.html;
        console.log('TapGoods: Location details updated successfully');
    }
}

/**
 * Get or create the location details container
 */
function getLocationDetailsContainer() {
    let container = document.getElementById('location-details-container');
    
    if (!container) {
        // Try to find existing location details section by looking for the h2 with "Location Details"
        const h2Elements = document.querySelectorAll('h2');
        let existingH2 = null;
        
        for (let h2 of h2Elements) {
            if (h2.textContent && h2.textContent.includes('Location Details')) {
                existingH2 = h2;
                break;
            }
        }
        
        if (existingH2) {
            // Create a wrapper container that includes the h2 and all following elements until the end of parent
            container = document.createElement('div');
            container.id = 'location-details-container';
            
            // Insert the container before the h2
            existingH2.parentNode.insertBefore(container, existingH2);
            
            // Move the h2 and all its sibling elements into the container
            let currentElement = existingH2;
            const elementsToMove = [];
            
            // Collect all elements from h2 to the end of the parent
            while (currentElement) {
                elementsToMove.push(currentElement);
                currentElement = currentElement.nextSibling;
            }
            
            // Move all collected elements to the container
            elementsToMove.forEach(el => {
                container.appendChild(el);
            });
        } else {
            // Create new container after the separator div
            const separator = document.querySelector('.position-absolute.start-0.end-0');
            if (separator) {
                container = document.createElement('div');
                container.id = 'location-details-container';
                separator.parentElement.insertBefore(container, separator.nextSibling);
            } else {
                // Fallback: create at the end of the main container
                const mainContainer = document.querySelector('.container');
                if (mainContainer) {
                    container = document.createElement('div');
                    container.id = 'location-details-container';
                    mainContainer.appendChild(container);
                }
            }
        }
    }
    
    return container;
}

/**
 * Admin Status Page Functions - from admin/partials/tapgoods-status.php:98
 */
function initAdminStatus() {
    // Status page specific functionality
    const statusPage = document.querySelector('.tapgoods-status-page');
    if (statusPage) {
        console.log('TapGoods: Admin status page initialized');
    }
}

/**
 * TinyMCE Editor Initialization - from includes/class-tapgoods-post-types.php
 */
function initTinyMCEEditor() {
    if (typeof wp !== 'undefined' && wp.editor) {
        const textareas = document.querySelectorAll('textarea[name="tg_description"]');
        textareas.forEach(function(textarea) {
            if (textarea && !textarea.hasAttribute('data-mce-initialized')) {
                wp.editor.initialize(textarea.id, {
                    tinymce: {
                        wpautop: true,
                        plugins: 'charmap colorpicker hr lists paste tabfocus textcolor fullscreen wordpress wpautoresize wpeditimage wpemoji wpgallery wplink wptextpattern',
                        toolbar1: 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,wp_more,spellchecker,fullscreen,wp_adv',
                        toolbar2: 'strikethrough,hr,forecolor,pastetext,removeformat,charmap,outdent,indent,undo,redo,wp_help'
                    },
                    quicktags: true,
                    mediaButtons: true
                });
                textarea.setAttribute('data-mce-initialized', 'true');
            }
        });
    }
}

/**
 * Color Picker Initialization - WordPress wp-color-picker
 */
function initColorPicker() {
    if (typeof jQuery !== 'undefined' && jQuery.fn.wpColorPicker) {
        jQuery(function($) {
            $('.color-picker').wpColorPicker();
        });
    }
}

/**
 * Tag Focus Functionality - from includes/tg_edit-tags.php
 */
function initTagFocus() {
    const tagInputs = document.querySelectorAll('input[name="tag-name"]');
    tagInputs.forEach(function(input) {
        if (input) {
            input.focus();
        }
    });
}

/**
 * Sync Button Functionality - from admin connection
 */
function initSyncButton() {
    // This function is now handled by initAdminConnection()
    // Sync button functionality is managed there to avoid conflicts
    console.log('TapGoods: Sync button initialization delegated to connection handler');
}

/**
 * Show sync popup overlay
 */
function showSyncPopup() {
    const overlay = document.querySelector('.overlay');
    if (overlay) {
        overlay.style.display = 'flex';
    }
}

/**
 * Hide sync popup overlay
 */
function hideSyncPopup() {
    const overlay = document.querySelector('.overlay');
    if (overlay) {
        overlay.style.display = 'none';
    }
}

/**
 * Reset-to-default confirmation popup controls (used by admin/partials/tapgoods-admin-connection.php)
 */
function openPopup() {
    const overlay = document.getElementById('popup');
    if (overlay) {
        overlay.style.display = 'flex';
    }
}

function closePopup() {
    const overlay = document.getElementById('popup');
    if (overlay) {
        overlay.style.display = 'none';
    }
}

/**
 * Inventory Sync AJAX Function
 */
function performInventorySync() {
    // This function is deprecated - sync is now handled by handleSync() in initAdminConnection
    console.log('TapGoods: performInventorySync is deprecated, using handleSync instead');
}

/**
 * Initialize inventory sync
 */
function initInventorySync() {
    console.log('TapGoods: Inventory sync functions initialized');
}