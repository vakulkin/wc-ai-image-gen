/**
 * WC AI Image Generator — Frontend JS
 *
 * Attribute listener, debounce, abort, AJAX calls,
 * client-side polling (webhook-based backend), orbit loader, SweetAlert2 modal.
 */
(function ($) {
    'use strict';

    if (typeof wcaig === 'undefined') {
        return;
    }

    /* ================================================================
     *  Config (from wp_localize_script)
     * ================================================================ */

    var POLL_INTERVAL     = (wcaig.poll_interval || 5) * 1000;   // ms
    var MAX_POLL_ATTEMPTS = wcaig.max_poll_attempts || 60;

    /* ================================================================
     *  State
     * ================================================================ */

    var currentXHR     = null;   // In-flight AJAX (check / generate).
    var pollTimer      = null;   // setInterval handle for polling.
    var pollCount      = 0;      // Current poll attempt.
    var debounceTimer  = null;
    var isGenerating   = false;
    var orbitActive    = false;
    var currentAttrs   = null;   // Attributes being generated (for dedup).

    /* ================================================================
     *  Selectors
     * ================================================================ */

    var $gallery        = $('.woocommerce-product-gallery, .product-images, .product-gallery').first();
    var $variationsForm = $('form.variations_form');

    /* ================================================================
     *  Attribute Change Listener
     * ================================================================ */

    $variationsForm.on('change', 'select, input[type="radio"]', function () {
        clearTimeout(debounceTimer);
        abortAll();

        debounceTimer = setTimeout(function () {
            onAttributesChanged();
        }, 300);
    });

    $variationsForm.on('woocommerce_variation_select_change', function () {
        clearTimeout(debounceTimer);
        abortAll();

        debounceTimer = setTimeout(function () {
            onAttributesChanged();
        }, 300);
    });

    $variationsForm.on('reset_data', function () {
        abortAll();
    });

    /* ================================================================
     *  Core Flow
     * ================================================================ */

    function onAttributesChanged() {
        var attributes = getSelectedAttributes();
        if (!attributes) {
            return;
        }

        checkCache(attributes);
    }

    /**
     * Step 1: Check cache.
     */
    function checkCache(attributes) {
        abortAll();

        currentXHR = $.ajax({
            url: wcaig.ajax_url,
            type: 'POST',
            data: {
                action: 'wcaig_check',
                nonce: wcaig.nonce,
                product_id: wcaig.product_id,
                attributes: attributes
            },
            success: function (response) {
                currentXHR = null;

                if (!response.success) {
                    return;
                }

                if (response.data.hit) {
                    showResultModal(response.data.image_url);
                } else {
                    var thumbs = response.data.ref_thumbs || wcaig.ref_thumbs || [];
                    showOrbitLoader(thumbs);
                    requestGeneration(attributes, thumbs);
                }
            },
            error: function (xhr, status) {
                currentXHR = null;
                if (status !== 'abort') {
                    console.error('[WCAIG] Check failed:', status);
                }
            }
        });
    }

    /**
     * Step 2: Request generation (returns immediately with task_id or cached result).
     */
    function requestGeneration(attributes, thumbs) {
        if (isGenerating) {
            return;
        }
        isGenerating = true;
        currentAttrs = attributes;

        currentXHR = $.ajax({
            url: wcaig.ajax_url,
            type: 'POST',
            data: {
                action: 'wcaig_generate',
                nonce: wcaig.nonce,
                product_id: wcaig.product_id,
                attributes: attributes
            },
            success: function (response) {
                currentXHR = null;

                if (!response.success) {
                    isGenerating = false;
                    hideOrbitLoader();
                    showErrorModal(response.data || 'Generation request failed.');
                    return;
                }

                var data = response.data;

                if (data.status === 'completed' && data.image_url) {
                    // Already cached (race condition guard on server hit).
                    isGenerating = false;
                    hideOrbitLoader();
                    showResultModal(data.image_url);
                    return;
                }

                if (data.status === 'pending' && data.task_id) {
                    // Task submitted — start polling our server.
                    startPolling(attributes, thumbs);
                    return;
                }

                // Unexpected shape.
                isGenerating = false;
                hideOrbitLoader();
                showErrorModal('Unexpected response from server.');
            },
            error: function (xhr, status, error) {
                currentXHR = null;
                isGenerating = false;

                if (status === 'abort') {
                    return;
                }

                hideOrbitLoader();
                showErrorModal('Request error: ' + (error || status));
            }
        });
    }

    /**
     * Step 3: Poll wcaig_poll until completed / failed / timeout.
     */
    function startPolling(attributes, thumbs) {
        stopPolling();
        pollCount = 0;

        pollTimer = setInterval(function () {
            pollCount++;

            if (pollCount > MAX_POLL_ATTEMPTS) {
                stopPolling();
                isGenerating = false;
                hideOrbitLoader();
                showErrorModal('Image generation timed out. Please try again.');
                return;
            }

            $.ajax({
                url: wcaig.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcaig_poll',
                    nonce: wcaig.nonce,
                    product_id: wcaig.product_id,
                    attributes: attributes
                },
                success: function (response) {
                    if (!response.success) {
                        return; // Keep polling.
                    }

                    var data = response.data;

                    if (data.status === 'completed' && data.image_url) {
                        stopPolling();
                        isGenerating = false;
                        hideOrbitLoader();
                        showResultModal(data.image_url);
                        return;
                    }

                    if (data.status === 'failed') {
                        stopPolling();
                        isGenerating = false;
                        hideOrbitLoader();
                        showErrorModal(data.error || 'Generation failed.');
                        return;
                    }

                    // status === 'processing' — keep polling.
                },
                error: function (xhr, status) {
                    // Network hiccup — keep polling, don't abort.
                    if (status === 'abort') {
                        stopPolling();
                    }
                }
            });
        }, POLL_INTERVAL);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        pollCount = 0;
    }

    /* ================================================================
     *  Attribute Helpers
     * ================================================================ */

    function getSelectedAttributes() {
        var attrs = {};
        var allSelected = true;

        $variationsForm.find('select[name^="attribute_"]').each(function () {
            var name = $(this).attr('name');
            var val  = $(this).val();

            if (!val || val === '') {
                allSelected = false;
                return false;
            }

            attrs[name] = val;
        });

        $variationsForm.find('input[type="radio"][name^="attribute_"]:checked').each(function () {
            attrs[$(this).attr('name')] = $(this).val();
        });

        if (!allSelected) {
            return null;
        }

        if ($.isEmptyObject(attrs)) {
            return null;
        }

        return attrs;
    }

    /* ================================================================
     *  Abort
     * ================================================================ */

    function abortAll() {
        if (currentXHR) {
            currentXHR.abort();
            currentXHR = null;
        }
        stopPolling();
        isGenerating = false;
        currentAttrs = null;
        hideOrbitLoader();
    }

    /* ================================================================
     *  Orbit Loader
     * ================================================================ */

    function showOrbitLoader(thumbUrls) {
        hideOrbitLoader();
        orbitActive = true;

        if (!thumbUrls || thumbUrls.length === 0) {
            thumbUrls = wcaig.ref_thumbs || [];
        }

        var $loader = $('<div class="wcaig-orbit-loader"></div>');
        var $center = $('<div class="wcaig-orbit-center"><div class="wcaig-spinner"></div></div>');
        $loader.append($center);

        if (thumbUrls.length > 0) {
            var count = Math.min(thumbUrls.length, 8);
            for (var i = 0; i < count; i++) {
                var angle = (360 / count) * i;
                var $thumb = $('<div class="wcaig-orbit-thumb"></div>');
                $thumb.css({
                    '--orbit-angle': angle + 'deg',
                    '--orbit-index': i,
                    '--orbit-total': count
                });
                $thumb.append('<img src="' + thumbUrls[i] + '" alt="">');
                $loader.append($thumb);
            }
        }

        $gallery.css('position', 'relative');
        $gallery.append($loader);

        requestAnimationFrame(function () {
            $loader.addClass('wcaig-orbit-active');
        });
    }

    function hideOrbitLoader() {
        if (!orbitActive) {
            return;
        }
        orbitActive = false;
        $('.wcaig-orbit-loader').remove();
    }

    /* ================================================================
     *  SweetAlert2 Modals
     * ================================================================ */

    function showResultModal(imageUrl, promptText) {
        var htmlContent = '<img src="' + imageUrl + '" style="max-width:100%;height:auto;border-radius:8px;" alt="AI Generated">';

        if (promptText) {
            htmlContent += '<details style="margin-top:12px;text-align:left;"><summary style="cursor:pointer;color:#666;">Show prompt</summary>';
            htmlContent += '<pre style="white-space:pre-wrap;font-size:11px;background:#f5f5f5;padding:10px;border-radius:4px;margin-top:6px;max-height:200px;overflow-y:auto;">' + escapeHtml(promptText) + '</pre></details>';
        }

        Swal.fire({
            title: 'AI Generated Preview',
            html: htmlContent,
            width: 700,
            showConfirmButton: true,
            confirmButtonText: 'Open Full Size',
            showCancelButton: true,
            cancelButtonText: 'Close',
            confirmButtonColor: '#3085d6'
        }).then(function (result) {
            if (result.isConfirmed) {
                window.open(imageUrl, '_blank');
            }
        });
    }

    function showErrorModal(message) {
        Swal.fire({
            icon: 'error',
            title: 'Generation Error',
            text: typeof message === 'string' ? message : 'Image generation failed. Please try again.',
            confirmButtonColor: '#d33'
        });
    }

    /* ================================================================
     *  Utilities
     * ================================================================ */

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

})(jQuery);
