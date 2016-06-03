<?php

use Solarium\Core\Query\Result\ResultInterface;
use Solarium\QueryType\Select\Query\Query;

require_once plugin_dir_path( __FILE__ ) . 'wpsolr-abstract-solr-client.php';

class WPSolrSearchSolrClient extends WPSolrAbstractSolrClient {

	protected $solarium_results;

	protected $solarium_query;

	protected $solarium_config;

	// Array of active extension objects
	protected $wpsolr_extensions;

	// Search template
	const _SEARCH_PAGE_TEMPLATE = 'wpsolr-search-engine/search.php';

	// Search page slug
	const _SEARCH_PAGE_SLUG = 'search-wpsolr';

	// Do not change - Sort by most relevant
	const SORT_CODE_BY_RELEVANCY_DESC = 'sort_by_relevancy_desc';

	// Do not change - Sort by newest
	const SORT_CODE_BY_DATE_DESC = 'sort_by_date_desc';

	// Do not change - Sort by oldest
	const SORT_CODE_BY_DATE_ASC = 'sort_by_date_asc';

	// Do not change - Sort by least comments
	const SORT_CODE_BY_NUMBER_COMMENTS_ASC = 'sort_by_number_comments_asc';

	// Do not change - Sort by most comments
	const SORT_CODE_BY_NUMBER_COMMENTS_DESC = 'sort_by_number_comments_desc';

	// Default maximum number of items returned by facet
	const DEFAULT_MAX_NB_ITEMS_BY_FACET = 10;

	// Defaut minimum count for a facet to be returned
	const DEFAULT_MIN_COUNT_BY_FACET = 1;

	// Default maximum size of highliting fragments
	const DEFAULT_HIGHLIGHTING_FRAGMENT_SIZE = 100;

	// Default highlighting prefix
	const DEFAULT_HIGHLIGHTING_PREFIX = '<b>';

	// Default highlighting postfix
	const DEFAULT_HIGHLIGHTING_POSFIX = '</b>';

	const PARAMETER_HIGHLIGHTING_FIELD_NAMES = 'field_names';
	const PARAMETER_HIGHLIGHTING_FRAGMENT_SIZE = 'fragment_size';
	const PARAMETER_HIGHLIGHTING_PREFIX = 'prefix';
	const PARAMETER_HIGHLIGHTING_POSTFIX = 'postfix';

	const PARAMETER_FACET_FIELD_NAMES = 'field_names';
	const PARAMETER_FACET_LIMIT = 'limit';
	const PARAMETER_FACET_MIN_COUNT = 'min_count';


	// Create using a configuration
	static function create_from_solarium_config( $solarium_config ) {

		return new self( $solarium_config );
	}


	/**
	 * Constructor used by factory WPSOLR_Global
	 * Create using the default index configuration
	 *
	 * @return WPSolrSearchSolrClient
	 */
	static function global_object() {

		return self::create_from_index_indice( null );
	}

	// Create using an index configuration
	static function create_from_index_indice( $index_indice ) {

		// Build Solarium config from the default indexing Solr index
		WpSolrExtensions::require_once_wpsolr_extension( WpSolrExtensions::OPTION_INDEXES, true );
		$options_indexes = new OptionIndexes();
		$solarium_config = $options_indexes->build_solarium_config( $index_indice, null, self::DEFAULT_SOLR_TIMEOUT_IN_SECOND );

		return new self( $solarium_config );
	}

	public function __construct( $solarium_config ) {

		// Load active extensions
		$this->wpsolr_extensions = new WpSolrExtensions();


		$path = plugin_dir_path( __FILE__ ) . '../../vendor/autoload.php';
		require_once $path;
		$this->solarium_client = new Solarium\Client( $solarium_config );

	}


