<?php
/*
Plugin Name: RPS Sitemap Generator
Plugin URI: http://redpixel.com/
Description: A lightweight XML sitemap generator with Multisite awareness.
Version: 1.1.1
Author: Red Pixel Studios
Author URI: http://redpixel.com/
License: GPL3
*/

/* 	Copyright (C) 2011  Red Pixel Studios  (email : support@redpixel.com)

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

/**
 * A lightweight XML sitemap generator with Multisite awareness.
 *
 * @package rps-sitemap-generator
 * @author Red Pixel Studios
 * @version 1.1.1
 * @todo (maybe) Add an "exclude this post from sitemap update" option for
 * one time exclusion from the update process.
 * @todo bind pagination to stay within bounds
 * @todo add an option to use pretty permalinks in sitemap generation (note the problems with it)
 * @todo add meta queries to take advantage of exclusion from sitemap options (once meta_queries work properly)
 * @todo fix bug that causes date to still display when timestamp is 0 (right after you delete a sitemap [cache?])
 */
if ( ! class_exists( 'RPS_Sitemap_Generator', false ) && ! class_exists( 'RPS_Sitemap_DateTime', false ) ):

/**
 * An extension of the DateTime class which provides two
 * new constants for use with the plugin: MYSQL and WORDPRESS.
 *
 * @package rps-sitemap-generator
 * @since 1.1.0
 */
class RPS_Sitemap_DateTime extends DateTime {

	const MYSQL = 'Y-m-d H:i:s';
	const WORDPRESS = 'F j, Y \a\t g:i a';
}

/**
 * A generic class wrapper used as an artificial namespace. Contains
 * all of the functionality for the plugin.
 *
 * @package rps-sitemap-generator
 * @since 1.1.0
 */
class RPS_Sitemap_Generator {

	const MGMT_SLUG = 'rps-sitemap-generator';
	const OPTIONS_NONCE = 'com.redpixel.wp.plugins.sitemap.options';
	const TOOLS_NONCE = 'com.redpixel.wp.plugins.sitemap.tools';
	const SAVE_META_NONCE = 'com.redpixel.wp.plugins.sitemap.save.meta';

	/**
	 * Setup necessary hooks to invoke plugin functionality.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		add_action( 'admin_menu', array( &$this, 'cb_init' ) );
		add_action( 'admin_enqueue_scripts', array( &$this, 'cb_admin_styles' ) );
		add_action( 'add_meta_boxes', array( &$this, 'cb_add_post_meta_boxes' ) );
		add_action( 'save_post', array( &$this, 'cb_admin_save_post_meta' ) );
	}
	
	/**
	 * Initialize the plugin, load options, and proccess
	 * necessary form data. Note that the order of execution is
	 * important.
	 *
	 * @todo Clean up.
	 * @since 1.1.0
	 */
	public function cb_init() {
		$this->admin_menus_single_init();
		
		// If the request isn't coming from one of our pages, then abort.
		if ( $_GET['page'] != self::MGMT_SLUG ) return;
		
		$this->preprocess_post_data();
		
		$this->load_options();
		$this->process_form_options();
		
		$this->process_form_tools();
		
		$this->parse_query_vars();
		
		$this->check_for_outdated_posts();
	}
	
	/**
	 * Create the plugin's custom administration pages.
	 *
	 * @todo Consider making method private. No longer a callback.
	 * @since 1.1.0
	 */
	public function admin_menus_single_init() {
		add_management_page( 'Sitemap Generator', 'Sitemap Generator', 'import', self::MGMT_SLUG, array( &$this, 'cb_admin_menu_single_tools' ) );
		add_options_page( 'Sitemap Generator', 'Sitemap Generator', 'manage_options', self::MGMT_SLUG, array( &$this, 'cb_admin_menu_single_options' ) );
	}
	
