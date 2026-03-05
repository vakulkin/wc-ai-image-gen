/**
 * WC AI Image Generator — Frontend JS
 *
 * Attribute listener, debounce, abort, AJAX calls, orbit loader, SweetAlert2 modal.
 */
(function ($) {
    'use strict';

    if (typeof wcaig === 'undefined') {
        return;
    }

    /* ================================================================
     *  State
     * ================================================================ */

    var currentXHR = null;       // In-flight AJAX request (abortable).
    var debounceTimer = null;    // Debounce timer handle.
    var isGenerating = false;    // Prevents duplicate triggers.
    var orbitActive = false;     // Orbit loader currently showing.

    /* ================================================================
     *  Selectors
     * ================================================================ */

    var $gallery = $('.woocommerce-product-gallery, .product-images, .product-gallery').first();
    var $variationsForm = $('form.variations_form');

    /* ================================================================
     *  Attribute Change Listener
     * ================================================================ */

    $variationsForm.on('change', 'select, input[type="radio"]', function () {
        clearTimeout(debounceTimer);
        abortCurrent();

        debounceTimer = setTimeout(function () {
            onAttributesChanged();
        }, 300);
    });

    // Also listen to WooCommerce's own variation events.
    $variationsForm.on('woocommerce_variation_select_change', function () {
        clearTimeout(debounceTimer);
        abortCurrent();

        debounceTimer = setTimeout(function () {
            onAttributesChanged();
        }, 300);
    });

    // Reset / clear events.
    $variationsForm.on('reset_data', function () {
        abortCurrent();
        hideOrbitLoader();
    });

    /* ================================================================
     *  Core Flow
     * ================================================================ */

    function onAttributesChanged() {
        var attributes = getSelectedAttributes();
        if (!attributes) {
            return; // Not all dropdowns selected.
        }

        // Step 1: Check cache.
        checkCache(attributes);
    }

    function checkCache(attributes) {
        abortCurrent();

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
                    // Cache hit — show modal immediately.
                    showResultModal(response.data.image_url);
                } else {
                    // Cache miss — start generation.
                    var thumbs = response.data.ref_thumbs || wcaig.ref_thumbs || [];
                    showOrbitLoader(thumbs);
                    generateImage(attributes);
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

    function generateImage(attributes) {
        if (isGenerating) {
            return;
        }
        isGenerating = true;

        currentXHR = $.ajax({
            url: wcaig.ajax_url,
            type: 'POST',
            data: {
                action: 'wcaig_generate',
                nonce: wcaig.nonce,
                product_id: wcaig.product_id,
                attributes: attributes
            },
            timeout: 0, // No client-side timeout — server manages polling.
            success: function (response) {
                currentXHR = null;
                isGenerating = false;
                hideOrbitLoader();

                if (response.success && response.data.image_url) {
                    showResultModal(response.data.image_url, response.data.prompt || '');
                } else {
                    showErrorModal(response.data || 'Generation failed.');
                }
            },
            error: function (xhr, status, error) {
                currentXHR = null;
                isGenerating = false;
                hideOrbitLoader();

                if (status === 'abort') {
                    return;
                }

                if (status === 'timeout') {
                    showErrorModal('Image generation timed out. Please try again.');
                } else {
                    showErrorModal('An error occurred: ' + (error || status));
                }
            }
        });
    }

    /* ================================================================
     *  Attribute Helpers
     * ================================================================ */

    /**
     * Collect all selected attribute values. Returns null if any dropdown is empty.
     */
    function getSelectedAttributes() {
        var attrs = {};
        var allSelected = true;

        $variationsForm.find('select[name^="attribute_"]').each(function () {
            var name = $(this).attr('name');
            var val = $(this).val();

            if (!val || val === '') {
                allSelected = false;
                return false; // break
            }

            attrs[name] = val;
        });

        // Also check radio-based attribute selectors (some themes use radios).
        $variationsForm.find('input[type="radio"][name^="attribute_"]:checked').each(function () {
            attrs[$(this).attr('name')] = $(this).val();
        });

        if (!allSelected) {
            return null;
        }

        // Verify we actually have attributes.
        if ($.isEmptyObject(attrs)) {
            return null;
        }

        return attrs;
    }

    /* ================================================================
     *  Abort
     * ================================================================ */

    function abortCurrent() {
        if (currentXHR) {
            currentXHR.abort();
            currentXHR = null;
        }
        isGenerating = false;
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

        // Trigger animation.
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
