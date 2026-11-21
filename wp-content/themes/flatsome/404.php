<?php
/**
 * The template for displaying 404 pages (not found).
 *
 * @package          Flatsome\Templates
 * @flatsome-version 3.16.0
 */

get_header(); ?>
<?php do_action( 'flatsome_before_404' ); ?>

<style>
	.page-404 {
		text-align: center;
		padding: 60px 20px;
		background-color: #f4f9fc;
		color: #5c6974;
		border-radius: 10px;
		box-shadow: 0 10px 30px rgba(0,0,0,.1);
		margin: 50px auto;
		max-width: 900px;
	}

	.page-404 h1 {
		font-size: 8rem;
		font-weight: bold;
		color: #027fc0;
		margin-bottom: 10px;
	}

	.page-404 h2 {
		font-size: 2rem;
		margin-bottom: 15px;
		color: #3b424a;
	}

	.page-404 p {
		font-size: 1rem;
		margin-bottom: 25px;
		color: #657786;
	}

	.page-404 .search-form input[type="search"] {
		border-radius: 30px;
		padding: 12px 20px;
		border: 1px solid #ccdcea;
		width: 100%;
		max-width: 500px;
		box-shadow: none;
	}

	.page-404 .search-form input[type="submit"] {
		display: none;
	}

	.page-404 .button-group {
		margin-top: 20px;
		
	}

	.page-404 .button-group a {
		display: inline-block;
		padding: 10px 25px;
		margin: 5px;
		border-radius: 30px;
		background-color: #3b424a;
		color: #fff;
		text-decoration: none;
		font-size: 1rem;
		transition: background-color 0.3s ease;
	}

	.page-404 .button-group a:hover {
		background-color: #2c3237;
	}
</style>

<div id="primary" class="content-area">
	<main id="main" class="site-main container pt" role="main">
		<section class="page-404">
			<h1>404</h1>
			<h2>Rất tiếc! Trang bạn tìm không tồn tại.</h2>
			<p>Trang bạn đang tìm kiếm không tồn tại hoặc đã bị chuyển đi nơi khác. Bạn có thể thử tìm kiếm nội dung hoặc quay trở lại trang chủ.</p>
			<?php get_search_form(); ?>
			<div class="button-group">
				<a href="<?php echo home_url(); ?>">Trở về Trang chủ</a>
				<a href="<?php echo home_url('/shop'); ?>">Xem Sản phẩm</a>
			</div>
		</section>
	</main>
</div>

<?php do_action( 'flatsome_after_404' ); ?>
<?php get_footer(); ?>
