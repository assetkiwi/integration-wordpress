<?php
/**
 * Asset browser HTML — rendered server-side and returned via AJAX.
 *
 * Available variables:
 *   $assets      array   List of asset objects from the API.
 *   $collections array   For filter dropdown.
 *   $tags        array   For filter dropdown.
 *   $pager       array   current_page, last_page, total, per_page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$current_page = (int) ( $pager['current_page'] ?? 1 );
$last_page    = (int) ( $pager['last_page'] ?? 1 );
$total        = (int) ( $pager['total'] ?? 0 );

$filter_search    = sanitize_text_field( $_GET['search'] ?? '' );
$filter_collection = sanitize_text_field( $_GET['collection'] ?? '' );
$filter_tag       = sanitize_text_field( $_GET['tag'] ?? '' );
$filter_mime      = sanitize_text_field( $_GET['mime_type'] ?? '' );
?>
<div class="assetkiwi-browser-view">

	<form class="assetkiwi-filters assetkiwi-filter-form" method="get">
		<label>
			<?php esc_html_e( 'Search', 'assetkiwi-connect' ); ?>
			<input type="text" name="search" value="<?php echo esc_attr( $filter_search ); ?>" placeholder="<?php esc_attr_e( 'Search assets…', 'assetkiwi-connect' ); ?>" />
		</label>

		<?php if ( ! empty( $collections ) ) : ?>
			<label>
				<?php esc_html_e( 'Collection', 'assetkiwi-connect' ); ?>
				<select name="collection">
					<option value=""><?php esc_html_e( 'All collections', 'assetkiwi-connect' ); ?></option>
					<?php foreach ( $collections as $collection ) :
						$col_id   = esc_attr( $collection['id'] ?? $collection['uuid'] ?? '' );
						$col_name = esc_html( $collection['name'] ?? $col_id );
					?>
						<option value="<?php echo $col_id; ?>" <?php selected( $filter_collection, $col_id ); ?>>
							<?php echo $col_name; ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
		<?php endif; ?>

		<?php if ( ! empty( $tags ) ) : ?>
			<label>
				<?php esc_html_e( 'Tag', 'assetkiwi-connect' ); ?>
				<select name="tag">
					<option value=""><?php esc_html_e( 'All tags', 'assetkiwi-connect' ); ?></option>
					<?php foreach ( $tags as $tag ) :
						$tag_id   = esc_attr( $tag['id'] ?? $tag['slug'] ?? '' );
						$tag_name = esc_html( $tag['name'] ?? $tag_id );
					?>
						<option value="<?php echo $tag_id; ?>" <?php selected( $filter_tag, $tag_id ); ?>>
							<?php echo $tag_name; ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
		<?php endif; ?>

		<label>
			<?php esc_html_e( 'Type', 'assetkiwi-connect' ); ?>
			<select name="mime_type">
				<option value=""><?php esc_html_e( 'All types', 'assetkiwi-connect' ); ?></option>
				<option value="image" <?php selected( $filter_mime, 'image' ); ?>><?php esc_html_e( 'Images', 'assetkiwi-connect' ); ?></option>
				<option value="video" <?php selected( $filter_mime, 'video' ); ?>><?php esc_html_e( 'Video', 'assetkiwi-connect' ); ?></option>
				<option value="application/pdf" <?php selected( $filter_mime, 'application/pdf' ); ?>><?php esc_html_e( 'PDF', 'assetkiwi-connect' ); ?></option>
			</select>
		</label>

		<div class="assetkiwi-filters__actions">
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'assetkiwi-connect' ); ?></button>
		</div>
	</form>

	<?php if ( empty( $assets ) ) : ?>
		<p class="assetkiwi-empty"><?php esc_html_e( 'No assets found.', 'assetkiwi-connect' ); ?></p>
	<?php else : ?>

		<div class="assetkiwi-asset-grid">
			<?php foreach ( $assets as $asset ) :
				$uuid      = esc_attr( $asset['uuid'] ?? '' );
				$name      = esc_html( $asset['original_name'] ?? $asset['filename'] ?? $uuid );
				$mime      = esc_html( $asset['mime_type'] ?? '' );
				$thumb_url = '';

				// Prefer a "thumb" or "thumbnail" variant, fall back to asset URL.
				foreach ( $asset['variants'] ?? array() as $variant ) {
					if ( in_array( $variant['variant_name'] ?? '', array( 'thumb', 'thumbnail', 'small' ), true ) ) {
						$thumb_url = $variant['url'] ?? '';
						break;
					}
				}
				if ( ! $thumb_url ) {
					$thumb_url = $asset['url'] ?? '';
				}
				$thumb_url = esc_url( $thumb_url );
			?>
				<div class="assetkiwi-asset-card" data-uuid="<?php echo $uuid; ?>" tabindex="0" role="option" aria-label="<?php echo $name; ?>">
					<?php if ( $thumb_url && 0 === strpos( $asset['mime_type'] ?? '', 'image/' ) ) : ?>
						<img class="assetkiwi-asset-card__thumbnail" src="<?php echo $thumb_url; ?>" alt="<?php echo esc_attr( $asset['alt_text'] ?? $name ); ?>" loading="lazy" />
					<?php else : ?>
						<div class="assetkiwi-asset-card__thumbnail--placeholder"><?php echo esc_html( strtoupper( pathinfo( $asset['filename'] ?? '', PATHINFO_EXTENSION ) ) ?: '?' ); ?></div>
					<?php endif; ?>

					<div class="assetkiwi-asset-card__info">
						<div class="assetkiwi-asset-card__name" title="<?php echo $name; ?>"><?php echo $name; ?></div>
						<div class="assetkiwi-asset-card__meta"><?php echo $mime; ?></div>
					</div>

					<button type="button" class="assetkiwi-asset-card__btn"><?php esc_html_e( 'Select', 'assetkiwi-connect' ); ?></button>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ( $last_page > 1 ) : ?>
			<div class="assetkiwi-pager">
				<?php if ( $current_page > 1 ) : ?>
					<button type="button" class="button assetkiwi-pager__btn" data-page="<?php echo $current_page - 1; ?>">&laquo; <?php esc_html_e( 'Prev', 'assetkiwi-connect' ); ?></button>
				<?php endif; ?>

				<span class="assetkiwi-pager__info">
					<?php printf(
						/* translators: 1: current page, 2: total pages */
						esc_html__( 'Page %1$d of %2$d', 'assetkiwi-connect' ),
						$current_page,
						$last_page
					); ?>
				</span>

				<?php if ( $current_page < $last_page ) : ?>
					<button type="button" class="button assetkiwi-pager__btn" data-page="<?php echo $current_page + 1; ?>"><?php esc_html_e( 'Next', 'assetkiwi-connect' ); ?> &raquo;</button>
				<?php endif; ?>
			</div>
		<?php endif; ?>

	<?php endif; ?>

</div>