	/**
	 * Create the sitemap settings meta boxes to display on posts
	 * and pages.
	 *
	 * @since 1.1.0
	 */
	public function cb_add_post_meta_boxes() {
		add_meta_box( 'rps_sitemap_post_options', 'Sitemap Options', array( &$this, 'cb_admin_edit_post_meta_box' ), 'post', 'side', 'default', null );
		add_meta_box( 'rps_sitemap_post_options', 'Sitemap Options', array( &$this, 'cb_admin_edit_post_meta_box' ), 'page', 'side', 'default', null );
	}
	
	/**
	 * Register and enqueue custom administration styles that
	 * are included with the plugin.
	 *
	 * @since 1.1.0
	 */
	public function cb_admin_styles() {
		wp_register_style( 'style-rps-sitemap-admin', plugins_url( 'wp-admin.css', __FILE__ ), array( 'wp-admin' ), '1.0.0' );
		wp_enqueue_style( 'style-rps-sitemap-admin' );
	}
	
	/**
	 * Output the HTML for the sitemap settings meta box.
	 *
	 * @todo Possibly change how options are being loaded overall.
	 * @since 1.1.0
	 */
	public function cb_admin_edit_post_meta_box( $post ) { ?>
		<input type="hidden" name="rps_sitemap_save_auth" value="<?php echo wp_create_nonce( self::SAVE_META_NONCE ); ?>" />
		<?php
		
		$this->load_options();
		
		?>
		<p>
			<label>
				<input type="checkbox" name="rps_sitemap_meta_exclude" value="yes"<?php if ( in_array( $post->ID, $this->options->excluded_posts ) ) echo ' checked="checked"'; ?> />
				Exclude from sitemap
			</label>
		</p>
	<?php }
	
	/**
	 * Save sitemap settings for posts and pages.
	 *
	 * @todo Consider changing the scope of how options are loaded and saved.
	 * @since 1.1.0
	 */
	public function cb_admin_save_post_meta( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return $post_id;
		if ( ! isset( $_POST['rps_sitemap_save_auth'] ) || ! wp_verify_nonce( $_POST['rps_sitemap_save_auth'], self::SAVE_META_NONCE ) ) return $post_id;
		
		if ( ! isset( $_POST['post_type'] ) ) return $post_id;
		// todo: pretty this security catch up
		if ( ! in_array( $_POST['post_type'], array( 'post', 'page' ) ) ) return $post_id;
		if ( $_POST['post_type'] == 'post' && ! current_user_can( 'edit_posts' ) ) return $post_id;
		if ( $_POST['post_type'] == 'page' && ! current_user_can( 'edit_pages' ) ) return $post_id;
		
		if ( wp_is_post_revision( $post_id ) ) return $post_id;
		
		$this->load_options();
		
		//print_r( $this->options );
		//wp_die( print_r( $this->options, true) );
		
		$exclude_post = $_POST['rps_sitemap_meta_exclude'];
		if ( $exclude_post == 'yes' ) {
		
			//$post_meta->exclude_from_sitemap = 1;
			if ( ! in_array( $post_id, $this->options->excluded_posts ) ) {
				$this->options->excluded_posts[] = $post_id;
			}
			
		} else {
		
			if ( false !== ( $key = array_search( $post_id, $this->options->excluded_posts ) ) ) {
				unset( $this->options->excluded_posts[$key] );
				$this->options->excluded_posts = array_values( $this->options->excluded_posts );
			}
			
		}
		
		// Update post meta.
		//update_post_meta( $post_id, '_rps_sitemap_exclude', $post_meta->exclude_from_sitemap );
		$this->update_options();
	}
	
