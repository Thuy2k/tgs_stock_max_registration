<?php
/**
 * Plugin Name: TGS Stock Max Registration
 * Plugin URI: https://bizgpt.vn/
 * Description: Quy trình kho tạo phiếu đăng ký tồn max sản phẩm mới cho các shop con.
 * Version: 1.0.4
 * Author: BIZGPT_AI
 * Author URI: https://bizgpt.vn/
 * License: GPL v2 or later
 * Text Domain: tgs-stock-max-registration
 * Requires Plugins: tgs_shop_management
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TGS_SMR_VERSION', '1.0.4');
define('TGS_SMR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TGS_SMR_PLUGIN_URL', plugin_dir_url(__FILE__));

class TGS_Stock_Max_Registration
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies()
    {
        require_once TGS_SMR_PLUGIN_DIR . 'includes/class-smr-helper.php';
        require_once TGS_SMR_PLUGIN_DIR . 'includes/class-smr-repository.php';
        require_once TGS_SMR_PLUGIN_DIR . 'includes/class-smr-xlsx-importer.php';
        require_once TGS_SMR_PLUGIN_DIR . 'includes/class-smr-xlsx-writer.php';
        require_once TGS_SMR_PLUGIN_DIR . 'includes/class-smr-ajax.php';
    }

    private function init_hooks()
    {
        add_filter('tgs_shop_dashboard_routes', [$this, 'add_dashboard_routes']);
        add_filter('tgs_shop_workflow_nav', [$this, 'add_workflow_nav'], 20, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_tgs_smr_export_request', [$this, 'export_request']);
        add_action('admin_post_tgs_smr_download_import_template', [$this, 'download_import_template']);

        TGS_SMR_Ajax::init();
    }

    public function add_dashboard_routes($routes)
    {
        $routes['stock-max-registration'] = [
            'Đăng ký tồn max sản phẩm mới',
            TGS_SMR_PLUGIN_DIR . 'admin-views/main.php',
        ];

        return $routes;
    }

    public function add_workflow_nav($workflow_nav, $current_view)
    {
        if (!isset($workflow_nav['purchase']['sections']) || !is_array($workflow_nav['purchase']['sections'])) {
            return $workflow_nav;
        }

        $item = [
            'view' => 'stock-max-registration',
            'label' => 'Đăng ký tồn max',
            'icon' => 'bx bx-table',
            'active_views' => ['stock-max-registration'],
        ];

        $inserted = false;
        foreach ($workflow_nav['purchase']['sections'] as &$section) {
            $heading = !empty($section['heading']) ? remove_accents((string) $section['heading']) : '';
            if ($heading !== '' && stripos($heading, 'cau hinh') !== false) {
                $section['items'][] = $item;
                $inserted = true;
                break;
            }
        }
        unset($section);

        if (!$inserted) {
            $workflow_nav['purchase']['sections'][] = [
                'heading' => 'Đăng ký tồn max',
                'icon' => 'bx bx-table',
                'items' => [$item],
            ];
        }

        return $workflow_nav;
    }

    public function enqueue_assets($hook)
    {
        if (strpos((string) $hook, 'tgs-shop-') === false) {
            return;
        }

        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : '';
        if ($view !== 'stock-max-registration') {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_style(
            'tgs-smr',
            TGS_SMR_PLUGIN_URL . 'assets/css/smr.css',
            [],
            TGS_SMR_VERSION
        );

        wp_enqueue_script(
            'tgs-smr',
            TGS_SMR_PLUGIN_URL . 'assets/js/smr.js',
            ['jquery'],
            TGS_SMR_VERSION,
            true
        );

        wp_localize_script('tgs-smr', 'TgsSmr', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'exportUrl' => admin_url('admin-post.php'),
            'nonce' => wp_create_nonce('tgs_smr_nonce'),
            'currentBlogId' => get_current_blog_id(),
        ]);
    }

    public function export_request()
    {
        $request_id = isset($_GET['request_id']) ? absint($_GET['request_id']) : 0;
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

        if (!$request_id || !wp_verify_nonce($nonce, 'tgs_smr_export_' . $request_id)) {
            wp_die('Link xuất Excel không hợp lệ.', 403);
        }

        $data = TGS_SMR_Repository::get_request_matrix($request_id);
        if (!$data || (int) ($data['request']['source_blog_id'] ?? 0) !== get_current_blog_id()) {
            wp_die('Chỉ kho tạo phiếu mới được xuất Excel tổng hợp.', 403);
        }

        $binary = TGS_SMR_Xlsx_Writer::build_request_workbook($data);
        $filename = sanitize_file_name(($data['request']['request_code'] ?: 'dang-ky-max') . '.xlsx');

        while (ob_get_level() > 0) {
            if (!@ob_end_clean()) {
                break;
            }
        }

        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Content-Length: ' . strlen($binary));
        header('X-Content-Type-Options: nosniff');
        echo $binary;
        exit;
    }

    public function download_import_template()
    {
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'tgs_smr_import_template')) {
            wp_die('Link tải mẫu Excel không hợp lệ.', 403);
        }

        if (!is_user_logged_in()) {
            wp_die('Bạn cần đăng nhập.', 403);
        }

        if (!TGS_SMR_Helper::is_warehouse_blog(get_current_blog_id()) && !current_user_can('manage_options')) {
            wp_die('Chỉ kho tạo phiếu mới được tải mẫu nhập Excel.', 403);
        }

        $binary = TGS_SMR_Xlsx_Writer::build_import_template_workbook();
        if ($binary === '') {
            wp_die('Không tạo được file mẫu Excel.', 500);
        }

        while (ob_get_level() > 0) {
            if (!@ob_end_clean()) {
                break;
            }
        }

        $filename = 'mau-nhap-san-pham-dang-ky-max.xlsx';
        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Content-Length: ' . strlen($binary));
        header('X-Content-Type-Options: nosniff');
        echo $binary;
        exit;
    }

    public static function activate()
    {
        if (class_exists('TGS_Shop_Database')) {
            TGS_Shop_Database::activate();
        }
    }
}

register_activation_hook(__FILE__, ['TGS_Stock_Max_Registration', 'activate']);

function tgs_stock_max_registration_init()
{
    if (!class_exists('TGS_Shop_Management')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>TGS Stock Max Registration</strong> can plugin <strong>TGS Shop Management</strong> duoc kich hoat.</p></div>';
        });
        return null;
    }

    return TGS_Stock_Max_Registration::get_instance();
}
add_action('plugins_loaded', 'tgs_stock_max_registration_init', 30);