	/**
	 * Get suggestions from Solr suggester.
	 *
	 * @param string $query Keywords to suggest from
	 *
	 * @return array
	 */
	public function get_suggestions( $query ) {

		$results = array();

		$client = $this->solarium_client;


		$suggestqry = $client->createSuggester();
		$suggestqry->setHandler( 'suggest' );
		$suggestqry->setDictionary( 'suggest' );
		$suggestqry->setQuery( $query );
		$suggestqry->setCount( 5 );
		$suggestqry->setCollate( true );
		$suggestqry->setOnlyMorePopular( true );

		$resultset = $this->execute( $client, $suggestqry );

		foreach ( $resultset as $term => $termResult ) {

			foreach ( $termResult as $result ) {

				array_push( $results, $result );
			}
		}

		return $results;
	}

	/**
	 * Retrieve or create the search page
	 */
	static function get_search_page() {

		// Let other plugins (POLYLANG, ...) modify the search page slug
		$search_page_slug = apply_filters( WpSolrFilters::WPSOLR_FILTER_SEARCH_PAGE_SLUG, self::_SEARCH_PAGE_SLUG );

		// Search page is found by it's path (hard-coded).
		$search_page = get_page_by_path( $search_page_slug );

		if ( ! $search_page ) {

			$search_page = self::create_default_search_page();

		} else {

			if ( $search_page->post_status != 'publish' ) {

				$search_page->post_status = 'publish';

				wp_update_post( $search_page );
			}
		}


		return $search_page;
	}


	/**
	 * Create a default search page
	 *
	 * @return WP_Post The search page
	 */
	static function create_default_search_page() {

		// Let other plugins (POLYLANG, ...) modify the search page slug
		$search_page_slug = apply_filters( WpSolrFilters::WPSOLR_FILTER_SEARCH_PAGE_SLUG, self::_SEARCH_PAGE_SLUG );

		$_search_page = array(
			'post_type'      => 'page',
			'post_title'     => 'Search Results',
			'post_content'   => '[solr_search_shortcode]',
			'post_status'    => 'publish',
			'post_author'    => 1,
			'comment_status' => 'closed',
			'post_name'      => $search_page_slug
		);

		// Let other plugins (POLYLANG, ...) modify the search page
		$_search_page = apply_filters( WpSolrFilters::WPSOLR_FILTER_BEFORE_CREATE_SEARCH_PAGE, $_search_page );

		$search_page_id = wp_insert_post( $_search_page );

		update_post_meta( $search_page_id, 'bwps_enable_ssl', '1' );

		return get_post( $search_page_id );
	}

	/**
	 * Get all sort by options available
	 *
	 * @param string $sort_code_to_retrieve
	 *
	 * @return array
	 */
	public
	static function get_sort_options() {

		$results = array(

			array(
				'code'  => self::SORT_CODE_BY_RELEVANCY_DESC,
				'label' => 'Most relevant',
			),
			array(
				'code'  => self::SORT_CODE_BY_DATE_DESC,
				'label' => 'Newest',
			),
			array(
				'code'  => self::SORT_CODE_BY_DATE_ASC,
				'label' => 'Oldest',
			),
			array(
				'code'  => self::SORT_CODE_BY_NUMBER_COMMENTS_DESC,
				'label' => 'More comments',
			),
			array(
				'code'  => self::SORT_CODE_BY_NUMBER_COMMENTS_ASC,
				'label' => 'Less comments',
			),
		);

		return $results;
	}

	/**
	 * Get all sort by options available
	 *
	 * @param string $sort_code_to_retrieve
	 *
	 * @return array
	 */
	public static function get_sort_option_from_code( $sort_code_to_retrieve, $sort_options = null ) {

		if ( $sort_options == null ) {
			$sort_options = self::get_sort_options();
		}

		if ( $sort_code_to_retrieve != null ) {
			foreach ( $sort_options as $sort ) {

				if ( $sort['code'] === $sort_code_to_retrieve ) {
					return $sort;
				}
			}
		}


		return null;
	}

