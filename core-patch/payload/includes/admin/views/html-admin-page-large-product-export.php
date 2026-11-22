<?php
/**
 * Admin view: durable, opt-in large product CSV export.
 *
 * @package WooCommerce\Admin\Export
 */

defined( 'ABSPATH' ) || exit;

$is_ready = ! $error && defined( 'WC_LARGE_PRODUCT_EXPORT' ) && true === WC_LARGE_PRODUCT_EXPORT
	&& defined( 'WC_LARGE_EXPORT_DIR' ) && is_string( WC_LARGE_EXPORT_DIR );
?>
<div class="wrap woocommerce">
	<h1><?php esc_html_e( 'Large Product Export', 'woocommerce' ); ?></h1>
	<p><?php esc_html_e( 'Jobs are stored durably and processed by Action Scheduler or a scheduled worker. Closing this page does not cancel an export.', 'woocommerce' ); ?></p>

	<?php if ( ! $is_ready ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'The background export is disabled. Enable WC_LARGE_PRODUCT_EXPORT and configure WC_LARGE_EXPORT_DIR outside the web root in wp-config.php. The standard WooCommerce exporter remains available.', 'woocommerce' ); ?></p></div>
	<?php endif; ?>
	<?php if ( $error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
	<?php endif; ?>

	<?php if ( count( $selected_ids ) > 2000 ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'The background exporter accepts at most 2,000 explicitly selected product IDs per job. Clear the selection or use filters.', 'woocommerce' ); ?></p></div>
	<?php endif; ?>

	<div id="wc-large-export-notice" class="notice inline" hidden><p></p></div>
	<div class="postbox" style="max-width:900px;padding:16px 20px">
		<form id="wc-large-export-form">
			<?php if ( $selected_ids ) : ?>
				<input type="hidden" name="product_ids" value="<?php echo esc_attr( implode( ',', $selected_ids ) ); ?>">
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wc-large-export-columns"><?php esc_html_e( 'Columns', 'woocommerce' ); ?></label></th>
					<td>
						<select id="wc-large-export-columns" name="columns[]" class="wc-enhanced-select" multiple style="width:100%" data-placeholder="<?php esc_attr_e( 'Export all available columns', 'woocommerce' ); ?>">
							<?php foreach ( $exporter->get_default_column_names() as $column_id => $column_name ) : ?>
								<option value="<?php echo esc_attr( $column_id ); ?>"><?php echo esc_html( $column_name ); ?></option>
							<?php endforeach; ?>
							<option value="downloads"><?php esc_html_e( 'Downloads', 'woocommerce' ); ?></option>
							<option value="attributes"><?php esc_html_e( 'Attributes', 'woocommerce' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Leave empty to export all columns, including registered custom columns.', 'woocommerce' ); ?></p>
					</td>
				</tr>
				<?php if ( ! $selected_ids ) : ?>
					<tr>
						<th scope="row"><label for="wc-large-export-types"><?php esc_html_e( 'Product types', 'woocommerce' ); ?></label></th>
						<td>
							<select id="wc-large-export-types" name="types[]" class="wc-enhanced-select" multiple style="width:100%" data-placeholder="<?php esc_attr_e( 'Export all product types', 'woocommerce' ); ?>">
								<?php foreach ( $product_types as $type => $label ) : ?>
									<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wc-large-export-categories"><?php esc_html_e( 'Categories', 'woocommerce' ); ?></label></th>
						<td>
							<select id="wc-large-export-categories" name="categories[]" class="wc-enhanced-select" multiple style="width:100%" data-placeholder="<?php esc_attr_e( 'Export all categories', 'woocommerce' ); ?>">
								<?php foreach ( $categories as $category ) : ?>
									<option value="<?php echo esc_attr( $category->slug ); ?>"><?php echo esc_html( $category->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><label for="wc-large-export-mode"><?php esc_html_e( 'Query mode', 'woocommerce' ); ?></label></th>
					<td>
						<select id="wc-large-export-mode" name="mode">
							<option value="compatibility"><?php esc_html_e( 'Compatibility paging', 'woocommerce' ); ?></option>
							<option value="keyset"><?php esc_html_e( 'Optimized ID cursor (opt-in; validate extension compatibility)', 'woocommerce' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wc-large-export-format"><?php esc_html_e( 'File format', 'woocommerce' ); ?></label></th>
					<td><select id="wc-large-export-format" name="file_format">
						<option value="csv"><?php esc_html_e( 'CSV', 'woocommerce' ); ?></option>
						<?php if ( method_exists( HashContext::class, '__serialize' ) ) : ?>
							<option value="zip"><?php esc_html_e( 'CSV in ZIP64 (uncompressed, resumable)', 'woocommerce' ); ?></option>
						<?php endif; ?>
					</select></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'CSV options', 'woocommerce' ); ?></th>
					<td>
						<label><input type="checkbox" name="excel_view" value="1" checked> <?php esc_html_e( 'Giới hạn ô Excel: giữ nguyên HTML và xuống dòng, chỉ cắt phần vượt 32.767 ký tự/ô, UTF-8 BOM.', 'woocommerce' ); ?></label>
						<p class="description"><?php esc_html_e( 'Bỏ chọn nếu cần xuất không giới hạn độ dài để sao lưu hoặc nhập lại. Nội dung nhiều dòng được đặt trong cùng một ô CSV. CSV không lưu độ rộng cột.', 'woocommerce' ); ?></p>
						<label><input type="checkbox" name="meta" value="1"> <?php esc_html_e( 'Export custom metadata', 'woocommerce' ); ?></label><br>
						<label><input type="checkbox" name="spreadsheet" value="1" checked> <?php esc_html_e( 'Protect spreadsheet formula cells', 'woocommerce' ); ?></label><br>
						<label><input type="checkbox" name="bom" value="1" checked> <?php esc_html_e( 'Include UTF-8 BOM', 'woocommerce' ); ?></label>
					</td>
				</tr>
			</table>
			<p><button type="submit" class="button button-primary" <?php disabled( ! $is_ready || count( $selected_ids ) > 2000 ); ?>><?php esc_html_e( 'Start background export', 'woocommerce' ); ?></button></p>
		</form>
	</div>

	<h2><?php esc_html_e( 'Your export jobs', 'woocommerce' ); ?></h2>
	<p><?php esc_html_e( 'Progress and ETA are estimates until the worker has processed all selected records. A job is complete only after its CSV has been verified.', 'woocommerce' ); ?></p>
	<table class="widefat striped" id="wc-large-export-jobs">
		<thead><tr>
			<th><?php esc_html_e( 'Job', 'woocommerce' ); ?></th>
			<th><?php esc_html_e( 'Status', 'woocommerce' ); ?></th>
			<th><?php esc_html_e( 'Progress', 'woocommerce' ); ?></th>
			<th><?php esc_html_e( 'Throughput', 'woocommerce' ); ?></th>
			<th><?php esc_html_e( 'Elapsed / ETA', 'woocommerce' ); ?></th>
			<th><?php esc_html_e( 'Verified output / staging', 'woocommerce' ); ?></th>
			<th><?php esc_html_e( 'Actions', 'woocommerce' ); ?></th>
		</tr></thead>
		<tbody><tr><td colspan="7"><?php esc_html_e( 'Loading saved jobs…', 'woocommerce' ); ?></td></tr></tbody>
	</table>
</div>
