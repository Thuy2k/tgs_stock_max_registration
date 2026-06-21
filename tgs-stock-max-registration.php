<?php
/**
 * Plugin Name: TGS Stock Max Registration
 * Plugin URI: https://bizgpt.vn/
 * Description: Quy trình kho tạo phiếu đăng ký tồn max sản phẩm mới cho các shop con.
 * Version: 1.0.5
 * Author: BIZGPT_AI
 * Author URI: https://bizgpt.vn/
 * License: GPL v2 or later
 * Text Domain: tgs-stock-max-registration
 * Requires Plugins: tgs_shop_management
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TGS_SMR_VERSION', '1.0.5');
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
        require_once TGS_SMR_PLUGIN_DIR . 'includes/class-smr-existing-repository.php';
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
        add_action('admin_post_tgs_smr_existing_export_request', [$this, 'export_existing_request']);
        add_action('admin_post_tgs_smr_download_import_template', [$this, 'download_import_template']);

        TGS_SMR_Ajax::init();
    }

    public function add_dashboard_routes($routes)
    {
        $routes['stock-max-registration'] = [
            'Đăng ký tồn max sản phẩm mới',
            TGS_SMR_PLUGIN_DIR . 'admin-views/main.php',
        ];

        $routes['stock-max-registration'][0] = 'Chi nhánh đăng ký tồn max cho sản phẩm mới';
        $routes['stock-max-existing-registration'] = [
            'Đăng ký max cho sản phẩm đã có mã hàng',
            TGS_SMR_PLUGIN_DIR . 'admin-views/existing.php',
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
        $item['label'] = 'Chi nhánh đăng ký tồn max cho sản phẩm mới';
        $existing_item = [
            'view' => 'stock-max-existing-registration',
            'label' => 'Đăng ký max cho sản phẩm đã có mã hàng',
            'icon' => 'bx bx-edit-alt',
            'active_views' => ['stock-max-existing-registration'],
        ];

        $inserted = false;
        foreach ($workflow_nav['purchase']['sections'] as &$section) {
            $heading = !empty($section['heading']) ? remove_accents((string) $section['heading']) : '';
            if ($heading !== '' && stripos($heading, 'dang ky ton max') !== false) {
                $section['items'][] = $item;
                $section['items'][] = $existing_item;
                $inserted = true;
                break;
            }
        }
        unset($section);

        if (!$inserted) {
            $workflow_nav['purchase']['sections'][] = [
                'heading' => 'Đăng ký tồn max',
                'icon' => 'bx bx-table',
                'items' => [$item, $existing_item],
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
        if (!in_array($view, ['stock-max-registration', 'stock-max-existing-registration'], true)) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_style(
            'tgs-smr',
            TGS_SMR_PLUGIN_URL . 'assets/css/smr.css',
            [],
            TGS_SMR_VERSION
        );

        $script_handle = $view === 'stock-max-existing-registration' ? 'tgs-smr-existing' : 'tgs-smr';
        $script_file = $view === 'stock-max-existing-registration' ? 'assets/js/smr-existing.js' : 'assets/js/smr.js';

        wp_enqueue_script(
            $script_handle,
            TGS_SMR_PLUGIN_URL . $script_file,
            ['jquery'],
            TGS_SMR_VERSION,
            true
        );

        wp_localize_script($script_handle, 'TgsSmr', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'exportUrl' => admin_url('admin-post.php'),
            'nonce' => wp_create_nonce('tgs_smr_nonce'),
            'currentBlogId' => get_current_blog_id(),
            'view' => $view,
        ]);
    }

    public function export_request()
    {
        $request_id = isset($_GET['request_id']) ? absint($_GET['request_id']) : 0;
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

        if (!$this->can_access_view('stock-max-registration')) {
            wp_die('Bạn không có quyền truy cập.', 403);
        }

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

    public function export_existing_request()
    {
        $request_id = isset($_GET['request_id']) ? absint($_GET['request_id']) : 0;
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

        if (!$this->can_access_view('stock-max-existing-registration')) {
            wp_die('Bạn không có quyền truy cập.', 403);
        }

        if (!$request_id || !wp_verify_nonce($nonce, 'tgs_smr_existing_export_' . $request_id)) {
            wp_die('Link xuat Excel khong hop le.', 403);
        }

        $data = TGS_SMR_Existing_Repository::get_request($request_id);
        if (is_wp_error($data)) {
            wp_die($data->get_error_message(), 403);
        }
        if (!$data) {
            wp_die('Khong tim thay phieu.', 404);
        }

        $binary = TGS_SMR_Xlsx_Writer::build_existing_request_workbook($data);
        if ($binary === '') {
            wp_die('Khong tao duoc file Excel.', 500);
        }

        $filename = sanitize_file_name(($data['request']['request_code'] ?: 'dang-ky-max-sku') . '.xlsx');

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

        if (!$this->can_access_view('stock-max-registration')) {
            wp_die('Bạn không có quyền truy cập.', 403);
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

    private function can_access_view($view)
    {
        if (!is_user_logged_in()) {
            return false;
        }

        if (class_exists('TGS_Permission')) {
            $permission = TGS_Permission::get_instance();
            if (method_exists($permission, 'user_can_access_view')) {
                return $permission->user_can_access_view(get_current_user_id(), $view);
            }
        }

        return false;
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
