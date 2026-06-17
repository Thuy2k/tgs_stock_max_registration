<?php

if (!defined('ABSPATH')) {
    exit;
}

class TGS_SMR_Repository
{
    public static function tables()
    {
        return [
            'product' => TGS_SMR_Helper::table('temp_product'),
            'request' => TGS_SMR_Helper::table('request'),
            'item' => TGS_SMR_Helper::table('request_item'),
            'shop' => TGS_SMR_Helper::table('request_shop'),
            'value' => TGS_SMR_Helper::table('request_value'),
            'log' => TGS_SMR_Helper::table('request_log'),
            'stock_config' => $GLOBALS['wpdb']->base_prefix . 'global_sku_stock_config',
        ];
    }

    public static function list_temp_products($args = [])
    {
        global $wpdb;
        $t = self::tables();
        $source_blog_id = (int) ($args['source_blog_id'] ?? 0);
        $search = trim((string) ($args['search'] ?? ''));

        $where = ['is_deleted = 0'];
        $params = [];

        if ($source_blog_id > 0) {
            $where[] = 'source_blog_id = %d';
            $params[] = $source_blog_id;
        }

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(product_name LIKE %s OR product_sku LIKE %s OR supplier_barcode LIKE %s)';
            array_push($params, $like, $like, $like);
        }

