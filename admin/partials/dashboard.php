<?php
/**
 * Analytics dashboard partial.
 *
 * @since 1.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$raw_start  = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : '';
$raw_end    = isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( $_GET['end_date'] ) ) : '';
$start_date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_start ) ? $raw_start : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
$end_date   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_end )   ? $raw_end   : gmdate( 'Y-m-d' );

$top_articles  = Peptide_News_Analytics::get_top_articles( $start_date, $end_date, 10 );
$trends        = Peptide_News_Analytics::get_click_trends( $start_date, $end_date );
$topics        = Peptide_News_Analytics::get_popular_topics( $start_date, $end_date, 10 );
$devices       = Peptide_News_Analytics::get_device_breakdown( $start_date, $end_date );
$sources       = Peptide_News_Analytics::get_source_performance( $start_date, $end_date );

// Summary stats.
$total_clicks  = array_sum( array_column( $trends, 'total_clicks' ) );
$total_unique  = array_sum( array_column( $trends, 'total_unique' ) );
$total_articles = count( $top_articles );
?>

<div class="wrap peptide-news-dashboard">
	<h1><?php esc_html_e( 'Peptide News Analytics', 'peptide-news' ); ?></h1>

	<!-- Date range filter -->
	<form method="get" class="pn-date-filter">
		<input type="hidden" name="page" value="peptide-news-dashboard" />
		<label for="start_date"><?php esc_html_e( 'From:', 'peptide-news' ); ?></label>
		<input type="date" id="start_date" name="start_date" value="<?php echo esc_attr( $start_date ); ?>" />
		<label for="end_date"><?php esc_html_e( 'To:', 'peptide-news' ); ?></label>
		<input type="date" id="end_date" name="end_date" value="<?php echo esc_attr( $end_date ); ?>" />
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'peptide-news' ); ?></button>
		<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=peptide-news-dashboard&start_date=' . urlencode( $start_date ) . '&end_date=' . urlencode( $end_date ) . '&export=csv' ), 'peptide_news_export_csv' ) ); ?>" class="button"><?php esc_html_e( 'Export CSV', 'peptide-news' ); ?></a>
	</form>

	<!-- Summary cards -->
	<div class="pn-summary-cards">
		<div class="pn-card">
			<h3><?php echo esc_html( number_format( $total_clicks ) ); ?></h3>
			<p><?php esc_html_e( 'Total Clicks', 'peptide-news' ); ?></p>
		</div>
		<div class="pn-card">
			<h3><?php echo esc_html( number_format( $total_unique ) ); ?></h3>
			<p><?php esc_html_e( 'Unique Visitors', 'peptide-news' ); ?></p>
		</div>
		<div class="pn-card">
			<h3><?php echo esc_html( $total_articles ); ?></h3>
			<p><?php esc_html_e( 'Articles Clicked', 'peptide-news' ); ?></p>
		</div>
		<div class="pn-card">
			<h3><?php echo $total_articles > 0 ? esc_html( round( $total_clicks / $total_articles, 1 ) ) : '0'; ?></h3>
			<p><?php esc_html_e( 'Avg Clicks / Article', 'peptide-news' ); ?></p>
		</div>
	</div>

	<!-- Charts row -->
	<div class="pn-charts-row">
		<div class="pn-chart-box pn-chart-wide">
			<h2><?php esc_html_e( 'Click Trends', 'peptide-news' ); ?></h2>
			<canvas id="pn-trends-chart" height="300"></canvas>
		</div>
	</div>

	<div class="pn-charts-row">
		<div class="pn-chart-box">
			<h2><?php esc_html_e( 'Device Breakdown', 'peptide-news' ); ?></h2>
			<canvas id="pn-devices-chart" height="250"></canvas>
		</div>
		<div class="pn-chart-box">
			<h2><?php esc_html_e( 'Source Performance', 'peptide-news' ); ?></h2>
			<canvas id="pn-sources-chart" height="250"></canvas>
		</div>
	</div>

	<!-- Top articles table -->
	<div class="pn-table-section">
		<h2><?php esc_html_e( 'Top Articles', 'peptide-news' ); ?></h2>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:5%">#</th>
					<th style="width:45%"><?php esc_html_e( 'Article', 'peptide-news' ); ?></th>
					<th style="width:15%"><?php esc_html_e( 'Source', 'peptide-news' ); ?></th>
					<th style="width:15%"><?php esc_html_e( 'Categories', 'peptide-news' ); ?></th>
					<th style="width:10%"><?php esc_html_e( 'Clicks', 'peptide-news' ); ?></th>
					<th style="width:10%"><?php esc_html_e( 'Unique', 'peptide-news' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $top_articles ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No click data for this period.', 'peptide-news' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $top_articles as $i => $article ) : ?>
						<tr>
							<td><?php echo esc_html( $i + 1 ); ?></td>
							<td><a href="<?php echo esc_url( $article->source_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $article->title ); ?></a></td>
							<td><?php echo esc_html( $article->source ); ?></td>
							<td><?php echo esc_html( $article->categories ); ?></td>
							<td><strong><?php echo esc_html( number_format( (int) $article->total_clicks ) ); ?></strong></td>
							<td><?php echo esc_html( number_format( (int) $article->total_unique ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<!-- Popular topics table -->
	<div class="pn-table-section">
		<h2><?php esc_html_e( 'Popular Topics', 'peptide-news' ); ?></h2>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:5%">#</th>
					<th style="width:45%"><?php esc_html_e( 'Topic', 'peptide-news' ); ?></th>
					<th style="width:25%"><?php esc_html_e( 'Total Clicks', 'peptide-news' ); ?></th>
					<th style="width:25%"><?php esc_html_e( 'Articles', 'peptide-news' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $topics ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No topic data for this period.', 'peptide-news' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $topics as $i => $topic ) : ?>
						<tr>
							<td><?php echo esc_html( $i + 1 ); ?></td>
							<td><?php echo esc_html( $topic['topic'] ); ?></td>
							<td><?php echo esc_html( number_format( $topic['total_clicks'] ) ); ?></td>
							<td><?php echo esc_html( $topic['article_count'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>

<script>
// Pass PHP data to JS for Chart.js rendering.
var peptideNewsDashboardData = {
	trends: <?php echo wp_json_encode( $trends ); ?>,
	devices: <?php echo wp_json_encode( $devices ); ?>,
	sources: <?php echo wp_json_encode( $sources ); ?>
};
</script>
