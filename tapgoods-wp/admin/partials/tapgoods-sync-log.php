<?php
/**
 * Status tab section: the sync activity log.
 *
 * Shows the tail of the log and a download link. The log file itself is not
 * reachable over HTTP by design, so this is the supported way to read it.
 *
 * Rendered from two places, because the Status tab exists twice: this partial is
 * required by admin/partials/tapgoods-status.php on page load AND by the
 * wp_ajax_load_status_tab_content handler in includes/tapgoods-core-functions.php,
 * which is what actually re-renders the tab when it is clicked. Keep it
 * self-contained so both callers behave the same.
 *
 * @package Tapgoods\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Administrators only: the log carries inventory ids and error text.
if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$tg_log        = Tapgoods_Sync_Log::get_instance();
$tg_log_lines  = $tg_log->get_recent_lines( 40 );
$tg_log_size   = $tg_log->get_size();
$tg_log_name   = $tg_log->get_file_name();
$tg_log_dl_url = wp_nonce_url(
	admin_url( 'admin-ajax.php?action=tapgrein_download_sync_log' ),
	'tapgrein_sync_log'
);

// size_format() returns false for 0 bytes.
$tg_log_size_str = ( $tg_log_size > 0 ) ? size_format( $tg_log_size, 1 ) : '0 B';
?>

<div class="position-absolute start-0 end-0" style="height: 16px; background-color: #f0f0f1;"></div>

<h2 class="mb-2 pt-4 mt-5">Sync Activity Log</h2>
<p class="mb-3">
	Every sync writes to this log, whether it was started by the button, by cron or by a
	site visit. If TapGoods support asks for it, use Download to send them the file.
</p>

<ul class="list-unstyled mb-3">
	<li class="mb-2"><strong>File:</strong> <code><?php echo esc_html( 'wp-content/uploads/tapgoods/' . $tg_log_name ); ?></code></li>
	<li class="mb-2"><strong>Size:</strong> <?php echo esc_html( $tg_log_size_str ); ?></li>
</ul>

<p class="mb-3">
	<a class="button button-primary" href="<?php echo esc_url( $tg_log_dl_url ); ?>">Download log</a>
</p>

<?php if ( empty( $tg_log_lines ) ) : ?>
	<p><em>No sync activity has been recorded yet.</em></p>
<?php else : ?>
	<p class="mb-1"><strong>Most recent entries</strong> (newest last):</p>
	<?php
	// Printed as one element: the log lines must not pick up the indentation of
	// this template, which is what splitting the tags across lines would do.
	printf(
		'<pre style="max-height: 22em; overflow: auto; background: #f6f7f7; padding: 12px; border: 1px solid #dcdcde; white-space: pre-wrap; word-break: break-word;">%s</pre>',
		esc_html( implode( "\n", $tg_log_lines ) )
	);
	?>
<?php endif; ?>