	/**
	 * Convert a $wpsolr_query in a Solarium select query
	 *
	 * @param WPSOLR_Query $wpsolr_query
	 *
	 * @return Query
	 */
	public function set_solarium_query( WPSOLR_Query $wpsolr_query ) {

		// Create the solarium query
		$solarium_query = $this->solarium_client->createSelect();

		// Set the query keywords.
		$this->set_keywords( $solarium_query, $wpsolr_query->get_wpsolr_query() );

		// Set default operator
		$solarium_query->setQueryDefaultOperator( 'AND' );

		// Limit nb of results
		$solarium_query->setStart( $wpsolr_query->get_start() )->setRows( WPSOLR_Global::getOption()->get_search_max_nb_results_by_page() );

		/*
		* Add sort field(s)
		*/
		$this->add_sort_field( $solarium_query, $wpsolr_query->get_wpsolr_sort() );

		/*
		* Add facet fields
		*/
		$this->add_filter_query_fields( $solarium_query, $wpsolr_query->get_filter_query_fields() );

		/*
		* Add highlighting fields
		*/
		$this->add_highlighting_fields( $solarium_query,
			array(
				self::PARAMETER_HIGHLIGHTING_FIELD_NAMES   => array(
					WpSolrSchema::_FIELD_NAME_TITLE,
					WpSolrSchema::_FIELD_NAME_CONTENT,
					WpSolrSchema::_FIELD_NAME_COMMENTS
				),
				self::PARAMETER_HIGHLIGHTING_FRAGMENT_SIZE => WPSOLR_Global::getOption()->get_search_max_length_highlighting(),
				self::PARAMETER_HIGHLIGHTING_PREFIX        => self::DEFAULT_HIGHLIGHTING_PREFIX,
				self::PARAMETER_HIGHLIGHTING_POSTFIX       => self::DEFAULT_HIGHLIGHTING_POSFIX
			)
		);

		/*
		 * Add facet fields
		 */
		$this->add_facet_fields( $solarium_query,
			array(
				self::PARAMETER_FACET_FIELD_NAMES => WPSOLR_Global::getOption()->get_facets_to_display(),
				self::PARAMETER_FACET_LIMIT       => WPSOLR_Global::getOption()->get_search_max_nb_items_by_facet(),
				self::PARAMETER_FACET_MIN_COUNT   => self::DEFAULT_MIN_COUNT_BY_FACET
			)
		);

		/*
		 * Add fields
		 */
		$this->add_fields( $solarium_query );


		// Filter to change the solarium query
		do_action( WpSolrFilters::WPSOLR_ACTION_SOLARIUM_QUERY,
			array(
				WpSolrFilters::WPSOLR_ACTION_SOLARIUM_QUERY__PARAM_SOLARIUM_QUERY => $solarium_query,
				WpSolrFilters::WPSOLR_ACTION_SOLARIUM_QUERY__PARAM_SEARCH_TERMS   => $wpsolr_query->get_wpsolr_query(),
				WpSolrFilters::WPSOLR_ACTION_SOLARIUM_QUERY__PARAM_SEARCH_USER    => wp_get_current_user(),
			)
		);

		// Done
		return $this->solarium_query = $solarium_query;
	}

	/**
	 * Execute a WPSOLR query.
	 *
	 * @param WPSOLR_Query $wpsolr_query
	 *
	 * @return ResultInterface
	 */
	public function execute_wpsolr_query( WPSOLR_Query $wpsolr_query ) {

		if ( isset( $this->solarium_results ) ) {
			// Return results already in cache
			return $this->solarium_results;
		}

		// Create the solarium query from the wpsolr query
		$this->set_solarium_query( $wpsolr_query );

		// Perform the query, return the Solarium result set
		return $this->execute_solarium_query();

	}

	/**
	 * Execute a Solarium query.
	 * Used internally, or when fine tuned solarium select query is better than using a WPSOLR query.
	 *
	 * @param Query $solarium_query
	 *
	 * @return ResultInterface
	 */
	public function execute_solarium_query( Query $solarium_query = null ) {

		// Perform the query, return the Solarium result set
		return $this->solarium_results = $this->execute( $this->solarium_client, isset( $solarium_query ) ? $solarium_query : $this->solarium_query );
	}

