/**
 * TapGoods Admin Inline Scripts
 * 
 * Centralizado script handling para el área de administración
 */

// Manejo del botón de sincronización - DEPRECATED
function initSyncButton() {
    // This function is now handled in tapgoods-admin-complete.js
    // Keeping empty to avoid conflicts
    console.log('TapGoods: Sync button handling moved to complete.js');
}

// Funciones para popup de confirmación
function openPopup() {
    document.getElementById('popup').style.display = 'flex';
}

function closePopup() {
    document.getElementById('popup').style.display = 'none';
}

// Sync inventory functionality - DEPRECATED
function initInventorySync() {
    // This function is now handled in tapgoods-admin-complete.js
    // Keeping empty to avoid conflicts
    console.log('TapGoods: Inventory sync handling moved to complete.js');
}

// Focus en form de tags
function initTagFocus() {
    try {
        if (document.forms.addtag && document.forms.addtag['tag-name']) {
            document.forms.addtag['tag-name'].focus();
        }
    } catch(e) {
        // Silent fail
    }
}

// Color picker functionality
function initColorPicker() {
    jQuery(document).ready(function($) {
        if (typeof $.fn.wpColorPicker !== 'undefined') {
            $('.color-picker').wpColorPicker();
        }
    });
}

// TinyMCE editor functionality for custom description
function initTinyMCEEditor() {
    jQuery(document).ready(function($) {
        // Ensure TinyMCE is initialized for our editor
        if (typeof tinymce !== 'undefined') {
            tinymce.on('AddEditor', function(e) {
                if (e.editor.id === 'tgcustomdescription') {
                    e.editor.on('change', function() {
                        e.editor.save();
                    });
                }
            });
        }
    });
}

// Initialize all admin functionality
jQuery(document).ready(function() {
    initSyncButton();
    initInventorySync();
    initColorPicker();
    initTinyMCEEditor();
    
    // Only init tag focus if not on mobile
    if (typeof tg_admin_vars !== 'undefined' && !tg_admin_vars.is_mobile) {
        initTagFocus();
    }
});