	/**
	 * Output the HTML for the tools page.
	 *
	 * @since 1.1.0
	 */
	public function cb_admin_menu_single_tools() {
		if ( $this->need_credentials ) {
			$this->request_credentials_for_update();
			return;
		}
		?>
		<div class="wrap">
			<div class="icon32" id="icon-tools">&nbsp;</div>
			<h2>Sitemap Generator</h2>
			<form action="" method="post">
				<p>
					<input type="hidden" name="rps_sitemap_auth" value="<?php echo wp_create_nonce( self::TOOLS_NONCE ); ?>" />
					
					<?php if ( $this->dt_last_update_wpt !== null ) : ?>
					
						Sitemap last updated on <?php echo $this->dt_last_update_wpt->format( RPS_Sitemap_DateTime::WORDPRESS ); ?>.
						&nbsp;
						<input type="submit" value="Update Sitemap" class="button-primary" id="rps_sitemap_action_generate" name="rps_sitemap_action_generate" />
					
					<?php else : ?>
					
						You have yet to generate a sitemap for this site.
						&nbsp;
						<input type="submit" value="Generate Sitemap" class="button-primary" id="rps_sitemap_action_generate" name="rps_sitemap_action_generate" />
					
					<?php endif; ?>
				</p>
			</form>
			
			<?php if ( $this->form_processed && empty( $this->form_errors ) ) : ?>
			
				<div class="updated">
					<p>
						<strong>Sitemap generated.</strong> You may need to <a href="<?php echo admin_url( 'tools.php?page=' . self::MGMT_SLUG ); ?>">refresh this page</a> for these changes to take effect.
					</p>
				</div>
				
			<?php elseif ( ! empty( $this->form_errors['rps_sitemap_action_generate'] ) ) : ?>
			
				<div class="error">
					<p>
						<strong>An error occurred:</strong>
						<?php echo $this->form_errors['rps_sitemap_action_generate']; ?>
					</p>
				</div>
				
			<?php endif; ?>
			
			<?php if ( $this->query_outdated_posts->have_posts() && $this->dt_last_update_wpt !== null ) : ?>
			
				<div class="updated">
					<p>
						Your sitemap is out of date. New or updated posts since your sitemap was last generated are listed below. If you just updated your sitemap, please ignore this message and wait a few minutes for your changes to take effect.
					</p>
				</div>
				
			<?php elseif ( $this->dt_last_update_wpt !== null ) : ?>
			
				<p>
					Your sitemap is up to date.
				</p>
				
			<?php else : ?>
			
				<div class="updated">
					<p>
						Click &quot;Generate Sitemap&quot; to get started. If you just generated your sitemap, please ignore this message and wait a few minutes for your changes to take effect.
					</p>
				</div>
				
			<?php endif; ?>
			
			<?php if ( $this->query_outdated_posts->have_posts() && $this->dt_last_update_wpt !== null ) : global $post; ?>
			
				<?php $this->output_template_pagination( 'top' ); ?>
				
				<table class="wp-list-table widefat fixed" cellspacing="0">
					<thead>
						<?php $this->output_template_thead_tfoot(); ?>
					</thead>
					<tfoot>
						<?php $this->output_template_thead_tfoot(); ?>
					</tfoot>
					<tbody>
						<?php $loop_count = 0; while ( $this->query_outdated_posts->have_posts() ) : $this->query_outdated_posts->the_post(); ?>
						
							<tr valign="top"<?php if ( $loop_count % 2 == 0 ) echo ' class="alternate"'; ?>>
								<td class="post-title page-title column-title">
									<strong>
										<a href="<?php echo get_edit_post_link( $post->ID ); ?>" class="row-title"><?php the_title(); ?></a>
									</strong>
									<div class="row-actions">
										<span>
											<a href="<?php echo get_edit_post_link( $post->ID ); ?>">Edit</a> |
										</span>
										<span>
											<a href="<?php the_permalink(); ?>">View</a>
										</span>
									</div>
								</td>
								<td class="author column-author">
									<a href="?<?php echo $this->generate_query_string( array( 'author' => intval( get_the_author_meta( 'ID' ) ), 'paged' => 1 ) ); ?>"><?php the_author(); ?></a>
								</td>
								<td class="date column-date">
									<abbr title="<?php the_time( RPS_Sitemap_DateTime::W3C ); ?>"><?php the_time( 'Y/m/d' ); ?></abbr>
								</td>
							</tr>
						
						<?php $loop_count++; endwhile; ?>
					</tbody>
				</table>
				
				<?php $this->output_template_pagination( 'bottom' ); ?>
				
			<?php endif; wp_reset_postdata(); ?>
		</div>
	<?php }
	
