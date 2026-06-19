<?php

if (!defined('ABSPATH')) {
    exit;
}

class TGS_SMR_Existing_Repository
{
    public static function tables()
    {
        global $wpdb;
        return [
            'request' => TGS_SMR_Helper::table('existing_request'),
            'item' => TGS_SMR_Helper::table('existing_item'),
            'log' => TGS_SMR_Helper::table('existing_log'),
            'stock_config' => $wpdb->base_prefix . 'global_sku_stock_config',
            'global_product' => $wpdb->base_prefix . 'global_product_name',
        ];
    }

    public static function status_label($status)
    {
        $map = [
            'draft' => 'Nháp',
            'submitted' => 'Chờ kho duyệt',
            'approved' => 'Đã duyệt',
            'cancelled' => 'Đã hủy',
            'applied' => 'Đã cập nhật max',
        ];

        return $map[$status] ?? (string) $status;
    }

    public static function list_requests($args = [])
    {
        global $wpdb;
        $t = self::tables();
        $mode = sanitize_key($args['mode'] ?? 'shop');
        $status = sanitize_key($args['status'] ?? '');
        $search = trim((string) ($args['search'] ?? ''));
        $blog_id = get_current_blog_id();
        $where = ['1=1'];
        $params = [];

        if ($mode === 'warehouse') {
            if (!TGS_SMR_Helper::is_warehouse_blog($blog_id) && !current_user_can('manage_options')) {
                return [];
            }

            $shop_ids = self::warehouse_visible_shop_ids($blog_id);
            if (!empty($shop_ids)) {
                $placeholders = implode(',', array_fill(0, count($shop_ids), '%d'));
                $where[] = "(r.warehouse_blog_id = %d OR r.shop_blog_id IN ({$placeholders}))";
                $params[] = $blog_id;
                foreach ($shop_ids as $sid) {
                    $params[] = (int) $sid;
                }
            } else {
                $where[] = 'r.warehouse_blog_id = %d';
                $params[] = $blog_id;
            }
        } else {
            $where[] = 'r.shop_blog_id = %d';
            $params[] = $blog_id;
        }

        if ($status !== '') {
            $where[] = 'r.status = %s';
            $params[] = $status;
        }

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = "(r.request_code LIKE %s OR r.request_title LIKE %s OR r.note LIKE %s OR r.warehouse_note LIKE %s OR r.shop_blog_name_cache LIKE %s OR EXISTS (
                SELECT 1 FROM {$t['item']} i
                WHERE i.request_id = r.request_id
                  AND (i.product_sku LIKE %s OR i.product_name LIKE %s OR i.shop_note LIKE %s OR i.warehouse_note LIKE %s)
            ))";
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        $sql = "SELECT r.*
                FROM {$t['request']} r
                WHERE " . implode(' AND ', $where) . "
                ORDER BY FIELD(r.status, 'submitted', 'draft', 'approved', 'applied', 'cancelled'), r.updated_at DESC, r.request_id DESC
                LIMIT 300";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
        foreach ($rows as &$row) {
            $row = self::format_request_row($row);
            $row['summary'] = self::request_item_summary((int) $row['request_id']);
        }
        unset($row);

        return $rows;
    }

    public static function products_payload($args = [])
    {
        global $wpdb;
        $t = self::tables();
        $keyword = trim(sanitize_text_field($args['search'] ?? ''));
        $all = !empty($args['all']);
        $limit = $all ? 20000 : 250;
        $where = ["gp.is_deleted = 0", "gp.global_product_sku IS NOT NULL", "TRIM(gp.global_product_sku) <> ''"];
        $params = [];

        if ($keyword !== '') {
            $tokens = preg_split('/\s+/', mb_strtolower($keyword, 'UTF-8'));
            foreach ((array) $tokens as $token) {
                $token = trim((string) $token);
                if ($token === '') {
                    continue;
                }
                $like = '%' . $wpdb->esc_like($token) . '%';
                $where[] = "(LOWER(gp.global_product_sku) LIKE %s OR LOWER(gp.global_product_name) LIKE %s OR LOWER(gp.global_product_barcode_main) LIKE %s)";
                array_push($params, $like, $like, $like);
            }
        }

        $sql = "SELECT gp.global_product_name_id,
                       gp.global_product_sku,
                       gp.global_product_name,
                       gp.global_product_thumbnail,
                       gp.global_product_barcode_main
                FROM {$t['global_product']} gp
                WHERE " . implode(' AND ', $where) . "
                ORDER BY gp.global_product_name ASC, gp.global_product_name_id DESC
                LIMIT %d";
        $params[] = $limit;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];
        $skus = array_values(array_filter(array_map(static function ($row) {
            return (string) ($row['global_product_sku'] ?? '');
        }, $rows)));
        $max_map = self::current_max_map($skus, get_current_blog_id());

        foreach ($rows as &$row) {
            $sku = (string) ($row['global_product_sku'] ?? '');
            $config = $max_map[$sku] ?? null;
            $row['current_max_qty'] = $config ? TGS_SMR_Helper::format_decimal($config['max_qty']) : '0';
            $row['current_config_note'] = $config ? (string) ($config['config_note'] ?? '') : '';
            $row['product_sku'] = $sku;
            $row['product_name'] = (string) ($row['global_product_name'] ?? '');
            $row['thumbnail_url'] = (string) ($row['global_product_thumbnail'] ?? '');
        }
        unset($row);

        return [
            'items' => $rows,
            'total_loaded' => count($rows),
            'is_truncated' => count($rows) >= $limit,
        ];
    }

    public static function create_request($data)
    {
        global $wpdb;
        $t = self::tables();
        $shop_blog_id = get_current_blog_id();
        $items = self::normalize_items($data['items'] ?? [], $shop_blog_id);

        if (is_wp_error($items)) {
            return $items;
        }

        if (empty($items)) {
            return new WP_Error('no_changed_items', 'Chưa có dòng nào cập nhật max mới. Dòng trống hoặc không đổi sẽ tự bỏ qua.');
        }

        $title = trim(sanitize_text_field($data['request_title'] ?? ''));
        if ($title === '') {
            $title = 'Đăng ký cập nhật Max ' . date_i18n('d/m/Y H:i');
        }

        $note = sanitize_textarea_field($data['note'] ?? '');
        $warehouse_blog_id = self::warehouse_for_shop($shop_blog_id);
        $now = TGS_SMR_Helper::now();
        $code = self::generate_request_code($shop_blog_id);

        $wpdb->query('START TRANSACTION');
        $ok = $wpdb->insert($t['request'], [
            'request_code' => $code,
            'request_title' => $title,
            'shop_blog_id' => $shop_blog_id,
            'shop_blog_name_cache' => TGS_SMR_Helper::site_name($shop_blog_id),
            'shop_blog_code_cache' => TGS_SMR_Helper::site_code($shop_blog_id),
            'warehouse_blog_id' => $warehouse_blog_id ?: null,
            'warehouse_blog_name_cache' => $warehouse_blog_id ? TGS_SMR_Helper::site_name($warehouse_blog_id) : '',
            'status' => 'submitted',
            'note' => $note,
            'item_count' => count($items),
            'changed_count' => count($items),
            'request_meta' => self::json_encode_safe(['source' => 'existing_sku_stock_max']),
            'created_by' => get_current_user_id(),
            'updated_by' => get_current_user_id(),
            'submitted_by' => get_current_user_id(),
            'submitted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (!$ok) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('db_error', 'Không tạo được phiếu đăng ký max.');
        }

        $request_id = (int) $wpdb->insert_id;
        $order = 1;
        foreach ($items as $item) {
            $inserted = $wpdb->insert($t['item'], [
                'request_id' => $request_id,
                'global_product_name_id' => (int) $item['global_product_name_id'],
                'product_sku' => (string) $item['product_sku'],
                'product_name' => (string) $item['product_name'],
                'thumbnail_url' => (string) $item['thumbnail_url'],
                'current_max_qty' => (float) $item['current_max_qty'],
                'proposed_max_qty' => (float) $item['proposed_max_qty'],
                'current_config_note' => (string) ($item['current_config_note'] ?? ''),
                'shop_note' => (string) ($item['shop_note'] ?? ''),
                'item_order' => $order++,
                'item_meta' => self::json_encode_safe([
                    'barcode' => $item['barcode'] ?? '',
                    'created_snapshot_blog_id' => $shop_blog_id,
                ]),
                'created_by' => get_current_user_id(),
                'updated_by' => get_current_user_id(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (!$inserted) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('db_error', 'Không lưu được dòng SKU trong phiếu.');
            }
        }

        self::add_log($request_id, [
            'action' => 'create_existing_request',
            'new_value' => $code,
            'note' => 'Shop tạo phiếu đăng ký cập nhật tồn max cho SKU đã có',
            'log_meta' => ['items' => self::items_for_log($items)],
        ]);

        $wpdb->query('COMMIT');
        return $request_id;
    }

    public static function get_request($request_id)
    {
        global $wpdb;
        $t = self::tables();
        $request_id = (int) $request_id;
        $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['request']} WHERE request_id = %d", $request_id), ARRAY_A);
        if (!$request) {
            return null;
        }
        if (!self::can_view_request($request)) {
            return new WP_Error('forbidden', 'Bạn không có quyền xem phiếu này.');
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t['item']} WHERE request_id = %d ORDER BY item_order ASC, item_id ASC",
            $request_id
        ), ARRAY_A) ?: [];

        $skus = array_values(array_filter(array_map(static function ($item) {
            return (string) ($item['product_sku'] ?? '');
        }, $items)));
        $latest = self::current_max_map($skus, (int) $request['shop_blog_id']);
        foreach ($items as &$item) {
            $sku = (string) $item['product_sku'];
            $latest_max = isset($latest[$sku]) ? (float) $latest[$sku]['max_qty'] : 0.0;
            $snapshot = (float) $item['current_max_qty'];
            $item['latest_max_qty'] = TGS_SMR_Helper::format_decimal($latest_max);
            $item['current_max_qty_display'] = TGS_SMR_Helper::format_decimal($snapshot);
            $item['proposed_max_qty_display'] = TGS_SMR_Helper::format_decimal($item['proposed_max_qty']);
            $item['warehouse_max_qty_display'] = TGS_SMR_Helper::format_decimal($item['warehouse_max_qty']);
            $item['effective_max_qty'] = TGS_SMR_Helper::format_decimal($item['warehouse_max_qty'] !== null && $item['warehouse_max_qty'] !== '' ? $item['warehouse_max_qty'] : $item['proposed_max_qty']);
            $item['snapshot_changed'] = abs($latest_max - $snapshot) > 0.0001;
        }
        unset($item);

        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t['log']} WHERE request_id = %d ORDER BY created_at DESC, log_id DESC LIMIT 200",
            $request_id
        ), ARRAY_A) ?: [];

        return [
            'request' => self::format_request_row($request),
            'items' => $items,
            'logs' => $logs,
            'stats' => self::request_stats($items),
            'can_shop_edit' => self::can_shop_edit($request),
            'can_warehouse_review' => self::can_warehouse_review($request),
            'can_apply' => self::can_apply($request),
            'export_url' => self::export_url($request_id),
        ];
    }

    public static function save_shop_request($request_id, $data)
    {
        global $wpdb;
        $t = self::tables();
        $request = self::raw_request($request_id);
        if (!$request || !self::can_shop_edit($request)) {
            return new WP_Error('locked_request', 'Phiếu đã khóa hoặc bạn không có quyền sửa.');
        }

        $items = self::normalize_items($data['items'] ?? [], (int) $request['shop_blog_id']);
        if (is_wp_error($items)) {
            return $items;
        }
        if (empty($items)) {
            return new WP_Error('no_changed_items', 'Cần giữ ít nhất một dòng có Max mới khác Max hiện tại.');
        }

        $now = TGS_SMR_Helper::now();
        $wpdb->query('START TRANSACTION');
        $wpdb->update($t['request'], [
            'request_title' => trim(sanitize_text_field($data['request_title'] ?? '')) ?: $request['request_title'],
            'note' => sanitize_textarea_field($data['note'] ?? ''),
            'item_count' => count($items),
            'changed_count' => count($items),
            'updated_by' => get_current_user_id(),
            'updated_at' => $now,
        ], ['request_id' => (int) $request_id]);

        $wpdb->delete($t['item'], ['request_id' => (int) $request_id]);
        $order = 1;
        foreach ($items as $item) {
            $wpdb->insert($t['item'], [
                'request_id' => (int) $request_id,
                'global_product_name_id' => (int) $item['global_product_name_id'],
                'product_sku' => (string) $item['product_sku'],
                'product_name' => (string) $item['product_name'],
                'thumbnail_url' => (string) $item['thumbnail_url'],
                'current_max_qty' => (float) $item['current_max_qty'],
                'proposed_max_qty' => (float) $item['proposed_max_qty'],
                'current_config_note' => (string) ($item['current_config_note'] ?? ''),
                'shop_note' => (string) ($item['shop_note'] ?? ''),
                'item_order' => $order++,
                'item_meta' => self::json_encode_safe(['barcode' => $item['barcode'] ?? '']),
                'created_by' => get_current_user_id(),
                'updated_by' => get_current_user_id(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        self::add_log((int) $request_id, [
            'action' => 'shop_update_request',
            'note' => 'Shop cập nhật lại phiếu khi chưa được kho duyệt',
            'new_value' => (string) count($items),
            'log_meta' => ['items' => self::items_for_log($items)],
        ]);
        $wpdb->query('COMMIT');

        return true;
    }

    public static function save_warehouse_review($request_id, $data)
    {
        global $wpdb;
        $t = self::tables();
        $request = self::raw_request($request_id);
        if (!$request || !self::can_warehouse_review($request)) {
            return new WP_Error('forbidden', 'Phiếu đã khóa hoặc bạn không có quyền rà soát.');
        }

        $now = TGS_SMR_Helper::now();
        $wpdb->update($t['request'], [
            'warehouse_note' => sanitize_textarea_field($data['warehouse_note'] ?? ''),
            'updated_by' => get_current_user_id(),
            'updated_at' => $now,
        ], ['request_id' => (int) $request_id]);

        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $log_items = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item_id = absint($row['item_id'] ?? 0);
            if (!$item_id) {
                continue;
            }
            $warehouse_max = array_key_exists('warehouse_max_qty', $row)
                ? TGS_SMR_Helper::normalize_qty($row['warehouse_max_qty'])
                : null;
            $update = [
                'warehouse_note' => sanitize_textarea_field($row['warehouse_note'] ?? ''),
                'updated_by' => get_current_user_id(),
                'updated_at' => $now,
            ];
            if ($warehouse_max !== false) {
                $update['warehouse_max_qty'] = $warehouse_max;
            }
            $wpdb->update($t['item'], $update, [
                'request_id' => (int) $request_id,
                'item_id' => $item_id,
            ]);
            $log_items[] = [
                'item_id' => $item_id,
                'warehouse_max_qty' => $warehouse_max,
                'warehouse_note' => sanitize_textarea_field($row['warehouse_note'] ?? ''),
            ];
        }

        self::add_log((int) $request_id, [
            'action' => 'warehouse_review_note',
            'note' => 'Kho cập nhật ghi chú rà soát phiếu',
            'log_meta' => ['items' => $log_items],
        ]);

        return true;
    }

    public static function update_status($request_id, $status)
    {
        global $wpdb;
        $t = self::tables();
        $request = self::raw_request($request_id);
        if (!$request || !self::can_warehouse_control($request)) {
            return new WP_Error('forbidden', 'Chỉ kho quản lý shop này được đổi trạng thái phiếu.');
        }

        $status = sanitize_key($status);
        if (!in_array($status, ['approved', 'cancelled'], true)) {
            return new WP_Error('invalid_status', 'Trạng thái không hợp lệ.');
        }
        if (in_array($request['status'], ['applied', 'cancelled'], true)) {
            return new WP_Error('locked_request', 'Phiếu đã khóa, không thể đổi trạng thái.');
        }

        $now = TGS_SMR_Helper::now();
        $data = [
            'status' => $status,
            'updated_by' => get_current_user_id(),
            'updated_at' => $now,
        ];
        if ($status === 'approved') {
            $data['approved_by'] = get_current_user_id();
            $data['approved_at'] = $now;
        } else {
            $data['cancelled_by'] = get_current_user_id();
            $data['cancelled_at'] = $now;
        }

        $wpdb->update($t['request'], $data, ['request_id' => (int) $request_id]);
        self::add_log((int) $request_id, [
            'action' => $status === 'approved' ? 'warehouse_approve' : 'warehouse_cancel',
            'old_value' => (string) $request['status'],
            'new_value' => $status,
            'note' => $status === 'approved' ? 'Kho duyệt phiếu' : 'Kho hủy phiếu',
        ]);

        return true;
    }

    public static function apply_request($request_id)
    {
        global $wpdb;
        $t = self::tables();
        $request = self::raw_request($request_id);
        if (!$request || !self::can_warehouse_control($request)) {
            return new WP_Error('forbidden', 'Chỉ kho quản lý shop này được cập nhật max.');
        }
        if (!in_array($request['status'], ['approved', 'applied'], true)) {
            return new WP_Error('not_approved', 'Cần duyệt phiếu trước khi cập nhật max cho shop.');
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t['item']} WHERE request_id = %d ORDER BY item_order ASC, item_id ASC",
            (int) $request_id
        ), ARRAY_A) ?: [];
        if (empty($items)) {
            return new WP_Error('missing_items', 'Phiếu chưa có dòng SKU để cập nhật.');
        }

        $now = TGS_SMR_Helper::now();
        $applied = 0;
        $log_items = [];
        foreach ($items as $item) {
            $max = $item['warehouse_max_qty'] !== null && $item['warehouse_max_qty'] !== ''
                ? (float) $item['warehouse_max_qty']
                : (float) $item['proposed_max_qty'];
            $note = trim('Cập nhật từ phiếu ' . $request['request_code'] . "\n"
                . 'Ghi chú shop: ' . (string) ($item['shop_note'] ?? '') . "\n"
                . 'Ghi chú kho: ' . (string) ($item['warehouse_note'] ?? ''));

            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$t['stock_config']}
                    (product_sku, product_name, blog_id, site_type, blog_name_cache, min_qty, max_qty, is_active, config_note, user_id, created_at, updated_at)
                 VALUES (%s, %s, %d, 0, %s, 0, %f, 1, %s, %d, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    product_name = VALUES(product_name),
                    site_type = VALUES(site_type),
                    blog_name_cache = VALUES(blog_name_cache),
                    max_qty = VALUES(max_qty),
                    is_active = 1,
                    config_note = VALUES(config_note),
                    user_id = VALUES(user_id),
                    updated_at = VALUES(updated_at)",
                (string) $item['product_sku'],
                (string) $item['product_name'],
                (int) $request['shop_blog_id'],
                (string) $request['shop_blog_name_cache'],
                $max,
                $note,
                get_current_user_id(),
                $now,
                $now
            ));
            $applied++;
            $log_items[] = [
                'sku' => (string) $item['product_sku'],
                'product_name' => (string) $item['product_name'],
                'applied_max_qty' => $max,
                'shop_note' => (string) ($item['shop_note'] ?? ''),
                'warehouse_note' => (string) ($item['warehouse_note'] ?? ''),
            ];
        }

        $wpdb->update($t['request'], [
            'status' => 'applied',
            'applied_by' => get_current_user_id(),
            'applied_at' => $now,
            'updated_by' => get_current_user_id(),
            'updated_at' => $now,
        ], ['request_id' => (int) $request_id]);

        self::add_log((int) $request_id, [
            'action' => 'warehouse_apply_max',
            'new_value' => (string) $applied,
            'note' => 'Kho cập nhật tồn max vào cấu hình shop',
            'log_meta' => ['items' => $log_items],
        ]);

        return $applied;
    }

    public static function export_url($request_id)
    {
        return add_query_arg([
            'action' => 'tgs_smr_existing_export_request',
            'request_id' => (int) $request_id,
            '_wpnonce' => wp_create_nonce('tgs_smr_existing_export_' . (int) $request_id),
        ], admin_url('admin-post.php'));
    }

    public static function can_view_request($request)
    {
        $blog_id = get_current_blog_id();
        if ((int) ($request['shop_blog_id'] ?? 0) === $blog_id) {
            return true;
        }
        return self::can_warehouse_control($request);
    }

    private static function normalize_items($items, $shop_blog_id)
    {
        $items = is_array($items) ? array_values($items) : [];
        $skus = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sku = trim(sanitize_text_field($row['product_sku'] ?? ''));
            if ($sku !== '') {
                $skus[] = $sku;
            }
        }
        $skus = array_values(array_unique($skus));
        if (empty($skus)) {
            return [];
        }

        $products = self::global_products_by_sku($skus);
        $max_map = self::current_max_map($skus, $shop_blog_id);
        $normalized = [];
        $seen = [];

        foreach ($items as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $sku = trim(sanitize_text_field($row['product_sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            if (isset($seen[$sku])) {
                continue;
            }
            $seen[$sku] = true;
            if (empty($products[$sku])) {
                return new WP_Error('invalid_sku', 'SKU ' . $sku . ' không tồn tại trong bảng sản phẩm global.');
            }
            $proposed = TGS_SMR_Helper::normalize_qty($row['proposed_max_qty'] ?? '');
            if ($proposed === null) {
                continue;
            }
            if ($proposed === false) {
                return new WP_Error('invalid_qty', 'Max mới của SKU ' . $sku . ' không hợp lệ.');
            }

            $current = isset($max_map[$sku]) ? (float) $max_map[$sku]['max_qty'] : 0.0;
            if (abs($proposed - $current) <= 0.0001) {
                continue;
            }

            $product = $products[$sku];
            $normalized[] = [
                'global_product_name_id' => (int) ($product['global_product_name_id'] ?? 0),
                'product_sku' => (string) ($product['global_product_sku'] ?? $sku),
                'product_name' => (string) ($product['global_product_name'] ?? ''),
                'thumbnail_url' => (string) ($product['global_product_thumbnail'] ?? ''),
                'barcode' => (string) ($product['global_product_barcode_main'] ?? ''),
                'current_max_qty' => $current,
                'proposed_max_qty' => (float) $proposed,
                'current_config_note' => isset($max_map[$sku]) ? (string) ($max_map[$sku]['config_note'] ?? '') : '',
                'shop_note' => sanitize_textarea_field($row['shop_note'] ?? ''),
                'item_order' => $index + 1,
            ];
        }

        return $normalized;
    }

    private static function current_max_map($skus, $blog_id)
    {
        global $wpdb;
        $t = self::tables();
        $skus = array_values(array_unique(array_filter(array_map('strval', (array) $skus))));
        if (empty($skus)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($skus), '%s'));
        $params = array_merge([$blog_id], $skus);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT product_sku, max_qty, config_note
             FROM {$t['stock_config']}
             WHERE blog_id = %d
               AND site_type = 0
               AND product_sku IN ({$placeholders})",
            ...$params
        ), ARRAY_A) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['product_sku']] = $row;
        }
        return $map;
    }

    private static function global_products_by_sku($skus)
    {
        global $wpdb;
        $t = self::tables();
        $skus = array_values(array_unique(array_filter(array_map('strval', (array) $skus))));
        if (empty($skus)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($skus), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT global_product_name_id, global_product_sku, global_product_name, global_product_thumbnail, global_product_barcode_main
             FROM {$t['global_product']}
             WHERE is_deleted = 0
               AND global_product_sku IN ({$placeholders})",
            ...$skus
        ), ARRAY_A) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['global_product_sku']] = $row;
        }
        return $map;
    }

    private static function warehouse_for_shop($shop_blog_id)
    {
        $shop_blog_id = (int) $shop_blog_id;
        $hierarchy = TGS_SMR_Helper::hierarchy();
        $seen = [];
        $current = $shop_blog_id;
        while (isset($hierarchy[$current]) && !isset($seen[$current])) {
            $seen[$current] = true;
            $parent = (int) $hierarchy[$current];
            if ($parent <= 0) {
                break;
            }
            if (TGS_SMR_Helper::is_warehouse_blog($parent)) {
                return $parent;
            }
            $current = $parent;
        }
        return 0;
    }

    private static function warehouse_visible_shop_ids($warehouse_blog_id)
    {
        $ids = TGS_SMR_Helper::real_target_sites($warehouse_blog_id, false);
        return array_values(array_unique(array_map(static function ($site) {
            return (int) ($site['blog_id'] ?? 0);
        }, $ids)));
    }

    private static function can_warehouse_control($request)
    {
        $blog_id = get_current_blog_id();
        if (current_user_can('manage_options')) {
            return true;
        }
        if (!TGS_SMR_Helper::is_warehouse_blog($blog_id)) {
            return false;
        }
        if ((int) ($request['warehouse_blog_id'] ?? 0) === $blog_id) {
            return true;
        }
        $visible = self::warehouse_visible_shop_ids($blog_id);
        return in_array((int) ($request['shop_blog_id'] ?? 0), $visible, true);
    }

    private static function can_shop_edit($request)
    {
        return (int) ($request['shop_blog_id'] ?? 0) === get_current_blog_id()
            && in_array((string) ($request['status'] ?? ''), ['draft', 'submitted'], true);
    }

    private static function can_warehouse_review($request)
    {
        return self::can_warehouse_control($request)
            && in_array((string) ($request['status'] ?? ''), ['submitted', 'approved'], true);
    }

    private static function can_apply($request)
    {
        return self::can_warehouse_control($request)
            && in_array((string) ($request['status'] ?? ''), ['approved', 'applied'], true);
    }

    private static function request_stats($items)
    {
        $snapshot_changed = 0;
        foreach ((array) $items as $item) {
            if (!empty($item['snapshot_changed'])) {
                $snapshot_changed++;
            }
        }
        return [
            'item_count' => count((array) $items),
            'snapshot_changed' => $snapshot_changed,
        ];
    }

    private static function request_item_summary($request_id)
    {
        global $wpdb;
        $t = self::tables();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT product_sku, product_name FROM {$t['item']} WHERE request_id = %d ORDER BY item_order ASC, item_id ASC LIMIT 3",
            (int) $request_id
        ), ARRAY_A) ?: [];

        return implode(' · ', array_map(static function ($row) {
            return trim((string) ($row['product_sku'] ?? '') . ' - ' . (string) ($row['product_name'] ?? ''));
        }, $rows));
    }

    private static function items_for_log($items)
    {
        return array_map(static function ($item) {
            return [
                'sku' => (string) ($item['product_sku'] ?? ''),
                'product_name' => (string) ($item['product_name'] ?? ''),
                'old_max_qty' => isset($item['current_max_qty']) ? (float) $item['current_max_qty'] : null,
                'new_max_qty' => isset($item['proposed_max_qty']) ? (float) $item['proposed_max_qty'] : null,
                'shop_note' => (string) ($item['shop_note'] ?? ''),
            ];
        }, (array) $items);
    }

    private static function raw_request($request_id)
    {
        global $wpdb;
        $t = self::tables();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['request']} WHERE request_id = %d", (int) $request_id), ARRAY_A);
    }

    private static function format_request_row($row)
    {
        $row['status_label'] = self::status_label($row['status'] ?? '');
        $row['mode_label'] = (int) ($row['shop_blog_id'] ?? 0) === get_current_blog_id() ? 'Shop' : 'Kho';
        return $row;
    }

    private static function generate_request_code($shop_blog_id)
    {
        global $wpdb;
        $t = self::tables();
        $base = 'DKMAXSKU-' . (int) $shop_blog_id . '-' . date_i18n('Ymd-His');
        $code = $base;
        $i = 1;
        while ((int) $wpdb->get_var($wpdb->prepare("SELECT request_id FROM {$t['request']} WHERE request_code = %s LIMIT 1", $code)) > 0) {
            $code = $base . '-' . $i++;
        }
        return $code;
    }

    private static function add_log($request_id, $data)
    {
        global $wpdb;
        $t = self::tables();
        $wpdb->insert($t['log'], [
            'request_id' => (int) $request_id,
            'item_id' => !empty($data['item_id']) ? (int) $data['item_id'] : null,
            'actor_blog_id' => get_current_blog_id(),
            'actor_user_id' => get_current_user_id(),
            'action' => sanitize_key($data['action'] ?? ''),
            'old_value' => isset($data['old_value']) ? (string) $data['old_value'] : null,
            'new_value' => isset($data['new_value']) ? (string) $data['new_value'] : null,
            'note' => isset($data['note']) ? sanitize_textarea_field($data['note']) : '',
            'log_meta' => isset($data['log_meta']) ? self::json_encode_safe($data['log_meta']) : null,
            'created_at' => TGS_SMR_Helper::now(),
        ]);
    }

    private static function json_encode_safe($value)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }
        $encoded = wp_json_encode($value, JSON_UNESCAPED_UNICODE);
        return $encoded ?: '{}';
    }
}