	/**
	 *
	 * @param WPSOLR_Query $wpsolr_query
	 *
	 * @return array Array of html
	 */
	public function display_results( WPSOLR_Query $wpsolr_query ) {

		$output        = array();
		$search_result = array();

		// Load options
		$localization_options = OptionLocalization::get_options();

		$resultset = $this->execute_wpsolr_query( $wpsolr_query );

		$found = $resultset->getNumFound();

		// No results: try a new query if did you mean is activated
		if ( ( $found === 0 ) && ( WPSOLR_Global::getOption()->get_search_is_did_you_mean() ) ) {

			// Add spellcheck to current solarium query
			$spell_check = $this->solarium_query->getSpellcheck();
			$spell_check->setCount( 10 );
			$spell_check->setCollate( true );
			$spell_check->setExtendedResults( true );
			$spell_check->setCollateExtendedResults( true );

			// Excecute the query modified
			$resultset = $this->execute_solarium_query();

			// Parse spell check results
			$spell_check_results = $resultset->getSpellcheck();
			if ( $spell_check_results && ! $spell_check_results->getCorrectlySpelled() ) {
				$collations          = $spell_check_results->getCollations();
				$queryTermsCorrected = $wpsolr_query->get_wpsolr_query(); // original query
				foreach ( $collations as $collation ) {
					foreach ( $collation->getCorrections() as $input => $correction ) {
						$queryTermsCorrected = str_replace( $input, is_array( $correction ) ? $correction[0] : $correction, $queryTermsCorrected );
					}

				}

				if ( $queryTermsCorrected != $wpsolr_query->get_wpsolr_query() ) {

					$err_msg         = sprintf( OptionLocalization::get_term( $localization_options, 'results_header_did_you_mean' ), $queryTermsCorrected ) . '<br/>';
					$search_result[] = $err_msg;

					// Execute query with spelled terms
					$this->solarium_query->setQuery( $queryTermsCorrected );
					try {
						$resultset = $this->execute_solarium_query();
						$found     = $resultset->getNumFound();

					} catch ( Exception $e ) {
						// Sometimes, the spelling query returns errors
						// java.lang.StringIndexOutOfBoundsException: String index out of range: 15\n\tat java.lang.AbstractStringBuilder.charAt(AbstractStringBuilder.java:203)\n\tat
						// java.lang.StringBuilder.charAt(StringBuilder.java:72)\n\tat org.apache.solr.spelling.SpellCheckCollator.getCollation(SpellCheckCollator.java:164)\n\tat

						$found = 0;
					}

				} else {
					$search_result[] = 0;
				}

			} else {
				$search_result[] = 0;
			}

		} else {
			$search_result[] = 0;
		}

		// Retrieve facets from resultset
		$facets_to_display = WPSOLR_Global::getOption()->get_facets_to_display();
		if ( count( $facets_to_display ) ) {
			foreach ( $facets_to_display as $facet ) {

				$fact      = $this->get_facet_hierarchy_name( WpSolrSchema::_FIELD_NAME_FLAT_HIERARCHY, $facet );
				$facet_res = $resultset->getFacetSet()->getFacet( "$fact" );

				foreach ( $facet_res as $value => $count ) {
					$output[ $facet ][] = array( 'value' => $value, 'count' => $count );
				}


			}
			$search_result[] = $output;

		} else {
			$search_result[] = 0;
		}

		$search_result[] = $found;

		$results      = array();
		$highlighting = $resultset->getHighlighting();

		$i                    = 1;
		$cat_arr              = array();
		$are_comments_indexed = WPSOLR_Global::getOption()->get_index_are_comments_indexed();
		foreach ( $resultset as $document ) {

			$id      = $document->id;
			$title   = $document->title;
			$content = '';

			$image_url = wp_get_attachment_image_src( get_post_thumbnail_id( $id ) );

			$no_comments = $document->numcomments;
			if ( $are_comments_indexed ) {
				$comments = $document->comments;
			}
			$date = date( 'm/d/Y', strtotime( $document->displaydate ) );

			if ( property_exists( $document, 'categories_str' ) ) {
				$cat_arr = $document->categories_str;
			}


			$cat  = implode( ',', $cat_arr );
			$auth = $document->author;

			$url = get_permalink( $id );

			$highlightedDoc = $highlighting->getResult( $document->id );
			$cont_no        = 0;
			$comm_no        = 0;
			if ( $highlightedDoc ) {

				foreach ( $highlightedDoc as $field => $highlight ) {

					if ( $field == WpSolrSchema::_FIELD_NAME_TITLE ) {

						$title = implode( ' (...) ', $highlight );

					} else if ( $field == WpSolrSchema::_FIELD_NAME_CONTENT ) {

						$content = implode( ' (...) ', $highlight );

					} else if ( $field == WpSolrSchema::_FIELD_NAME_COMMENTS ) {

						$comments = implode( ' (...) ', $highlight );
						$comm_no  = 1;

					}

				}

			}

			$msg = '';
			$msg .= "<div id='res$i'><div class='p_title'><a href='$url'>$title</a></div>";

			$image_fragment = '';
			// Display first image
			if ( is_array( $image_url ) && count( $image_url ) > 0 ) {
				$image_fragment .= "<img class='wdm_result_list_thumb' src='$image_url[0]' />";
			}

			if ( empty( $content ) ) {
				// Set a default value for content if no highlighting returned.
				$post_to_show = get_post( $id );
				if ( isset( $post_to_show ) ) {
					// Excerpt first, or content.
					$content = ( ! empty( $post_to_show->post_excerpt ) ) ? $post_to_show->post_excerpt : $post_to_show->post_content;

					if ( isset( $ind_opt['is_shortcode_expanded'] ) && ( strpos( $content, '[solr_search_shortcode]' ) === false ) ) {

						// Expand shortcodes which have a plugin active, and are not the search form shortcode (else pb).
						global $post;
						$post    = $post_to_show;
						$content = do_shortcode( $content );
					}

					// Remove shortcodes tags remaining, but not their content.
					// strip_shortcodes() does nothing, probably because shortcodes from themes are not loaded in admin.
					// Credit: https://wordpress.org/support/topic/stripping-shortcodes-keeping-the-content.
					// Modified to enable "/" in attributes
					$content = preg_replace( "~(?:\[/?)[^\]]+/?\]~s", '', $content );  # strip shortcodes, keep shortcode content;


					// Strip HTML and PHP tags
					$content = strip_tags( $content );

					$solr_res_options = get_option( 'wdm_solr_res_data', array() );
					if ( isset( $solr_res_options['highlighting_fragsize'] ) && is_numeric( $solr_res_options['highlighting_fragsize'] ) ) {
						// Cut content at the max length defined in options.
						$content = substr( $content, 0, $solr_res_options['highlighting_fragsize'] );
					}
				}
			}


			// Format content text a little bit
			$content = str_replace( '&nbsp;', '', $content );
			$content = str_replace( '  ', ' ', $content );
			$content = ucfirst( trim( $content ) );
			$content .= '...';

			//if ( $cont_no == 1 ) {
			if ( false ) {
				$msg .= "<div class='p_content'>$image_fragment $content - <a href='$url'>Content match</a></div>";
			} else {
				$msg .= "<div class='p_content'>$image_fragment $content</div>";
			}
			if ( $comm_no == 1 ) {
				$msg .= "<div class='p_comment'>" . $comments . "-<a href='$url'>Comment match</a></div>";
			}

			// Groups bloc - Bottom right
			$wpsolr_groups_message = apply_filters( WpSolrFilters::WPSOLR_FILTER_SOLR_RESULTS_DOCUMENT_GROUPS_INFOS, get_current_user_id(), $document );
			if ( isset( $wpsolr_groups_message ) ) {

				// Display groups of this user which owns at least one the document capability
				$message = $wpsolr_groups_message['message'];
				$msg .= "<div class='p_misc'>$message";
				$msg .= "</div>";
				$msg .= '<br/>';

			}

			// Informative bloc - Bottom right
			$msg .= "<div class='p_misc'>";
			$msg .= "<span class='pauthor'>" . sprintf( OptionLocalization::get_term( $localization_options, 'results_row_by_author' ), $auth ) . "</span>";
			$msg .= empty( $cat ) ? "" : "<span class='pcat'>" . sprintf( OptionLocalization::get_term( $localization_options, 'results_row_in_category' ), $cat ) . "</span>";
			$msg .= "<span class='pdate'>" . sprintf( OptionLocalization::get_term( $localization_options, 'results_row_on_date' ), $date ) . "</span>";
			$msg .= empty( $no_comments ) ? "" : "<span class='pcat'>" . sprintf( OptionLocalization::get_term( $localization_options, 'results_row_number_comments' ), $no_comments ) . "</span>";
			$msg .= "</div>";

			// End of snippet bloc
			$msg .= "</div><hr>";

			array_push( $results, $msg );
			$i = $i + 1;
		}
		//  $msg.='</div>';


		if ( count( $results ) < 0 ) {
			$search_result[] = 0;
		} else {
			$search_result[] = $results;
		}

		$fir = $wpsolr_query->get_start() + 1;

		$last = $wpsolr_query->get_start() + $wpsolr_query->get_nb_results_by_page();
		if ( $last > $found ) {
			$last = $found;
		}

		if ( WPSOLR_Global::getOption()->get_search_is_infinitescroll() ) {

			$information_header = sprintf( OptionLocalization::get_term( $localization_options, 'infinitescroll_results_header_pagination_numbers' ), $found );

		} else {

			$information_header = sprintf( OptionLocalization::get_term( $localization_options, 'results_header_pagination_numbers' ), $fir, $last, $found );
		}

		$search_result[] = "<span class='infor'>" . $information_header . "</span>";


		return $search_result;
	}