	/**
	 * Template to output pagination for the tools page.
	 *
	 * @since 1.1.0
	 */
	private function output_template_pagination( $location = 'top' ) { ?>
		<form action="" method="get">
			<input type="hidden" name="page" value="<?php echo self::MGMT_SLUG; ?>" />
			<input type="hidden" name="author" value="<?php echo $this->query_vars->author; ?>" />
			<input type="hidden" name="order" value="<?php echo $this->query_vars->order; ?>" />
			<input type="hidden" name="orderby" value="<?php echo $this->query_vars->orderby; ?>" />
			<?php
			
			$found_posts = $this->query_outdated_posts->found_posts;
			$total_pages = $this->query_outdated_posts->max_num_pages;
			$page_current = ( $this->query_outdated_posts->get( 'paged' ) ) ? $this->query_outdated_posts->get( 'paged' ) : 1;
			$page_first = 1;
			$page_last = $total_pages;
			$page_prev = ( $page_current - 1 >= $page_first ) ? $page_current - 1 : $page_first;
			$page_next = ( $page_current + 1 <= $page_last ) ? $page_current + 1 : $page_last;
			
			?>
			<div class="tablenav <?php echo $location; ?>">
				<div class="tablenav-pages">
					<span class="displaying-num"><?php echo $found_posts . ( ( $found_posts > 1 ) ? ' items' : ' item' ); ?></span>
					<span class="pagination-links">
						<a href="?<?php echo $this->generate_query_string( array( 'paged' => $page_first ) ); ?>" title="Go to the first page" class="first-page<?php if ( $page_current == $page_first ) echo ' disabled'; ?>">&laquo;</a>
						<a href="?<?php echo $this->generate_query_string( array( 'paged' => $page_prev ) ); ?>" title="Go to the previous page" class="prev-page<?php if ( $page_current == $page_prev ) echo ' disabled'; ?>">&lsaquo;</a>
						<span class="paging-input">
							<input type="text" size="2" value="<?php echo $page_current; ?>" name="paged" title="Current page" class="current-page"> of <span class="total-pages"><?php echo $total_pages; ?></span>
						</span>
						<a href="?<?php echo $this->generate_query_string( array( 'paged' => $page_next ) ); ?>" title="Go to the next page" class="next-page<?php if ( $page_current == $page_next ) echo ' disabled'; ?>">&rsaquo;</a>
						<a href="?<?php echo $this->generate_query_string( array( 'paged' => $page_last ) ); ?>" title="Go to the last page" class="last-page<?php if ( $page_current == $page_last ) echo ' disabled'; ?>">&raquo;</a>
					</span>
				</div>
				<br class="clear" />
			</div>
		</form>
	<?php }
	
