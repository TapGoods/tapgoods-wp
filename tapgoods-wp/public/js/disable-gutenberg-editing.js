wp.domReady(() => {
    // Disable title editing directly
    const disableTitleField = () => {
        const titleField = document.querySelector('.editor-post-title__input');
        if (titleField) {
            titleField.setAttribute('readonly', true);  // Set the field to read-only
            titleField.setAttribute('disabled', true);  // Completely disable the field
            titleField.style.pointerEvents = 'none';    // Disable interaction
            titleField.style.opacity = '0.5';           // Visual style to indicate it's disabled
        }
    };

    // Ensure the title remains disabled even if there are DOM changes
    disableTitleField();
    wp.data.subscribe(() => {
        disableTitleField();
    });

    // Hide or disable the block inserter buttons
    const inserterButton = document.querySelector('.block-editor-inserter__toggle');
    if (inserterButton) {
        inserterButton.style.display = 'none'; // Hide the block inserter button
    }

    // Programmatically disable block insertion
    wp.blocks.getBlockTypes().forEach(function(blockType) {
        wp.blocks.unregisterBlockType(blockType.name);  // Unregister all block types
    });

    // Disable manual saving and autosaving
    wp.data.dispatch('core/editor').lockPostSaving();  // Lock the ability to save changes
    wp.data.dispatch('core/editor').disablePostSaving();  // Disable autosaving

    // Hide the save button
    const saveButton = document.querySelector('.editor-post-publish-button');
    if (saveButton) {
        saveButton.disabled = true;          // Disable the button
        saveButton.style.pointerEvents = 'none';  // Disable interaction
        saveButton.style.opacity = '0.5';    // Visual style to indicate it's disabled
    }
});
