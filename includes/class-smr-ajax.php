<?php

if (!defined('ABSPATH')) {
    exit;
}

class TGS_SMR_Ajax
{
    public static function init()
    {
        $actions = [
            'list_requests' => 'list_requests',
            'create_request' => 'create_request',
            'get_request' => 'get_request',
            'save_cell' => 'save_cell',
            'save_item_sku' => 'save_item_sku',
            'save_item_name' => 'save_item_name',
            'update_status' => 'update_status',
            'delete_item' => 'delete_item',
            'apply_request' => 'apply_request',
            'search_global_products' => 'search_global_products',
            'payload_shops' => 'payload_shops',
        ];

        foreach ($actions as $action => $method) {
            add_action('wp_ajax_tgs_smr_' . $action, [__CLASS__, $method]);
        }
    }

    private static function verify_access()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Bạn cần đăng nhập.'], 403);
        }

        if (class_exists('TGS_Permission')) {
            $permission = TGS_Permission::get_instance();
            if (method_exists($permission, 'user_can_access_view')) {
                $can = $permission->user_can_access_view(get_current_user_id(), 'stock-max-registration');
                if (!$can && !current_user_can('manage_options')) {
                    wp_send_json_error(['message' => 'Bạn không có quyền truy cập.'], 403);
                }
            }
        }
    }
    public static function list_requests()
    {
        self::verify_access();
        TGS_SMR_Helper::verify_nonce();
        $mode = sanitize_key($_POST['mode'] ?? 'warehouse');
        $rows = TGS_SMR_Repository::list_requests([
            'mode' => $mode,
            'blog_id' => get_current_blog_id(),
            'status' => sanitize_key($_POST['status'] ?? ''),
            'search' => sanitize_text_field($_POST['search'] ?? ''),
        ]);
        wp_send_json_success(['items' => $rows]);
    }

    public static function create_request()
    {
        self::verify_access();
        TGS_SMR_Helper::verify_nonce();

        $products = [];
        $shop_ids = array_map('intval', (array) ($_POST['shop_ids'] ?? []));
        if (!empty($_POST['products_json'])) {
            $decoded_products = json_decode(wp_unslash($_POST['products_json']), true);
            if (is_array($decoded_products)) {
                $products = $decoded_products;
            }
        }
        if (empty($shop_ids) && !empty($_POST['shop_ids_json'])) {
            $shop_ids = array_map('intval', (array) json_decode(wp_unslash($_POST['shop_ids_json']), true));
        }

        $result = TGS_SMR_Repository::create_request([
            'source_blog_id' => get_current_blog_id(),
            'request_title' => sanitize_text_field($_POST['request_title'] ?? ''),
            'note' => sanitize_textarea_field($_POST['note'] ?? ''),
            'products' => $products,
            'shop_ids' => $shop_ids,
            'include_demo' => !empty($_POST['include_demo']),
            'demo_count' => absint($_POST['demo_count'] ?? 65),
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success([
            'request_id' => (int) $result,
            'export_url' => TGS_SMR_Repository::export_url((int) $result),
        ]);
    }

    public static function get_request()
    {
        self::verify_access();
        TGS_SMR_Helper::verify_nonce();
        $request_id = absint($_POST['request_id'] ?? 0);
        $data = TGS_SMR_Repository::get_request_matrix($request_id);
        if (!$data) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu.'], 404);
        }

        if (!TGS_SMR_Repository::can_view_request($data['request'])) {
            wp_send_json_error(['message' => 'Không đủ quyền xem phiếu này.'], 403);
        }

        if ((int) ($data['request']['source_blog_id'] ?? 0) !== get_current_blog_id()) {
            $allowed_shop_ids = [];
            $data['shops'] = array_values(array_filter((array) $data['shops'], static function ($shop) use (&$allowed_shop_ids) {
                $allowed = (int) ($shop['target_blog_id'] ?? 0) === get_current_blog_id()
                    && (int) ($shop['is_fake'] ?? 0) === 0;
                if ($allowed) {
                    $allowed_shop_ids[(int) $shop['request_shop_id']] = true;
                }
                return $allowed;
            }));

            foreach ($data['values'] as $item_id => $shop_values) {
                foreach ($shop_values as $shop_id => $value) {
                    if (empty($allowed_shop_ids[(int) $shop_id])) {
                        unset($data['values'][$item_id][$shop_id]);
                    }
                }
            }

            $data['logs'] = array_values(array_filter((array) $data['logs'], static function ($log) {
                $target = isset($log['target_blog_id']) ? (int) $log['target_blog_id'] : 0;
                return $target === 0 || $target === get_current_blog_id();
            }));
        }

        wp_send_json_success($data);
    }

    public static function save_cell()
    {
        self::verify_access();
        TGS_SMR_Helper::verify_nonce();
        $request_id = absint($_POST['request_id'] ?? 0);
        $request_item_id = absint($_POST['request_item_id'] ?? 0);
        $request_shop_id = absint($_POST['request_shop_id'] ?? 0);
        $max_qty = sanitize_text_field($_POST['max_qty'] ?? '');
        $note = sanitize_textarea_field($_POST['note'] ?? '');
        $source = sanitize_key($_POST['source'] ?? 'shop');

        $result = TGS_SMR_Repository::update_cell($request_id, $request_item_id, $request_shop_id, $max_qty, $note, $source);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success(['saved' => true]);
    }

    public static function save_item_sku()
    {
        self::verify_access();
        TGS_SMR_Helper::verify_nonce();
        $request_id = absint($_POST['request_id'] ?? 0);
        $request_item_id = absint($_POST['request_item_id'] ?? 0);
        $product_sku = sanitize_text_field($_POST['product_sku'] ?? '');

        $result = TGS_SMR_Repository::update_item_sku($request_id, $request_item_id, $product_sku);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success(array_merge(['saved' => true], $result));
    }

    public static function save_item_name()
    {
        self::verify_access();
        TGS_SMR_Helper::verify_nonce();
        $request_id = absint($_POST['request_id'] ?? 0);
        $request_item_id = absint($_POST['request_item_id'] ?? 0);
        $product_name = sanitize_textarea_field($_POST['product_name'] ?? '');

        $result = TGS_SMR_Repository::update_item_name($request_id, $request_item_id, $product_name);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success(['saved' => true, 'product_name' => $result]);
    }

    public static function update_status()
    {
        self::verify_access();
        TGS_SMR_Helper::verify_nonce();
        $request_id = absint($_POST['request_id'] ?? 0);
        $status = sanitize_key($_POST['status'] ?? '');
        $result = TGS_SMR_Repository::update_request_status($request_id, $status);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }
        wp_send_json_success(['status' => $status]);
    }

    public static function delete_item()
    {
        self::verify_access();
        TGS_SMR_Helper::verify_nonce();
        $request_id = absint($_POST['request_id'] ?? 0);
        $request_item_id = absint($_POST['request_item_id'] ?? 0);
        $result = TGS_SMR_Repository::delete_item($request_id, $request_item_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }
        wp_send_json_success(['deleted' => true]);
    }

    public static function apply_request()
    {
        self::verify_access();
        TGS_SMR_Helper::verify_nonce();
        $request_id = absint($_POST['request_id'] ?? 0);
        $result = TGS_SMR_Repository::apply_request($request_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }
        wp_send_json_success(['applied_count' => (int) $result]);
    }

    public static function search_global_products()
    {
        self::verify_access();
        TGS_SMR_Helper::verify_nonce();
        global $wpdb;
        $keyword = trim(sanitize_text_field($_POST['keyword'] ?? ''));
        if ($keyword === '') {
            wp_send_json_success(['items' => []]);
        }

        $like = '%' . $wpdb->esc_like($keyword) . '%';
        $table = $wpdb->base_prefix . 'global_product_name';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT global_product_name_id, global_product_sku, global_product_name, global_product_thumbnail, global_product_barcode_main, global_product_price_after_tax
             FROM {$table}
             WHERE is_deleted = 0
               AND (global_product_name LIKE %s OR global_product_sku LIKE %s OR global_product_barcode_main LIKE %s)
             ORDER BY updated_at DESC, global_product_name_id DESC
             LIMIT 30",
            $like,
            $like,
            $like
        ), ARRAY_A);

        wp_send_json_success(['items' => $rows ?: []]);
    }

    public static function payload_shops()
    {
        self::verify_access();
        TGS_SMR_Helper::verify_nonce();
        wp_send_json_success(TGS_SMR_Repository::available_shops_payload());
    }
}