	/**
	 * Add facet fields to the solarium query
	 *
	 * @param Query $solarium_query
	 * @param array $field_names
	 * @param int $max_nb_items_by_facet Maximum items by facet
	 * @param int $min_count_by_facet Do not return facet elements with less than this minimum count
	 */
	public function add_facet_fields(
		Query $solarium_query,
		$facets_parameters
	) {

		// Field names
		$field_names = isset( $facets_parameters[ self::PARAMETER_FACET_FIELD_NAMES ] )
			? $facets_parameters[ self::PARAMETER_FACET_FIELD_NAMES ]
			: array();

		// Limit
		$limit = isset( $facets_parameters[ self::PARAMETER_FACET_LIMIT ] )
			? $facets_parameters[ self::PARAMETER_FACET_LIMIT ]
			: self::DEFAULT_MAX_NB_ITEMS_BY_FACET;

		// Min count
		$min_count = isset( $facets_parameters[ self::PARAMETER_FACET_MIN_COUNT ] )
			? $facets_parameters[ self::PARAMETER_FACET_MIN_COUNT ]
			: self::DEFAULT_MIN_COUNT_BY_FACET;


		if ( count( $field_names ) ) {

			$facetSet = $solarium_query->getFacetSet();

			// Only display facets that contain data
			$facetSet->setMinCount( $min_count );

			foreach ( $field_names as $facet ) {

				$fact = $this->get_facet_hierarchy_name( WpSolrSchema::_FIELD_NAME_FLAT_HIERARCHY, $facet );

				// Add the facet
				$facetSet->createFacetField( "$fact" )->setField( "$fact" );

				if ( ! empty( $limit ) ) {

					$facetSet->setLimit( $limit );
				}
			}
		}

	}