	/**
	 * Template to output table headers/footers for the tools
	 * page.
	 *
	 * @since 1.1.0
	 */
	private function output_template_thead_tfoot() {
		$order_this = ( $this->query_vars->order == 'DESC' ) ? 'desc' : 'asc';
		$order_next = ( $this->query_vars->order == 'DESC' ) ? 'ASC' : 'DESC'; // Case matters
		
		// Title
		$title_sort_status = ( $this->query_vars->orderby == 'title' ) ? 'sorted' : 'sortable';
		$title_classes = ' ' . $title_sort_status . ' ' . $order_this;
		
		// Author
		$author_sort_status = ( $this->query_vars->orderby == 'author' ) ? 'sorted' : 'sortable';
		$author_classes = ' ' . $author_sort_status . ' ' . $order_this;
		
		// Date
		$date_sort_status = ( $this->query_vars->orderby == 'date' ) ? 'sorted' : 'sortable';
		$date_classes = ' ' . $date_sort_status . ' ' . $order_this;
		?>
		<tr>
			<th class="manage-column column-title<?php echo $title_classes; ?>">
				<a href="?<?php echo $this->generate_query_string( array( 'orderby' => 'title', 'order' => $order_next, 'paged' => 1 ) ); ?>">
					<span>Title</span>
					<span class="sorting-indicator">&nbsp;</span>
				</a>
			</th>
			<th class="manage-column column-author<?php echo $author_classes; ?>">
				<a href="?<?php echo $this->generate_query_string( array( 'orderby' => 'author', 'order' => $order_next, 'paged' => 1 ) ); ?>">
					<span>Author</span>
					<span class="sorting-indicator">&nbsp;</span>
				</a>
			</th>
			<th class="manage-column column-date<?php echo $date_classes; ?>">
				<a href="?<?php echo $this->generate_query_string( array( 'orderby' => 'date', 'order' => $order_next, 'paged' => 1 ) ); ?>">
					<span>Last Modified</span>
					<span class="sorting-indicator">&nbsp;</span>
				</a>
			</th>
		</tr>
	<?php }
	
	/**
	 * Output the HTML for the options page.
	 *
	 * @since 1.1.0
	 */
	public function cb_admin_menu_single_options() { ?>
		<div class="wrap">
			<div class="icon32" id="icon-options-general">&nbsp;</div>
			<h2>Sitemap Generator</h2>
			
			<?php if ( $this->form_processed && empty( $this->form_errors ) ) : ?>
			
				<div class="updated">
					<p>
						<strong>Changes saved.</strong>
					</p>
				</div>
				
			<?php elseif ( ! empty( $this->form_errors ) ) : ?>
			
				<div class="error">
					<p>
						<strong>One or more errors occurred:</strong>
						See below for details.
					</p>
				</div>
				
			<?php endif; ?>
			
			<form action="" method="post">
				<input type="hidden" name="rps_sitemap_opt_auth" value="<?php echo wp_create_nonce( self::OPTIONS_NONCE ); ?>" />
				
				<table class="form-table">
					<tr valign="top">
						<th>
							<label for="rps_sitemap_opt_path">Path to sitemap</label>
						</th>
						<td>
							<?php echo $this->options->sitemap_path_prefix; ?>
							<input type="text" id="rps_sitemap_opt_path" name="rps_sitemap_opt_path" class="regular-text" value="<?php echo ( isset( $_POST['rps_sitemap_opt_path'] ) && ! empty( $this->form_errors['rps_sitemap_opt_path'] ) ) ? esc_attr( $_POST['rps_sitemap_opt_path'] ) : esc_attr( $this->options->path_to_sitemap ); ?>" />
							
							<?php if ( ! empty( $this->form_errors['rps_sitemap_opt_path'] ) ) : ?>
							
								<br /><span class="rps-description-error description"><?php echo $this->form_errors['rps_sitemap_opt_path']; ?></span>
								
							<?php endif; ?>
						</td>
					</tr>
				</table>
				
				<p class="submit">
					<input type="submit" value="Save Changes" class="button-primary" id="rps_sitemap_opt_save" name="rps_sitemap_opt_save" />
				</p>
			</form>
		</div>
	<?php }
	
	/**
	 * Callback to alter the where clause of WP_Query and
	 * add a restriction on the post modified date.
	 *
	 * @since 1.1.0
	 */
	public function cb_query_where_clause( $where ) {
		if ( $this->dt_last_update_gmt === null ) return $where;
		
		global $wpdb;
		$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_modified_gmt > %s", $this->dt_last_update_gmt->format( RPS_Sitemap_DateTime::MYSQL ) );
		return $where;
	}
	
