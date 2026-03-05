<?php
/**
 * ACF Fields — product-level enable/prompt-config + attribute-term reference images.
 *
 * These fields are for CONFIGURATION only — cache is stored in a custom DB table.
 */

defined( 'ABSPATH' ) || exit;

class WCAIG_ACF_Fields {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'acf/init', array( $this, 'register_product_fields' ) );
		add_action( 'acf/init', array( $this, 'register_term_fields' ) );
	}

	/* ------------------------------------------------------------------
	 *  Product-level fields
	 * ----------------------------------------------------------------*/

	public function register_product_fields(): void {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		acf_add_local_field_group( array(
			'key'      => 'group_wcaig_product',
			'title'    => __( 'AI Image Generation', 'wc-ai-image-gen' ),
			'fields'   => array(
				array(
					'key'           => 'field_wcaig_enabled',
					'label'         => __( 'Enable AI Image Generation', 'wc-ai-image-gen' ),
					'name'          => 'wcaig_enabled',
					'type'          => 'true_false',
					'default_value' => 0,
					'ui'            => 1,
					'instructions'  => __( 'Enable AI-generated images for this product when customers select variation attributes.', 'wc-ai-image-gen' ),
				),
				array(
					'key'               => 'field_wcaig_prompt_config',
					'label'             => __( 'Prompt Config', 'wc-ai-image-gen' ),
					'name'              => 'wcaig_prompt_config',
					'type'              => 'repeater',
					'instructions'      => __( 'Additional reference images and instructions for the AI prompt (e.g., texture references, style guides).', 'wc-ai-image-gen' ),
					'min'               => 0,
					'max'               => 10,
					'layout'            => 'block',
					'conditional_logic' => array(
						array(
							array(
								'field'    => 'field_wcaig_enabled',
								'operator' => '==',
								'value'    => '1',
							),
						),
					),
					'sub_fields'        => array(
						array(
							'key'          => 'field_wcaig_pc_ref_image',
							'label'        => __( 'Reference Image', 'wc-ai-image-gen' ),
							'name'         => 'reference_image',
							'type'         => 'image',
							'return_format' => 'id',
							'preview_size' => 'thumbnail',
							'instructions' => __( 'Optional reference image for the AI.', 'wc-ai-image-gen' ),
						),
						array(
							'key'          => 'field_wcaig_pc_instruction',
							'label'        => __( 'Instruction', 'wc-ai-image-gen' ),
							'name'         => 'instruction',
							'type'         => 'textarea',
							'required'     => 1,
							'rows'         => 3,
							'instructions' => __( 'How the AI should use this reference (e.g., "Use this texture as reference for the leather grain pattern").', 'wc-ai-image-gen' ),
						),
					),
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'product',
					),
				),
			),
			'position'          => 'normal',
			'style'             => 'default',
			'label_placement'   => 'top',
			'active'            => true,
		) );
	}

	/* ------------------------------------------------------------------
	 *  Attribute term-level fields
	 * ----------------------------------------------------------------*/

	public function register_term_fields(): void {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		// Build location rules for all WC attribute taxonomies.
		$locations = $this->get_attribute_taxonomy_locations();
		if ( empty( $locations ) ) {
			return;
		}

		acf_add_local_field_group( array(
			'key'      => 'group_wcaig_attr_term',
			'title'    => __( 'AI Image Generation — Attribute Reference', 'wc-ai-image-gen' ),
			'fields'   => array(
				array(
					'key'           => 'field_wcaig_attr_ref_image',
					'label'         => __( 'Reference Image', 'wc-ai-image-gen' ),
					'name'          => 'wcaig_attr_ref_image',
					'type'          => 'image',
					'return_format' => 'id',
					'preview_size'  => 'thumbnail',
					'instructions'  => __( 'Visual reference image for this attribute value.', 'wc-ai-image-gen' ),
				),
				array(
					'key'          => 'field_wcaig_attr_instruction',
					'label'        => __( 'Instruction', 'wc-ai-image-gen' ),
					'name'         => 'wcaig_attr_instruction',
					'type'         => 'textarea',
					'rows'         => 3,
					'instructions' => __( 'How this attribute value modifies the product image (e.g., "Change all leather surfaces to light blue").', 'wc-ai-image-gen' ),
				),
			),
			'location' => $locations,
			'active'   => true,
		) );
	}

	/**
	 * Build ACF location array for all registered WC attribute taxonomies.
	 */
	private function get_attribute_taxonomy_locations(): array {
		$locations = array();

		$attribute_taxonomies = wc_get_attribute_taxonomies();
		if ( empty( $attribute_taxonomies ) ) {
			return $locations;
		}

		foreach ( $attribute_taxonomies as $tax ) {
			$taxonomy_name = wc_attribute_taxonomy_name( $tax->attribute_name );
			$locations[]   = array(
				array(
					'param'    => 'taxonomy',
					'operator' => '==',
					'value'    => $taxonomy_name,
				),
			);
		}

		return $locations;
	}
}
