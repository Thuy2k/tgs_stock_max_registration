<?php

if (!defined('ABSPATH')) {
    exit;
}

class TGS_SMR_Repository
{
    public static function tables()
    {
        return [
            'request' => TGS_SMR_Helper::table('request'),
            'item' => TGS_SMR_Helper::table('request_item'),
            'shop' => TGS_SMR_Helper::table('request_shop'),
            'value' => TGS_SMR_Helper::table('request_value'),
            'log' => TGS_SMR_Helper::table('request_log'),
            'stock_config' => $GLOBALS['wpdb']->base_prefix . 'global_sku_stock_config',
            'global_product' => $GLOBALS['wpdb']->base_prefix . 'global_product_name',
        ];
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
        $products = self::normalize_request_products($data['products'] ?? []);
        $shop_ids = array_values(array_unique(array_map('intval', (array) ($data['shop_ids'] ?? []))));
        $include_demo = false;
        $demo_count = 0;

        if (is_wp_error($products)) {
            return $products;
        }

        if ($title === '') {
            $title = 'Đăng ký tồn max ' . date_i18n('d/m/Y H:i');
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
            return new WP_Error('missing_shops', 'Vui lòng chọn shop thật.');
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
                'product_payload_version' => 2,
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
        foreach ($products as $p) {
            $inserted = $wpdb->insert($t['item'], [
                'request_id' => $request_id,
                'global_product_name_id' => !empty($p['global_product_name_id']) ? (int) $p['global_product_name_id'] : null,
                'product_sku' => self::nullable_text($p['product_sku'] ?? ''),
                'product_name' => (string) ($p['product_name'] ?? ''),
                'thumbnail_url' => (string) ($p['thumbnail_url'] ?? ''),
                'suggested_price' => ($p['suggested_price'] ?? '') === '' ? null : (float) $p['suggested_price'],
                'item_order' => $order++,
                'item_meta' => self::json_encode_safe([
                    'supplier_barcode' => $p['supplier_barcode'] ?? '',
                    'product_description' => $p['product_description'] ?? '',
                    'source' => !empty($p['global_product_name_id']) ? 'global_product' : 'manual_entry',
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (!$inserted) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('db_error', 'Không lưu được dòng sản phẩm trong phiếu.');
            }

            $item_ids[] = (int) $wpdb->insert_id;
        }

        $shop_ids_inserted = [];
        $order = 1;
        foreach ($targets as $target) {
            $inserted = $wpdb->insert($t['shop'], [
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

            if (!$inserted) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('db_error', 'Không lưu được danh sách shop trong phiếu.');
            }

            $shop_ids_inserted[] = [
                'request_shop_id' => (int) $wpdb->insert_id,
                'target_blog_id' => (int) $target['blog_id'],
                'is_fake' => (int) $target['is_fake'],
            ];
        }

        foreach ($item_ids as $item_id) {
            foreach ($shop_ids_inserted as $shop) {
                $inserted = $wpdb->insert($t['value'], [
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

                if (!$inserted) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error('db_error', 'Không tạo được ô đăng ký cho shop.');
                }
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

    private static function normalize_request_products($products)
    {
        $products = is_array($products) ? array_values($products) : [];
        if (empty($products)) {
            return new WP_Error('missing_products', 'Vui lòng thêm ít nhất 1 sản phẩm vào phiếu.');
        }

        $normalized = [];
        foreach ($products as $index => $product) {
            if (!is_array($product)) {
                continue;
            }

            $global_product = null;
            $global_id = (int) ($product['global_product_name_id'] ?? 0);
            if ($global_id > 0) {
                $global_product = self::find_global_product_by_id($global_id);
                if (!$global_product) {
                    return new WP_Error('invalid_global_product', 'Sản phẩm global ở dòng ' . ($index + 1) . ' không còn tồn tại. Vui lòng tìm chọn lại.');
                }
            }

            $name = trim(sanitize_textarea_field($product['product_name'] ?? ''));
            if ($name === '' && $global_product) {
                $name = (string) ($global_product['global_product_name'] ?? '');
            }

            $image = esc_url_raw($product['thumbnail_url'] ?? '');
            if ($name === '') {
                return new WP_Error('missing_name', 'Vui lòng nhập tên hàng cho dòng ' . ($index + 1) . '.');
            }
            if ($image === '') {
                return new WP_Error('missing_image', 'Vui lòng chọn hoặc dán URL ảnh cho dòng ' . ($index + 1) . '.');
            }

            $price_raw = isset($product['suggested_price']) ? trim((string) $product['suggested_price']) : '';
            $price = $price_raw === '' ? null : max(0, (float) $price_raw);

            $normalized[] = [
                'global_product_name_id' => $global_product ? (int) $global_product['global_product_name_id'] : null,
                'product_sku' => $global_product ? self::nullable_text($global_product['global_product_sku'] ?? '') : null,
                'product_name' => $name,
                'thumbnail_url' => $image,
                'suggested_price' => $price,
                'supplier_barcode' => sanitize_text_field($product['supplier_barcode'] ?? ''),
                'product_description' => sanitize_textarea_field($product['product_description'] ?? ''),
            ];
        }

        if (empty($normalized)) {
            return new WP_Error('missing_products', 'Vui lòng thêm ít nhất 1 sản phẩm vào phiếu.');
        }

        return $normalized;
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

    public static function update_item_sku($request_id, $request_item_id, $product_sku)
    {
        global $wpdb;
        $t = self::tables();
        $request_id = (int) $request_id;
        $request_item_id = (int) $request_item_id;
        $sku = self::nullable_text($product_sku);
        if (!$sku) {
            return new WP_Error('missing_sku', 'Vui lòng nhập mã SKU.');
        }

        $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['request']} WHERE request_id = %d", $request_id), ARRAY_A);
        if (!$request || (int) $request['source_blog_id'] !== get_current_blog_id()) {
            return new WP_Error('forbidden', 'Chỉ kho tạo phiếu được sửa mã SKU.');
        }

        if (!in_array($request['status'], ['draft', 'open', 'approved'], true)) {
            return new WP_Error('locked_request', 'Phiếu đã áp dụng hoặc đã hủy, không thể sửa SKU.');
        }

        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t['item']} WHERE request_id = %d AND request_item_id = %d",
            $request_id,
            $request_item_id
        ), ARRAY_A);

        if (!$item) {
            return new WP_Error('missing_item', 'Không tìm thấy dòng sản phẩm.');
        }

        $global_product = self::find_global_product_by_sku($sku);
        if (!$global_product) {
            return new WP_Error(
                'invalid_global_sku',
                'Mã SKU "' . $sku . '" chưa tồn tại trong bảng sản phẩm global. Vui lòng tạo sản phẩm global trước rồi nhập lại SKU.'
            );
        }

        $sku = (string) $global_product['global_product_sku'];

        $wpdb->update($t['item'], [
            'global_product_name_id' => (int) $global_product['global_product_name_id'],
            'product_sku' => $sku,
            'updated_at' => TGS_SMR_Helper::now(),
        ], [
            'request_id' => $request_id,
            'request_item_id' => $request_item_id,
        ]);

        self::add_log($request_id, [
            'request_item_id' => $request_item_id,
            'action' => 'update_item_sku',
            'old_value' => (string) ($item['product_sku'] ?? ''),
            'new_value' => (string) ($sku ?? ''),
            'note' => 'Kho cập nhật mã SKU cho dòng sản phẩm',
        ]);

        return [
            'global_product_name_id' => (int) $global_product['global_product_name_id'],
            'global_product_name' => (string) ($global_product['global_product_name'] ?? ''),
            'product_sku' => $sku,
        ];
    }

    public static function update_item_name($request_id, $request_item_id, $product_name)
    {
        global $wpdb;
        $t = self::tables();
        $request_id = (int) $request_id;
        $request_item_id = (int) $request_item_id;
        $name = trim(sanitize_textarea_field((string) $product_name));

        if ($name === '') {
            return new WP_Error('missing_name', 'Vui lòng nhập tên hàng.');
        }

        $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['request']} WHERE request_id = %d", $request_id), ARRAY_A);
        if (!$request || (int) $request['source_blog_id'] !== get_current_blog_id()) {
            return new WP_Error('forbidden', 'Chỉ kho tạo phiếu được sửa tên hàng.');
        }

        if (!in_array($request['status'], ['draft', 'open', 'approved'], true)) {
            return new WP_Error('locked_request', 'Phiếu đã áp dụng hoặc đã hủy, không thể sửa tên hàng.');
        }

        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t['item']} WHERE request_id = %d AND request_item_id = %d",
            $request_id,
            $request_item_id
        ), ARRAY_A);

        if (!$item) {
            return new WP_Error('missing_item', 'Không tìm thấy dòng sản phẩm.');
        }

        $wpdb->update($t['item'], [
            'product_name' => $name,
            'updated_at' => TGS_SMR_Helper::now(),
        ], [
            'request_id' => $request_id,
            'request_item_id' => $request_item_id,
        ]);

        self::add_log($request_id, [
            'request_item_id' => $request_item_id,
            'action' => 'update_item_name',
            'old_value' => (string) ($item['product_name'] ?? ''),
            'new_value' => $name,
            'note' => 'Kho cập nhật tên hàng cho dòng sản phẩm',
        ]);

        return $name;
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

        $missing_sku_items = $wpdb->get_results($wpdb->prepare(
            "SELECT request_item_id, product_name
             FROM {$t['item']}
             WHERE request_id = %d
               AND (product_sku IS NULL OR TRIM(product_sku) = '')
             ORDER BY item_order ASC, request_item_id ASC",
            $request_id
        ), ARRAY_A);

        if (!empty($missing_sku_items)) {
            $names = array_map(static function ($item) {
                return (string) ($item['product_name'] ?? '');
            }, array_slice($missing_sku_items, 0, 5));
            $message = 'Không thể áp dụng max vì còn dòng chưa có mã SKU: ' . implode(', ', array_filter($names)) . '. Vui lòng cập nhật SKU trước.';
            return new WP_Error('missing_item_sku', $message);
        }

        $invalid_sku_items = $wpdb->get_results($wpdb->prepare(
            "SELECT i.request_item_id, i.product_name, i.product_sku
             FROM {$t['item']} i
             LEFT JOIN {$t['global_product']} gp
                    ON gp.global_product_sku = i.product_sku
                   AND gp.is_deleted = 0
             WHERE i.request_id = %d
               AND i.product_sku IS NOT NULL
               AND TRIM(i.product_sku) <> ''
               AND gp.global_product_name_id IS NULL
             ORDER BY i.item_order ASC, i.request_item_id ASC",
            $request_id
        ), ARRAY_A);

        if (!empty($invalid_sku_items)) {
            $items = array_map(static function ($item) {
                $sku = (string) ($item['product_sku'] ?? '');
                $name = (string) ($item['product_name'] ?? '');
                return $name !== '' ? $name . ' (' . $sku . ')' : $sku;
            }, array_slice($invalid_sku_items, 0, 5));
            $message = 'Không thể áp dụng max vì còn SKU chưa tồn tại trong bảng sản phẩm global: ' . implode(', ', array_filter($items)) . '. Vui lòng tạo sản phẩm global trước rồi lưu lại SKU.';
            return new WP_Error('invalid_item_sku', $message);
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
            'recommended_demo_count' => 0,
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

    private static function find_global_product_by_sku($sku)
    {
        global $wpdb;
        $sku = trim(sanitize_text_field((string) $sku));
        if ($sku === '') {
            return null;
        }

        $t = self::tables();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT global_product_name_id, global_product_sku, global_product_name
             FROM {$t['global_product']}
             WHERE is_deleted = 0
               AND global_product_sku = %s
             LIMIT 1",
            $sku
        ), ARRAY_A);
    }

    private static function find_global_product_by_id($id)
    {
        global $wpdb;
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }

        $t = self::tables();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT global_product_name_id, global_product_sku, global_product_name
             FROM {$t['global_product']}
             WHERE is_deleted = 0
               AND global_product_name_id = %d
             LIMIT 1",
            $id
        ), ARRAY_A);
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
