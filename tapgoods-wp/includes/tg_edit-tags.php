<?php
/**
 * Edit Tags Administration Screen.
 *
 * @package WordPress
 * @subpackage Administration
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
/** WordPress Administration Bootstrap */
global $taxnow;
if ( ! $taxnow ) {
	wp_die( esc_html__( 'Invalid taxonomy.', 'tapgoods-wp' ) );
}

$tax = get_taxonomy( $taxnow );

if ( ! $tax ) {
	wp_die( esc_html__( 'Invalid taxonomy.', 'tapgoods-wp' ) );
}

if ( ! in_array( $tax->name, get_taxonomies( array( 'show_ui' => true ) ), true ) ) {
	wp_die( esc_html__( 'Sorry, you are not allowed to edit terms in this taxonomy.', 'tapgoods-wp' ) );
}

if ( ! current_user_can( $tax->cap->manage_terms ) ) {
	wp_die(
		'<h1>' . esc_html__( 'You need a higher level of permission.', 'tapgoods-wp' ) . '</h1>' .
		'<p>' . esc_html__( 'Sorry, you are not allowed to manage terms in this taxonomy.', 'tapgoods-wp' ) . '</p>',
		403
	);
}

/**
 * $post_type is set when the WP_Terms_List_Table instance is created
 *
 * @global string $post_type
 */
global $post_type, $taxonomy;
$taxonomy      = $tax->name;
$wp_list_table = _get_list_table( 'WP_Terms_List_Table' );
$pagenum       = $wp_list_table->get_pagenum();

$title = $tax->labels->name;
if ( 'post' !== $post_type ) {
	$parent_file  = ( 'attachment' === $post_type ) ? 'upload.php' : "edit.php?post_type=$post_type";
	$submenu_file = "edit-tags.php?taxonomy=$taxonomy&amp;post_type=$post_type";
} elseif ( 'link_category' === $tax->name ) {
	$parent_file  = 'link-manager.php';
	$submenu_file = 'edit-tags.php?taxonomy=link_category';
} else {
	$parent_file  = 'edit.php';
	$submenu_file = "edit-tags.php?taxonomy=$taxonomy";
}

add_screen_option(
	'per_page',
	array(
		'default' => 20,
		'option'  => 'edit_' . $tax->name . '_per_page',
	)
);

get_current_screen()->set_screen_reader_content(
	array(
		'heading_pagination' => $tax->labels->items_list_navigation,
		'heading_list'       => $tax->labels->items_list,
	)
);

