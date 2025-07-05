jQuery(document).ready(function($) {
    // Recheck URL
    $(document).on('click', '.clm-action-recheck', function(e) {
        e.preventDefault();
        var $link = $(this);
        var id = $link.data('id');
        var nonce = $link.data('nonce');
        var $row = $link.closest('tr');
        var $statusCell = $row.find('td.column-http_status');

        $link.html('<span class="spinner is-active" style="float:none; margin-right: 5px;"></span>' + clm_object.i18n.rechecking);

        $.ajax({
            url: clm_object.ajax_url, // Using localized ajax_url
            type: 'POST',
            data: {
                action: 'clm_recheck_url',
                id: id,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    $statusCell.text(response.data.status_text);
                    // Update row class based on new status if needed
                    $row.removeClass('status-error status-redirect status-valid'); // Clear existing status classes
                    if (response.data.status_code >= 200 && response.data.status_code < 300) {
                        $row.addClass('status-valid');
                    } else if (response.data.status_code >= 300 && response.data.status_code < 400) {
                        $row.addClass('status-redirect');
                    } else {
                        $row.addClass('status-error');
                    }
                     // Restore original link text
                    $link.html(clm_object.i18n.recheck);
                } else {
                    alert(response.data.message || clm_object.i18n.error);
                    $link.html(clm_object.i18n.recheck);
                }
            },
            error: function() {
                alert(clm_object.i18n.error);
                $link.html(clm_object.i18n.recheck);
            }
        });
    });

    // Remove URL entry
    $(document).on('click', '.clm-action-remove', function(e) {
        // Confirmation is handled by inline onclick, this is the AJAX part
        // return true; // Let the onclick confirm handle it
        var $link = $(this);
        var id = $link.data('id');
        var nonce = $link.data('nonce');
        var $row = $link.closest('tr');

        // Optimistically hide the row or show spinner
        // $row.css('opacity', '0.5');

        $.ajax({
            url: clm_object.ajax_url, // Using localized ajax_url
            type: 'POST',
            data: {
                action: 'clm_remove_url',
                id: id,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                    // TODO: Update pagination count if possible, or just reload table data.
                    // For now, user might need to refresh for pagination to be perfect.
                } else {
                    alert(response.data.message || clm_object.i18n.error);
                    // $row.css('opacity', '1');
                }
            },
            error: function() {
                alert(clm_object.i18n.error);
                // $row.css('opacity', '1');
            }
        });
       return false; // Prevent default if confirm was not part of this click handler
    });

    // Mark as Not Broken (currently same as remove)
    $(document).on('click', '.clm-action-mark-not-broken', function(e) {
        e.preventDefault();
        if (!confirm(clm_object.i18n.confirmMarkNotBroken)) {
            return false;
        }
        var $link = $(this);
        var id = $link.data('id');
        var nonce = $link.data('nonce');
        var $row = $link.closest('tr');

        $.ajax({
            url: clm_object.ajax_url, // Using localized ajax_url
            type: 'POST',
            data: {
                action: 'clm_mark_not_broken',
                id: id,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                } else {
                    alert(response.data.message || clm_object.i18n.error);
                }
            },
            error: function() {
                alert(clm_object.i18n.error);
            }
        });
    });

    // Replace Redirect URL
    $(document).on('click', '.clm-action-replace-redirect', function(e) {
        e.preventDefault();
        if (!confirm(clm_object.i18n.confirmReplaceRedirect)) {
            return false;
        }
        var $link = $(this);
        var id = $link.data('id');
        var nonce = $link.data('nonce');
        var $row = $link.closest('tr');
        var $urlCell = $row.find('td.column-url a:first');
        var $statusCell = $row.find('td.column-http_status');

        $link.html('<span class="spinner is-active" style="float:none; margin-right: 5px;"></span>' + clm_object.i18n.replacing);


        $.ajax({
            url: clm_object.ajax_url, // Using localized ajax_url
            type: 'POST',
            data: {
                action: 'clm_replace_redirect_url',
                id: id,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    $urlCell.attr('href', response.data.new_url).text(response.data.new_url);
                    $statusCell.text(response.data.status_text);
                     // Update row class based on new status if needed
                    $row.removeClass('status-error status-redirect status-valid'); // Clear existing status classes
                    if (response.data.status_code >= 200 && response.data.status_code < 300) {
                        $row.addClass('status-valid');
                    } else if (response.data.status_code >= 300 && response.data.status_code < 400) {
                        $row.addClass('status-redirect');
                         // Potentially remove the "Use Final URL" action if it's no longer a redirect
                        $link.remove();
                    } else {
                        $row.addClass('status-error');
                         $link.remove(); // Or hide, as it's no longer a redirect
                    }
                    $link.html(clm_object.i18n.useFinalUrl); // Restore or remove
                    alert(response.data.message);
                } else {
                    alert(response.data.message || clm_object.i18n.error);
                    $link.html(clm_object.i18n.useFinalUrl);
                }
            },
            error: function() {
                alert(clm_object.i18n.error);
                 $link.html(clm_object.i18n.useFinalUrl);
            }
        });
    });

});
