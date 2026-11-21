<?php
/**
 * Category layout with left sidebar.
 *
 * @package          Flatsome/WooCommerce/Templates
 * @flatsome-version 3.16.0
 */

?>
<div class="row category-page-row">
    <?php if ( !is_search() ) : // Kiểm tra nếu không phải là trang tìm kiếm ?>
    <div class="col large-3 hide-for-medium <?php flatsome_sidebar_classes(); ?>">
        <?php flatsome_sticky_column_open( 'category_sticky_sidebar' ); ?>
        <div id="shop-sidebar" class="sidebar-inner col-inner">
            <?php
				  if(is_active_sidebar('shop-sidebar')) {
				  		dynamic_sidebar('shop-sidebar');
				  } else {
					  echo '<p>You need to assign Widgets to <strong>"Shop Sidebar"</strong> in <a href="'.get_site_url().'/wp-admin/widgets.php">Appearance > Widgets</a> to show anything here</p>';
				  }
				?>
        </div>
        <?php flatsome_sticky_column_close( 'category_sticky_sidebar' ); ?>
    </div>
    <?php endif; ?>

    <div class="col <?php echo is_search() ? 'large-12' : 'large-9'; ?>">
        <?php
		/**
		 * Hook: woocommerce_before_main_content.
		 *
		 * @hooked woocommerce_output_content_wrapper - 10 (outputs opening divs for the content)
		 * @hooked woocommerce_breadcrumb - 20 (FL removed)
		 * @hooked WC_Structured_Data::generate_website_data() - 30
		 */
		do_action( 'woocommerce_before_main_content' );

		?>

        <?php
		/**
		 * Hook: woocommerce_archive_description.
		 *
		 * @hooked woocommerce_taxonomy_archive_description - 10
		 * @hooked woocommerce_product_archive_description - 10
		 */
		do_action( 'woocommerce_archive_description' );
		?>
        <!--  Hienenr thị thương hiệu -->
        <?php 
		if ( !is_search() ) {
			display_brand_filter();
		}
		?>
        <?php
		if ( woocommerce_product_loop() ) {

			/**
			 * Hook: woocommerce_before_shop_loop.
			 *
			 * @hooked wc_print_notices - 10
			 * @hooked woocommerce_result_count - 20 (FL removed)
			 * @hooked woocommerce_catalog_ordering - 30 (FL removed)
			 */
			do_action( 'woocommerce_before_shop_loop' );

			woocommerce_product_loop_start();

			if ( wc_get_loop_prop( 'total' ) ) {
				while ( have_posts() ) {
					the_post();

					/**
					 * Hook: woocommerce_shop_loop.
					 *
					 * @hooked WC_Structured_Data::generate_product_data() - 10
					 */
					do_action( 'woocommerce_shop_loop' );

					wc_get_template_part( 'content', 'product' );
				}
			}
			woocommerce_product_loop_end();

			/**
			 * Hook: woocommerce_after_shop_loop.
			 *
			 * @hooked woocommerce_pagination - 10
			 */
			do_action( 'woocommerce_after_shop_loop' );
		} else {
			/**
			 * Hook: woocommerce_no_products_found.
			 *
			 * @hooked wc_no_products_found - 10
			 */	
			do_action( 'woocommerce_no_products_found' );
		}
		?>
        <div class="tw-mt-4">
            <?php
		if ( is_search() ) {
			echo do_shortcode('[block id="cac-doi-tac-cua-chung-toi"]');
		}
		?>
        </div>
        
        <hr id="term-description">
        
        <?php
			/**
			 * Hook: flatsome_products_after.
			 *
			 * @hooked flatsome_products_footer_content - 10
			 */
			//do_action( 'flatsome_products_after' );
			
			/**
			 * Hook: woocommerce_after_main_content.
			 *
			 * @hooked woocommerce_output_content_wrapper_end - 10 (outputs closing divs for the content)
			 */
			do_action( 'woocommerce_after_main_content' );
		?>
		<button id="toggle-desc" class="toggle-desc-btn">Xem thêm</button>
    </div>