$location = false;
$referer  = wp_get_referer();
if ( ! $referer ) { // For POST requests.
	$referer = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
}
$referer = remove_query_arg( array( '_wp_http_referer', '_wpnonce', 'error', 'message', 'paged' ), $referer );
switch ( $wp_list_table->current_action() ) {

	case 'add-tag':
		check_admin_referer( 'add-tag', '_wpnonce_add-tag' );

		if ( ! current_user_can( $tax->cap->edit_terms ) ) {
			wp_die(
				'<h1>' . esc_html__( 'You need a higher level of permission.', 'tapgoods-wp' ) . '</h1>' .
				'<p>' . esc_html__( 'Sorry, you are not allowed to manage terms in this taxonomy.', 'tapgoods-wp' ) . '</p>',
				403
			);
		}

		if ( isset( $_POST['tag-name'], $taxonomy ) ) {
			$tag_name = sanitize_text_field( wp_unslash( $_POST['tag-name'] ) ); // Sanitize the tag name
			$post_data = wp_unslash( $_POST ); // Unslash all POST data
		
			$ret = wp_insert_term( $tag_name, sanitize_key( $taxonomy ), $post_data );
		}
		
		if ( $ret && ! is_wp_error( $ret ) ) {
			$location = add_query_arg( 'message', 1, $referer );
		} else {
			$location = add_query_arg(
				array(
					'error'   => true,
					'message' => 4,
				),
				$referer
			);
		}

		break;

	case 'delete':
		if ( ! isset( $_REQUEST['tag_ID'] ) ) {
			break;
		}

		$tag_ID = (int) $_REQUEST['tag_ID'];
		check_admin_referer( 'delete-tag_' . $tag_ID );

		if ( ! current_user_can( 'delete_term', $tag_ID ) ) {
			wp_die(
				'<h1>' . esc_html__( 'You need a higher level of permission.', 'tapgoods-wp' ) . '</h1>' .
				'<p>' . esc_html__( 'Sorry, you are not allowed to manage terms in this taxonomy.', 'tapgoods-wp' ) . '</p>',
				403
			);
		}

		wp_delete_term( $tag_ID, $taxonomy );

		$location = add_query_arg( 'message', 2, $referer );

		// When deleting a term, prevent the action from redirecting back to a term that no longer exists.
		$location = remove_query_arg( array( 'tag_ID', 'action' ), $location );

		break;

	case 'bulk-delete':
		check_admin_referer( 'bulk-tags' );

		if ( ! current_user_can( $tax->cap->delete_terms ) ) {
			wp_die(
				'<h1>' . esc_html__( 'You need a higher level of permission.', 'tapgoods-wp' ) . '</h1>' .
				'<p>' . esc_html__( 'Sorry, you are not allowed to manage terms in this taxonomy.', 'tapgoods-wp' ) . '</p>',
				403
			);
		}

		$tags = isset( $_REQUEST['delete_tags'] ) ? array_map( 'absint', wp_unslash( (array) $_REQUEST['delete_tags'] ) ) : [];
		foreach ( $tags as $tag_ID ) {
			wp_delete_term( $tag_ID, $taxonomy );
		}

		$location = add_query_arg( 'message', 6, $referer );

		break;

	case 'edit':
		if ( ! isset( $_REQUEST['tag_ID'] ) ) {
			break;
		}

		$term_id = (int) $_REQUEST['tag_ID'];
		$term    = get_term( $term_id );

		if ( ! $term instanceof WP_Term ) {
			wp_die( esc_html__( 'You attempted to edit an item that does not exist. Perhaps it was deleted?', 'tapgoods-wp' ) );
		}

		wp_redirect( sanitize_url( get_edit_term_link( $term_id, $taxonomy, $post_type ) ) );
		exit;

	case 'editedtag':
		$tag_ID = isset( $_POST['tag_ID'] ) ? absint( wp_unslash( $_POST['tag_ID'] ) ) : 0;
		check_admin_referer( 'update-tag_' . $tag_ID );

		if ( ! current_user_can( 'edit_term', $tag_ID ) ) {
			wp_die(
				'<h1>' . esc_html__( 'You need a higher level of permission.', 'tapgoods-wp' ) . '</h1>' .
				'<p>' . esc_html__( 'Sorry, you are not allowed to manage terms in this taxonomy.', 'tapgoods-wp' ) . '</p>',
				403
			);
		}

		$tag = get_term( $tag_ID, $taxonomy );
		if ( ! $tag ) {
			wp_die( esc_html__( 'You attempted to edit an item that does not exist. Perhaps it was deleted?', 'tapgoods-wp' ) );
		}

		$ret = wp_update_term( $tag_ID, $taxonomy, $_POST );

		if ( $ret && ! is_wp_error( $ret ) ) {
			$location = add_query_arg( 'message', 3, $referer );
		} else {
			$location = add_query_arg(
				array(
					'error'   => true,
					'message' => 5,
				),
				$referer
			);
		}
		break;
	default:
		if ( ! $wp_list_table->current_action() || ! isset( $_REQUEST['delete_tags'] ) ) {
			break;
		}
		check_admin_referer( 'bulk-tags' );

		$screen = get_current_screen()->id;
		$tags = isset( $_REQUEST['delete_tags'] ) ? array_map( 'absint', wp_unslash( (array) $_REQUEST['delete_tags'] ) ) : [];

		/** This action is documented in wp-admin/edit.php */
		$location = apply_filters( "handle_bulk_actions-{$screen}", $location, $wp_list_table->current_action(), $tags ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		break;
}

if ( ! $location && ! empty( $_REQUEST['_wp_http_referer'] ) ) {
	if ( isset( $_SERVER['REQUEST_URI'] ) ) {
		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ); // Sanitize input
		
		$location = esc_url_raw( remove_query_arg( array( '_wp_http_referer', '_wpnonce' ), $request_uri ) );
	} else {
		$location = '';
	}	
}