	/**
	 * Add highlighting fields to the solarium query
	 *
	 * @param Query $solarium_query
	 * @param array $highlighting_parameters
	 */
	public
	function add_highlighting_fields(
		Query $solarium_query,
		$highlighting_parameters
	) {

		// Field names
		$field_names = isset( $highlighting_parameters[ self::PARAMETER_HIGHLIGHTING_FIELD_NAMES ] )
			? $highlighting_parameters[ self::PARAMETER_HIGHLIGHTING_FIELD_NAMES ]
			: array(
				WpSolrSchema::_FIELD_NAME_TITLE,
				WpSolrSchema::_FIELD_NAME_CONTENT,
				WpSolrSchema::_FIELD_NAME_COMMENTS
			);

		// Fragment size
		$fragment_size = isset( $highlighting_parameters[ self::PARAMETER_HIGHLIGHTING_FRAGMENT_SIZE ] )
			? $highlighting_parameters[ self::PARAMETER_HIGHLIGHTING_FRAGMENT_SIZE ]
			: self::DEFAULT_HIGHLIGHTING_FRAGMENT_SIZE;

		// Prefix
		$prefix = isset( $highlighting_parameters[ self::PARAMETER_HIGHLIGHTING_PREFIX ] )
			? $highlighting_parameters[ self::PARAMETER_HIGHLIGHTING_PREFIX ]
			: self::DEFAULT_HIGHLIGHTING_PREFIX;

		// Postfix
		$postfix = isset( $highlighting_parameters[ self::PARAMETER_HIGHLIGHTING_POSTFIX ] )
			? $highlighting_parameters[ self::PARAMETER_HIGHLIGHTING_POSTFIX ]
			: self::DEFAULT_HIGHLIGHTING_POSFIX;

		$highlighting = $solarium_query->getHighlighting();

		foreach ( $field_names as $field_name ) {

			$highlighting->getField( $field_name )->setSimplePrefix( $prefix )->setSimplePostfix( $postfix );

			// Max size of each highlighting fragment for post content
			$highlighting->getField( $field_name )->setFragSize( $fragment_size );
		}

	}

