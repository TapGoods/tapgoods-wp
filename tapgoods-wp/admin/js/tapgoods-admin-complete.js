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
 * Admin Connection Success Messages - from admin/partials/tapgoods-admin-connection.php:48
 */
function showSuccessMessage() {
    const messageElement = document.querySelector('.tapgoods-success');
    if (messageElement) {
        messageElement.style.display = 'block';
        setTimeout(function() {
            messageElement.style.display = 'none';
        }, 3000);
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
    // This function will be populated via wp_add_inline_script with location data
    console.log('TapGoods: Admin options initialized');
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
    const syncButton = document.querySelector('#tg_api_sync');
    if (syncButton) {
        syncButton.addEventListener('click', function(e) {
            e.preventDefault();
            
            if (confirm('¿Estás seguro de que quieres sincronizar el inventario? Esto puede tomar varios minutos.')) {
                this.disabled = true;
                this.innerHTML = 'Sincronizando...';
                
                // Show popup overlay
                showSyncPopup();
                
            // Perform AJAX sync
            performInventorySync();
            }
        });
    }
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
    if (typeof tg_admin_vars === 'undefined') {
        console.error('TapGoods admin variables not loaded');
        return;
    }
    
    const xhr = new XMLHttpRequest();
    xhr.open('POST', tg_admin_vars.ajaxurl, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        console.log('Sync completed successfully');
                        location.reload(); // Refresh page to show updated data
                    } else {
                        console.error('Sync failed:', response.data);
                        alert('Error en la sincronización: ' + response.data);
                    }
                } catch (e) {
                    console.error('Error parsing sync response:', e);
                    alert('Error procesando la respuesta de sincronización');
                }
            } else {
                console.error('Sync request failed:', xhr.status);
                alert('Error en la conexión durante la sincronización');
            }
            
            hideSyncPopup();
            
            // Re-enable sync button
            const syncButton = document.querySelector('#sync-inventory-btn');
            if (syncButton) {
                syncButton.disabled = false;
                syncButton.innerHTML = 'Sincronizar Inventario';
            }
        }
    };
    
    const params = 'action=tg_api_sync';
    xhr.send(params);
}

/**
 * Initialize inventory sync
 */
function initInventorySync() {
    console.log('TapGoods: Inventory sync functions initialized');
}