if ( $location ) {
	if ( $pagenum > 1 ) {
		$location = add_query_arg( 'paged', $pagenum, $location ); // $pagenum takes care of $total_pages.
	}

	/**
	 * Filters the taxonomy redirect destination URL.
	 *
	 * @since 4.6.0
	 *
	 * @param string      $location The destination URL.
	 * @param WP_Taxonomy $tax      The taxonomy object.
	 */
	wp_redirect( apply_filters( 'redirect_term_location', $location, $tax ) );
	exit;
}

$wp_list_table->prepare_items();
$total_pages = $wp_list_table->get_pagination_arg( 'total_pages' );

if ( $pagenum > $total_pages && $total_pages > 0 ) {
	wp_redirect( add_query_arg( 'paged', $total_pages ) );
	exit;
}

wp_enqueue_script( 'admin-tags' );
if ( current_user_can( $tax->cap->edit_terms ) ) {
	wp_enqueue_script( 'inline-edit-tax' );
}

if ( 'category' === $taxonomy || 'link_category' === $taxonomy || 'post_tag' === $taxonomy ) {
	$help = '';
	if ( 'category' === $taxonomy ) {
		$help = '<p>' . sprintf(
			/* translators: %s: URL to Writing Settings screen. */
			__( 'You can use categories to define sections of your site and group related posts. The default category is &#8220;Uncategorized&#8221; until you change it in your <a href="%s">writing settings</a>.', 'tapgoods-wp' ),
			'options-writing.php'
		) . '</p>';
	} elseif ( 'link_category' === $taxonomy ) {
		$help = '<p>' . __( 'You can create groups of links by using Link Categories. Link Category names must be unique and Link Categories are separate from the categories you use for posts.', 'tapgoods-wp' ) . '</p>';
	} else {
		$help = '<p>' . __( 'You can assign keywords to your posts using <strong>tags</strong>. Unlike categories, tags have no hierarchy, meaning there is no relationship from one tag to another.', 'tapgoods-wp' ) . '</p>';
	}

	if ( 'link_category' === $taxonomy ) {
		$help .= '<p>' . __( 'You can delete Link Categories in the Bulk Action pull-down, but that action does not delete the links within the category. Instead, it moves them to the default Link Category.', 'tapgoods-wp' ) . '</p>';
	} else {
		$help .= '<p>' . __( 'What&#8217;s the difference between categories and tags? Normally, tags are ad-hoc keywords that identify important information in your post (names, subjects, etc) that may or may not recur in other posts, while categories are pre-determined sections. If you think of your site like a book, the categories are like the Table of Contents and the tags are like the terms in the index.', 'tapgoods-wp' ) . '</p>';
	}

	get_current_screen()->add_help_tab(
		array(
			'id'      => 'overview',
			'title'   => __( 'Overview', 'tapgoods-wp' ),
			'content' => $help,
		)
	);

	if ( 'category' === $taxonomy || 'post_tag' === $taxonomy ) {
		if ( 'category' === $taxonomy ) {
			$help = '<p>' . __( 'When adding a new category on this screen, you&#8217;ll fill in the following fields:', 'tapgoods-wp' ) . '</p>';
		} else {
			$help = '<p>' . __( 'When adding a new tag on this screen, you&#8217;ll fill in the following fields:', 'tapgoods-wp' ) . '</p>';
		}

		$help .= '<ul>' .
		'<li>' . __( '<strong>Name</strong> &mdash; The name is how it appears on your site.', 'tapgoods-wp' ) . '</li>';

		$help .= '<li>' . __( '<strong>Slug</strong> &mdash; The &#8220;slug&#8221; is the URL-friendly version of the name. It is usually all lowercase and contains only letters, numbers, and hyphens.', 'tapgoods-wp' ) . '</li>';

		if ( 'category' === $taxonomy ) {
			$help .= '<li>' . __( '<strong>Parent</strong> &mdash; Categories, unlike tags, can have a hierarchy. You might have a Jazz category, and under that have child categories for Bebop and Big Band. Totally optional. To create a subcategory, just choose another category from the Parent dropdown.', 'tapgoods-wp' ) . '</li>';
		}

		$help .= '<li>' . __( '<strong>Description</strong> &mdash; The description is not prominent by default; however, some themes may display it.', 'tapgoods-wp' ) . '</li>' .
		'</ul>' .
		'<p>' . __( 'You can change the display of this screen using the Screen Options tab to set how many items are displayed per screen and to display/hide columns in the table.', 'tapgoods-wp' ) . '</p>';

		get_current_screen()->add_help_tab(
			array(
				'id'      => 'adding-terms',
				'title'   => 'category' === $taxonomy ? __( 'Adding Categories', 'tapgoods-wp' ) : __( 'Adding Tags', 'tapgoods-wp' ),
				'content' => $help,
			)
		);
	}

	$help = '<p><strong>' . __( 'For more information:', 'tapgoods-wp' ) . '</strong></p>';

	if ( 'category' === $taxonomy ) {
		$help .= '<p>' . __( '<a href="https://wordpress.org/documentation/article/posts-categories-screen/">Documentation on Categories</a>', 'tapgoods-wp' ) . '</p>';
	} elseif ( 'link_category' === $taxonomy ) {
		$help .= '<p>' . __( '<a href="https://codex.wordpress.org/Links_Link_Categories_Screen">Documentation on Link Categories</a>', 'tapgoods-wp' ) . '</p>';
	} else {
		$help .= '<p>' . __( '<a href="https://wordpress.org/documentation/article/posts-tags-screen/">Documentation on Tags</a>', 'tapgoods-wp' ) . '</p>';
	}

	$help .= '<p>' . __( '<a href="https://wordpress.org/support/forums/">Support forums</a>', 'tapgoods-wp' ) . '</p>';

	get_current_screen()->set_help_sidebar( $help );

	unset( $help );
}


