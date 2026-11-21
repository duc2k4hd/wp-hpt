<?php
/*
Plugin Name: Chuyển hướng thủ công
Plugin URI: https://haiphongtech.vn/
Description: Cho phép tạo và quản lý redirect thủ công từ file TXT, tích hợp với giao diện Admin.
Version: 1.0
Author: Đức Nobi ❤️
Author URI: https://haiphongtech.vn/
*/

if (!defined('ABSPATH')) exit;

// Load các chức năng từ file riêng
require_once plugin_dir_path(__FILE__) . 'includes/manual-redirection-core.php';