        $sql = "SELECT * FROM {$t['product']} WHERE " . implode(' AND ', $where) . " ORDER BY updated_at DESC, temp_product_id DESC LIMIT 200";
        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        return array_map([__CLASS__, 'object_to_array'], (array) $wpdb->get_results($sql));
    }

    public static function save_temp_product($data)
    {
        global $wpdb;
        $t = self::tables();
        $id = (int) ($data['temp_product_id'] ?? 0);
        $now = TGS_SMR_Helper::now();
        $row = [
            'global_product_name_id' => !empty($data['global_product_name_id']) ? (int) $data['global_product_name_id'] : null,
            'product_sku' => self::nullable_text($data['product_sku'] ?? ''),
            'product_name' => sanitize_textarea_field($data['product_name'] ?? ''),
            'product_description' => sanitize_textarea_field($data['product_description'] ?? ''),
            'thumbnail_url' => esc_url_raw($data['thumbnail_url'] ?? ''),
            'supplier_barcode' => sanitize_text_field($data['supplier_barcode'] ?? ''),
            'suggested_price' => ($data['suggested_price'] ?? '') === '' ? null : (float) $data['suggested_price'],
            'product_meta' => self::json_encode_safe($data['product_meta'] ?? []),
            'source_blog_id' => (int) ($data['source_blog_id'] ?? get_current_blog_id()),
            'source_blog_name_cache' => TGS_SMR_Helper::site_name((int) ($data['source_blog_id'] ?? get_current_blog_id())),
            'updated_by' => get_current_user_id(),
            'updated_at' => $now,
        ];

        if ($row['product_name'] === '') {
            return new WP_Error('missing_name', 'Vui lòng nhập tên sản phẩm.');
        }

        if ($id > 0) {
            $wpdb->update($t['product'], $row, ['temp_product_id' => $id]);
            return $id;
        }

        $row['created_by'] = get_current_user_id();
        $row['created_at'] = $now;
        $wpdb->insert($t['product'], $row);
        return (int) $wpdb->insert_id;
    }

    public static function delete_temp_product($id)
    {
        global $wpdb;
        $t = self::tables();
        return (bool) $wpdb->update($t['product'], [
            'is_deleted' => 1,
            'deleted_at' => TGS_SMR_Helper::now(),
            'updated_by' => get_current_user_id(),
            'updated_at' => TGS_SMR_Helper::now(),
        ], ['temp_product_id' => (int) $id]);
    }

    public static function get_temp_products_by_ids($ids)
    {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', (array) $ids)));
        if (empty($ids)) {
            return [];
        }

        $t = self::tables();
        $in = implode(',', $ids);
        $rows = $wpdb->get_results("SELECT * FROM {$t['product']} WHERE temp_product_id IN ({$in}) AND is_deleted = 0");
        $map = [];
        foreach ((array) $rows as $row) {
            $map[(int) $row->temp_product_id] = self::object_to_array($row);
        }

        return $map;
    }

    public static function list_requests($args = [])
    {
        global $wpdb;
        $t = self::tables();
        $mode = sanitize_key($args['mode'] ?? 'warehouse');
        $status = sanitize_key($args['status'] ?? '');
        $search = trim((string) ($args['search'] ?? ''));
        $blog_id = (int) ($args['blog_id'] ?? get_current_blog_id());
        $where = ['1=1'];
        $params = [];

        if ($mode === 'shop') {
            $where[] = "EXISTS (
                SELECT 1 FROM {$t['shop']} s
                WHERE s.request_id = r.request_id
                  AND s.target_blog_id = %d
                  AND s.is_fake = 0
            )";
            $params[] = $blog_id;
        } else {
            $where[] = 'r.source_blog_id = %d';
            $params[] = $blog_id;
        }

        if ($status !== '') {
            $where[] = 'r.status = %s';
            $params[] = $status;
        }

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(r.request_code LIKE %s OR r.request_title LIKE %s OR r.note LIKE %s)';
            array_push($params, $like, $like, $like);
        }

        $sql = "SELECT r.*,
                       (SELECT COUNT(*) FROM {$t['shop']} s WHERE s.request_id = r.request_id AND s.is_fake = 0) AS real_shop_count,
                       (SELECT COUNT(*) FROM {$t['value']} v WHERE v.request_id = r.request_id AND v.max_qty IS NOT NULL) AS filled_cells
                FROM {$t['request']} r
                WHERE " . implode(' AND ', $where) . "
                ORDER BY r.created_at DESC, r.request_id DESC
                LIMIT 200";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $rows = array_map([__CLASS__, 'object_to_array'], (array) $wpdb->get_results($sql));
        foreach ($rows as &$row) {
            $row['status_label'] = TGS_SMR_Helper::status_label($row['status'] ?? '');
        }
        unset($row);

        return $rows;
    }

    public static function create_request($data)
    {
        global $wpdb;
        $t = self::tables();
        $source_blog_id = (int) ($data['source_blog_id'] ?? get_current_blog_id());
        $title = sanitize_text_field($data['request_title'] ?? '');
        $note = sanitize_textarea_field($data['note'] ?? '');
        $temp_product_ids = array_values(array_filter(array_map('intval', (array) ($data['temp_product_ids'] ?? []))));
        $shop_ids = array_values(array_unique(array_map('intval', (array) ($data['shop_ids'] ?? []))));
        $include_demo = !empty($data['include_demo']);
        $demo_count = max(0, min(150, (int) ($data['demo_count'] ?? 0)));

        if ($title === '') {
            $title = 'Đăng ký tồn max ' . date_i18n('d/m/Y H:i');
        }

        if (empty($temp_product_ids)) {
            return new WP_Error('missing_products', 'Vui lòng chọn ít nhất 1 sản phẩm.');
        }

        $products = self::get_temp_products_by_ids($temp_product_ids);
        if (empty($products)) {
            return new WP_Error('invalid_products', 'Không tìm thấy sản phẩm đã chọn.');
        }

        $real_targets = TGS_SMR_Helper::real_target_sites($source_blog_id, false);
        $real_map = [];
        foreach ($real_targets as $target) {
            $real_map[(int) $target['blog_id']] = $target;
        }

        if (!empty($shop_ids)) {
            $real_targets = [];
            foreach ($shop_ids as $sid) {
                if (isset($real_map[$sid])) {
                    $real_targets[] = $real_map[$sid];
                }
            }
        }

        if (empty($real_targets) && !$include_demo) {
            return new WP_Error('missing_shops', 'Chưa có shop con nào để tạo phiếu.');
        }

        $demo_targets = $include_demo ? TGS_SMR_Helper::demo_sites($demo_count, 1) : [];
        $targets = array_merge($real_targets, $demo_targets);
        if (empty($targets)) {
            return new WP_Error('missing_shops', 'Vui long chon shop hoac bat shop demo.');
        }

        $now = TGS_SMR_Helper::now();
        $code = self::generate_request_code($source_blog_id);
        $wpdb->query('START TRANSACTION');

        $ok = $wpdb->insert($t['request'], [
            'request_code' => $code,
            'request_title' => $title,
            'source_blog_id' => $source_blog_id,
            'source_blog_name_cache' => TGS_SMR_Helper::site_name($source_blog_id),
            'status' => 'open',
            'note' => $note,
            'item_count' => count($products),
            'shop_count' => count($targets),
            'fake_shop_count' => count($demo_targets),
            'request_meta' => self::json_encode_safe([
                'selected_temp_product_ids' => $temp_product_ids,
                'created_from' => 'tgs_stock_max_registration',
            ]),
            'created_by' => get_current_user_id(),
            'updated_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (!$ok) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('db_error', 'Không tạo được phiếu.');
        }

        $request_id = (int) $wpdb->insert_id;
        $item_ids = [];
        $order = 1;
        foreach ($temp_product_ids as $pid) {
            if (empty($products[$pid])) {
                continue;
            }
            $p = $products[$pid];
            $wpdb->insert($t['item'], [
                'request_id' => $request_id,
                'temp_product_id' => $pid,
                'global_product_name_id' => !empty($p['global_product_name_id']) ? (int) $p['global_product_name_id'] : null,
                'product_sku' => self::nullable_text($p['product_sku'] ?? ''),
                'product_name' => (string) ($p['product_name'] ?? ''),
                'thumbnail_url' => (string) ($p['thumbnail_url'] ?? ''),
                'suggested_price' => ($p['suggested_price'] ?? '') === '' ? null : (float) $p['suggested_price'],
                'item_order' => $order++,
                'item_meta' => self::json_encode_safe([
                    'supplier_barcode' => $p['supplier_barcode'] ?? '',
                    'product_description' => $p['product_description'] ?? '',
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $item_ids[] = (int) $wpdb->insert_id;
        }

        $shop_ids_inserted = [];
        $order = 1;
        foreach ($targets as $target) {
            $wpdb->insert($t['shop'], [
                'request_id' => $request_id,
                'target_blog_id' => (int) $target['blog_id'],
                'target_blog_name_cache' => (string) $target['name'],
                'target_blog_code_cache' => (string) $target['code'],
                'is_fake' => (int) $target['is_fake'],
                'status' => 'pending',
                'shop_order' => $order++,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $shop_ids_inserted[] = [
                'request_shop_id' => (int) $wpdb->insert_id,
                'target_blog_id' => (int) $target['blog_id'],
                'is_fake' => (int) $target['is_fake'],
            ];
        }

        foreach ($item_ids as $item_id) {
            foreach ($shop_ids_inserted as $shop) {
                $wpdb->insert($t['value'], [
                    'request_id' => $request_id,
                    'request_item_id' => $item_id,
                    'request_shop_id' => (int) $shop['request_shop_id'],
                    'target_blog_id' => (int) $shop['target_blog_id'],
                    'is_fake' => (int) $shop['is_fake'],
                    'max_qty' => null,
                    'note' => '',
                    'value_source' => 'shop',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        self::add_log($request_id, [
            'action' => 'create_request',
            'note' => 'Tạo phiếu đăng ký tồn max',
            'new_value' => $code,
        ], false);

        $wpdb->query('COMMIT');
        return $request_id;
    }

    public static function get_request_matrix($request_id)
    {
        global $wpdb;
        $request_id = (int) $request_id;
        $t = self::tables();
        $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['request']} WHERE request_id = %d", $request_id), ARRAY_A);
        if (!$request) {
            return null;
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t['item']} WHERE request_id = %d ORDER BY item_order ASC, request_item_id ASC",
            $request_id
        ), ARRAY_A);

        $shops = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t['shop']} WHERE request_id = %d ORDER BY shop_order ASC, request_shop_id ASC",
            $request_id
        ), ARRAY_A);

        $values = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t['value']} WHERE request_id = %d",
            $request_id
        ), ARRAY_A);

        $value_map = [];
        foreach ((array) $values as $value) {
            $value_map[(int) $value['request_item_id']][(int) $value['request_shop_id']] = $value;
        }

        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t['log']} WHERE request_id = %d ORDER BY created_at DESC, log_id DESC LIMIT 120",
            $request_id
        ), ARRAY_A);

        $request['status_label'] = TGS_SMR_Helper::status_label($request['status']);
        return [
            'request' => $request,
            'items' => $items ?: [],
            'shops' => $shops ?: [],
            'values' => $value_map,
            'logs' => $logs ?: [],
            'export_url' => self::export_url($request_id),
        ];
    }

    public static function can_view_request($request)
    {
        $blog_id = get_current_blog_id();
        if ((int) ($request['source_blog_id'] ?? 0) === $blog_id) {
            return true;
        }

        global $wpdb;
        $t = self::tables();
        $request_id = (int) ($request['request_id'] ?? 0);
        if (!$request_id) {
            return false;
        }

        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT request_shop_id FROM {$t['shop']} WHERE request_id = %d AND target_blog_id = %d AND is_fake = 0 LIMIT 1",
            $request_id,
            $blog_id
        ));

        return $exists > 0;
    }

    public static function update_cell($request_id, $request_item_id, $request_shop_id, $max_qty, $note, $source)
    {
        global $wpdb;
        $t = self::tables();
        $request_id = (int) $request_id;
        $request_item_id = (int) $request_item_id;
        $request_shop_id = (int) $request_shop_id;
        $source = $source === 'warehouse' ? 'warehouse' : 'shop';
        $qty = TGS_SMR_Helper::normalize_qty($max_qty);

        if ($qty === false) {
            return new WP_Error('invalid_qty', 'Số lượng max không hợp lệ.');
        }

        $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['request']} WHERE request_id = %d", $request_id), ARRAY_A);
        if (!$request) {
            return new WP_Error('missing_request', 'Không tìm thấy phiếu.');
        }

        if (!in_array($request['status'], ['draft', 'open'], true)) {
            return new WP_Error('locked_request', 'Phiếu đã khóa, không thể sửa.');
        }

        $value = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t['value']} WHERE request_id = %d AND request_item_id = %d AND request_shop_id = %d",
            $request_id,
            $request_item_id,
            $request_shop_id
        ), ARRAY_A);

        if (!$value) {
            return new WP_Error('missing_cell', 'Không tìm thấy ô đăng ký.');
        }

        if ($source === 'shop') {
            $shop = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['shop']} WHERE request_shop_id = %d", $request_shop_id), ARRAY_A);
            if (!$shop || (int) $shop['is_fake'] === 1 || (int) $shop['target_blog_id'] !== get_current_blog_id()) {
                return new WP_Error('forbidden', 'Shop chỉ được sửa phiếu của mình.');
            }
        } elseif ((int) $request['source_blog_id'] !== get_current_blog_id()) {
            return new WP_Error('forbidden', 'Chỉ kho tạo phiếu được sửa tổng quan.');
        }

        $old = [
            'max_qty' => $value['max_qty'],
            'note' => $value['note'],
        ];
        $now = TGS_SMR_Helper::now();
        $data = [
            'max_qty' => $qty,
            'note' => sanitize_textarea_field($note),
            'value_source' => $source,
            'updated_by' => get_current_user_id(),
            'updated_at' => $now,
        ];
        if ($source === 'shop') {
            $data['submitted_by'] = get_current_user_id();
            $data['submitted_at'] = $now;
        }

        $wpdb->update($t['value'], $data, ['value_id' => (int) $value['value_id']]);
        if ($source === 'shop') {
            $wpdb->update($t['shop'], [
                'status' => 'submitted',
                'submitted_by' => get_current_user_id(),
                'submitted_at' => $now,
                'updated_at' => $now,
            ], ['request_shop_id' => $request_shop_id]);
        }

        self::add_log($request_id, [
            'request_item_id' => $request_item_id,
            'request_shop_id' => $request_shop_id,
            'target_blog_id' => (int) $value['target_blog_id'],
            'is_fake' => (int) $value['is_fake'],
            'action' => $source === 'warehouse' ? 'warehouse_update_cell' : 'shop_update_cell',
            'old_value' => self::json_encode_safe($old),
            'new_value' => self::json_encode_safe([
                'max_qty' => $qty,
                'note' => sanitize_textarea_field($note),
            ]),
            'note' => $source === 'warehouse' ? 'Kho cập nhật số liệu đăng ký' : 'Shop cập nhật đăng ký',
        ]);

        return true;
    }

    public static function delete_item($request_id, $request_item_id)
    {
        global $wpdb;
        $t = self::tables();
        $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['request']} WHERE request_id = %d", (int) $request_id), ARRAY_A);
        if (!$request || (int) $request['source_blog_id'] !== get_current_blog_id()) {
            return new WP_Error('forbidden', 'Bạn không có quyền xóa dòng này.');
        }
        if (!in_array($request['status'], ['draft', 'open'], true)) {
            return new WP_Error('locked_request', 'Phiếu đã khóa, không thể xóa dòng.');
        }

        $wpdb->delete($t['value'], ['request_item_id' => (int) $request_item_id]);
        $wpdb->delete($t['item'], ['request_item_id' => (int) $request_item_id, 'request_id' => (int) $request_id]);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$t['request']} SET item_count = GREATEST(item_count - 1, 0), updated_at = %s, updated_by = %d WHERE request_id = %d",
            TGS_SMR_Helper::now(),
            get_current_user_id(),
            (int) $request_id
        ));
        self::add_log((int) $request_id, [
            'request_item_id' => (int) $request_item_id,
            'action' => 'delete_item',
            'note' => 'Kho xóa dòng sản phẩm khỏi phiếu',
        ]);

        return true;
    }

    public static function update_request_status($request_id, $status)
    {
        global $wpdb;
        $t = self::tables();
        $request_id = (int) $request_id;
        $status = sanitize_key($status);
        if (!in_array($status, ['approved', 'cancelled'], true)) {
            return new WP_Error('invalid_status', 'Trạng thái không hợp lệ.');
        }

        $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['request']} WHERE request_id = %d", $request_id), ARRAY_A);
        if (!$request || (int) $request['source_blog_id'] !== get_current_blog_id()) {
            return new WP_Error('forbidden', 'Chỉ kho tạo phiếu được đổi trạng thái.');
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

        $wpdb->update($t['request'], $data, ['request_id' => $request_id]);
        self::add_log($request_id, [
            'action' => $status === 'approved' ? 'approve_request' : 'cancel_request',
            'old_value' => $request['status'],
            'new_value' => $status,
            'note' => $status === 'approved' ? 'Kho duyệt phiếu' : 'Kho hủy phiếu',
        ]);

        return true;
    }

    public static function apply_request($request_id)
    {
        global $wpdb;
        $t = self::tables();
        $request_id = (int) $request_id;
        $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['request']} WHERE request_id = %d", $request_id), ARRAY_A);
        if (!$request || (int) $request['source_blog_id'] !== get_current_blog_id()) {
            return new WP_Error('forbidden', 'Chỉ kho tạo phiếu được áp dụng.');
        }
        if (!in_array($request['status'], ['approved', 'applied'], true)) {
            return new WP_Error('not_approved', 'Cần duyệt phiếu trước khi áp dụng.');
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT v.*, i.product_sku, i.product_name, s.target_blog_name_cache
             FROM {$t['value']} v
             INNER JOIN {$t['item']} i ON i.request_item_id = v.request_item_id
             INNER JOIN {$t['shop']} s ON s.request_shop_id = v.request_shop_id
             WHERE v.request_id = %d
               AND v.is_fake = 0
               AND v.max_qty IS NOT NULL
               AND i.product_sku IS NOT NULL
               AND i.product_sku <> ''",
            $request_id
        ), ARRAY_A);

        $now = TGS_SMR_Helper::now();
        $applied = 0;
        foreach ((array) $rows as $row) {
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
                (string) $row['product_sku'],
                (string) $row['product_name'],
                (int) $row['target_blog_id'],
                (string) $row['target_blog_name_cache'],
                (float) $row['max_qty'],
                'Áp dụng từ phiếu ' . $request['request_code'],
                get_current_user_id(),
                $now,
                $now
            ));
            $applied++;
        }

        $wpdb->update($t['request'], [
            'status' => 'applied',
            'applied_by' => get_current_user_id(),
            'applied_at' => $now,
            'updated_by' => get_current_user_id(),
            'updated_at' => $now,
        ], ['request_id' => $request_id]);

        self::add_log($request_id, [
            'action' => 'apply_request',
            'new_value' => (string) $applied,
            'note' => 'Áp dụng tồn max vào cấu hình shop thật',
        ]);

        return $applied;
    }

    public static function available_shops_payload()
    {
        $real = TGS_SMR_Helper::real_target_sites(get_current_blog_id(), false);
        return [
            'real_shops' => $real,
            'recommended_demo_count' => max(0, 65 - count($real)),
            'is_warehouse' => TGS_SMR_Helper::is_warehouse_blog(get_current_blog_id()),
        ];
    }

    public static function export_url($request_id)
    {
        return add_query_arg([
            'action' => 'tgs_smr_export_request',
            'request_id' => (int) $request_id,
            '_wpnonce' => wp_create_nonce('tgs_smr_export_' . (int) $request_id),
        ], admin_url('admin-post.php'));
    }

    private static function generate_request_code($source_blog_id)
    {
        global $wpdb;
        $t = self::tables();
        $base = 'DKMAX-' . (int) $source_blog_id . '-' . date_i18n('Ymd-His');
        $code = $base;
        $i = 1;
        while ((int) $wpdb->get_var($wpdb->prepare("SELECT request_id FROM {$t['request']} WHERE request_code = %s LIMIT 1", $code)) > 0) {
            $code = $base . '-' . $i++;
        }
        return $code;
    }

    private static function add_log($request_id, $data, $inside_transaction = true)
    {
        global $wpdb;
        $t = self::tables();
        $wpdb->insert($t['log'], [
            'request_id' => (int) $request_id,
            'request_item_id' => !empty($data['request_item_id']) ? (int) $data['request_item_id'] : null,
            'request_shop_id' => !empty($data['request_shop_id']) ? (int) $data['request_shop_id'] : null,
            'target_blog_id' => isset($data['target_blog_id']) ? (int) $data['target_blog_id'] : null,
            'is_fake' => !empty($data['is_fake']) ? 1 : 0,
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

    private static function nullable_text($value)
    {
        $value = trim(sanitize_text_field((string) $value));
        return $value === '' ? null : $value;
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

    private static function object_to_array($row)
    {
        return is_array($row) ? $row : (array) $row;
    }
}