// Also used by the Edit Tag form.

$class = ( isset( $_REQUEST['error'] ) ) ? 'error' : 'updated';

if ( is_plugin_active( 'wpcat2tag-importer/wpcat2tag-importer.php' ) ) {
	$import_link = admin_url( 'admin.php?import=wpcat2tag' );
} else {
	$import_link = admin_url( 'import.php' );
}

?>

<div class="wrap nosubsub">
<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>

<?php
if ( isset( $_REQUEST['s'] ) ) {
    $search_query = sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ); // Properly sanitize input

    if ( strlen( trim( $search_query ) ) ) {
        echo '<span class="subtitle">';
        printf(
            /* translators: %s: Search query. */
            esc_html__( 'Search results for: %s', 'tapgoods-wp' ),
            '<strong>' . esc_html( $search_query ) . '</strong>'
        );
        echo '</span>';
    }
}
?>

<hr class="wp-header-end">

<?php
if ( $message ) :
	wp_admin_notice(
		$message,
		array(
			'id'                 => 'message',
			'additional_classes' => array( $class ),
			'dismissible'        => true,
		)
	);
	if ( isset( $_SERVER['REQUEST_URI'] ) ) {
		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ); // Sanitize input
	
		$_SERVER['REQUEST_URI'] = esc_url_raw( remove_query_arg( array( 'message', 'error' ), $request_uri ) );
	}
	
endif;
?>
<div id="ajax-response"></div>
<?php

// Fires before the search form
do_action( "{$taxonomy}_pre_search_form", $taxonomy );

?>
<form class="search-form wp-clearfix" method="get">
<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy ); ?>" />
<input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>" />

<?php $wp_list_table->search_box( $tax->labels->search_items, 'tag' ); ?>

</form>

<?php
$can_edit_terms = current_user_can( $tax->cap->edit_terms );

// if ( $can_edit_terms ) {
if ( true ) {
	?>
<div id="col-container" class="wp-clearfix">

<div id="col-left">
<div class="col-wrap">

	<?php
	/**
	 * Fires before the Add Term form for all taxonomies.
	 *
	 * The dynamic portion of the hook name, `$taxonomy`, refers to the taxonomy slug.
	 *
	 * Possible hook names include:
	 *
	 *  - `category_pre_add_form`
	 *  - `post_tag_pre_add_form`
	 *
	 * @since 3.0.0
	 *
	 * @param string $taxonomy The taxonomy slug.
	 */
	do_action( "{$taxonomy}_pre_add_form", $taxonomy );

	?>
<p>Use TapGoods to edit, add, and remove categories and tags</p>
	<?php if ( false ) : ?>
<div class="form-wrap">
<h2><?php echo esc_html( $tax->labels->add_new_item ); ?></h2>
<form id="addtag" method="post" action="edit-tags.php" class="validate"
	<?php
	/**
	 * Fires inside the Add Tag form tag.
	 *
	 * The dynamic portion of the hook name, `$taxonomy`, refers to the taxonomy slug.
	 *
	 * Possible hook names include:
	 *
	 *  - `category_term_new_form_tag`
	 *  - `post_tag_term_new_form_tag`
	 *
	 * @since 3.7.0
	 */
	do_action( "{$taxonomy}_term_new_form_tag" );
	?>
>
<input type="hidden" name="action" value="add-tag" />
<input type="hidden" name="screen" value="<?php echo esc_attr( $current_screen->id ); ?>" />
<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy ); ?>" />
<input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>" />
	<?php wp_nonce_field( 'add-tag', '_wpnonce_add-tag' ); ?>