	/**
	 * Preprocess a form's post data to get rid of any
	 * slashes that may have been added by magic quotes.
	 *
	 * @since 1.1.0
	 */
	private function preprocess_post_data() {
		if ( ! get_magic_quotes_gpc() ) return;
		
		foreach ( $_POST as $key => $value ) {
			$_POST[$key] = stripslashes( $value );
		}
	}
	
	/**
	 * Load common options that are used throughout the plugin.
	 *
	 * @since 1.1.0
	 */
	private function load_options() {
		$options_stored = get_option( '_rps_sitemap_options', array() );
		
		$sitemap_suffix = '';
		if ( is_multisite() ) {
			global $current_blog;
			if ( (int) $current_blog->blog_id != 1) {
				$sitemap_suffix = '_' . $current_blog->blog_id;
			}
			//echo '<pre>' . print_r( $current_blog, true ) . '</pre>';
		}
		
		// These options cannot be overriden regardless of what has been stored.
		$options_permanent = array(
			'sitemap_path_prefix' => ( defined( 'ABSPATH' ) ) ? ABSPATH : $_SERVER['DOCUMENT_ROOT'],
			'posts_per_page' => 10
		);
		
		$options_defaults = array(
			'path_to_sitemap' => 'sitemap' . $sitemap_suffix . '.xml',
			'excluded_posts' => array()
		);
		
		$options_pre_perm = wp_parse_args( $options_stored, $options_defaults );
		$this->options = (object) wp_parse_args( $options_permanent, $options_pre_perm );
	}
	
	/**
	 * Process the options form data to update options.
	 *
	 * @since 1.1.0
	 */
	private function process_form_options() {
		if ( ! isset( $_POST['rps_sitemap_opt_save'] ) || ! wp_verify_nonce( $_POST['rps_sitemap_opt_auth'], self::OPTIONS_NONCE ) ) return;
		
		// Validate the path.
		$path_to_sitemap = trim( $_POST['rps_sitemap_opt_path'] );
		if ( validate_file( $path_to_sitemap ) !== 0 ) :
		
			$this->form_errors['rps_sitemap_opt_path'] = 'Your sitemap path contains invalid characters. You cannot traverse up the directory tree.';
			
		elseif ( substr( $path_to_sitemap, 0, 1 ) == '/' || substr( $path_to_sitemap, 0, 1 ) == '\\' ) :
		
			$this->form_errors['rps_sitemap_opt_path'] = 'Your sitemap path cannot begin with a slash. It must be relative to your document root.';
			
		elseif ( substr( $path_to_sitemap, -4 ) != '.xml' || strlen( $path_to_sitemap ) < 5 ) : // also covers case of empty filename
		
		
			$this->form_errors['rps_sitemap_opt_path'] = 'Your sitemap path must lead to an XML (*.xml) file.';
		
		else :
		
			$this->options->path_to_sitemap = $path_to_sitemap;
		
		endif;
		
		$this->update_options();
		$this->form_processed = true;
	}
	
	/**
	 * Update options.
	 *
	 * @since 1.1.0
	 */
	private function update_options() {
		update_option( '_rps_sitemap_options', (array) $this->options );
	}
	
	/**
	 * Parse URL query vars for the tools page to allow for
	 * sorting and pagination.
	 *
	 * @since 1.1.0
	 */
	private function parse_query_vars() {
		$author = ( isset( $_GET['author'] ) ) ? intval( $_GET['author'] ) : 0;
		
		$order = ( isset( $_GET['order'] ) ) ? $_GET['order'] : 'DESC';
		$order_filter = array( 'DESC', 'ASC' );
		if ( ! in_array( $order, $order_filter ) ) $order = 'DESC';
		
		$orderby = ( isset( $_GET['orderby'] ) ) ? $_GET['orderby'] : 'date';
		$orderby_filter = array( 'date', 'title', 'author' );
		if ( ! in_array( $orderby, $orderby_filter ) ) $orderby = 'date';
		
		$paged = ( isset( $_GET['paged'] ) ) ? intval( $_GET['paged'] ) : 1;
		
		$this->query_vars = (object) array(
			'author' => $author,
			'order' => $order,
			'orderby' => $orderby,
			'paged' => $paged
		);
	}
	
