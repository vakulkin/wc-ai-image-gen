/**
 * WCAIG Frontend — AI Image Generation for WooCommerce variable products.
 *
 * Listens for WooCommerce variation events, computes hashes, interacts with
 * the REST API, and displays results via SweetAlert2.
 *
 * @package WC_AI_Image_Gen
 */
(function ($) {
  'use strict';

  if (typeof wcaig_params === 'undefined') {
    return;
  }

  var params = wcaig_params;
  var pollTimer = null;
  var currentHash = null;
  var requestHash = null;

  // ─── Hash Computation ─────────────────────────────────

  /**
   * Compute MD5 hash matching server-side logic (WCAIG_Hash::compute).
   *
   * @param {number} productId
   * @param {Object} attributes Key-value pairs of selected attributes.
   * @param {Array}  enabled    List of enabled attribute slugs.
   * @return {string} MD5 hex hash.
   */
  function computeHash(productId, attributes, enabled) {
    var filtered = {};

    for (var i = 0; i < enabled.length; i++) {
      var key = enabled[i].toLowerCase().trim();

      // Look for the attribute value, handling pa_ prefix.
      for (var attrKey in attributes) {
        if (attributes.hasOwnProperty(attrKey)) {
          var normalizedAttrKey = attrKey.toLowerCase().trim().replace(/^pa_/, '');
          if (normalizedAttrKey === key) {
            filtered[key] = attributes[attrKey].toLowerCase().trim();
          }
        }
      }
    }

    // Sort by key alphabetically.
    var sortedKeys = Object.keys(filtered).sort();
    var pairs = sortedKeys.map(function (k) {
      return k + '=' + filtered[k];
    });

    var str = productId + '_' + pairs.join('&');
    return md5(str);
  }

  // ─── Base-Match Check ─────────────────────────────────

  /**
   * Check if selected attributes match the base configuration.
   */
  function isBaseMatch(selected, baseAttrs, enabled) {
    for (var i = 0; i < enabled.length; i++) {
      var attr = enabled[i];
      var selectedVal = (selected[attr] || '').toLowerCase().trim();
      var baseVal = (baseAttrs[attr] || '').toLowerCase().trim();
      if (selectedVal !== baseVal) {
        return false;
      }
    }
    return true;
  }

  // ─── Collect Selected Attributes ──────────────────────

  /**
   * Normalize a WooCommerce variation.attributes object
   * (keys like "attribute_pa_color") to plain slugs ({color: "blue"}).
   */
  function normalizeAttributes(variationAttrs) {
    var attrs = {};
    for (var key in variationAttrs) {
      if (variationAttrs.hasOwnProperty(key)) {
        var name = key.replace(/^attribute_/, '').replace(/^pa_/, '');
        var value = variationAttrs[key];
        if (value) {
          attrs[name] = value;
        }
      }
    }
    return attrs;
  }

  /**
   * Collect currently selected attribute values from the variation form.
   * Used as a fallback when variation.attributes is unavailable.
   */
  function getSelectedAttributes() {
    var attrs = {};
    $('form.variations_form select[name^="attribute_"]').each(function () {
      var name = $(this).attr('name').replace('attribute_', '').replace('pa_', '');
      var value = $(this).val();
      if (value) {
        attrs[name] = value;
      }
    });
    return attrs;
  }

  // ─── API Communication ────────────────────────────────

  /**
   * POST to /wcaig/v1/variation to request an image variation.
   */
  function requestVariation(productId, attributes) {
    return fetch(params.rest_url + 'variation', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        product_id: productId,
        attributes: attributes,
      }),
    }).then(function (response) {
      if (response.status === 429) {
        throw new Error('rate_limited');
      }
      return response.json();
    });
  }

  /**
   * GET /wcaig/v1/variation/{hash} to poll for status.
   */
  function pollVariation(hash) {
    return fetch(params.rest_url + 'variation/' + hash, {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json',
      },
    }).then(function (response) {
      return response.json();
    });
  }

  // ─── Polling Logic ────────────────────────────────────

  /**
   * Start polling for a variation by hash.
   */
  function startPolling(hash) {
    var attempts = 0;
    var interval = params.poll_interval * 1000;
    var maxAttempts = params.max_poll_attempts;

    currentHash = hash;

    // Poll silently — no loading modal.

    pollTimer = setInterval(function () {
      attempts++;

      if (maxAttempts > 0 && attempts > maxAttempts) {
        stopPolling();
        console.warn('[WCAIG] Max poll attempts reached for hash:', hash);
        return;
      }

      pollVariation(hash)
        .then(function (data) {
          if (data.status === 'published') {
            stopPolling();
            showImage(data.image_url);
          } else if (data.status === 'pending_review') {
            stopPolling();
            console.log('[WCAIG] Image generated, awaiting admin approval:', hash);
          } else if (data.status === 'failed') {
            stopPolling();
            console.warn('[WCAIG] Variation failed for hash:', hash);
          }
          // 'pending' — keep polling silently.
        })
        .catch(function (err) {
          console.warn('[WCAIG] Poll error:', err);
          // Don't stop polling on transient network errors — keep trying.
        });
    }, interval);
  }

  /**
   * Stop the current polling cycle.
   */
  function stopPolling() {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
    currentHash = null;
  }

  // ─── SweetAlert2 Modals ───────────────────────────────

  /**
   * Show the generated image in a modal.
   */
  function showImage(imageUrl) {
    Swal.fire({
      imageUrl: imageUrl,
      imageAlt: 'Generated product image',
      showConfirmButton: false,
      showCloseButton: true,
      allowOutsideClick: true,
    });
  }

  /**
   * Show an error modal.
   */
  function showError(title, text) {
    Swal.fire({
      icon: 'error',
      title: title,
      text: text,
      allowOutsideClick: true,
      showCloseButton: true,
    });
  }

  /**
   * Show the cap reached message.
   */
  function showCapReached() {
    Swal.fire({
      icon: 'info',
      title: params.i18n.cap_reached,
      allowOutsideClick: true,
      showCloseButton: true,
    });
  }

  // ─── Main Handler ─────────────────────────────────────

  /**
   * Handle a variation selection event.
   */
  function handleVariationFound(event, variation) {
    console.log('[WCAIG] found_variation fired. variation:', variation);

    // Use the attributes WooCommerce already resolved for this variation.
    var selected = normalizeAttributes(variation.attributes || {});
    console.log('[WCAIG] Normalized selected attributes:', JSON.parse(JSON.stringify(selected)));

    // Fall back to reading DOM selects if variation.attributes is empty.
    if (Object.keys(selected).length === 0) {
      selected = getSelectedAttributes();
      console.log('[WCAIG] Fallback to DOM selects:', JSON.parse(JSON.stringify(selected)));
    }

    var enabled = params.enabled_attributes;
    console.log('[WCAIG] Enabled attributes:', enabled);

    // Check all enabled attributes are selected.
    var allSelected = true;
    for (var i = 0; i < enabled.length; i++) {
      if (!selected[enabled[i]]) {
        console.warn('[WCAIG] Missing enabled attribute "' + enabled[i] + '" in selected. Keys:', Object.keys(selected));
        allSelected = false;
        break;
      }
    }

    if (!allSelected) {
      console.warn('[WCAIG] Not all enabled attributes selected — aborting.');
      return;
    }

    // Base-match check — if all enabled attributes match base, show base image.
    if (isBaseMatch(selected, params.base_attributes, enabled)) {
      console.log('[WCAIG] Base match detected — skipping generation.');
      return; // Use WooCommerce default image handling.
    }

    // Compute hash.
    var hash = computeHash(params.product_id, selected, enabled);
    console.log('[WCAIG] Computed hash:', hash, '| Requesting variation...');

    // Stop any previous polling — only track the latest selection.
    stopPolling();

    // Track which hash this request is for, so stale responses are ignored.
    requestHash = hash;

    // Request variation image.
    requestVariation(params.product_id, selected)
      .then(function (data) {
        // Ignore response if user already switched to a different variation.
        if (requestHash !== hash) {
          console.log('[WCAIG] Ignoring stale API response for hash:', hash);
          return;
        }

        console.log('[WCAIG] API response:', data);
        switch (data.status) {
          case 'base_match':
            // Server confirmed base match — do nothing extra.
            break;

          case 'published':
            showImage(data.image_url);
            break;

          case 'pending_review':
            // Image generated but awaiting admin approval — do nothing.
            console.log('[WCAIG] Image awaiting admin approval:', hash);
            break;

          case 'created':
          case 'pending':
            startPolling(data.hash || hash);
            break;

          case 'failed':
            console.warn('[WCAIG] Variation failed.');
            break;

          default:
            if (data.code === 'cap_reached') {
              console.warn('[WCAIG] Cap reached.');
            }
            break;
        }
      })
      .catch(function (err) {
        console.warn('[WCAIG] Request error:', err);
      });
  }

  // ─── Event Bindings ───────────────────────────────────

  $(document).ready(function () {
    var $form = $('form.variations_form');

    console.log('[WCAIG] Init — form.variations_form found:', $form.length > 0);
    console.log('[WCAIG] Params:', JSON.parse(JSON.stringify(params)));

    if (!$form.length) {
      console.warn('[WCAIG] No form.variations_form found — aborting.');
      return;
    }

    // Listen for WooCommerce variation found event.
    $form.on('found_variation', handleVariationFound);
    console.log('[WCAIG] Bound found_variation event on form.');

    // Listen for reset event — cleanup.
    $form.on('reset_data', function () {
      stopPolling();
      Swal.close();
    });
  });

})(jQuery);