<div class="form-field form-required term-name-wrap">
	<label for="tag-name"><?php esc_html_ex( 'Name', 'term name', 'tapgoods-wp' ); ?></label>
	<input name="tag-name" id="tag-name" type="text" value="" size="40" aria-required="true" aria-describedby="name-description" />
	<p id="name-description"><?php echo esc_html( $tax->labels->name_field_description ); ?></p>
</div>
<div class="form-field term-slug-wrap">
	<label for="tag-slug"><?php esc_html_e( 'Slug', 'tapgoods-wp' ); ?></label>
	<input name="slug" id="tag-slug" type="text" value="" size="40" aria-describedby="slug-description" />
	<p id="slug-description"><?php echo esc_html( $tax->labels->slug_field_description ); ?></p>
</div>
	<?php if ( 'tg_tags' === $taxonomy ) : ?>
<div class="form-field term-parent-wrap">
	<label for="parent"><?php echo esc_html( $tax->labels->parent_item ); ?></label>
		<?php
		$dropdown_args = array(
			'hide_empty'       => 0,
			'hide_if_empty'    => false,
			'taxonomy'         => $taxonomy,
			'name'             => 'parent',
			'orderby'          => 'name',
			'hierarchical'     => true,
			'show_option_none' => __( 'None', 'tapgoods-wp' ),
		);

		/**
		 * Filters the taxonomy parent drop-down on the Edit Term page.
		 *
		 * @since 3.7.0
		 * @since 4.2.0 Added `$context` parameter.
		 *
		 * @param array  $dropdown_args {
		 *     An array of taxonomy parent drop-down arguments.
		 *
		 *     @type int|bool $hide_empty       Whether to hide terms not attached to any posts. Default 0.
		 *     @type bool     $hide_if_empty    Whether to hide the drop-down if no terms exist. Default false.
		 *     @type string   $taxonomy         The taxonomy slug.
		 *     @type string   $name             Value of the name attribute to use for the drop-down select element.
		 *                                      Default 'parent'.
		 *     @type string   $orderby          The field to order by. Default 'name'.
		 *     @type bool     $hierarchical     Whether the taxonomy is hierarchical. Default true.
		 *     @type string   $show_option_none Label to display if there are no terms. Default 'None'.
		 * }
		 * @param string $taxonomy The taxonomy slug.
		 * @param string $context  Filter context. Accepts 'new' or 'edit'.
		 */
		$dropdown_args = apply_filters( 'taxonomy_parent_dropdown_args', $dropdown_args, $taxonomy, 'new' );

		$dropdown_args['aria_describedby'] = 'parent-description';

		wp_dropdown_categories( $dropdown_args );
		?>
		<?php if ( 'category' === $taxonomy ) : ?>
		<p id="parent-description"><?php esc_html_e( 'Categories, unlike tags, can have a hierarchy. You might have a Jazz category, and under that have children categories for Bebop and Big Band. Totally optional.', 'tapgoods-wp' ); ?></p>
	<?php else : ?>
		<p id="parent-description"><?php echo esc_html( $tax->labels->parent_field_description ); ?></p>
	<?php endif; ?>
</div>
	<?php endif; // is_taxonomy_hierarchical() ?>
<div class="form-field term-description-wrap">
	<label for="tag-description"><?php esc_html_e( 'Description', 'tapgoods-wp' ); ?></label>
	<textarea name="description" id="tag-description" rows="5" cols="40" aria-describedby="description-description"></textarea>
	<p id="description-description"><?php echo esc_html( $tax->labels->desc_field_description ); ?></p>
