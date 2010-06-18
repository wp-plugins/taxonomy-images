<?php
/*
Plugin Name: Taxonomy Images BETA
Plugin URI: http://wordpress.mfields.org/plugins/taxonomy-images/
Description: The Taxonomy Images plugin enables you to associate images from your Media Library to categories, tags and taxonomies.
Version: 0.4.3
Author: Michael Fields
Author URI: http://wordpress.mfields.org/
License: GPLv2

Copyright 2010  Michael Fields  michael@mfields.org

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License version 2 as published by
the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
	
TODO LIST:
	1.	Move inline styles to style tag in admin head.
	2.	Set up for localization.
	3.	Make border appear around image on successful association.
	4.	Support for Link Category Images?
	5.	Add support for wp_list_categories() or create new functionality based on wp_list_categories()
	6.	Add support for thumbnail generation of old images.
*/

if( !function_exists( 'pr' ) ) {
	function pr( $var ) {
		print '<pre>' . print_r( $var, true ) . '</pre>';
	}
}

/* 2.9 Branch support */
if( !function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $taxonomy ) {
		global $wp_taxonomies;
		return isset( $wp_taxonomies[$taxonomy] );
	}
}

/**
* @package Crop
*/
if( !class_exists( 'taxonomy_images_plugin' ) ) {
	/**
	* Category Thumbs
	* @author Michael Fields <michael@mfields.org>
	* @copyright Copyright (c) 2009, Michael Fields.
	* @license http://opensource.org/licenses/gpl-2.0.php GNU Public License v2
	* @package Plugins
	* @filesource
	*/
	class taxonomy_images_plugin {
		public $settings = array();
		public $locale = 'taxonomy_image_plugin';
		private $permission = 'manage_categories';
		private $ajax_action = 'update_relationship';
		private $attr_slug = 'mf_term_id';
		private $detail_size = array( 75, 75, true );
		private $custom_taxonomies = array();
		private $current_taxonomy = false;
		private $plugin_basename = '';
		private $min_version_wordpress = '2.9.1'; /* Due to te use of add_image_size() */
		private $min_version_php = '5';
		public function __construct() {
			/* Set Properties */
			$this->dir = dirname( __FILE__ );
			$this->url = plugin_dir_url( __FILE__ );
			$this->ajax_url = admin_url() . 'admin-ajax.php?action=' . $this->ajax_action;
			$this->settings = get_option( $this->locale );
			$this->plugin_basename = plugin_basename( __FILE__ );
			
			/* Plugin Activation Hooks */
			register_activation_hook( __FILE__, array( &$this, 'activate' ) );
			
			/* General Hooks. */
			add_action( 'init', array( &$this, 'add_new_image_size' ) );
			add_action( 'admin_init', array( &$this, 'register_settings' ) );
			add_action( 'admin_head', array( &$this, 'set_current_taxonomy' ), 10 );
			add_action( 'wp_head', array( &$this, 'set_current_taxonomy' ), 10 );
			
			/* Media Upload Thickbox Hooks. */
			add_filter( 'media_meta', array( &$this, 'thumbnail_button' ), 10, 2 );
			add_action( 'admin_head-media-upload-popup', array( &$this, 'media_popup_script' ), 2000 );
			add_action( 'wp_ajax_' . $this->ajax_action, array( &$this, 'process_ajax' ), 10 );
						
			/* Category Admin Hooks. */
			add_action( 'admin_print_scripts-categories.php', array( &$this, 'scripts' ) );
			add_action( 'admin_print_styles-categories.php', array( &$this, 'styles' ) );
			
			/* 3.0 and beyond. Dynamically create hooks. */
			add_action( 'admin_init', array( &$this, 'generate_column_and_row_filters' ) );
			
			/* 2.9 Support - hook into taxonomy terms administration panel. */
			add_filter( 'manage_categories_custom_column', array( &$this, 'category_rows' ), 15, 3 );
			add_filter( 'manage_categories_columns', array( &$this, 'category_columns' ) );
			add_filter( 'manage_edit-tags_columns', array( &$this, 'category_columns' ) );
			
			/* Tag + Taxonomy Admin Hooks. */
			add_action( 'admin_print_scripts-edit-tags.php', array( &$this, 'scripts' ) );
			add_action( 'admin_print_styles-edit-tags.php', array( &$this, 'styles' ) );
			
			/* Custom Actions for front-end. */
			add_action( $this->locale . '_print_image_html', array( &$this, 'print_image_html' ), 1, 3 );
			add_shortcode( $this->locale, array( &$this, 'list_term_images_shortcode' ) );
			$this->debug_hooks();
		}
		/**
		 * Dynamically hooks into administration panels for taxonomy terms.
		 * @since 0.4.3
		 * @uses $wp_taxonomies
		 */
		public function generate_column_and_row_filters() {
			global $wp_taxonomies;	
			foreach( $wp_taxonomies as $taxonomy => $taxonomies ) {
				add_filter( 'manage_' . $taxonomy . '_custom_column', array( &$this, 'category_rows' ), 10, 3 );
				add_filter( 'manage_edit-' . $taxonomy . '_columns', array( &$this, 'category_columns' ), 10, 3 );
			}
		}
		public function get_fullsize_image_dimensions( $term_tax_id ) {
			$post_id = ( array_key_exists( $term_tax_id, $this->settings ) )
				? $this->settings[$term_tax_id]
				: false;
			$meta = ( $post_id )
				? get_post_meta( $post_id, '_wp_attachment_metadata', true )
				: false;
			return $meta;
		}
		public function list_term_images_shortcode( $atts = array() ) {
			$o = '';
			$defaults = array(
				// 'id' => false,
				'taxonomy' => 'category',
				'size' => 'detail',
				'template' => 'list'
				);
				
			extract( shortcode_atts( $defaults, $atts ) );
			
			/* No taxonomy defined return an html comment. */
			if( !taxonomy_exists( $taxonomy ) ) {
				$tax = strip_tags( trim( $taxonomy ) );
				return '<!--' . $this->locale . ' error: Taxonomy "' . $taxonomy . '" is not defined.-->';
			}
			
			$terms = get_terms( $taxonomy );
			
			#pr( $this->settings );
			
			if( !is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$open = '';
					$close = '';
					$img_tag = '';
					$url = get_term_link( $term, $term->taxonomy );
					$img = $this->get_image_html( $size, $term->term_taxonomy_id, true, 'left' );
					$title = apply_filters( 'the_title', $term->name );
					$title_attr = esc_attr( $term->name . ' (' . $term->count . ')' );
					$description = apply_filters( 'the_content', $term->description );
					
					
					if( $template === 'grid' ) {
						$o.= "\n\t" . '<div class="' . $this->locale . '-' . $template . '">';
						$o.= "\n\t\t" . '<a style="float:left;" title="' . $title_attr . '" href="' . $url . '">' . $img . '</a>';
						$o.= "\n\t" . '</div>';
					}
					else {
						$o.= "\n\t\t" . '<a title="' . $title_attr . '" href="' . $url . '">' . $img . '</a>';;
						$o.= "\n\t\t" . '<h2 style="clear:none;margin-top:0;padding-top:0;line-height:1em;"><a href="' . $url . '">' . $title . '</a></h2>';
						$o.= $description;
						$o.= "\n\t" . '<div style="clear:both;height:1.5em"></div>';
						$o.= "\n";
					}
				}
			}
			return $o;
		}
		public function set_current_taxonomy() {
			if( is_admin() ) {
				global $hook_suffix;
				if( $hook_suffix === 'categories.php' )
					$this->current_taxonomy = 'category';
				if( $hook_suffix === 'edit-tags.php' && isset( $_GET['taxonomy'] ) )
					$this->current_taxonomy = ( get_taxonomy( $_GET['taxonomy'] ) ) ? $_GET['taxonomy'] : false;
			}
			else {
				global $wp_query;
				$q = $wp_query->get_queried_object();
				$this->current_taxonomy = ( get_taxonomy( $q->taxonomy ) ) ? $q->taxonomy : false;
			}
		}
		public function add_new_image_size() {
			add_image_size( 'detail', $this->detail_size[0], $this->detail_size[1], $this->detail_size[2] );
		}
		public function process_ajax() {
			require_once( $this->dir . '/ajax.php' );
		}
		public function create_nonce_action( $action ) {
			return $this->locale . '-' . $action;
		}
		public function installation_success( $c ) {
			return $c . ' here I am';
		}
		/*
		* Special error handling function for activation
		* Terminates script execution and prints a link
		* for user to upgrade WordPress.
		*/
		public function installation_fail_wordpress( $errno, $errstr, $errfile, $errline ) {
			global $wp_version;
			$upgrade = admin_url() . 'update-core.php';
			exit( 'The Taxonomy Image Plugin requires WordPress version ' . $this->min_version_wordpress . ' or greater. You are currently using version ' . $wp_version . ' of WordPress. To succesfuly activate this plugin, please <a target="_top" href="' . $upgrade . '">Upgrade WordPress</a>' );
		}
		/*
		* Special error handling function for activation
		* Terminates script execution and prints a notice
		* for user to upgrade PHP.
		*/
		public function installation_fail_php( $errno, $errstr, $errfile, $errline ) {
			exit( 'The Taxonomy Image Plugin requires PHP version ' . $this->min_version_php . ' or greater. You are currently using version ' . PHP_VERSION . ' of PHP. Please update if you would like to use this plugin.' );
		}
		/*
		* Checks that current version of WordPress is adequate for installation.
		* 
		* Adds a record to the options table which stores relationships
		* between "term_taxonomies" and "attachment_id" in a serialized
		* indexed array. Array keys are set to the term_taxonomy_id
		* column of the *_term_taxonomy table while array values posses
		* the post->ID of the associated attachment.
		*/
		public function activate() {
			global $wp_version;
			if ( version_compare( $wp_version, $this->min_version_wordpress, '<' ) ) {
				deactivate_plugins( $this->plugin_basename, true );
				$old_error_handler = set_error_handler( array( &$this, 'installation_fail_wordpress' ) );
				trigger_error( 'You are using WordPress version ' . $wp_version, E_USER_ERROR );
			}
			else if ( version_compare( PHP_VERSION, $this->min_version_php, '<' ) ) {
				deactivate_plugins( $this->plugin_basename, true );
				$old_error_handler = set_error_handler( array( &$this, 'installation_fail_php' ) );
				trigger_error( 'You are using PHP version ' . $wp_version, E_USER_ERROR );
			}
			else {
				add_option( $this->locale, array() );
			}
		}
		/*
		* Ensures that all key/value pairs in an array are integers.
		* @param $array (array)
		* @return (array)
		*/
		public function sanitize_array( $array ) {
			$o = array();
			if( is_array( $array ) )
				foreach( $array as $key => $value )
					$o[ (int) $key ] = (int) $value;
			return $o;
		}
		public function register_settings( ) {
			register_setting( $this-locale, $this-locale, array( $this, 'sanitize_array' ) );
		}
		/*
		* Creates html for the button which appears in the Media Upload Thickbox.
		*/
		public function thumbnail_button( $c, $post ) {
			if( isset( $_GET[ $this->attr_slug ] ) ) {
				$id = (int) $post->ID;
				$text = __( 'Add Thumbnail to Taxonomy', $this->locale );
				return $c . '<br /><br /><a rel="' . $id . '" class="button ' . $this->locale . '" href="#" onclick="return false;">' . $text . '</a>';
			}
			return $c;
		}
		public function scripts() { wp_enqueue_script( 'thickbox' ); }
		public function styles() { wp_enqueue_style( 'thickbox' ); }
		public function category_rows( $c, $column_name, $term_id ) {
			if( $column_name === 'custom' ) {
				$term_id = $this->term_tax_id( (int) $term_id );
				$style = '';
				$href = $this->media_link( $term_id );
				$id = $this->locale . '_' . $term_id;
				$attachment_id = ( isset( $this->settings[ $term_id ] ) ) ? (int) $this->settings[ $term_id ] : false;
				$img = ( $attachment_id ) ? $this->get_thumb( $attachment_id ) : $this->url . 'default-image.png';
				return "\n" . $c . '<img class="hide-if-js" src="' . $this->url . 'no-javascript.png" alt="Please enable javascript." /><div class="hide-if-no-js">' . "\n" . '<a class="thickbox" onclick="return false;" href="' . $href . '" style="display:block;height:77px;width:77px;overflow:hidden;text-align:center;"><img' . $style . ' id="' . $id . '" src="' . $img . '" alt="" /></a></div>';
			}
		}
		public function category_columns( $original_columns ) {
			$new_columns = $original_columns;
			array_splice( $new_columns, 1 ); /* isolate the checkbox column */
			$new_columns['custom'] = __( 'Image', $this->locale ); /* Add custom column */
			return array_merge( $new_columns, $original_columns ); 
		}
		private function term_tax_id( $term ) {
			if( empty( $this->current_taxonomy ) )
				return false;
			$data = get_term( $term, $this->current_taxonomy );
			if( isset( $data->term_taxonomy_id ) && !empty( $data->term_taxonomy_id ) )
				return $data->term_taxonomy_id;
			else
				return false;
		}
		public function print_image_html( $size = 'medium', $term_tax_id = false, $title = true, $align = 'none' ) {
			print $this->get_image_html( $size, $term_tax_id, $title, $align );
		}
		/*
		* @uses $wp_query
		*/
		public function get_image_html( $size = 'medium', $term_tax_id = false, $title = true, $align = 'none' ) {
			$o = '';
			if( !$term_tax_id ) {
				global $wp_query;
				$mfields_queried_object = $wp_query->get_queried_object();
				$term_tax_id = $mfields_queried_object->term_taxonomy_id;
			}
			
			$term_tax_id = (int) $term_tax_id;
			
			if( isset( $this->settings[ $term_tax_id ] ) ) {
				$attachment_id = (int) $this->settings[ $term_tax_id ];
				$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt' );
				$attachment = get_post( $attachment_id ); /* Just in case an attachment was deleted, but there is still a record for it in this plugins settings. */
				if( $attachment !== NULL ) {
					$o = get_image_tag( $attachment_id, $alt, '', $align, $size );
					
				}
			}
			return $o;
		}
		/*
		* @param $id (int) Attachment ID
		*/
		private function get_thumb( $id ) {
			global $wp_version;
			
			/* Get the originally uploaded size path. */
			list( $img_url, $img_path ) = get_attachment_icon_src( $id, true );
			
			/* Attepmt to get custom intermediate size. */
			$img = image_get_intermediate_size( $id, 'detail' );
			
			/* If custom intermediate size cannot be found, attempt to create it. */
			if( !$img ) {
				
				/* Need to check to see if fullsize path can be found - sometimes this disappears during import/export. */
				if( !is_file( $img_path ) ) {
					$wp_upload_dir = wp_upload_dir();
					$img_path = $wp_upload_dir['path'] . get_post_meta( $id, '_wp_attached_file', true );
				}
				
				if( is_file( $img_path ) ) {
					$new = image_resize( $img_path, $this->detail_size[0], $this->detail_size[1], $this->detail_size[2] );
					
					if( !is_wp_error( $new ) ) {
						$meta = wp_generate_attachment_metadata( $id, $img_path );
						wp_update_attachment_metadata( $id, $meta );
						$img = image_get_intermediate_size( $id, 'detail' );
					}
				}
			}
			
			/* Custom intermediate size cannot be created, try for thumbnail. */
			if( !$img )
				$img = image_get_intermediate_size( $id, 'thumbnail' );
			
			/* Thumbnail cannot be found, try fullsize. */
			if( !$img )
				$img['url'] = wp_get_attachment_url( $id );
			
			/* Administration */
			if( !$img && is_admin() )
				return $this->url . 'deleted-image.png';
				
			/* Return. */
			if( $img )
				return $img['url'];
			else
				return false;
		}
		public function debug() {
			/*
			global $wp_taxonomies;
			pr( $this->current_taxonomy );
			pr( WP_PLUGIN_URL.'/'.str_replace(basename( __FILE__),"",plugin_basename(__FILE__)) );
			pr( WP_PLUGIN_URL.'/' );
			pr( $wp_taxonomies );
			pr( $this->custom_taxonomies );
			*/
		}
		public function debug_hooks() {
			add_action( 'wp_head', array( &$this, 'debug' ), 4000 );
			add_action( 'admin_head', array( &$this, 'debug' ), 4000 );
		}
		public function media_link( $term_tax_id = 0 ) {
			return admin_url() . 'media-upload.php?type=image&amp;tab=library&amp;' . $this->attr( $term_tax_id ). '&amp;TB_iframe=true';
		}
		public function attr( $id = 0 ) { /* $id = term_id */
			return $this->attr_slug . '=' . (int) $id;
		} 
	
		public function media_popup_script( ) {
			?>
			<script type="text/javascript">
				/* <![CDATA[ */
				jQuery( document ).ready( function( $ ) {
					<?php
					if( isset( $_GET[ $this->attr_slug ] ) ) {
						global $post;
						$term_id = (int) $_GET[ $this->attr_slug ];
						$attr = $this->attr( $id );
						$nonce = wp_create_nonce( $this->create_nonce_action( $this->ajax_action ) );
						/* Declare php Vars in Javascript */
						print <<<EOF
							var attr = '{$attr}';
							var attrSlug = '{$this->attr_slug}';
EOF;
					?>
					/*
					* Loop over all anchors in the media upload iframe and add
					* a query var for those links that do not already possess
					* one.
					*/
					$.each( $( 'a' ), function ( order, img ) {
						
						/* Capture href attribute for all links on page.*/
						var href = $( this ).attr( 'href' );
						
						/* See if custom attribute already exists. */
						var hasAttr = href.indexOf( attrSlug );
						
						/* See if there is a question mark in the url. */
						var hasQueryString = href.indexOf( '?' );
						
						/* Set to true if href contains only the hash character. */
						var isHash = ( href == '#' ) ? true : false;
						
						/* Append attribute to all links that do not already posses it. */
						if( hasAttr == -1 && !isHash ) {
							if( hasQueryString == -1 )
								href += '?' + attr;
							else
								href += '&' + attr;
						}
						
						/* Replace the href attribute with new value. */
						$( this ).attr( 'href', href );
						// alert( $( this ).attr( 'href' ) );
					});
					
					$( '.<?php print $this->locale; ?>' ).click( function () {
						/* Form values */
						var termId = encodeURIComponent( <?php print $term_id; ?> );
						var attachment_id = encodeURIComponent( $( this ).attr( 'rel' ) );
						
						/* organize the data properly */
						var data = 'term_id=' + termId + '&attachment_id=' + attachment_id  + '&wp_nonce=<?php print $nonce; ?>';
						
						/* Process $_POST request */
						$.ajax({  
							url: "<?php print $this->ajax_url; ?>",
							type: "POST",
							dataType: 'json',							
							data: data,
							cache: false,
							success: function ( data, textStatus ) {
								/* Vars */
								data = eval( data );
								var tableRowId = 'cat-' + data.term_id;
								
								/* Refresh the image on the screen below */
								if( data.attachment_thumb_src != 'false' ) {
									var img = parent.document.getElementById( '<?php print $this->locale . '_' . $term_id; ?>' );
									$( img ).attr( 'src', data.attachment_thumb_src );
								}
								/* Close Thickbox */
								self.parent.tb_remove()
							}         
						});
					} ); 
					<?php } ?>
				} );
				/* ]]> */
			</script>
			<?php
		}
	}
	$taxonomy_images_plugin = new taxonomy_images_plugin();
}
?>