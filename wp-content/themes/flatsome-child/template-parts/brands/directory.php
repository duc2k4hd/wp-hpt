<?php
/**
 * Template trang danh sách thương hiệu (/thuong-hieu/).
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

$directory_data = hpt_get_grouped_brands_for_directory();
$total_brands   = $directory_data['total'];
$brand_groups   = $directory_data['groups'];
$group_keys     = array_keys($brand_groups);
?>

<div id="content" class="hpt-brands-page page-wrapper" role="main">
    <div class="container">
        <nav class="hpt-brands-breadcrumb" aria-label="Breadcrumb">
            <a href="<?php echo esc_url(home_url('/')); ?>">Trang chủ</a>
            <span class="divider">/</span>
            <span>Thương hiệu</span>
        </nav>

        <header class="hpt-brands-header">
            <div>
                <h1 class="hpt-brands-title">Thương hiệu</h1>
                <p class="hpt-brands-subtitle">
                    Khám phá <?php echo esc_html((string) $total_brands); ?> thương hiệu thiết bị điện, tự động hóa chính hãng.
                </p>
            </div>
            <div class="hpt-brands-search-wrap">
                <input
                    type="search"
                    id="hpt-brand-search-input"
                    class="hpt-brands-search-input"
                    placeholder="Tìm nhanh thương hiệu (VD: ABB, Omron, Autonics...)"
                    aria-label="Tìm kiếm thương hiệu"
                    autocomplete="off"
                >
            </div>
        </header>

        <?php if (!empty($brand_groups)) : ?>
            <nav class="hpt-brands-az-bar" aria-label="Lọc thương hiệu theo chữ cái">
                <button type="button" class="hpt-az-btn is-active" data-letter="ALL">Tất cả</button>
                <?php foreach ($group_keys as $letter) : ?>
                    <button type="button" class="hpt-az-btn" data-letter="<?php echo esc_attr($letter); ?>">
                        <?php echo esc_html($letter); ?>
                    </button>
                <?php endforeach; ?>
            </nav>

            <div id="hpt-brands-groups" class="hpt-brands-groups">
                <?php foreach ($brand_groups as $letter => $brands) : ?>
                    <section class="hpt-brand-group" data-group="<?php echo esc_attr($letter); ?>">
                        <div class="hpt-brand-group-head">
                            <h2 class="hpt-brand-group-letter"><?php echo esc_html($letter); ?></h2>
                            <span class="hpt-brand-group-count"><?php echo esc_html((string) count($brands)); ?> thương hiệu</span>
                        </div>

                        <div class="hpt-brand-grid">
                            <?php foreach ($brands as $brand) :
                                $initial = mb_strtoupper(mb_substr($brand['name'], 0, 2, 'UTF-8'), 'UTF-8');
                                $search_name = mb_strtolower($brand['name'], 'UTF-8');
                            ?>
                                <a
                                    href="<?php echo esc_url($brand['url']); ?>"
                                    class="hpt-brand-card"
                                    data-name="<?php echo esc_attr($search_name); ?>"
                                >
                                    <div class="hpt-brand-logo-box">
                                        <?php if ($brand['avatar_url'] !== '') : ?>
                                            <img
                                                src="<?php echo esc_url($brand['avatar_url']); ?>"
                                                alt="<?php echo esc_attr($brand['name']); ?>"
                                                loading="lazy"
                                                decoding="async"
                                            >
                                        <?php else : ?>
                                            <span class="hpt-brand-monogram" aria-hidden="true"><?php echo esc_html($initial); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="hpt-brand-card-info">
                                        <span class="hpt-brand-card-name"><?php echo esc_html($brand['name']); ?></span>
                                        <?php if ($brand['count'] > 0) : ?>
                                            <span class="hpt-brand-card-count"><?php echo esc_html((string) $brand['count']); ?> sản phẩm</span>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>

            <div id="hpt-brands-empty" class="hpt-brands-empty" hidden>
                Không tìm thấy thương hiệu phù hợp với từ khóa của bạn.
            </div>
        <?php else : ?>
            <div class="hpt-brands-empty">
                Hiện chưa có thương hiệu nào.
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.hpt-brands-page {
    padding: 24px 0 56px;
    background: #f8fafc;
}
.hpt-brands-breadcrumb {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: #64748b;
    margin-bottom: 16px;
}
.hpt-brands-breadcrumb a {
    color: #0284c7;
    text-decoration: none;
}
.hpt-brands-breadcrumb a:hover {
    text-decoration: underline;
}
.hpt-brands-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}
.hpt-brands-title {
    margin: 0 0 6px;
    font-size: 28px;
    line-height: 1.2;
    font-weight: 700;
    color: #0f172a;
}
.hpt-brands-subtitle {
    margin: 0;
    font-size: 14px;
    color: #475569;
}
.hpt-brands-search-wrap {
    flex: 1 1 300px;
    max-width: 380px;
}
.hpt-brands-search-input {
    width: 100%;
    height: 42px;
    padding: 0 14px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #fff;
    font-size: 14px;
    color: #0f172a;
    margin: 0;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}
.hpt-brands-search-input:focus {
    outline: none;
    border-color: #0284c7;
    box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.14);
}
.hpt-brands-az-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding: 12px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    margin-bottom: 24px;
}
.hpt-az-btn {
    min-width: 36px;
    height: 34px;
    padding: 0 10px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    background: #f8fafc;
    color: #334155;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    margin: 0;
    line-height: 1;
    transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}
.hpt-az-btn:hover {
    border-color: #0284c7;
    color: #0284c7;
}
.hpt-az-btn.is-active {
    background: #0284c7;
    border-color: #0284c7;
    color: #fff;
}
.hpt-brand-group {
    margin-bottom: 28px;
}
.hpt-brand-group[hidden],
.hpt-brand-card[hidden] {
    display: none;
}
.hpt-brand-group-head {
    display: flex;
    align-items: baseline;
    gap: 10px;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 2px solid #e2e8f0;
}
.hpt-brand-group-letter {
    margin: 0;
    font-size: 20px;
    font-weight: 700;
    color: #0284c7;
}
.hpt-brand-group-count {
    font-size: 12px;
    color: #64748b;
}
.hpt-brand-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(175px, 1fr));
    gap: 14px;
}
.hpt-brand-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 14px 12px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    text-decoration: none;
    color: #0f172a;
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
}
.hpt-brand-card:hover {
    transform: translateY(-2px);
    border-color: #0284c7;
    box-shadow: 0 8px 20px rgba(2, 132, 199, 0.1);
}
.hpt-brand-logo-box {
    width: 100%;
    height: 70px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 10px;
    padding: 4px;
}
.hpt-brand-logo-box img {
    max-width: 100%;
    max-height: 62px;
    width: auto;
    height: auto;
    object-fit: contain;
}
.hpt-brand-monogram {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    background: #e0f2fe;
    color: #0369a1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    font-weight: 700;
    letter-spacing: 0.03em;
}
.hpt-brand-card-info {
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.hpt-brand-card-name {
    font-size: 14px;
    font-weight: 600;
    color: #1e293b;
    line-height: 1.3;
    word-break: break-word;
}
.hpt-brand-card-count {
    font-size: 12px;
    color: #64748b;
}
.hpt-brands-empty {
    padding: 40px 20px;
    text-align: center;
    background: #fff;
    border: 1px dashed #cbd5e1;
    border-radius: 10px;
    color: #64748b;
    font-size: 15px;
}
@media (max-width: 640px) {
    .hpt-brands-title {
        font-size: 23px;
    }
    .hpt-brand-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }
}
</style>

<script>
(function () {
    var searchInput = document.getElementById('hpt-brand-search-input');
    var azButtons = document.querySelectorAll('.hpt-az-btn');
    var groups = document.querySelectorAll('.hpt-brand-group');
    var emptyBox = document.getElementById('hpt-brands-empty');

    if (!groups.length) {
        return;
    }

    var activeLetter = 'ALL';

    function applyFilter() {
        var keyword = searchInput ? searchInput.value.trim().toLowerCase() : '';
        var visibleGroups = 0;

        for (var i = 0; i < groups.length; i++) {
            var group = groups[i];
            var groupLetter = group.getAttribute('data-group');
            var letterMatch = (activeLetter === 'ALL' || groupLetter === activeLetter);

            if (!letterMatch) {
                group.hidden = true;
                continue;
            }

            var cards = group.querySelectorAll('.hpt-brand-card');
            var visibleCards = 0;

            for (var j = 0; j < cards.length; j++) {
                var card = cards[j];
                var name = card.getAttribute('data-name') || '';
                var match = (keyword === '' || name.indexOf(keyword) !== -1);
                card.hidden = !match;
                if (match) {
                    visibleCards++;
                }
            }

            group.hidden = (visibleCards === 0);
            if (visibleCards > 0) {
                visibleGroups++;
            }
        }

        if (emptyBox) {
            emptyBox.hidden = (visibleGroups > 0);
        }
    }

    for (var k = 0; k < azButtons.length; k++) {
        azButtons[k].addEventListener('click', function () {
            activeLetter = this.getAttribute('data-letter') || 'ALL';
            for (var m = 0; m < azButtons.length; m++) {
                azButtons[m].classList.remove('is-active');
            }
            this.classList.add('is-active');
            applyFilter();
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyFilter);
    }
})();
</script>

<?php
get_footer();