</div>

	<?php
	if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
		/**
		 * Fires after the Add Tag form fields for non-hierarchical taxonomies.
		 *
		 * @since 3.0.0
		 *
		 * @param string $taxonomy The taxonomy slug.
		 */
		do_action( 'add_tag_form_fields', $taxonomy );
	}

	/**
	 * Fires after the Add Term form fields.
	 *
	 * The dynamic portion of the hook name, `$taxonomy`, refers to the taxonomy slug.
	 *
	 * Possible hook names include:
	 *
	 *  - `category_add_form_fields`
	 *  - `post_tag_add_form_fields`
	 *
	 * @since 3.0.0
	 *
	 * @param string $taxonomy The taxonomy slug.
	 */
	do_action( "{$taxonomy}_add_form_fields", $taxonomy );
	?>
	<p class="submit">
		<?php submit_button( $tax->labels->add_new_item, 'primary', 'submit', false ); ?>
		<span class="spinner"></span>
	</p>
	<?php
	/**
	 * Fires at the end of the Add Term form for all taxonomies.
	 *
	 * The dynamic portion of the hook name, `$taxonomy`, refers to the taxonomy slug.
	 *
	 * Possible hook names include:
	 *
	 *  - `category_add_form`
	 *  - `post_tag_add_form`
	 *
	 * @since 3.0.0
	 *
	 * @param string $taxonomy The taxonomy slug.
	 */
	do_action( "{$taxonomy}_add_form", $taxonomy );
	?>
</form></div>
<?php endif; ?>
</div>
</div><!-- /col-left -->

<div id="col-right">
<div class="col-wrap">
<?php } ?>

<?php $wp_list_table->views(); ?>

<form id="posts-filter" method="post">
<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy ); ?>" />
<input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>" />

<?php $wp_list_table->display(); ?>

</form>

<?php if ( 'category' === $taxonomy ) : ?>
<div class="form-wrap edit-term-notes">
<p>
	<?php
	printf(
		wp_kses(
			'Deleting a category does not delete the posts in that category. Instead, posts that were only assigned to the deleted category are set to the default category %s. The default category cannot be deleted.',
			'tapgoods-wp'
		),
		'<strong>' . esc_html( apply_filters( 'the_category', get_cat_name( get_option( 'default_category' ) ), '', '' ) ) . '</strong>'
	);
	?>
</p>
	<?php if ( current_user_can( 'import' ) ) : ?>
	<p>
		<?php
		printf(
			wp_kses(
				/* translators: %s: URL to Categories to Tags Converter tool. */
				__( 'Categories can be selectively converted to tags using the <a href="%s">category to tag converter</a>.', 'tapgoods-wp' ),
				array( 'a' => array( 'href' => array() ) ) // Permitir solo <a> con atributo href
			),
			esc_url( $import_link )
		);
		?>
	</p>
	<?php endif; ?>
</div>
<?php elseif ( 'post_tag' === $taxonomy && current_user_can( 'import' ) ) : ?>
<div class="form-wrap edit-term-notes">
<p>
	<?php
	printf(
		wp_kses(
			/* translators: %s: URL to Tag to Category Converter tool. */
			__( 'Tags can be selectively converted to categories using the <a href="%s">tag to category converter</a>.', 'tapgoods-wp' ),
			array( 'a' => array( 'href' => array() ) ) // Permitir solo <a> con atributo href
		),
		esc_url( $import_link )
	);
	?>
	</p>
</div>
	<?php
endif;

/**
 * Fires after the taxonomy list table.
 *
 * The dynamic portion of the hook name, `$taxonomy`, refers to the taxonomy slug.
 *
 * Possible hook names include:
 *
 *  - `after-category-table`
 *  - `after-post_tag-table`
 *
 * @since 3.0.0
 *
 * @param string $taxonomy The taxonomy name.
 */
do_action( "after-{$taxonomy}-table", $taxonomy );  // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

if ( $can_edit_terms ) {
	?>
</div>
</div><!-- /col-right -->

</div><!-- /col-container -->
<?php } ?>

</div><!-- /wrap -->

<?php if ( ! wp_is_mobile() ) : ?>
<script type="text/javascript">
try{document.forms.addtag['tag-name'].focus();}catch(e){}
</script>
	<?php
endif;

$wp_list_table->inline_edit();
