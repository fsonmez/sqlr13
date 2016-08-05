<?php

/**
 * Class PluginAcf
 *
 * Manage Advanced Custom Fields (ACF) plugin
 * @link https://wordpress.org/plugins/advanced-custom-fields/
 */
class PluginAcf extends WpSolrExtensions {

	// Prefix of ACF fields
	const FIELD_PREFIX = '_';

	// Polylang options
	const _OPTIONS_NAME = 'wdm_solr_extension_acf_data';

	// acf fields indexed by name.
	private $_fields;

	// Options
	private $_options;


	/**
	 * Factory
	 *
	 * @return PluginAcf
	 */
	static function create() {

		return new self();
	}

	/*
	 * Constructor
	 * Subscribe to actions
	 */

	function __construct() {

		$this->_options = self::get_option_data( self::EXTENSION_ACF );

		add_filter( WpSolrFilters::WPSOLR_FILTER_SEARCH_PAGE_FACET_NAME, array(
			$this,
			'get_field_label'
		), 10, 1 );

		add_filter( WpSolrFilters::WPSOLR_FILTER_POST_CUSTOM_FIELDS, array(
			$this,
			'filter_custom_fields'
		), 10, 2 );

		add_filter( WpSolrFilters::WPSOLR_FILTER_GET_POST_ATTACHMENTS, array(
			$this,
			'filter_get_post_attachments'
		), 10, 2 );

	}


	/**
	 * Retrieve all field keys of all ACF fields.
	 *
	 * @return array
	 */
	function get_fields() {
		global $wpdb;

		// Uue cached fields if exist
		if ( isset( $this->_fields ) ) {
			return $this->_fields;
		}

		$fields = array();

		// Else create the cached fields
		$results = $wpdb->get_results( "SELECT distinct meta_key, meta_value
                                        FROM $wpdb->postmeta
                                        WHERE meta_key like '_%'
                                        AND   meta_value like 'field_%'" );

		$nb_results = count( $results );
		for ( $loop = 0; $loop < $nb_results; $loop ++ ) {
			$fields[ $results[ $loop ]->meta_key ] = $results[ $loop ]->meta_value;

		}

		// Save the cache
		$this->_fields = $fields;

		return $this->_fields;
	}


	/**
	 * Get the ACF field label from the custom field name.
	 *
	 * @param $custom_field_name
	 *
	 * @return mixed
	 */
	public
	function get_field_label(
		$custom_field_name
	) {

		$result = $custom_field_name;

		if ( ! isset( $this->_options['display_acf_label_on_facet'] ) ) {
			// No need to replace custom field name by acf field label
			return $result;
		}

		// Retrieve field among ACF fields
		$fields = $this->get_fields();
		if ( isset( $fields[ self::FIELD_PREFIX . $custom_field_name ] ) ) {
			$field_key = $fields[ self::FIELD_PREFIX . $custom_field_name ];
			$field     = get_field_object( $field_key );
			$result    = isset( $field['label'] ) ? $field['label'] : $custom_field_name;
		}

		return $result;
	}


	/**
	 * Decode acf multi-values before indexing
	 *
	 * @param $custom_fields
	 * @param $post_id
	 *
	 * @return mixed
	 */
	public function filter_custom_fields( $custom_fields, $post_id ) {

		if ( ! isset( $custom_fields ) ) {
			$custom_fields = array();
		}

		$fields = $this->get_fields();

		foreach ( $custom_fields as $custom_field_name => $custom_field_value ) {

			if ( isset( $fields[ self::FIELD_PREFIX . $custom_field_name ] ) ) {
				$field_key                           = $fields[ self::FIELD_PREFIX . $custom_field_name ];
				$field                               = get_field_object( $field_key, $post_id );
				$custom_fields[ $custom_field_name ] = $field['value'];
			}
		}

		return $custom_fields;
	}

	/**
	 * Retrieve attachments in the fields of type file of the post
	 *
	 * @param array $attachments
	 * @param string $post
	 *
	 */
	public function filter_get_post_attachments( $attachments, $post_id ) {

		if ( ! WPSOLR_Metabox::get_metabox_is_do_index_acf_field_files( $post_id ) ) {
			// Do nothing
			return $attachments;
		}

		// Get post ACF field objects
		$fields = get_field_objects( $post_id );

		if ( $fields ) {

			foreach ( $fields as $field_name => $field ) {

				// Retrieve the post_id of the file
				if ( ! empty( $field['value'] ) && ( 'file' === $field['type'] ) ) {

					switch ( $field['save_format'] ) {
						case 'id':
							array_push( $attachments, array( 'post_id' => $field['value'] ) );
							break;

						case 'object':
							array_push( $attachments, array( 'post_id' => $field['value']['id'] ) );
							break;

						case 'url':
							array_push( $attachments, array( 'url' => $field['value'] ) );
							break;

						default:
							// Do nothing
							break;
					}
				}
			}

		}

		return $attachments;
	}
}