	/**
	 * Ping the Solr index
	 */
	public
	function ping() {

		return $this->solarium_client->ping( $this->solarium_client->createPing() );
	}

	/**
	 * Add filter query fields to the solarium query
	 *
	 * @param Query $solarium_query
	 * @param array $filter_query_fields
	 */
	private
	function add_filter_query_fields(
		Query $solarium_query, $filter_query_fields = array()
	) {

		foreach ( $filter_query_fields as $filter_query_field ) {

			if ( ! empty( $filter_query_field ) ) {

				$filter_query_field_array = explode( ':', $filter_query_field );

				$filter_query_field_name  = strtolower( $filter_query_field_array[0] );
				$filter_query_field_value = isset( $filter_query_field_array[1] ) ? $filter_query_field_array[1] : '';


				if ( ! empty( $filter_query_field_name ) && ! empty( $filter_query_field_value ) ) {

					$filter_query_field_name = $this->get_facet_hierarchy_name( WpSolrSchema::_FIELD_NAME_NON_FLAT_HIERARCHY, $filter_query_field_name );

					$fac_fd = "$filter_query_field_name";

					// In case the facet contains white space, we enclose it with "".
					$filter_query_field_value_escaped = "\"$filter_query_field_value\"";

					$solarium_query->addFilterQuery( array(
						'key'   => "$fac_fd:$filter_query_field_value_escaped",
						'query' => "$fac_fd:$filter_query_field_value_escaped"
					) );

				}
			}
		}
	}

	/**
	 * Add sort field to the solarium query
	 *
	 * @param Query $solarium_query
	 * @param string $sort_field_name
	 */
	private
	function add_sort_field(
		Query $solarium_query, $sort_field_name = self::SORT_CODE_BY_RELEVANCY_DESC
	) {

		switch ( $sort_field_name ) {

			case self::SORT_CODE_BY_DATE_DESC:
				$solarium_query->addSort( WpSolrSchema::_FIELD_NAME_DATE, $solarium_query::SORT_DESC );
				break;

			case self::SORT_CODE_BY_DATE_ASC:
				$solarium_query->addSort( WpSolrSchema::_FIELD_NAME_DATE, $solarium_query::SORT_ASC );
				break;

			case self::SORT_CODE_BY_NUMBER_COMMENTS_DESC:
				$solarium_query->addSort( WpSolrSchema::_FIELD_NAME_NUMBER_OF_COMMENTS, $solarium_query::SORT_DESC );
				break;

			case self::SORT_CODE_BY_NUMBER_COMMENTS_ASC:
				$solarium_query->addSort( WpSolrSchema::_FIELD_NAME_NUMBER_OF_COMMENTS, $solarium_query::SORT_ASC );
				break;

			case self::SORT_CODE_BY_RELEVANCY_DESC:
			default:
				// None is relevancy by default
				break;

		}

		// Let a chance to add custom sort options
		$solarium_query = apply_filters( WpSolrFilters::WPSOLR_FILTER_SORT, $solarium_query, $sort_field_name );
	}

