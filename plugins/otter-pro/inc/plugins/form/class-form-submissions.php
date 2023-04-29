<?php
/**
 * Form Block Responses Storing.
 *
 * @package ThemeIsle\OtterPro\Plugins\Form
 */

namespace ThemeIsle\OtterPro\Plugins\Form;

use ThemeIsle\GutenbergBlocks\Integration\Form_Data_Request;
use ThemeIsle\GutenbergBlocks\Server\Form_Server;
use ThemeIsle\OtterPro\Plugins\License;
use WP_Post;
use WP_Query;

/**
 * Class Form_Block
 */
class Form_Block_Emails_Storing {
	/**
	 * Form record post type.
	 */
	private const FORM_RECORD_TYPE = 'otter_form_record';

	/**
	 * Form record meta key.
	 */
	private const FORM_RECORD_META_KEY  = 'otter_form_record_meta';

	/**
	 * The main instance var.
	 *
	 * @var Form_Block
	 */
	public static $instance = null;

	/**
	 * Initialize the class
	 */
	public function init() {
		if ( ! License::has_active_license() ) {
			return;
		}

		add_action( 'init', array( $this, 'create_form_records_type' ) );
		add_action( 'admin_init', array( $this, 'set_form_records_cap' ), 10, 0 );
		add_action( 'otter_form_after_submit', array( $this, 'store_form_record' ) );
		add_action( 'admin_head', array( $this, 'add_style' ) );

		// Customize the wp_list_table.
		add_filter( 'manage_' . self::FORM_RECORD_TYPE . '_posts_columns', array( $this, 'form_record_columns' ) );
		add_filter( 'manage_edit-' . self::FORM_RECORD_TYPE . '_sortable_columns', array( $this, 'form_record_sortable_columns' ) );
		add_filter( 'manage_' . self::FORM_RECORD_TYPE . '_posts_custom_column', array( $this, 'form_record_column_values' ), 10, 2 );
		add_filter( 'bulk_actions-edit-' . self::FORM_RECORD_TYPE, array( $this, 'form_record_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . self::FORM_RECORD_TYPE, array( $this, 'handle_form_record_bulk_actions' ), 0, 3 );

		add_filter( 'post_row_actions', array( $this, 'form_record_row_actions' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'form_record_add_filters' ) );
		add_filter( 'parse_query', array( $this, 'form_record_filter_query' ) );
		add_action( 'transition_post_status', array( $this, 'transition_draft_to_read' ), 10, 3 );

		// Implement row actions behaviour.
		add_action( 'admin_action_row-read', array( $this, 'read_otter_form_record' ) );
		add_action( 'admin_action_row-unread', array( $this, 'unread_otter_form_record' ) );
		add_action( 'admin_action_edit', array( $this, 'mark_read_on_edit' ) );

		// Manage meta boxes.
		add_action( 'add_meta_boxes', array( $this, 'add_form_record_meta_box' ) );
		add_action( 'admin_menu', array( $this, 'handle_admin_menu' ) );
		add_action( 'save_post', array( $this, 'form_record_save_meta_box' ), 10, 2 );
	}

	/**
	 * Create custom post type for form records.
	 *
	 * @return void
	 */
	public function create_form_records_type() {
		register_post_type(
			self::FORM_RECORD_TYPE,
			array(
				'labels'          => array(
					'name'               => esc_html_x( 'Form Submissions', '', 'otter-blocks' ),
					'singular_name'      => esc_html_x( 'Form Submission', '', 'otter-blocks' ),
					'search_items'       => esc_html__( 'Search Submissions', 'otter-blocks' ),
					'all_items'          => esc_html__( 'Form Submissions', 'otter-blocks' ),
					'view_item'          => esc_html__( 'View Submission', 'otter-blocks' ),
					'update_item'        => esc_html__( 'Update Submission', 'otter-blocks' ),
					'not_found'          => esc_html__( 'No submissions found' ),
					'not_found_in_trash' => esc_html__( 'No submissions found in the Trash' ),
				),
				'capability_type' => self::FORM_RECORD_TYPE,
				'capabilities' => array(
					'create_posts' => 'create_otter_form_records',
				),
				'description'     => __( 'Holds the data from the form submissions', 'otter-blocks' ),
				'public'          => false,
				'show_ui'         => true,
				'show_in_rest'    => true,
				'supports'        => array( 'title' ),
			)
		);

		register_post_status( 'read', array(
			'label'                     => _x( 'Read', 'post', 'otter-blocks' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Read <span class="count">(%s)</span>', 'Read <span class="count">(%s)</span>', 'otter-blocks' ),
		) );

		register_post_status( 'unread', array(
			'label'                     => _x( 'Unread', 'post', 'otter-blocks' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Unread <span class="count">(%s)</span>', 'Unread <span class="count">(%s)</span>', 'otter-blocks' ),
		) );
	}

	/**
	 * Set custom capabilities for otter_form_record.
	 *
	 * @return void
	 */
	public function set_form_records_cap() {
		global $wp_roles;
		foreach ( $wp_roles->roles as $key => $current_role ) {
			$role = get_role( $key );
			if ( $role === null ) {
				continue;
			}

			if ( ! method_exists( $role, 'add_cap' ) ) {
				continue;
			}

			$role->add_cap( 'edit_' . self::FORM_RECORD_TYPE );
			$role->add_cap( 'read_' . self::FORM_RECORD_TYPE );
			$role->add_cap( 'delete_' . self::FORM_RECORD_TYPE );
			$role->add_cap( 'edit_' . self::FORM_RECORD_TYPE . 's' );
			$role->add_cap( 'read_' . self::FORM_RECORD_TYPE . 's' );
			$role->add_cap( 'delete_' . self::FORM_RECORD_TYPE . 's');

			$role->remove_cap( 'create_' . self::FORM_RECORD_TYPE );
			$role->remove_cap( 'create_' . self::FORM_RECORD_TYPE . 's' );
		}
	}

	/**
	 * Store form record in custom post type.
	 *
	 * @param Form_Data_Request $form_data The form data object.
	 * @return void
	 */
	public function store_form_record( $form_data ) {
		$email = Form_Server::instance()->get_email_from_form_input( $form_data );

		if ( ! $email ) {
			return;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::FORM_RECORD_TYPE,
				'post_title'  => $email,
				'post_status' => 'unread',
			)
		);

		if ( ! $post_id ) {
			return;
		}

		$meta = array(
			'email'    => array(
				'label' => 'Email',
				'value' => $email,
			),
			'form'     => array(
				'label' => 'Form',
				'value' => $form_data->get_payload_field( 'formId' ),
			),
			'post_url' => array(
				'label' => 'Post URL',
				'value' => $form_data->get_payload_field( 'postUrl' ),
			),
		);

		$form_inputs = $form_data->get_form_inputs();
		foreach ( $form_inputs as $input ) {
			if ( ! isset( $input['id'] ) ) {
				continue;
			}

			$id = substr( $input['id'], -8 );
			$meta['inputs'][ $id ] = array(
				'label' => $input['label'],
				'value' => $input['value'],
				'type'  => $input['type'],
			);
		}

		add_post_meta( $post_id, self::FORM_RECORD_META_KEY, $meta );
	}

	/**
	 * Hide the default headline.
	 *
	 * @return void
	 */
	public function add_style() {
		$screen = get_current_screen();
		if ( $screen->id === 'edit-' . self::FORM_RECORD_TYPE ) {
			?>
			<style>
			.wrap h1.wp-heading-inline {
				display: none;
			}
			</style>
			<?php
		}
	}

	/**
	 * Set the table columns.
	 *
	 * @return array
	 */
	public function form_record_columns() {
		return array(
			'cb'              => '<input type="checkbox" />',
			'email'           => __( 'Email', 'otter-blocks' ),
			'form'            => __( 'Form ID', 'otter-blocks' ),
			'post_url'        => __( 'Post', 'otter-blocks' ),
			'ID'              => __( 'ID', 'otter-blocks' ),
			'submission_date' => __( 'Submission Date', 'otter-blocks' ),
		);
	}

	/**
	 * Set the table sortable columns.
	 *
	 * @return array
	 */
	public function form_record_sortable_columns() {
		return array(
			'email'           => __( 'Email', 'otter-blocks' ),
			'ID'              => __( 'ID', 'otter-blocks' ),
			'submission_date' => __( 'Submission Date', 'otter-blocks' ),
		);
	}

	/**
	 * Set form records bulk actions.
	 *
	 * @return array
	 */
	public function form_record_bulk_actions() {
		$status       = isset( $_GET['post_status'] ) ? sanitize_text_field( wp_unslash( $_GET['post_status'] ) ) : 'all';
		$bulk_actions = array();

		if ( $status !== 'trash' ) {
			$bulk_actions['trash'] = 'Move to Trash';

			if ( $status !== 'read' ) {
				$bulk_actions['read'] = 'Mark as Read';
			}

			if ( $status !== 'unread' ) {
				$bulk_actions['unread'] = 'Mark as Unread';
			}
		} else {
			$bulk_actions['untrash'] = 'Restore';
			$bulk_actions['delete']  = 'Delete Permanently';
		}

		return $bulk_actions;
	}

	/**
	 * Manage form records row actions.
	 *
	 * @param array $actions The current row actions.
	 * @param WP_Post $post The current post object.
	 *
	 * @return array
	 */
	public function form_record_row_actions( $actions, $post ) {
		if ( $post->post_type !== 'otter_form_record' ) {
			return $actions;
		}

		unset( $actions['inline hide-if-no-js'] );
		unset( $actions['edit'] );

		$status = $post->post_status;
		if ( 'trash' !== $status ) {
			$actions['view'] = sprintf(
				'<a href="%s">%s</a>',
				get_edit_post_link( $post->ID ),
				__( 'View', 'otter-blocks' )
			);
		}

		if ( 'unread' === $status ) {
			$actions['read'] = sprintf(
				'<a href="?action=%s&' . self::FORM_RECORD_TYPE . '=%s&_wpnonce=%s">%s</a>',
				'row-read',
				$post->ID,
				wp_create_nonce( 'read-' . self::FORM_RECORD_TYPE . '_' . $post->ID ),
				__( 'Mark as Read', 'otter-blocks' )
			);
		} else if ( 'trash' !== $status ) {
			$actions['unread'] = sprintf(
				'<a href="?action=%s&' . self::FORM_RECORD_TYPE . '=%s&_wpnonce=%s">%s</a>',
				'row-unread',
				$post->ID,
				wp_create_nonce( 'unread-' . self::FORM_RECORD_TYPE . '_' . $post->ID ),
				__( 'Mark as Unread', 'otter-blocks' )
			);
		}

		return $actions;
	}

	/**
	 * Handle form record bulk actions.
	 *
	 * @param string $redirect The redirect URL.
	 * @param string $doaction The action being taken.
	 * @param array $object_ids The object IDs.
	 *
	 * @return string
	 */
	public function handle_form_record_bulk_actions( $redirect, $doaction, $object_ids ) {
		switch( $doaction ) {
			case 'read':
				foreach ( $object_ids as $object_id ) {
					wp_update_post(
						array(
							'ID'          => $object_id,
							'post_status' => 'read',
						)
					);
				}
				break;
			case 'unread':
				foreach ( $object_ids as $object_id ) {
					wp_update_post(
						array(
							'ID'          => $object_id,
							'post_status' => 'unread',
						)
					);
				}
				break;
		}

		return $redirect;
	}

	/**
	 * Mark form record as read when they're restored from trash.
	 *
	 * @param string $new_status The new status.
	 * @param string $old_status The old status.
	 * @param WP_Post $post The post object.
	 */
	public function transition_draft_to_read( $new_status, $old_status, $post ) {
		if ( $post->post_type !== self::FORM_RECORD_TYPE || $old_status !== 'trash' || $new_status !== 'draft' ) {
			return;
		}

		wp_update_post( array( 'ID'=> $post->ID, 'post_status' => 'read' ) );
	}

	/**
	 * Add form record filters.
	 *
	 * @return void
	 */
	public function form_record_add_filters() {
		if ( ! get_current_screen() || get_current_screen()->id !== 'edit-' . self::FORM_RECORD_TYPE ) {
			return;
		}

		$this->form_dropdown();
		$this->post_dropdown();
	}

	/**
	 * Parse form record filters.
	 *
	 * @param WP_Query $query Query.
	 * @return void
	 */
	public function form_record_filter_query( $query ) {
		global $pagenow;
		if ( ! is_admin() || ! isset( $_GET['post_type'] ) || $_GET['post_type'] != self::FORM_RECORD_TYPE ) {
			return;
		}

		if ( ! isset( $query->query['post_type'] ) || $query->query['post_type'] != self::FORM_RECORD_TYPE ) {
			return;
		}

		if ( $pagenow != 'edit.php' || ! isset( $_GET['filter_action'] ) ) {
			return;
		}

		$form = ( ! empty( $_REQUEST['form'] ) ) ? $_REQUEST['form'] : '';
		$post = ( ! empty( $_REQUEST['post'] ) ) ? $_REQUEST['post'] : '';

		if ( ! empty( $form ) ) {
			$query->query_vars['meta_query'][] = array(
				'key'     => self::FORM_RECORD_META_KEY,
				'value'   => serialize( $form ),
				'compare' => 'LIKE',
			);
		}

		if ( ! empty( $post ) ) {
			$query->query_vars['meta_query'][] = array(
				'key'     => self::FORM_RECORD_META_KEY,
				'value'   => serialize( $post ),
				'compare' => 'LIKE',
			);
		}
	}

	/**
	 * Manage form record columns.
	 *
	 * @param string $column The column name.
	 * @param int $post_id The post ID.
	 *
	 * @return void
	 */
	public function form_record_column_values( $column, $post_id ) {
		$meta = get_post_meta( $post_id, self::FORM_RECORD_META_KEY, true );
		switch ( $column ) {
			case 'email':
				echo $this->format_based_on_status(
					sprintf(
						'<a href="%1$s">%2$s</a>',
						get_edit_post_link( $post_id ),
						$meta['email']['value']
					),
					get_post_status( $post_id )
				);
				break;
			case 'form':
				echo $this->format_based_on_status(
					sprintf(
						'<a href="%1$s">%2$s</a>',
						esc_url( $meta['post_url']['value'] . '#' . $meta['form']['value'] ),
						substr( $meta['form']['value'], -8 )
					),
					get_post_status( $post_id )
				);
				break;
			case 'post_url':
				if ( function_exists( 'wpcom_vip_url_to_postid' ) ) {
					$source_post = wpcom_vip_url_to_postid( $meta['post_url']['value'] );
				} else {
					$source_post = url_to_postid( $meta['post_url']['value'] ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.url_to_postid_url_to_postid
				}

				$title = $source_post ? get_the_title( $source_post ) : $meta['post_url']['value'];

				echo $this->format_based_on_status(
					sprintf(
						'<a href="%1$s">%2$s</a>',
						esc_url( $meta['post_url']['value'] ),
						$title
					),
					get_post_status( $post_id )
				);
				break;
			case 'ID':
				echo $this->format_based_on_status( substr( $post_id, -8 ), get_post_status( $post_id ) );
				break;
			case 'submission_date':
				echo $this->format_based_on_status(
					get_the_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $post_id ),
					get_post_status( $post_id )
				);
				break;
		}
	}

	/**
	 * Remove the 'publish' box from the otter_form_record post type.
	 *
	 * @return void
	 */
	public function handle_admin_menu() {
		remove_meta_box( 'submitdiv', self::FORM_RECORD_TYPE, 'side' );

		global $submenu;
		unset( $submenu['edit.php?post_type=' . self::FORM_RECORD_TYPE] );

		remove_menu_page( 'edit.php?post_type=' . self::FORM_RECORD_TYPE );
		remove_submenu_page( 'otter', 'otter-form-submissions-free' );

		add_submenu_page(
			'otter',
			__( 'Form Submissions', 'otter-blocks' ),
			__( 'Form Submissions', 'otter-blocks' ),
			'manage_options',
			'edit.php?post_type=' . self::FORM_RECORD_TYPE,
			'',
			10
		);
	}

	/**
	 * Add meta box for form record.
	 *
	 * @return void
	 */
	public function add_form_record_meta_box() {
		add_meta_box(
			'field_values_meta_box',
			esc_html__( 'Submission Data', 'otter-blocks' ),
			array( $this, 'fields_meta_box_markup' ),
			self::FORM_RECORD_TYPE
		);

		// this will replace the default publish box, that's why it's using its id.
		add_meta_box(
			'submitpost',
			esc_html__( 'Update', 'otter-blocks' ),
			array( $this, 'update_meta_box_markup' ),
			self::FORM_RECORD_TYPE,
			'side'
		);
	}

	/**
	 * Save data from form record meta box.
	 *
	 * @param $post_id
	 * @param $post
	 *
	 * @return void
	 */
	public function form_record_save_meta_box( $post_id, $post ) {
		if ( empty( $_POST ) || ! isset( $_POST['action'] ) || 'editpost' !== $_POST['action'] ) {
			return;
		}

		if ( self::FORM_RECORD_TYPE !== $post->post_type ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'update-post_' . $post->ID ) ) {
			wp_die( esc_html__( 'Nonce not verified.', 'otter-blocks' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'User cannot edit this post.', 'otter-blocks' ) );
		}

		$meta = get_post_meta( $post_id, self::FORM_RECORD_TYPE . '_meta', true );

		foreach( $_POST as $key => $value ) {
			if ( ! str_starts_with( $key, 'otter_meta_' ) ) {
				continue;
			}

			$id = substr( $key, -8 );

			if ( isset( $meta['inputs'][ $id ] ) && $meta['inputs'][ $id ]['value'] !== $value ) {
				$meta['inputs'][ $id ]['value'] = $value;
			}
		}

		update_post_meta( $post_id, self::FORM_RECORD_TYPE . '_meta', $meta );
	}

	/**
	 * Render form record meta box.
	 *
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function fields_meta_box_markup( $post ) {
		$meta = get_post_meta( $post->ID, self::FORM_RECORD_TYPE . '_meta', true );
		?>
		<table class="otter_form_record_meta" style="border-spacing: 10px; width: 100%">
			<tbody style="display: table; width: 100%">
				<?php
				foreach ( $meta['inputs'] as $id => $field ) {
					?>
					<tr>
						<td><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label></td>
						<?php
						if ( $field['type'] === 'textarea' ) {
							?>
							<td><textarea name="<?php echo esc_attr( 'otter_meta_' . $id ); ?>" id="<?php echo esc_attr( $id ); ?>" class="otter_form_record_meta__value" rows="5" cols="40"><?php echo esc_html( $field['value'] ); ?></textarea></td>
							<?php
							continue;
						}
						?>
						<td><input name="<?php echo esc_attr( 'otter_meta_' . $id ); ?>" id="<?php echo esc_attr( $id ); ?>" type="<?php echo isset( $field['type'] ) ? esc_attr( $field['type'] ) : ''; ?>" class="otter_form_record_meta__value" value="<?php echo esc_html( $field['value'] ); ?>" size="40"/></td>
					</tr>
					<?php
				}
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render update form record meta box.
	 *
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function update_meta_box_markup( $post ) {
		$meta = get_post_meta( $post->ID, self::FORM_RECORD_TYPE . '_meta', true );
		?>
		<div class="submitbox">
			<div class="metadata">
				<div>
					<span class="dashicons dashicons-feedback"></span>
					<?php echo esc_html( $meta['form']['label'] ); ?>:
					<a href="<?php echo esc_url( $meta['post_url']['value'] . "#" . $meta['form']['value'] ); ?>"><?php echo esc_html( substr( $meta['form']['value'], -8 ) ); ?></a>
				</div>
				<div>
					<span class="dashicons dashicons-admin-page"></span>
					<?php echo esc_html__( 'Post', 'otter-blocks' ); ?>:
					<a href="<?php echo esc_url( $meta['post_url']['value'] ); ?>"><?php echo esc_html__('View', 'otter-blocks' ); ?></a>
				</div>
				<div>
					<span class="dashicons dashicons-calendar"></span>
					<?php echo esc_html__( 'Submitted on', 'otter-blocks' ); ?>:
					<span><strong><?php echo esc_html( get_the_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $post ) ); ?></strong></span>
				</div>
			</div>
			<div id="major-publishing-actions">
				<div id="delete-action">
					<?php
					echo sprintf(
						'<a href="?action=%s&' . self::FORM_RECORD_TYPE . '=%s&_wpnonce=%s" class="submitdelete">%s</a>',
						'trash',
						$post->ID,
						wp_create_nonce( 'trash-' . self::FORM_RECORD_TYPE . '_' . $post->ID ),
						__( 'Move to Trash', 'otter-blocks' )
					);
					?>
				</div>

				<div id="updating-action" style="text-align: right">
					<?php
					echo sprintf(
						'<input type="submit" class="button button-primary button-large" value="%s"/>',
						__( 'Update', 'otter-blocks' )
					);
					?>
				</div>
				<div class="clear"></div>
			</div>
		</div>
		<style>
			#submitpost .inside {
				padding: 0;
			}
			#submitpost .metadata {
				padding: 10px;
				display: flex;
				flex-direction: column;
				row-gap: 20px;
			}
			#submitpost .dashicons {
				color: #8c8f94;
			}
		</style>
		<?php
	}

	/**
	 * Mark form record as read when it is edited.
	 *
	 * @return void
	 */
	public function mark_read_on_edit() {
		if ( ! isset( $_REQUEST['post'] ) ) {
			return;
		}

		$post = $_REQUEST['post'];
		if ( ! get_post( $post ) || self::FORM_RECORD_TYPE !== get_post_type( $post ) ) {
			return;
		}

		$status = get_post_status( $post );
		if ( 'unread' === $status ) {
			wp_update_post( array( 'ID' => $post, 'post_status' => 'read' ) );
		}
	}

	/**
	 * Check request nonce and post ID.
	 *
	 * @return string $action The action name.
	 */
	public function check_posts( $action ) {
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

		if ( ! isset( $_REQUEST[self::FORM_RECORD_TYPE] ) ) {
			wp_die( __( 'Post ID is required', 'otter-blocks' ) );
		}

		$id = sanitize_text_field( $_REQUEST[self::FORM_RECORD_TYPE] );

		if ( ! wp_verify_nonce( $nonce, $action . '-' . self::FORM_RECORD_TYPE . '_' . $id) ) {
			wp_die( __( 'Security check failed', 'otter-blocks' ) );
		}

		$post = get_post( $id );
		if ( ! $post ) {
			wp_die( __( 'Invalid post ID', 'otter-blocks' ) );
		}

		if ( self::FORM_RECORD_TYPE !== $post->post_type ) {
			wp_die( __( 'Invalid post type', 'otter-blocks' ) );
		}

		return $id;
	}

	/**
	 * Read form record.
	 *
	 * @return void
	 */
	public function read_otter_form_record() {
		$id = $this->check_posts( 'read' );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'read' ) );

		wp_safe_redirect( remove_query_arg( array( 'action', self::FORM_RECORD_TYPE, '_wpnonce' ), admin_url( 'edit.php?post_type=' . self::FORM_RECORD_TYPE ) ) );
		exit;
	}

