<?php
/**
 * Articles management partial.
 *
 * @since 1.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$table   = $wpdb->prefix . 'peptide_news_articles';
$page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
$per_page = 25;
$offset  = ( $page - 1 ) * $per_page;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$articles = $wpdb->get_results( $wpdb->prepare(
	"SELECT * FROM {$table} ORDER BY published_at DESC LIMIT %d OFFSET %d",
	$per_page,
	$offset
) );

$total_pages = ceil( $total / $per_page );
?>

<div class="wrap">
	<h1><?php esc_html_e( 'Stored Articles', 'peptide-news' ); ?> <span class="title-count">(<?php echo esc_html( number_format( $total ) ); ?>)</span></h1>

	<div class="tablenav top" style="margin-bottom:8px;">
		<div class="alignleft actions bulkactions">
			<button type="button" id="pn-bulk-delete" class="button action" disabled>
				<?php esc_html_e( 'Delete Selected', 'peptide-news' ); ?>
			</button>
		</div>
	</div>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<td class="manage-column column-cb check-column" style="width:3%;">
					<input type="checkbox" id="pn-select-all" />
				</td>
				<th style="width:4%">ID</th>
				<th style="width:26%"><?php esc_html_e( 'Title', 'peptide-news' ); ?></th>
				<th style="width:10%"><?php esc_html_e( 'Source', 'peptide-news' ); ?></th>
				<th style="width:14%"><?php esc_html_e( 'AI Keywords', 'peptide-news' ); ?></th>
				<th style="width:21%"><?php esc_html_e( 'AI Summary', 'peptide-news' ); ?></th>
				<th style="width:8%"><?php esc_html_e( 'Published', 'peptide-news' ); ?></th>
				<th style="width:8%"><?php esc_html_e( 'Fetched', 'peptide-news' ); ?></th>
				<th style="width:4%"><?php esc_html_e( 'Active', 'peptide-news' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $articles ) ) : ?>
				<tr><td colspan="9"><?php esc_html_e( 'No articles found. Run a fetch first.', 'peptide-news' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $articles as $article ) : ?>
					<tr data-article-id="<?php echo esc_attr( $article->id ); ?>">
						<th class="check-column">
							<input type="checkbox" class="pn-article-cb" value="<?php echo esc_attr( $article->id ); ?>" />
						</th>
						<td><?php echo esc_html( $article->id ); ?></td>
						<td>
							<a href="<?php echo esc_url( $article->source_url ); ?>" target="_blank" rel="noopener">
								<?php echo esc_html( wp_trim_words( $article->title, 12 ) ); ?>
							</a>
							<div class="row-actions">
								<span class="delete">
									<a href="#" class="pn-delete-single" data-id="<?php echo esc_attr( $article->id ); ?>" style="color:#b32d2e;">
										<?php esc_html_e( 'Delete', 'peptide-news' ); ?>
									</a>
								</span>
							</div>
						</td>
						<td><?php echo esc_html( $article->source ); ?></td>
						<td>
							<?php if ( ! empty( $article->tags ) ) : ?>
								<span style="font-size:12px;line-height:1.4;"><?php echo esc_html( wp_trim_words( $article->tags, 10 ) ); ?></span>
							<?php else : ?>
								<span style="color:#999;font-style:italic;"><?php esc_html_e( 'Pending', 'peptide-news' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( ! empty( $article->ai_summary ) ) : ?>
								<span style="font-size:12px;line-height:1.4;" title="<?php echo esc_attr( $article->ai_summary ); ?>"><?php echo esc_html( wp_trim_words( $article->ai_summary, 15 ) ); ?></span>
							<?php else : ?>
								<span style="color:#999;font-style:italic;"><?php esc_html_e( 'Pending', 'peptide-news' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $article->published_at ) ) ); ?></td>
						<td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $article->fetched_at ) ) ); ?></td>
						<td><?php echo $article->is_active ? '<span style="color:green;">&#10003;</span>' : '<span style="color:#ccc;">&#10007;</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php
				echo paginate_links( array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $page,
					'total'   => $total_pages,
				) );
				?>
			</div>
		</div>
	<?php endif; ?>
</div>

<script>
(function() {
	var selectAll  = document.getElementById( 'pn-select-all' );
	var bulkBtn    = document.getElementById( 'pn-bulk-delete' );
	var checkboxes = document.querySelectorAll( '.pn-article-cb' );

	function getCheckedIds() {
		var ids = [];
		checkboxes.forEach( function( cb ) {
			if ( cb.checked ) {
				ids.push( cb.value );
			}
		});
		return ids;
	}

	function updateBulkBtn() {
		bulkBtn.disabled = getCheckedIds().length === 0;
	}

	if ( selectAll ) {
		selectAll.addEventListener( 'change', function() {
			checkboxes.forEach( function( cb ) {
				cb.checked = selectAll.checked;
			});
			updateBulkBtn();
		});
	}

	checkboxes.forEach( function( cb ) {
		cb.addEventListener( 'change', updateBulkBtn );
	});

	function deleteArticles( ids ) {
		var data = new FormData();
		data.append( 'action', 'peptide_news_delete_articles' );
		data.append( 'nonce', peptideNewsAdmin.admin_nonce );
		data.append( 'ids', ids.join( ',' ) );

		fetch( peptideNewsAdmin.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		})
		.then( function( r ) { return r.json(); } )
		.then( function( resp ) {
			if ( resp.success ) {
				ids.forEach( function( id ) {
					var row = document.querySelector( 'tr[data-article-id="' + id + '"]' );
					if ( row ) {
						row.remove();
					}
				});
				/* Update the count in the heading. */
				var countEl = document.querySelector( '.title-count' );
				if ( countEl ) {
					var current = parseInt( countEl.textContent.replace( /[^\d]/g, '' ), 10 ) || 0;
					countEl.textContent = '(' + Math.max( 0, current - ids.length ).toLocaleString() + ')';
				}
				updateBulkBtn();
			} else {
				alert( resp.data || 'Delete failed.' );
			}
		})
		.catch( function() {
			alert( 'Network error. Please try again.' );
		});
	}

	/* Bulk delete button. */
	if ( bulkBtn ) {
		bulkBtn.addEventListener( 'click', function() {
			var ids = getCheckedIds();
			if ( ids.length === 0 ) {
				return;
			}
			if ( ! confirm( 'Delete ' + ids.length + ' article(s)? This also removes their click analytics.' ) ) {
				return;
			}
			deleteArticles( ids );
		});
	}

	/* Per-row delete links. */
	document.querySelectorAll( '.pn-delete-single' ).forEach( function( link ) {
		link.addEventListener( 'click', function( e ) {
			e.preventDefault();
			var id = this.getAttribute( 'data-id' );
			if ( ! confirm( 'Delete article #' + id + '? This also removes its click analytics.' ) ) {
				return;
			}
			deleteArticles( [ id ] );
		});
	});
})();
</script>