</div>
<script type="text/javascript">
    jQuery(document).ready(function ($) {
        let currentBrand = 0;
        let currentCat = 0;
        let currentPage = 1;
        let activeRequest = null;

        const filterRootCatId = 0;
        const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        const initialProductsHtml = getProductsContainer() ? getProductsContainer().innerHTML : '';
        const initialPaginationHtml = getPaginationContainer() ? getPaginationContainer().innerHTML : '';
        const hadInitialPagination = !!getPaginationContainer();

        function getProductsContainer() {
            return document.querySelector('#main .products');
        }

        function getProductsWrapper() {
            const container = getProductsContainer();

            return container ? container.parentNode : null;
        }

        function getPaginationContainer() {
            return document.querySelector('#main .woocommerce-pagination');
        }

        function getProductCatSection() {
            return document.querySelector('.product_cat');
        }

        function getTermDescription() {
            return document.querySelector('#main .term-description');
        }

        function getToggleDescButton() {
            return document.getElementById('toggle-desc');
        }

        function updateToggleDescButton() {
            const termDescription = getTermDescription();
            const toggleButton = getToggleDescButton();

            if (!termDescription || !toggleButton) {
                return;
            }

            const needsToggle = termDescription.scrollHeight > termDescription.clientHeight + 5;

            if (!needsToggle && !termDescription.classList.contains('expanded')) {
                toggleButton.style.display = 'none';
                return;
            }

            toggleButton.style.display = 'block';
            toggleButton.textContent = termDescription.classList.contains('expanded') ? 'Thu gọn' : 'Xem thêm';
        }

        function ensurePaginationContainer() {
            const existing = getPaginationContainer();

            if (existing) {
                return existing;
            }

            const productsWrapper = getProductsWrapper();

            if (!productsWrapper || !productsWrapper.parentNode) {
                return null;
            }

            const pagination = document.createElement('nav');
            pagination.className = 'woocommerce-pagination';
            productsWrapper.parentNode.insertBefore(pagination, productsWrapper.nextSibling);

            return pagination;
        }

        function renderPagination(paginationHtml) {
            const container = ensurePaginationContainer();

            if (!container) {
                return;
            }

            if (paginationHtml && paginationHtml.trim()) {
                const wrap = document.createElement('div');
                wrap.innerHTML = paginationHtml.trim();

                if (wrap.firstElementChild && wrap.firstElementChild.classList.contains('woocommerce-pagination')) {
                    container.innerHTML = wrap.firstElementChild.innerHTML;
                } else {
                    container.innerHTML = paginationHtml;
                }

                container.style.display = '';
            } else {
                container.innerHTML = '';
                container.style.display = 'none';
            }
        }

        function showLoading() {
            const container = getProductsContainer();
            const wrapper = getProductsWrapper();

            if (!container || !wrapper) {
                console.warn('Không tìm thấy #main .products');
                return false;
            }

            wrapper.classList.add('loading');
            container.innerHTML = '<li class="product hpt-filter-message hpt-loading"><div class="lds-ellipsis"><div></div><div></div><div></div><div></div></div></li>';

            return true;
        }

        function hideLoading() {
            const wrapper = getProductsWrapper();

            if (wrapper) {
                wrapper.classList.remove('loading');
            }
        }

        function renderProducts(productsHtml) {
            const container = getProductsContainer();

            if (!container) {
                console.warn('Không tìm thấy #main .products');
                return;
            }

            container.innerHTML = productsHtml && productsHtml.trim()
                ? productsHtml
                : '<li class="product hpt-filter-message"><div class="box-text"><p>Không có sản phẩm phù hợp.</p></div></li>';
        }

        function renderSubCats(subCats) {
            const categorySection = getProductCatSection();

            if (Array.isArray(subCats) && subCats.length > 0) {
                const html = subCats.map(function (cat) {
                    return '<a href="javascript:void(0);" class="filter-product_cat" data-cat-id="' + cat.term_id + '" rel="nofollow">' + cat.name + '</a>';
                }).join('');

                $('.product_cat_items').html(html);

                if (categorySection) {
                    categorySection.style.display = '';
                }
            } else {
                $('.product_cat_items').html('');

                if (categorySection) {
                    categorySection.style.display = 'none';
                }
            }

            syncCategoryActiveState();
        }

        function syncCategoryActiveState() {
            $('.filter-product_cat').removeClass('active');

            if (currentCat > 0) {
                $('.filter-product_cat[data-cat-id="' + currentCat + '"]').addClass('active');
            }
        }

        function restoreInitialState() {
            if (activeRequest && activeRequest.readyState !== 4) {
                activeRequest.abort();
                activeRequest = null;
            }

            const container = getProductsContainer();

            if (container) {
                container.innerHTML = initialProductsHtml;
            }

            renderSubCats([]);
            currentPage = 1;

            if (hadInitialPagination) {
                renderPagination(initialPaginationHtml);
            } else {
                renderPagination('');
            }

            hideLoading();
        }

        function initTermDescriptionToggle() {
            const termDescription = getTermDescription();
            const toggleButton = getToggleDescButton();

            if (!toggleButton) {
                return;
            }

            if (!termDescription) {
                toggleButton.style.display = 'none';
                return;
            }

            updateToggleDescButton();

            toggleButton.addEventListener('click', function () {
                termDescription.classList.toggle('expanded');
                updateToggleDescButton();

                if (!termDescription.classList.contains('expanded')) {
                    termDescription.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });

            window.addEventListener('resize', updateToggleDescButton);
        }

        function renderAjaxError(message) {
            const container = getProductsContainer();

            if (container) {
                container.innerHTML = '<li class="product hpt-filter-message"><div class="box-text"><p>' + message + '</p></div></li>';
            }
        }

        function filterProducts(options = {}) {
            if (activeRequest && activeRequest.readyState !== 4) {
                activeRequest.abort();
            }

            if (!showLoading()) {
                return;
            }

            const data = {
                action: 'filter_brand',
                rootCatId: filterRootCatId,
                brandId: currentBrand || 0,
                catId: currentCat || 0,
                page: currentPage || 1
            };
            const request = $.ajax({
                type: 'POST',
                dataType: 'json',
                url: ajaxUrl,
                data: data,
                success: function (response) {
                    if (!response || !response.success || !response.data) {
                        renderAjaxError('Lỗi tải sản phẩm');
                        return;
                    }

                    const productsHtml = response.data.products_html || response.data.products || '';
                    const paginationHtml = response.data.pagination_html || '';
                    const subCats = Array.isArray(response.data.sub_cat) ? response.data.sub_cat : [];

                    renderProducts(productsHtml);
                    renderPagination(paginationHtml);

                    if (options.renderSubCat) {
                        renderSubCats(subCats);
                    }

                    syncCategoryActiveState();
                },
                error: function (xhr, statusText) {
                    if (statusText === 'abort') {
                        return;
                    }

                    console.error('AJAX error:', xhr.responseText || statusText);
                    renderAjaxError('Lỗi kết nối AJAX');
                },
                complete: function () {
                    if (activeRequest === request) {
                        activeRequest = null;
                        hideLoading();
                    }
                }
            });

            activeRequest = request;
        }

        $(document).on('click', '.filter-brand', function (e) {
            e.preventDefault();

            const nextBrandId = parseInt($(this).data('brand-id'), 10) || 0;

            if (!nextBrandId) {
                return;
            }

            $('.brand-item').removeClass('active');
            $(this).closest('.brand-item').addClass('active');

            currentBrand = nextBrandId;
            currentCat = 0;
            currentPage = 1;

            filterProducts({
                renderSubCat: true
            });
        });

        $(document).on('click', '.brand-remove', function (e) {
            e.preventDefault();
            e.stopPropagation();

            $('.brand-item').removeClass('active');
            currentBrand = 0;
            currentCat = 0;
            currentPage = 1;
            restoreInitialState();
        });

        $(document).on('click', '.filter-product_cat', function (e) {
            e.preventDefault();

            const nextCatId = parseInt($(this).data('cat-id'), 10) || 0;

            if (!nextCatId) {
                return;
            }

            if (nextCatId === currentCat) {
                currentCat = 0;
                currentPage = 1;

                if (currentBrand > 0) {
                    filterProducts({
                        renderSubCat: true
                    });
                } else {
                    restoreInitialState();
                }

                return;
            }

            currentCat = nextCatId;
            currentPage = 1;
            syncCategoryActiveState();

            filterProducts({
                renderSubCat: false
            });
        });

        $(document).on('click', '.woocommerce-pagination a.page-numbers[data-page]', function (e) {
            if (!currentBrand && !currentCat) {
                return;
            }

            e.preventDefault();

            const nextPage = parseInt($(this).data('page'), 10) || 1;

            if (nextPage === currentPage) {
                return;
            }

            currentPage = nextPage;

            filterProducts({
                renderSubCat: false
            });
        });

        initTermDescriptionToggle();
    });
</script>
<style type="text/css">
    .term-description {
        max-height: 140px;
        /* Chiều cao hiển thị khi thu gọn */
        overflow: hidden;
        position: relative;
        transition: max-height 0.5s ease;
    }

    .term-description.expanded {
        max-height: 2000px;
        /* đủ lớn để hiển thị toàn bộ */
    }

    .term-description::after {
        content: "";
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        height: 50px;
        background: linear-gradient(to bottom, transparent, #fff);
        transition: opacity 0.3s ease;
    }

    .term-description.expanded::after {
        opacity: 0;
        pointer-events: none;
    }

    .toggle-desc-btn {
        display: block;
        margin-top: 8px;
        background: #0073aa;
        color: #fff;
        border: none;
        padding: 6px 16px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        transition: background 0.3s ease;
        margin: auto;
    }

    .toggle-desc-btn:hover {
        background: #005f8a;
    }

    .product_cat_items {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: flex-start;
    }

    .product_cat_items a {
        display: inline-flex;
        align-items: center;
        min-height: 36px;
        padding: 6px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        background: #fff;
        line-height: 1.4;
    }
    
    .product_cat_items a.active {
        color: var(--fs-experimental-link-color-hover);
        border-color: #0284C7;
    }

    .brand-item.active {
        background: rgba(0, 0, 0, 0.1);
    }

    .brand-item {
        position: relative;
    }

    .brand-item span {
        position: absolute;
        right: -10px;
        top: -10px;
        cursor: pointer;
        display: none;
    }

    .brand-item.active span {
        display: block;
    }

    #main .loading .products {
        display: flex;
        justify-content: center;
        align-items: center;
    }

    #main .loading .woocommerce-pagination {
        display: none;
    }

    #main .products .hpt-filter-message {
        width: 100%;
        list-style: none;
        display: flex;
        justify-content: center;
    }

    .lds-ellipsis,
    .lds-ellipsis div {
        box-sizing: border-box;
    }

    #main .lds-ellipsis {
        display: inline-block;
        position: relative;
        width: 80px !important;
        height: 80px;
    }

    .lds-ellipsis div {
        position: absolute;
        top: 33.33333px;
        width: 13.33333px;
        height: 13.33333px;
        border-radius: 50%;
        background: currentColor;
        animation-timing-function: cubic-bezier(0, 1, 1, 0);
    }

    .lds-ellipsis div:nth-child(1) {
        left: 8px;
        animation: lds-ellipsis1 0.6s infinite;
    }

    .lds-ellipsis div:nth-child(2) {
        left: 8px;
        animation: lds-ellipsis2 0.6s infinite;
    }

    .lds-ellipsis div:nth-child(3) {
        left: 32px;
        animation: lds-ellipsis2 0.6s infinite;
    }

    .lds-ellipsis div:nth-child(4) {
        left: 56px;
        animation: lds-ellipsis3 0.6s infinite;
    }

    @keyframes lds-ellipsis1 {
        0% {
            transform: scale(0);
        }

        100% {
            transform: scale(1);
        }
    }

    @keyframes lds-ellipsis3 {
        0% {
            transform: scale(1);
        }

        100% {
            transform: scale(0);
        }
    }

    @keyframes lds-ellipsis2 {
        0% {
            transform: translate(0, 0);
        }

        100% {
            transform: translate(24px, 0);
        }
    }
    
    .category-tags {
        margin-top: 16px;
        padding-top: 12px;
        border-top: 1px solid #eee;
        font-size: 14px;
        display: flex;
        justify-content: space-between;
        flex-wrap: wrap;
    }

    .category-tags .tag-label {
        font-weight: 600;
        margin-right: 8px;
        color: #333;
    }

    .category-tags a {
        display: inline-block;
        margin: 4px 6px 4px 0;
        padding: 4px 10px;
        background-color: #f5f5f5;
        color: #0073aa;
        border-radius: 3px;
        text-decoration: none;
        transition: all 0.3s ease;
    }

    .category-tags a:hover {
        background-color: #0073aa;
        color: #fff;
    }
</style>
