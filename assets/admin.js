jQuery(document).ready(function ($) {

    /* ── Manual full sync (batch processing with progress bar) ── */

    var syncInProgress = false;
    var totalProcessed = 0;
    var totalCreated   = 0;
    var totalUpdated   = 0;
    var totalSkipped   = 0;

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
                    $('#woo-rs-sync-status').text('Error: ' + (response.data || 'Unknown error'));
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
                $('#woo-rs-sync-status').text('AJAX Error: ' + error);
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