	/**
	 * Create a WP_Query instance containing all posts which
	 * have been created or modified since the sitemap was
	 * last updated.
	 *
	 * @since 1.1.0
	 */
	private function check_for_outdated_posts() {
		if ( file_exists( $this->options->sitemap_path_prefix . $this->options->path_to_sitemap ) ) {
			// Accommodate for varying timezones.
			$timezone_wp = get_option( 'timezone_string' );
			$timezone_str = ( ! empty( $timezone_wp ) ) ? $timezone_wp : date_default_timezone_get();
			$dt_last_update_generic = new DateTime( date( RPS_Sitemap_DateTime::MYSQL, filemtime( $this->options->sitemap_path_prefix . $this->options->path_to_sitemap ) ), new DateTimeZone( date_default_timezone_get() ) );
			
			$this->dt_last_update_gmt = clone $dt_last_update_generic;
			$this->dt_last_update_gmt->setTimezone( new DateTimeZone( 'GMT' ) );
			
			$this->dt_last_update_wpt = clone $dt_last_update_generic;
			$this->dt_last_update_wpt->setTimezone( new DateTimeZone( $timezone_str ) );
			
			// Generate the outdated posts query.
			add_filter( 'posts_where', array( &$this, 'cb_query_where_clause' ) );
			
			$this->query_outdated_posts = new WP_Query( array(
				'author' => $this->query_vars->author,
				'post_type' => array( 'post', 'page' ),
				'post_status' => 'publish',
				'posts_per_page' => $this->options->posts_per_page,
				'paged' => $this->query_vars->paged,
				'order' => $this->query_vars->order,
				'orderby' => $this->query_vars->orderby,
				'post__not_in' => $this->options->excluded_posts/*,
				'meta_query' => array(
					//'relation' => 'OR',
					array(
						'key' => '_rps_sitemap_exclude',
						'value' => 1,
						'compare' => '!=',
						'type' => 'NUMERIC'
					),
					array(
						'key' => '_rps_sitemap_exclude',
						'value' => '',
						'compare' => 'NOT EXISTS'
					)
				)*/
			) );
			
			remove_filter( 'posts_where', array( &$this, 'cb_query_where_clause' ) );
			
		} else {
			$this->query_outdated_posts = new WP_Query; // Null query.
		}
	}
	
	/**
	 * Process the form data for the tools page and
	 * generate the sitemap if possible.
	 *
	 * @todo Use wp_nonce_field for the URL referer.
	 * @since 1.1.0
	 * @return boolean Currently the return value means nothing.
	 */
	private function process_form_tools() {
		if ( ! isset( $_POST['rps_sitemap_action_generate'] ) || ! wp_verify_nonce( $_POST['rps_sitemap_auth'], self::TOOLS_NONCE ) ) return true;
		
		ob_start();
		if ( false === ( $creds = $this->request_credentials_for_update() ) ) {
			$this->need_credentials = true;//ob_get_contents();
			ob_end_clean();
			return false;
		}
		
		if ( ! WP_Filesystem( $creds ) ) {
			//request_filesystem_credentials( $referer_url, $connection_method, true, false, $post_data );
			$this->need_credentials = true;//ob_get_contents();
			ob_end_clean();
			return false;
		}
		ob_end_clean();
		
		//if ( $this->form_tools_need_auth() ) return false;
		
		// At this point, the filesystem is active. Let's do some writing :)
		global $wp_filesystem;
		
		// $this->options->sitemap_path_prefix is the ABSPATH, but
		// $wp_filesystem->abspath() is the FTP-safe ABSPATH, so we must use it here instead.
		if ( ! $wp_filesystem->put_contents( /*$this->options->sitemap_path_prefix*/$wp_filesystem->abspath() . $this->options->path_to_sitemap, $this->generate_sitemap_xml(), FS_CHMOD_FILE ) ) {
			$this->form_errors['rps_sitemap_action_generate'] = 'Unable to generate sitemap. Could not write to file.';
		}
		
		$this->form_processed = true;
		
		return true;
	}
	
