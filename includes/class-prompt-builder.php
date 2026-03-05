<?php
/**
 * Prompt Builder — assembles structured prompt + ordered image_urls[] array.
 */

defined( 'ABSPATH' ) || exit;

class WCAIG_Prompt_Builder {

	/** @var int Product ID. */
	private $product_id;

	/** @var array Selected attributes ['attribute_pa_color' => 'blekitny', …]. */
	private $selected_attributes;

	/** @var string[] Ordered image URLs (index 0 = base image). */
	private $image_urls = array();

	/** @var string[] Prompt sections collected during build. */
	private $prompt_sections = array();

	/**
	 * Build prompt and image_urls array.
	 *
	 * @param int   $product_id
	 * @param int   $base_image_id
	 * @param array $selected_attributes
	 * @return array { prompt: string, image_urls: string[] }
	 */
	public function build( int $product_id, int $base_image_id, array $selected_attributes ): array {
		$this->product_id          = $product_id;
		$this->selected_attributes = $selected_attributes;
		$this->image_urls          = array();
		$this->prompt_sections     = array();

		// Image #1 = base product image (always index 0).
		$base_url = wp_get_attachment_url( $base_image_id );
		if ( ! $base_url ) {
			WC_AI_Image_Gen::log( "Prompt build failed: no URL for base image {$base_image_id}." );
			return array( 'prompt' => '', 'image_urls' => array() );
		}
		$this->image_urls[] = $base_url;

		// Opening instruction.
		$this->prompt_sections[] = 'Modify the base product image (the first image provided) according to the following instructions.';

		// Collect prompt-config references (product-level repeater).
		$config_lines       = array();
		$config_ref_images  = $this->collect_prompt_config_references( $config_lines );

		// Next image index accounts for base image + config images already added.
		$next_image_index = 1 + count( $config_ref_images ); // 1-based; base=1

		// Collect attribute references.
		$attribute_lines = $this->collect_attribute_references( $next_image_index );

		// Section: Attribute-based transformations.
		if ( ! empty( $attribute_lines ) ) {
			$this->prompt_sections[] = "\n\nAttribute-based transformations:\n" . implode( "\n", $attribute_lines );
		}

		// Section: Preservation rules.
		$rules = $this->get_preservation_rules();
		if ( $rules ) {
			$this->prompt_sections[] = "\n\nGeneral requirements:\n" . $rules;
		}

		// Section: Additional reference instructions (prompt-config).
		if ( ! empty( $config_lines ) ) {
			$this->prompt_sections[] = "\n\nAdditional reference instructions:\n" . implode( "\n", $config_lines );
		}

		$prompt = implode( '', $this->prompt_sections );

		WC_AI_Image_Gen::log( "Built prompt for product {$product_id}: " . substr( $prompt, 0, 300 ) . '…' );
		WC_AI_Image_Gen::log( 'Image URLs (' . count( $this->image_urls ) . '): ' . implode( ', ', $this->image_urls ) );

		return array(
			'prompt'     => $prompt,
			'image_urls' => $this->image_urls,
		);
	}

	/* ------------------------------------------------------------------
	 *  Prompt-config references (product-level repeater)
	 * ----------------------------------------------------------------*/

	/**
	 * Collect prompt-config repeater rows. Adds images to $this->image_urls,
	 * builds formatted lines into &$lines.
	 *
	 * @param array &$lines  Receives formatted instruction lines.
	 * @return array          Array of image URLs added (for counting).
	 */
	private function collect_prompt_config_references( array &$lines ): array {
		$added_images = array();
		$rows         = get_field( 'wcaig_prompt_config', $this->product_id );

		if ( ! is_array( $rows ) ) {
			return $added_images;
		}

		foreach ( $rows as $row ) {
			$instruction = trim( $row['instruction'] ?? '' );
			$image_id    = $row['reference_image'] ?? 0;

			if ( ! $instruction ) {
				continue;
			}

			if ( $image_id ) {
				$url = wp_get_attachment_url( $image_id );
				if ( $url ) {
					$this->image_urls[] = $url;
					$added_images[]     = $url;
					$image_index        = count( $this->image_urls ); // 1-based
					$lines[]            = "- Image #{$image_index} — {$instruction}";
				} else {
					// Image missing, include instruction without image ref.
					$lines[] = "- {$instruction}";
				}
			} else {
				$lines[] = "- {$instruction}";
			}
		}

		return $added_images;
	}

	/* ------------------------------------------------------------------
	 *  Attribute references
	 * ----------------------------------------------------------------*/

	/**
	 * Collect attribute term reference images & instructions.
	 *
	 * @param int $next_image_index  1-based index of the next image to be added.
	 * @return array  Formatted prompt lines.
	 */
	private function collect_attribute_references( int $next_image_index ): array {
		$lines = array();

		foreach ( $this->selected_attributes as $attr_key => $term_slug ) {
			if ( empty( $term_slug ) ) {
				continue;
			}

			// Derive taxonomy name from attribute key.
			$taxonomy = preg_replace( '/^attribute_/', '', $attr_key );
			$term     = get_term_by( 'slug', $term_slug, $taxonomy );

			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}

			// Human-readable label.
			$tax_obj    = get_taxonomy( $taxonomy );
			$attr_label = $tax_obj ? $tax_obj->labels->singular_name : $taxonomy;
			$term_name  = strtoupper( $term->name );

			// ACF fields on the term.
			$ref_image_id = get_field( 'wcaig_attr_ref_image', $term );
			$instruction  = trim( (string) get_field( 'wcaig_attr_instruction', $term ) );

			if ( $ref_image_id ) {
				$url = wp_get_attachment_url( $ref_image_id );
				if ( $url ) {
					$this->image_urls[] = $url;
					$image_index        = count( $this->image_urls ); // 1-based
					$line               = "- {$attr_label}: {$term_name} - - Image #{$image_index}";
				} else {
					$line = "- {$attr_label}: {$term_name}";
				}
			} else {
				$line = "- {$attr_label}: {$term_name}";
			}

			// Append instruction if present.
			if ( $instruction ) {
				$line .= " — {$instruction}";
			}

			$lines[] = $line;
		}

		return $lines;
	}

	/* ------------------------------------------------------------------
	 *  Preservation rules
	 * ----------------------------------------------------------------*/

	/**
	 * Get preservation rules from WC settings, falling back to defaults.
	 */
	private function get_preservation_rules(): string {
		$rules = get_option( 'wcaig_preservation_rules', '' );

		if ( ! empty( trim( $rules ) ) ) {
			return trim( $rules );
		}

		// Default rules.
		return implode( "\n", array(
			'- On the original photo, all leather elements are black and all hardware (metal fittings, buckles, zippers, rivets, etc.) is silver.',
			'- Preserve the original product geometry, proportions, camera angle, lighting conditions, shadows, and overall composition.',
			'- Do not alter the structural form of the object.',
			'- Apply all material and texture changes realistically across visible surfaces with accurate scaling and natural material behavior.',
			'- Texture patterns must remain visually continuous while exhibiting slight natural misalignment at seams, consistent with handcrafted products.',
			'- Ensure physically accurate reflections, correct texture scaling, and natural seam alignment.',
			'- Replace metal hardware and fittings with the specified finish if instructed, ensuring realistic reflections and metallic properties.',
			'- No distortion of proportions.',
		) );
	}
}