	/**
	 * Set fields returned by the query.
	 * We do not ask for 'content', because it can be huge for attachments, and is anyway replaced by highlighting.
	 *
	 * @param Query $solarium_query
	 * @param array $field_names
	 */
	private
	function add_fields(
		Query $solarium_query,
		$field_names = array(
			WpSolrSchema::_FIELD_NAME_ID,
			WpSolrSchema::_FIELD_NAME_TITLE,
			WpSolrSchema::_FIELD_NAME_NUMBER_OF_COMMENTS,
			WpSolrSchema::_FIELD_NAME_COMMENTS,
			WpSolrSchema::_FIELD_NAME_DISPLAY_DATE,
			WpSolrSchema::_FIELD_NAME_CATEGORIES_STR,
			WpSolrSchema::_FIELD_NAME_AUTHOR
		)
	) {
		$solarium_query->setFields( $field_names );
	}

	/**
	 * Set the query keywords.
	 *
	 * @param Query $solarium_query
	 * @param string $keywords
	 */
	private
	function set_keywords(
		Query $solarium_query, $keywords
	) {

		if ( WPSOLR_Global::getOption()->get_search_is_partial_matches() ) {

			$keywords = trim( $keywords );

			// Add '*' to each world of the query string.
			// 'word1  word2 ' => 'word1*  word2* '
			$keywords1 = preg_replace( '/(\S+)/i', '$1*', $keywords );

			if ( $keywords1 === ( $keywords . '*' ) ) {
				// then use 'OR' to ensure results include the exact keywords also (not only beginning with keywords) if there is one word only

				$keywords = $keywords . ' OR ' . $keywords1;

			} else {

				$keywords = $keywords1;
			}
		}

		$solarium_query->setQuery( WpSolrSchema::_FIELD_NAME_DEFAULT_QUERY . ':' . ! empty( $keywords ) ? $keywords : '*' );
	}

	/**
	 * Does a facet has to be shown as a hierarchy
	 *
	 * @param $facet_name
	 *
	 * @return bool
	 */
	private function is_facet_to_show_as_a_hierarchy( $facet_name ) {

		$facets_to_show_as_a_hierarchy = WPSOLR_Global::getOption()->get_facets_to_show_as_hierarchy();

		return ! empty( $facets_to_show_as_a_hierarchy ) && ! empty( $facets_to_show_as_a_hierarchy[ $facet_name ] );
	}

	/**
	 * Get a facet name if it's hierarchy (or not)
	 *
	 * @param $facet_name
	 *
	 * @return string Facet name with hierarch or not
	 */
	private function get_facet_hierarchy_name( $hierarchy_field_name, $facet_name ) {

		$facet_name   = strtolower( str_replace( ' ', '_', $facet_name ) );
		$is_hierarchy = $this->is_facet_to_show_as_a_hierarchy( $facet_name );

		if ( WpSolrSchema::_FIELD_NAME_CATEGORIES === $facet_name ) {

			// Field 'categories' are now treated as other fields (dynamic string type)
			$facet_name = WpSolrSchema::_FIELD_NAME_CATEGORIES_STR;
		}

		$result = $is_hierarchy ? sprintf( $hierarchy_field_name, $facet_name ) : $facet_name;

		return $result;
	}


}
