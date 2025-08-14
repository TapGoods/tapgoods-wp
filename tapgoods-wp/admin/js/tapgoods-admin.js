"use strict";

(function ($, window, document, undefined) {
    function loadBootstrap(callback) {
        if (typeof bootstrap !== "undefined") {
            console.log("Bootstrap ya está cargado.");
            callback();
            return;
        }

        console.log("Cargando Bootstrap...");

        let script = document.createElement("script");
        script.src = tapgoods_vars.pluginUrl + 'assets/js/bootstrap.bundle.min.js';
        script.onload = function () {
            console.log("Bootstrap cargado correctamente.");
            callback();
        };
        script.onerror = function () {
            console.error("Error al cargar Bootstrap.");
        };

        document.head.appendChild(script);
    }

    function initTapGoods() {
        $(document).ready(function () {
            // Show the current tab based on the hash fragment
            show_hash_tab(get_hash());

            // Enable updating the URL on tab navigation for each tab, refresh editors when shown
            const tabList = document.querySelectorAll('button[data-bs-toggle="tab"]');
            tabList.forEach((tab) => {
                enable_tab_nav(tab);
                if ("#styling" == tab.dataset.bsTarget) {
                    tab.addEventListener('shown.bs.tab', () => refresh_editors(editorList));
                }
            });

            // Initialize the CSS editors with debugging
            const editorList = [];
            const tgCustomCss = $('#tg-tapgrein_custom-css');
            const tgCss = $('#tg-css');

            console.log('tg-tapgrein_custom-css element:', tgCustomCss);
            console.log('tg-css element:', tgCss);

            if (tgCustomCss.length) {
                try {
                    editorList.push(wp.codeEditor.initialize(tgCustomCss, tg_editor_settings));
                    console.log('tg-tapgrein_custom-css editor initialized successfully.');
                } catch (error) {
                    console.error('Error initializing tg-tapgrein_custom-css editor:', error);
                }
            } else {
                console.error('tg-tapgrein_custom-css element not found!');
            }

            if (tgCss.length) {
                try {
                    editorList.push(wp.codeEditor.initialize(tgCss, tg_viewer_settings));
                    console.log('tg-css editor initialized successfully.');
                } catch (error) {
                    console.error('Error initializing tg-css editor:', error);
                }
            } else {
                console.error('tg-css element not found!');
            }

            // Setup the inputs for the connection form
            const connectButton = $('#tg_update_connection');
            const connectInput = $('#tapgoods_api_key');
            const syncButton = $('#tg_api_sync');

            console.log('Captured Input Element:', connectInput); // Debugging log

            // Show the correct tab if the URL changes
            window.addEventListener("hashchange", () => {
                show_hash_tab(get_hash());
            }, false);

            init_tooltips();

            // Handle input changes for the API key
            connectInput.on('change input', function (e) {
                if (e.target.value === e.target.dataset.original) {
                    connectButton.prop('disabled', true).text(connectButton.data('original'));
                    if ('' !== e.target.value) {
                        syncButton.show();
                    }
                    return;
                }
                connectButton.removeAttr('disabled').text('CONNECT');
                syncButton.hide();
            });

            // Form submission event
            $('#tg_connection_form').on('submit', { btn: connectButton, input: connectInput }, tg_connect);
            syncButton.on('click', tg_sync);
        });
    }

    function tg_connect(event) {
        event.preventDefault();
        let connectButton = event.data.btn;
        let connectInput = event.data.input;
        const syncButton = $('#tg_api_sync');
        const el = document.getElementById("tg_ajax_connection");

        connectButton.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> CONNECTING');

        // Debugging: Log API key and serialized form data
        console.log('API Key Value:', connectInput.val());
        console.log('Serialized Form Data:', $('#tg_connection_form').serialize());

        let data = $('#tg_connection_form').serialize() + '&action=tg_update_connection';

        $.ajax({
            url: tg_ajax.ajaxurl,
            type: 'post',
            data: data,
            success: function (response) {
                console.log('AJAX Response:', response);
                if (response.success) {
                    connectButton.prop('disabled', true).text('CONNECTED');
                    const newVal = connectInput.val();
                    connectInput.attr('data-original', newVal).attr('value', newVal);
                    syncButton.show();
                } else {
                    connectButton.removeAttr('disabled').text('CONNECT');
                    syncButton.hide();
                }
                if (el) {
                    show_notice(response.data, el);
                }
            },
            error: function (response) {
                console.error('AJAX Error Response:', response);
                if (el) {
                    show_notice(response.data, el);
                }
                syncButton.hide();
            }
        });
    }

    function tg_sync(event) {
        event.preventDefault();

        const nonce = $('#_tgnonce_connection').val();
        const syncBtn = $('#tg_api_sync');
        syncBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> WORKING');
        const url = tg_ajax.ajaxurl + '?action=tg_api_sync&_tgnonce_connection=' + nonce;
        const statusEl = document.getElementById('tg_connection_test');

        $.ajax({
            url: url,
            type: 'get',
            success: function (response) {
                console.log('Sync Response:', response);
                syncBtn.removeAttr('disabled').text('SYNC');
                if (statusEl) {
                    show_notice(response.data, statusEl);
                }
            },
            error: function (response) {
                console.error('Sync Error:', response);
                if (statusEl) {
                    show_notice(response.data, statusEl);
                }
            },
        });
    }

    function show_notice(notice, el) {
        if (!notice || !el) {
            console.log('Notice or target element is undefined.');
            return;
        }

        console.log('Notice Element:', el);
        const template = document.createElement('div');
        template.innerHTML = notice;
        if (template.firstChild && template.firstChild.classList.contains('is-dismissible')) {
            template.firstChild.insertAdjacentHTML('beforeend', '<button type="button" class="notice-dismiss" onclick="javascript: return tg_dismiss_notice(this);"><span class="screen-reader-text">Dismiss this notice.</span></button>');
        }
        el.innerHTML = notice;
        $(el).removeAttr('hidden');
    }

    // Cargar Bootstrap antes de ejecutar TapGoods
    loadBootstrap(initTapGoods);

})(jQuery, window, document);

function tg_dismiss_notice(notice) {
    jQuery(notice).parent().slideUp("normal", function () { jQuery(this).remove(); });
    return false;
}

function get_hash() {
    return location.hash.replace(/^#/, '');
}

function show_hash_tab(hash = false) {
    if (!hash) return;

    const selector = 'button[data-bs-target="#' + hash + '"]';
    const tab = document.querySelector(selector);

    if (tab === null) return;

    bootstrap.Tab.getOrCreateInstance(tab).show();
}

function refresh_editors(editors) {
    editors.map((editor) => editor.codemirror.refresh());
}

function enable_tab_nav(tabEl) {
    tabEl.addEventListener('shown.bs.tab', function (event) {
        window.location.hash = event.target.dataset.bsTarget;
    });
}

function init_tooltips() {
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    [...tooltipTriggerList].map(tooltipTriggerEl => bootstrap.Tooltip.getOrCreateInstance(tooltipTriggerEl).setContent({ '.tooltip-inner': tooltipTriggerEl.dataset.bsTitle }));
}
