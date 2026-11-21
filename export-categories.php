<?php
require 'wp-load.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

$sheet->setCellValue('A1', 'name');
$sheet->setCellValue('B1', 'slug');
$sheet->setCellValue('C1', 'parent');
$sheet->setCellValue('D1', 'description');

$terms = get_terms([
    'taxonomy' => 'product_cat',
    'hide_empty' => false
]);

$row = 2;

foreach ($terms as $term) {

    $parentName = '';
    if ($term->parent != 0) {
        $parent = get_term($term->parent, 'product_cat');
        $parentName = $parent ? $parent->name : '';
    }

    $sheet->setCellValue("A$row", $term->name);
    $sheet->setCellValue("B$row", $term->slug);
    $sheet->setCellValue("C$row", $parentName);
    $sheet->setCellValue("D$row", $term->description);

    $row++;
}

$writer = new Xlsx($spreadsheet);
$writer->save('product_categories_export.xlsx');

echo "Export thành công!";