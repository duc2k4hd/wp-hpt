<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'wp-load.php';
require 'vendor/autoload.php';

// Tắt bộ lọc HTML để giữ nguyên thẻ từ Excel
remove_filter('pre_term_description', 'wp_filter_kses');
remove_filter('pre_term_description', 'wp_kses_data');
// Tắt thêm filter để tránh việc tự động thêm thẻ <p> hoặc chuyển đổi ký tự
remove_filter('term_description', 'wpautop');
remove_filter( 'category_description', 'wpautop' );

use PhpOffice\PhpSpreadsheet\IOFactory;

echo "<pre>";

$filePath = 'product_categories_import.xlsx';

if (!file_exists($filePath)) {
    die("❌ Không tìm thấy file Excel");
}

echo "📂 Đang load file...\n";

$spreadsheet = IOFactory::load($filePath);
$sheet = $spreadsheet->getActiveSheet();
$data = $sheet->toArray();

echo "📊 Tổng dòng đọc được: " . count($data) . "\n\n";

unset($data[0]); // bỏ header

foreach ($data as $index => $row) {

    $name = trim($row[0] ?? '');
    $slug = trim($row[1] ?? '');
    $parentName = trim($row[2] ?? '');
    $description = trim($row[3] ?? '');

    echo "===========================\n";
    echo "🔎 Dòng " . ($index+1) . "\n";
    echo "Name: $name\n";
    echo "Slug: $slug\n";
    echo "Parent: $parentName\n";

    if (!$name) {
        echo "⛔ Bỏ qua vì name rỗng\n";
        continue;
    }

    // kiểm tra tồn tại theo slug trước
    $existing = get_term_by('slug', $slug, 'product_cat');

    if ($existing) {

        echo "⚠ Đã tồn tại theo slug (ID: {$existing->term_id})\n";
    
        // xử lý parent trước
        $parentId = 0;
    
        if ($parentName) {
            $parent = get_term_by('name', $parentName, 'product_cat');
    
            if ($parent) {
                $parentId = $parent->term_id;
            }
        }
    
        $update = wp_update_term($existing->term_id, 'product_cat', [
            'name'        => $name,
            'slug'        => $slug ?: sanitize_title($name),
            'parent'      => $parentId,
            'description' => $description
        ]);
    
        if (is_wp_error($update)) {
            echo "❌ Lỗi update: " . $update->get_error_message() . "\n";
        } else {
            echo "✅ Đã update FULL dữ liệu\n";
        }
    
        continue;
    }

    // xử lý parent
    $parentId = 0;

    if ($parentName) {
        $parent = get_term_by('name', $parentName, 'product_cat');

        if ($parent) {
            $parentId = $parent->term_id;
            echo "✔ Parent ID: $parentId\n";
        } else {
            echo "⚠ Không tìm thấy parent → tạo parent mới\n";

            $newParent = wp_insert_term($parentName, 'product_cat');

            if (!is_wp_error($newParent)) {
                $parentId = $newParent['term_id'];
                echo "✔ Đã tạo parent ID: $parentId\n";
            } else {
                echo "❌ Lỗi tạo parent: " . $newParent->get_error_message() . "\n";
            }
        }
    }

    $result = wp_insert_term($name, 'product_cat', [
        'slug' => $slug ?: sanitize_title($name),
        'parent' => $parentId,
        'description' => $description
    ]);

    if (is_wp_error($result)) {
        echo "❌ Lỗi insert: " . $result->get_error_message() . "\n";
    } else {
        echo "✅ Tạo mới thành công ID: " . $result['term_id'] . "\n";
    }
}

echo "\n🎉 HOÀN TẤT IMPORT\n";
echo "</pre>";