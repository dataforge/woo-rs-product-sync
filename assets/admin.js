jQuery(document).ready(function ($) {

    /* ── Manual full sync (batch processing with progress bar) ── */

    var syncInProgress = false;
    var totalProcessed = 0;
    var totalCreated   = 0;
    var totalUpdated   = 0;
    var totalSkipped   = 0;

    function errorMessage(data, fallback) {
        if (data && typeof data === 'object') {
            if (data.message) {
                return data.message;
            }
            if (data.data && data.data.message) {
                return data.data.message;
            }
            if (data.code) {
                return data.code;
            }
        }
        return data || fallback;
    }

    function renderSyncError(data, fallback) {
        var $status = $('#woo-rs-sync-status');
        var details = data && data.data && typeof data.data === 'object' ? data.data : data;

        if (data && data.code === 'rs_sku_conflict' && details && details.wc_product_id && details.rs_product_id) {
            renderSkuConflict(details);
            return;
        }

        $status.empty().append(document.createTextNode('Error: ' + errorMessage(data, fallback)));

        if (details && typeof details === 'object' && (details.wc_edit_url || details.rs_product_url)) {
            var $links = $('<span class="woo-rs-sync-error-links"> — </span>');

            if (details.wc_edit_url) {
                $links.append($('<a>', {
                    href: details.wc_edit_url,
                    text: 'Open WooCommerce product',
                    target: '_blank',
                    rel: 'noopener noreferrer'
                }));
            }

            if (details.rs_product_url) {
                if (details.wc_edit_url) {
                    $links.append(document.createTextNode(' | '));
                }
                $links.append($('<a>', {
                    href: details.rs_product_url,
                    text: 'Open RepairShopr product',
                    target: '_blank',
                    rel: 'noopener noreferrer'
                }));
            }

            $status.append($links);
        }
    }

    function productLink(url, label) {
        if (!url) {
            return null;
        }
        return $('<a>', {
            href: url,
            text: label,
            target: '_blank',
            rel: 'noopener noreferrer'
        });
    }

    function renderSkuConflict(details) {
        var $status = $('#woo-rs-sync-status').empty();
        var $card = $('<div>', { 'class': 'woo-rs-sync-conflict' });
        var $woo = $('<div>', { 'class': 'woo-rs-sync-conflict-product' })
            .append($('<span>', { 'class': 'woo-rs-sync-conflict-label', text: 'WooCommerce product' }))
            .append($('<strong>', { text: details.wc_product_name || 'Unknown WooCommerce product' }))
            .append($('<span>', { 'class': 'woo-rs-sync-conflict-id', text: ' — SKU: ' + (details.wc_product_sku || 'Unknown') }));
        var wooLink = productLink(details.wc_edit_url, 'Open WooCommerce product');
        if (wooLink) {
            $woo.append($('<div>', { 'class': 'woo-rs-sync-conflict-link' }).append(wooLink));
        }

        var $rs = $('<div>', { 'class': 'woo-rs-sync-conflict-product' })
            .append($('<span>', { 'class': 'woo-rs-sync-conflict-label', text: 'RepairShopr product' }))
            .append($('<strong>', { text: details.rs_product_name || 'Unknown RepairShopr product' }))
            .append($('<span>', { 'class': 'woo-rs-sync-conflict-id', text: ' — Product ID: ' + details.rs_product_id }));
        var rsLink = productLink(details.rs_product_url, 'Open RepairShopr product');
        if (rsLink) {
            $rs.append($('<div>', { 'class': 'woo-rs-sync-conflict-link' }).append(rsLink));
        }

        var $actions = $('<div>', { 'class': 'woo-rs-sync-conflict-actions' });
        var $match = $('<button>', { type: 'button', 'class': 'button button-primary', text: 'Match & Continue' });
        var $notMatch = $('<button>', { type: 'button', 'class': 'button', text: 'Not a Match' });

        $match.on('click', function () {
            $match.add($notMatch).prop('disabled', true);
            $.ajax({
                url: woo_rs_sync.ajax_url,
                type: 'POST',
                data: {
                    action: 'woo_rs_match_sku_conflict',
                    nonce: woo_rs_sync.nonce,
                    rs_product_id: details.rs_product_id,
                    wc_product_id: details.wc_product_id
                },
                success: function (response) {
                    if (!response.success) {
                        renderSyncError(response.data, 'Unable to match these products.');
                        return;
                    }
                    $status.text('Products matched. Restarting full sync...');
                    $('#woo-rs-start-sync').prop('disabled', false).trigger('click');
                },
                error: function (xhr, status, error) {
                    var payload = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : null;
                    renderSyncError(payload, error);
                }
            });
        });

        $notMatch.on('click', function () {
            $status.empty().append(document.createTextNode('Products were not linked. Change the WooCommerce product SKU, then run the sync again.'));
            if (details.wc_edit_url || details.rs_product_url) {
                var $links = $('<span class="woo-rs-sync-error-links"> â€” </span>');
                var notMatchWooLink = productLink(details.wc_edit_url, 'Open WooCommerce product');
                var notMatchRsLink = productLink(details.rs_product_url, 'Open RepairShopr product');
                if (notMatchWooLink) {
                    $links.append(notMatchWooLink);
                }
                if (notMatchWooLink && notMatchRsLink) {
                    $links.append(document.createTextNode(' | '));
                }
                if (notMatchRsLink) {
                    $links.append(notMatchRsLink);
                }
                $status.append($links);
            }
        });

        $actions.append($match, $notMatch);
        $card.append(
            $('<p>', { text: 'A WooCommerce product already has the same SKU. Are these the same item?' }),
            $woo,
            $rs,
            $actions
        );
        $status.append($card);
    }

    $('#woo-rs-start-sync').on('click', function (e) {
        e.preventDefault();

        if (syncInProgress) {
            return;
        }

        syncInProgress = true;
        totalProcessed = 0;
        totalCreated   = 0;
        totalUpdated   = 0;
        totalSkipped   = 0;

        $(this).prop('disabled', true);
        $('.woo-rs-progress-container').show();
        $('#woo-rs-sync-status').text('Starting sync...').show();
        $('#woo-rs-sync-progress').css('width', '0%').text('0%');

        processBatch(1);
    });

    function processBatch(page) {
        $.ajax({
            url: woo_rs_sync.ajax_url,
            type: 'POST',
            data: {
                action:   'woo_rs_run_manual_sync',
                nonce:    woo_rs_sync.nonce,
                page:     page,
                per_page: 50
            },
            success: function (response) {
                if (!response.success) {
                    syncInProgress = false;
                    $('#woo-rs-start-sync').prop('disabled', false);
                    renderSyncError(response.data, 'Unknown error');
                    return;
                }

                var data = response.data;
                totalProcessed += data.processed;
                totalCreated   += data.stats.created;
                totalUpdated   += data.stats.updated;
                totalSkipped   += data.stats.skipped;

                // Update status text
                $('#woo-rs-sync-status').text(
                    'Processed ' + totalProcessed + ' products — ' +
                    totalCreated + ' created, ' +
                    totalUpdated + ' updated, ' +
                    totalSkipped + ' skipped'
                );

                if (data.more && data.next_page) {
                    // Estimate progress (we don't know total, so just pulse)
                    var pct = Math.min(90, totalProcessed * 2);
                    $('#woo-rs-sync-progress').css('width', pct + '%').text(pct + '%');
                    processBatch(data.next_page);
                } else {
                    // Done
                    $('#woo-rs-sync-progress').css('width', '100%').text('100%');
                    $('#woo-rs-sync-status').text(
                        'Sync complete! Processed ' + totalProcessed + ' products — ' +
                        totalCreated + ' created, ' +
                        totalUpdated + ' updated, ' +
                        totalSkipped + ' skipped'
                    );
                    syncInProgress = false;
                    $('#woo-rs-start-sync').prop('disabled', false);

                    setTimeout(function () {
                        window.location.reload();
                    }, 2000);
                }
            },
            error: function (xhr, status, error) {
                syncInProgress = false;
                $('#woo-rs-start-sync').prop('disabled', false);
                var payload = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : null;
                renderSyncError(payload, error);
            }
        });
    }

    /* ── Refresh RS categories via AJAX ── */

    $('#woo-rs-refresh-categories').on('click', function (e) {
        e.preventDefault();

        var $btn    = $(this);
        var $status = $('#woo-rs-refresh-status');

        $btn.prop('disabled', true);
        $status.text('Fetching categories from RepairShopr...');

        $.ajax({
            url: woo_rs_sync.ajax_url,
            type: 'POST',
            data: {
                action: 'woo_rs_refresh_categories',
                nonce:  woo_rs_sync.nonce
            },
            success: function (response) {
                $btn.prop('disabled', false);
                if (response.success) {
                    $status.text('Categories refreshed! Reloading...');
                    setTimeout(function () {
                        window.location.reload();
                    }, 500);
                } else {
                    $status.text('Error: ' + (response.data || 'Unknown error'));
                }
            },
            error: function (xhr, status, error) {
                $btn.prop('disabled', false);
                $status.text('AJAX Error: ' + error);
            }
        });
    });

    /* ── Save single category mapping via AJAX ── */

    $(document).on('click', '.woo-rs-save-category', function (e) {
        e.preventDefault();

        var $btn     = $(this);
        var rsCat    = $btn.data('rs-category');
        var selectId = $btn.data('select-id');
        var selected = $('#' + selectId).val() || [];

        $btn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: woo_rs_sync.ajax_url,
            type: 'POST',
            data: {
                action:        'woo_rs_save_category_mapping',
                nonce:         woo_rs_sync.nonce,
                rs_category:   rsCat,
                wc_categories: selected
            },
            success: function (response) {
                if (response.success) {
                    $btn.text('Saved!');
                    setTimeout(function () {
                        window.location.reload();
                    }, 500);
                } else {
                    $btn.prop('disabled', false).text('Error');
                }
            },
            error: function () {
                $btn.prop('disabled', false).text('Error');
            }
        });
    });

    /* ── Create WC category matching RS category name ── */

    $(document).on('click', '.woo-rs-create-category', function (e) {
        e.preventDefault();

        var $btn  = $(this);
        var rsCat = $btn.data('rs-category');

        $btn.prop('disabled', true).text('Creating...');

        $.ajax({
            url: woo_rs_sync.ajax_url,
            type: 'POST',
            data: {
                action:      'woo_rs_create_wc_category',
                nonce:       woo_rs_sync.nonce,
                rs_category: rsCat
            },
            success: function (response) {
                if (response.success) {
                    $btn.text('Created! Reloading...');
                    setTimeout(function () {
                        window.location.reload();
                    }, 500);
                } else {
                    $btn.prop('disabled', false).text('Error: ' + (response.data || 'Unknown'));
                }
            },
            error: function (xhr, status, error) {
                $btn.prop('disabled', false).text('Error: ' + error);
            }
        });
    });

    /* ── Test OpenAI API key ── */

    $('#woo-rs-test-openai').on('click', function (e) {
        e.preventDefault();

        var $btn     = $(this);
        var $spinner = $('#woo-rs-openai-test-spinner');
        var $result  = $('#woo-rs-openai-test-result');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.hide().empty();

        $.ajax({
            url: woo_rs_sync.ajax_url,
            type: 'POST',
            timeout: 130000,
            data: {
                action:       'woo_rs_test_openai',
                nonce:        woo_rs_sync.nonce,
                product_name: $('#woo-rs-test-openai-name').val(),
                description:  $('#woo-rs-test-openai-input').val()
            },
            success: function (response) {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');

                if (response.success) {
                    var d = response.data;
                    var html = '<div class="notice notice-success inline" style="padding:8px 12px;">' +
                        '<strong>Success!</strong> Model: <code>' + $('<span>').text(d.model).html() + '</code>';

                    if (d.usage) {
                        html += ' &mdash; Tokens: ' + d.usage.prompt_tokens + ' in / ' + d.usage.completion_tokens + ' out';
                    }

                    html += '</div>';
                    html += '<div style="margin-top:8px;"><strong>Sample input:</strong></div>';
                    html += '<pre class="woo-rs-payload" style="background:#f6f6f6; padding:8px; white-space:pre-wrap;">' + $('<span>').text(d.input).html() + '</pre>';
                    html += '<div style="margin-top:8px;"><strong>Rewritten output:</strong></div>';
                    html += '<pre class="woo-rs-payload" style="background:#f0f8f0; padding:8px; white-space:pre-wrap;">' + $('<span>').text(d.output).html() + '</pre>';

                    $result.html(html).show();
                } else {
                    $result.html(
                        '<div class="notice notice-error inline" style="padding:8px 12px;">' +
                        '<strong>Error:</strong> ' + $('<span>').text(response.data).html() +
                        '</div>'
                    ).show();
                }
            },
            error: function (xhr, status, error) {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
                $result.html(
                    '<div class="notice notice-error inline" style="padding:8px 12px;">' +
                    '<strong>AJAX Error:</strong> ' + $('<span>').text(error).html() +
                    '</div>'
                ).show();
            }
        });
    });

    /* ── Auto-sync toggle: enable/disable interval field ── */

    var $autoSync  = $('#woo_rs_auto_sync');
    var $interval  = $('#woo_rs_sync_interval');

    function toggleInterval() {
        if ($autoSync.length) {
            $interval.prop('disabled', !$autoSync.is(':checked'));
        }
    }

    $autoSync.on('change', toggleInterval);
    toggleInterval();
});
