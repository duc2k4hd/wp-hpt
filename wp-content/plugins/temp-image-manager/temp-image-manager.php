<?php
/*
Plugin Name: Quản lý Ảnh Tạm
Plugin URI: https://haiphongtech.vn/
Description: Plugin giúp upload và quản lý ảnh tạm lưu vào thư mục riêng. Hỗ trợ chỉnh sửa, xóa, phân trang và SweetAlert.
Version: 1.0
Author: Đức Nobi ❤️
Author URI: https://haiphongtech.vn/
*/

if (!defined('ABSPATH')) exit;

// === Include main functions ===
require_once plugin_dir_path(__FILE__) . 'includes/temp-image-core.php';