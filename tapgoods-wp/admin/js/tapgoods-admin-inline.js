/**
 * TapGoods Admin Inline Scripts
 * 
 * Centralizado script handling para el área de administración
 */

// Manejo del botón de sincronización
function initSyncButton() {
    jQuery(document).ready(function($) {
        // Sync button para admin connection
        $('#tapgrein_api_sync').on('click', function() {
            $(this).prop('disabled', true).text('Syncing...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'execute_manual_sync',
                    nonce: tg_admin_vars.sync_nonce
                },
                success: function(response) {
                    alert('Synchronization completed.');
                    $('#tapgrein_api_sync').prop('disabled', false).text('SYNC');
                },
                error: function() {
                    alert('Synchronization failed.');
                    $('#tapgrein_api_sync').prop('disabled', false).text('SYNC');
                }
            });
        });
    });
}

// Funciones para popup de confirmación
function openPopup() {
    document.getElementById('popup').style.display = 'flex';
}

function closePopup() {
    document.getElementById('popup').style.display = 'none';
}

// Sync inventory functionality
function initInventorySync() {
    jQuery(function($) {
        function syncInventory() {
            $('#tapgrein_api_sync').prop('disabled', true).text('WORKING');
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'tapgoods_manual_sync'
                },
                success: function(response) {
                    $('#tapgoods_sync_status').text(response.message);
                    if (response.success && response.continue) {
                        setTimeout(function() {
                            $('#tapgrein_api_sync').click();
                        }, 1000);
                    } else {
                        $('#tapgrein_api_sync').prop('disabled', false).text('SYNC');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error during sync: ' + error);
                    $('#tapgoods_sync_status').text('Error during sync: ' + error);
                    $('#tapgrein_api_sync').prop('disabled', false).text('SYNC');
                }
            });
        }

        // Start synchronization when the button is clicked
        $('#tapgrein_api_sync').on('click', function() {
            syncInventory();
        });
    });
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