<?php
/**
 * Template trang danh sách thương hiệu (/thuong-hieu/) - Lưới ảnh tối giản.
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

$brands = hpt_get_brands_for_directory();
?>

<div id="content" class="hpt-brands-page page-wrapper" role="main">
    <div class="container">
        <div class="hpt-brands-topbar">
            <h1 class="hpt-brands-title">Thương hiệu</h1>
            <?php if (!empty($brands)) : ?>
                <input
                    type="search"
                    id="hpt-brand-search-input"
                    class="hpt-brands-search-input"
                    placeholder="Tìm thương hiệu..."
                    aria-label="Tìm thương hiệu"
                    autocomplete="off"
                >
            <?php endif; ?>
        </div>

        <?php if (!empty($brands)) : ?>
            <div id="hpt-brand-grid" class="hpt-brand-grid">
                <?php foreach ($brands as $brand) :
                    $search_name = mb_strtolower($brand['name'], 'UTF-8');
                ?>
                    <a
                        href="<?php echo esc_url($brand['url']); ?>"
                        class="hpt-brand-cell"
                        data-name="<?php echo esc_attr($search_name); ?>"
                        title="<?php echo esc_attr($brand['name']); ?>"
                    >
                        <div class="hpt-brand-img-wrap">
                            <?php if ($brand['avatar_url'] !== '') : ?>
                                <img
                                    src="<?php echo esc_url($brand['avatar_url']); ?>"
                                    alt="<?php echo esc_attr($brand['name']); ?>"
                                    loading="lazy"
                                    decoding="async"
                                >
                            <?php else : ?>
                                <span class="hpt-brand-fallback"><?php echo esc_html($brand['name']); ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="hpt-brand-label"><?php echo esc_html(mb_strtoupper($brand['name'], 'UTF-8')); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <p id="hpt-brands-empty" class="hpt-brands-empty" hidden>
                Không tìm thấy thương hiệu phù hợp.
            </p>
        <?php else : ?>
            <p class="hpt-brands-empty">Hiện chưa có thương hiệu nào.</p>
        <?php endif; ?>
    </div>
</div>

<style>
.hpt-brands-page {
    padding: 20px 0 48px;
    background: #fff;
}
.hpt-brands-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}
.hpt-brands-title {
    margin: 0;
    font-size: 22px;
    font-weight: 700;
    color: #111827;
}
.hpt-brands-search-input {
    width: 240px;
    max-width: 100%;
    height: 36px;
    padding: 0 12px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    font-size: 13px;
    color: #111827;
    margin: 0;
    background: #fff;
}
.hpt-brands-search-input:focus {
    outline: none;
    border-color: #0284c7;
}
.hpt-brand-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    background: #e5e7eb;
    gap: 1px;
    border: 1px solid #e5e7eb;
}
.hpt-brand-cell {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    background: #fff;
    padding: 16px 14px 10px;
    text-decoration: none;
    color: #4b5563;
    transition: background-color 0.15s ease;
}
.hpt-brand-cell:hover {
    background: #f8fafc;
}
.hpt-brand-cell[hidden] {
    display: none;
}
.hpt-brand-img-wrap {
    width: 100%;
    height: 110px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 10px;
}
.hpt-brand-img-wrap img {
    max-width: 92%;
    max-height: 100%;
    width: auto;
    height: auto;
    object-fit: contain;
    transition: transform 0.15s ease;
}
.hpt-brand-cell:hover .hpt-brand-img-wrap img {
    transform: scale(1.04);
}
.hpt-brand-fallback {
    font-size: 15px;
    font-weight: 700;
    color: #374151;
    text-align: center;
    word-break: break-word;
}
.hpt-brand-label {
    display: block;
    font-size: 11px;
    line-height: 1.3;
    color: #6b7280;
    text-align: left;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.hpt-brands-empty {
    padding: 28px 16px;
    text-align: center;
    color: #6b7280;
    font-size: 14px;
    border: 1px solid #e5e7eb;
    margin: 0;
}
@media (max-width: 1024px) {
    .hpt-brand-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}
@media (max-width: 640px) {
    .hpt-brands-search-input {
        width: 100%;
    }
    .hpt-brand-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .hpt-brand-img-wrap {
        height: 86px;
    }
}
</style>

<script>
(function () {
    var input = document.getElementById('hpt-brand-search-input');
    var cells = document.querySelectorAll('.hpt-brand-cell');
    var empty = document.getElementById('hpt-brands-empty');

    if (!input || !cells.length) {
        return;
    }

    input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase();
        var visible = 0;

        for (var i = 0; i < cells.length; i++) {
            var name = cells[i].getAttribute('data-name') || '';
            var match = (q === '' || name.indexOf(q) !== -1);
            cells[i].hidden = !match;
            if (match) {
                visible++;
            }
        }

        if (empty) {
            empty.hidden = (visible > 0);
        }
    });
})();
</script>

<?php
get_footer();
