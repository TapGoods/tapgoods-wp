"use strict";

(function($,window,document,undefined) {
    $( document ).ready(function() {
        // show the current tab based on the hash fragment
        show_hash_tab(get_hash());

        const editorList = [];
        editorList.push(wp.codeEditor.initialize($('#tg-custom-css'), tg_editor_settings));
        editorList.push(wp.codeEditor.initialize($('#tg-css'), tg_viewer_settings));

        // enable updating the url on tab navigation for each tab, refresh editors when shown
        const tabList = document.querySelectorAll('button[data-bs-toggle="tab"]');
        tabList.forEach( (tab) => {
            enable_tab_nav(tab);
            if("#styling" == tab.dataset.bsTarget) {
                tab.addEventListener('shown.bs.tab', () => refresh_editors(editorList) );
            }
        });

        const connectButton = $('#tg_update_connection');
        const connectInput = $('#tapgoods_api_key');

        // show the correct tab if the url changes
        window.addEventListener( "hashchange", () => { show_hash_tab(get_hash()) } , false, );
        init_tooltips();

        connectInput.on('change input', function(e) {
            if( e.target.value === e.target.dataset.original ) {
                connectButton.prop('disabled', true ).text( connectButton.data('original') );
                return;
            }
            connectButton.removeAttr('disabled').text('CONNECT');
        });

        $('#tg_connection_form').on('submit', function(e) {
            e.preventDefault();
            connectButton.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> CONNECTING');
            let data = $('#tg_connection_form').serialize() + '&action=tg_update_connection';
            
            $.ajax({
                url: tg_ajax.ajaxurl,
                type: 'post',
                data: data,
                success: function( response ) {
                    console.log(response);
                    if ( response.success ) {
                        connectButton.prop('disabled', true).text('CONNECTED');
                        const newVal = connectInput.val();
                        connectInput.attr('data-original', newVal).attr('value', newVal );
                    } else {
                        connectButton.removeAttr('disabled').text('CONNECT');
                    }
                    const el = document.getElementById( "connection" );
                    show_notice( response.data, el );

                },
                error: function( response ){
                    console.log( response );
                }
            })
        });
    });
})(jQuery,window,document)

function show_notice( notice, el ) 
{
    const template = document.createElement('div');
    template.innerHTML = notice;
    if( template.firstChild.classList.contains('is-dismissible') ) {
        template.firstChild.insertAdjacentHTML('beforeend', '<button type="button" class="notice-dismiss" onclick="javascript: return tg_dissmiss_notice(this);"><span class="screen-reader-text">Dismiss this notice.</span></button>');
    }
    el.insertAdjacentElement( 'afterbegin', template.firstChild );
}

function tg_dissmiss_notice( notice )
{
    jQuery( notice ).parent().slideUp("normal", function() {jQuery(this).remove();});
    return false;
}

function get_hash() 
{
    return location.hash.replace(/^#/, '');
}
    
function show_hash_tab(hash = false) 
{
    // exit if no url fragment
    if (! hash) return;
    
    const selector = 'button[data-bs-target="#'+ hash +'"]';
    const tab = document.querySelector(selector);
    
    // exit if no matching tabs
    if (tab === null) return;

    bootstrap.Tab.getOrCreateInstance(tab).show();
}

function refresh_editors(editors) 
{
    editors.map( (editor) => editor.codemirror.refresh() );
}

// updates the url on tab nav
function enable_tab_nav(tabEl) 
{
    tabEl.addEventListener('shown.bs.tab', function(event){
        window.location.hash = event.target.dataset.bsTarget;
    });
}

// initialize or reset the tooltips. Called on load and when someone clicks on a tooltip button to update the tooltip text

function init_tooltips() 
{
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');

    // use getOrCreateInstance > new Tooltip so that we can call this to reset any tooltips that have already been initialized
    const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => bootstrap.Tooltip.getOrCreateInstance(tooltipTriggerEl).setContent({ '.tooltip-inner': tooltipTriggerEl.dataset.bsTitle}));
}

function copyText(btn) 
{
    // first reset all the tooltips to their original titles
    init_tooltips();

    // select and copy the text with fix for mobile
    const copyText = document.getElementById(btn.dataset.target);
    copyText.select();
    copyText.setSelectionRange(0, 99999); 
    navigator.clipboard.writeText(copyText.value);
    
    // update the tooltip for this button with a success message
    const tt = bootstrap.Tooltip.getInstance(btn);
    tt.setContent({ '.tooltip-inner': 'Copied'});
}