(function ($) {

    // Sync all chip values into the hidden input
    function syncHidden(chipsEl) {
        var vals = [];
        chipsEl.find('.chip').each(function () {
            vals.push($(this).data('value'));
        });
        chipsEl.closest('.tagforge-chip-select').find('input[type="hidden"]').val(vals.join(','));
    }

    // Add a chip by slug. Returns true if added, false if already present or invalid.
    function addChip(chipsEl, slug) {
        slug = (slug || '').trim().toLowerCase();
        if (!slug) return false;

        var available = (window.TagForgeUI && Array.isArray(window.TagForgeUI.modules))
            ? window.TagForgeUI.modules : [];

        if (available.length && available.indexOf(slug) === -1) {
            alert('Unknown module: ' + slug + '\nAvailable: ' + available.join(', '));
            return false;
        }

        // Deduplicate
        var already = false;
        chipsEl.find('.chip').each(function () {
            if ($(this).data('value') === slug) { already = true; }
        });
        if (already) return false;

        var chip = $('<span class="chip" data-value="' + slug + '">'
            + slug
            + '<button type="button" class="remove" aria-label="Remove">\xd7</button></span>');
        chipsEl.find('.chip-input').before(chip);
        syncHidden(chipsEl);   // <-- always sync after adding
        return true;
    }

    // Remove a chip by slug
    function removeChip(chipsEl, slug) {
        chipsEl.find('.chip').each(function () {
            if ($(this).data('value') === slug) { $(this).remove(); }
        });
        syncHidden(chipsEl);   // <-- always sync after removing
    }

    // Keyboard: add chip on Enter
    $(document).on('keydown', '.tagforge-chip-select .chip-input', function (e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
            e.preventDefault();
            var chipsEl = $(this).closest('[data-chips]');
            var val = $(this).val();
            if (addChip(chipsEl, val)) {
                $(this).val('');
            }
        }
    });

    // Click × to remove
    $(document).on('click', '.tagforge-chip-select .chip .remove', function () {
        var chipsEl = $(this).closest('[data-chips]');
        var slug    = $(this).parent().data('value');
        removeChip(chipsEl, slug);
    });

    // Quick-add preset buttons
    $(document).on('click', '.tagforge-add-common', function () {
        var mods    = ($(this).data('mods') || '').split(',');
        var chipsEl = $(this).closest('.tagforge-wrap').find('[data-chips]');
        mods.forEach(function (m) {
            addChip(chipsEl, m.trim());
        });
    });

}(jQuery));
