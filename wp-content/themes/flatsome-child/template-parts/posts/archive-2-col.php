<?php
/**
 * Posts archive 2 column.
 *
 * @package          Flatsome\Templates
 * @flatsome-version 3.18.0
 */

if ( have_posts() ) : ?>
	<div id="post-list" class="row "> <!-- Thêm lớp 'row' để tạo lưới -->
		<?php while ( have_posts() ) : the_post(); ?>
			<div class="post-item large-6 col group"> <!-- Thay đổi lớp để chia cột -->
				<!-- Hiển thị hình ảnh đại diện -->
				<div class="post-thumbnail">
					<a href="<?php the_permalink(); ?>">
					<?php
						if ( has_post_thumbnail() ) {
							// Thêm class vào ảnh đại diện
							echo wp_get_attachment_image( get_post_thumbnail_id(), 'medium', false, array('class' => 'tw-w-full group-hover:tw-scale-110') );
						} else {
							echo '<img src="' . esc_url(get_template_directory_uri() . '/images/default-thumbnail.jpg') . '" alt="Default Thumbnail" class="your-custom-class" />'; // Hình ảnh mặc định nếu không có hình đại diện
						}
					?>
					</a>
				</div>
				<div>
					<h2 class="post-title tw-text-[#171717] tw-text-[18px] tw-font-bold tw-mt-4">
						<a href="<?php the_permalink(); ?>" class="tw-text-[#171717] hover:tw-underline">
							<?php the_title(); ?>
						</a>
					</h2>
					<div class="post-excerpt"><?php //the_excerpt(); ?></div>
					<a href="<?php the_permalink(); ?>" class="read-more-button tw-flex tw-items-center tw-gap-x-2 tw-text-[#0284C7] tw-font-bold tw-transion-all">Xem thêm <span class="dashicons dashicons-arrow-right-alt"></span></a>
				</div>
			</div>
		<?php endwhile; ?>
		<div class="tw-px-[15px]">
			<?php flatsome_posts_pagination(); ?>	
		</div>
	</div>
<?php else : ?>

	<?php get_template_part( 'template-parts/posts/content', 'none' ); ?>

<?php endif; ?>