	/**
	 * Unread form record.
	 *
	 * @return void
	 */
	public function unread_otter_form_record() {
		$id = $this->check_posts( 'unread' );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'unread' ) );

		wp_safe_redirect( remove_query_arg( array( 'action', self::FORM_RECORD_TYPE, '_wpnonce' ), admin_url( 'edit.php?post_type=' . self::FORM_RECORD_TYPE ) ) );
		exit;
	}

	/**
	 * Get filter options.
	 *
	 * @param string $filter Filter.
	 *
	 * @return array
	 */
	private function get_filter( $filter ) {
		/**
		 * Get all form records. Here we want to avoid using WP_Query to not
		 * trigger the 'form_record_filter_query'. This is why the $wpdb.
		 */
		global $wpdb;
		$form_records = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->posts WHERE post_type = '%s' AND post_status IN ('read', 'unread', 'trash', 'publish')",
				self::FORM_RECORD_TYPE
			)
		);

		$options = array();
		foreach ( $form_records as $record ) {
			$meta = get_post_meta( $record->ID, self::FORM_RECORD_META_KEY, true );

			switch ( $filter ) {
				case 'form':
					$options[ $meta['form']['value'] ] = substr( $meta['form']['value'], -8 );
					break;
				case 'post':
					if ( function_exists( 'wpcom_vip_url_to_postid' ) ) {
						$post_id = wpcom_vip_url_to_postid( $meta['post_url']['value'] );
					} else {
						$post_id = url_to_postid( $meta['post_url']['value'] ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.url_to_postid_url_to_postid
					}

					$options[ $meta['post_url']['value'] ] = $post_id ? get_the_title( $post_id ) : $meta['post_url']['value'];
					break;
			}
		}

		return $options;
	}

	/**
	 * Get forms dropdown.
	 *
	 * @return void
	 */
	private function form_dropdown() {
		$forms = $this->get_filter( 'form' );

		if ( empty( $forms ) ) {
			return;
		}

		$form = isset( $_GET['form'] ) ? sanitize_text_field( wp_unslash( $_GET['form'] ) ) : '';

		?>
		<label for="filter-by-form"></label>
		<select name="form" id="filter-by-form">
			<option value=""><?php esc_html_e( 'All Forms', 'otter-blocks' ); ?></option>
			<?php foreach ( $forms as $form_id => $form_name ) : ?>
				<option value="<?php echo esc_attr( $form_id ); ?>" <?php selected( $form, $form_id ); ?>><?php echo esc_html( $form_name ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Get posts dropdown.
	 *
	 * @return void
	 */
	private function post_dropdown() {
		$posts = $this->get_filter( 'post' );

		if ( empty( $posts ) ) {
			return;
		}

		$post = isset( $_GET['post'] ) ? sanitize_text_field( wp_unslash( $_GET['post'] ) ) : '';

		?>
		<label for="filter-by-post"></label>
		<select name="post" id="filter-by-post">
			<option value=""><?php esc_html_e( 'All Posts', 'otter-blocks' ); ?></option>
			<?php foreach ( $posts as $post_id => $post_title ) : ?>
				<option value="<?php echo esc_attr( $post_id ); ?>" <?php selected( $post, $post_id ); ?>><?php echo esc_html( $post_title ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Make unread rows bold.
	 *
	 * @param $content
	 * @param $status
	 *
	 * @return mixed|string
	 */
	private function format_based_on_status( $content, $status ) {
		if ( 'unread' === $status ) {
			return '<strong>' . $content . '</strong>';
		}

		return $content;
	}

	/**
	 * The instance method for the static class.
	 * Defines and returns the instance of the static class.
	 *
	 * @static
	 * @access public
	 * @return Form_Block_Emails_Storing
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Throw error on object clone
	 *
	 * The whole idea of the singleton design pattern is that there is a single
	 * object therefore, we don't want the object to be cloned.
	 *
	 * @access public
	 * @return void
	 */
	public function __clone() {
		// Cloning instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'otter-blocks' ), '1.0.0' );
	}

	/**
	 * Disable unserializing of the class
	 *
	 * @access public
	 * @return void
	 */
	public function __wakeup() {
		// Unserializing instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'otter-blocks' ), '1.0.0' );
	}
}