	/**
	 * Request credentials for the user so that we may
	 * access the WP_Filesystem.
	 *
	 * @since 1.1.0
	 * @return mixed False if credentials aren't set, an
	 * array of credentials otherwise.
	 */
	private function request_credentials_for_update() {
		$referer_url = admin_url( 'tools.php?page=' . self::MGMT_SLUG );
		$connection_method = '';
		$post_data = array( 'rps_sitemap_auth', 'rps_sitemap_action_generate' );
		
		return request_filesystem_credentials( $referer_url, $connection_method, false, false, $post_data );
	}
	
	/**
	 * Generate the sitemap XML.
	 *
	 * @since 1.1.0
	 * @return string A string of XML.
	 */
	private function generate_sitemap_xml() {
		$sitemap_posts = new WP_Query( array(
			'post_type' => array( 'post', 'page' ),
			'post_status' => 'publish',
			'order' => 'DESC',
			'orderby' => 'modified',
			'posts_per_page' => -1,
			'post__not_in' => $this->options->excluded_posts//,
			/*'meta_query' => array(
				array(
					'key' => '_rps_sitemap_exclude',
					'value' => '1',
					'compare' => 'NOT LIKE'
				)
			)*/
		) );
		
		//echo '<pre>' . print_r( $sitemap_posts, true ) . '</pre>';
		
		$xmlstr = '<?xml version="1.0" encoding="UTF-8"?>' . "\r\n";
		$xmlstr .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\r\n";
		
		global $post;
		
		if ( $sitemap_posts->have_posts() ) : while ( $sitemap_posts->have_posts() ) : $sitemap_posts->the_post();
		
			$location = get_permalink(); // todo: make this option (use post id instead)
			$lastmod = get_the_modified_time( RPS_Sitemap_DateTime::W3C );
			
			$xmlstr .= "\t<url>\r\n";
			
				$xmlstr .= "\t\t<loc>{$location}</loc>\r\n";
				$xmlstr .= "\t\t<lastmod>{$lastmod}</lastmod>\r\n";
			
			$xmlstr .= "\t</url>\r\n";
		
		endwhile;
		endif;
		
		wp_reset_postdata();
		
		$xmlstr .= '</urlset>';
		
		return $xmlstr;
	}
	
	/**
	 * Generate a query string which preserves options
	 * and has defaults to fallback on.
	 *
	 * @since 1.1.0
	 * @return string A query string to be appended to a URL.
	 */
	private function generate_query_string( $args ) {
		$defaults = array(
			'page' => self::MGMT_SLUG,
			'author' => $this->query_vars->author,
			'order' => $this->query_vars->order,
			'orderby' => $this->query_vars->orderby,
			'paged' => $this->query_vars->paged
		);
		
		$parsed_args = wp_parse_args( $args, $defaults );
		
		$query_string = '';
		foreach ( $parsed_args as $key => $value ) {
			$pre_amp = ( ! empty( $query_string ) ) ? '&amp;' : '';
			$query_string .= $pre_amp . $key . '=' . urlencode( $value );
		}
		
		return $query_string;
	}
	
	private $options = null; // object (options meta from site table)
	private $query_vars = null; // object (parsed $_GET requests)
	private $query_outdated_posts = null; // object (WP_Query instance)
	private $dt_last_update_wpt = null; // object
	private $dt_last_update_gmt = null; // object
	private $form_errors = array(); // array
	private $form_processed = false; // boolean
	private $need_credentials = false; // boolean
}

$rps_sitemap_generator = new RPS_Sitemap_Generator;

endif;

?>
