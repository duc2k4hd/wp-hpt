<?php
/**
 * Post-entry title.
 *
 * @package          Flatsome\Templates
 * @flatsome-version 3.16.0
 */

if ( is_single() ) :
	if ( get_theme_mod( 'blog_single_header_category', 1 ) ) :
		echo '<h6 class="entry-category is-xsmall *:tw-text-[#171717] tw-text-[32px] tw-font-bold">';
		echo get_the_category_list( __( ', ', 'flatsome' ) );
		echo '</h6>';
	endif;
else :
	echo '<h6 class="entry-category is-xsmall *:tw-text-[#171717] tw-text-[32px] tw-font-bold">';
	echo get_the_category_list( __( ', ', 'flatsome' ) );
	echo '</h6>';
endif;

if ( is_single() ) :
	if ( get_theme_mod( 'blog_single_header_title', 1 ) ) :
		echo '<h1 class="entry-title">' . get_the_title() . '</h1>';
		//echo '<div class="entry-divider is-divider small"></div>';
	endif;
else :
	echo '<h2 class="entry-title"><a href="' . get_the_permalink() . '" rel="bookmark" class="plain">' . get_the_title() . '</a></h2>';
	//echo '<div class="entry-divider is-divider small"></div>';
endif;
?>

<?php
$single_post = is_singular( 'post' );
if ( $single_post && get_theme_mod( 'blog_single_header_meta', 1 ) ) : ?>
	<div class="entry-meta uppercase is-xsmall tw-text-[#171717] tw-text-base tw-font-[500]">
		<?php //flatsome_posted_on(); ?>
		<span class="author tw-normal-case">Đăng bởi: <?php the_author(); ?></span> - 
        <span class="date tw-normal-case"><?php echo get_the_date('d/m/Y'); ?></span>
	</div>
<?php elseif ( ! $single_post && 'post' == get_post_type() ) : ?>
	<div class="entry-meta uppercase is-xsmall tw-text-[#171717] tw-text-base tw-font-[500]">
		<?php //flatsome_posted_on(); ?>
		<span class="author tw-normal-case">Đăng bởi: <?php the_author(); ?></span> - 
        <span class="date tw-normal-case"><?php echo get_the_date('d/m/Y'); ?></span>
	</div>
<?php endif; ?>
