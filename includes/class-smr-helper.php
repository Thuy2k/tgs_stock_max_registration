<?php

if (!defined('ABSPATH')) {
    exit;
}

class TGS_SMR_Helper
{
    private static $blog_site_code_cache = [];
    private static $blog_site_code_column_exists = null;
    private static $blog_web_label_cache = [];

    public static function table($suffix)
    {
        global $wpdb;
        return $wpdb->base_prefix . 'global_stock_max_' . $suffix;
    }

    public static function now()
    {
        return current_time('mysql');
    }

    public static function verify_nonce()
    {
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'tgs_smr_nonce')) {
            wp_send_json_error(['message' => 'Phiên làm việc không hợp lệ.'], 403);
        }
    }

    public static function current_blog_name()
    {
        return self::site_name(get_current_blog_id());
    }

    public static function site_name($blog_id)
    {
        $blog_id = (int) $blog_id;
        if ($blog_id < 0) {
            return 'Shop demo ' . str_pad((string) abs($blog_id), 2, '0', STR_PAD_LEFT);
        }

        $sites_info = self::sites_info();
        if (isset($sites_info[$blog_id]['name']) && $sites_info[$blog_id]['name'] !== '') {
            return (string) $sites_info[$blog_id]['name'];
        }

        $name = get_blog_option($blog_id, 'blogname');
        return $name ? (string) $name : 'Site #' . $blog_id;
    }

    public static function site_code($blog_id, $site_info = [])
    {
        $blog_id = (int) $blog_id;
        if ($blog_id < 0) {
            return 'DEMO' . str_pad((string) abs($blog_id), 2, '0', STR_PAD_LEFT);
        }

        $blogs_table_code = self::site_code_from_blogs_table($blog_id);
        if ($blogs_table_code !== '') {
            return $blogs_table_code;
        }

        $custom = isset($site_info['custom_data']) && is_array($site_info['custom_data'])
            ? $site_info['custom_data']
            : [];

        foreach (['code', 'site_code', 'shop_code', 'ma_shop', 'warehouse_code'] as $key) {
            if (!empty($site_info[$key])) {
                return (string) $site_info[$key];
            }
            if (!empty($custom[$key])) {
                return (string) $custom[$key];
            }
        }

        return (string) $blog_id;
    }

    public static function prime_site_code_cache($blog_ids)
    {
        global $wpdb;

        if (empty($wpdb->blogs)) {
            return;
        }

        $blog_ids = array_values(array_unique(array_filter(array_map('intval', (array) $blog_ids), static function ($blog_id) {
            return $blog_id > 0;
        })));

        $missing = array_values(array_filter($blog_ids, static function ($blog_id) {
            return !array_key_exists($blog_id, self::$blog_site_code_cache);
        }));

        if (empty($missing)) {
            return;
        }

        if (self::$blog_site_code_column_exists === null) {
            $blogs_table = esc_sql($wpdb->blogs);
            self::$blog_site_code_column_exists = (bool) $wpdb->get_var("SHOW COLUMNS FROM {$blogs_table} LIKE 'tgs_site_code'");
        }

        if (!self::$blog_site_code_column_exists) {
            foreach ($missing as $blog_id) {
                self::$blog_site_code_cache[$blog_id] = '';
            }
            return;
        }

        $blogs_table = esc_sql($wpdb->blogs);
        $placeholders = implode(',', array_fill(0, count($missing), '%d'));
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT blog_id, tgs_site_code FROM {$blogs_table} WHERE blog_id IN ({$placeholders})",
            ...$missing
        ), ARRAY_A);

        foreach ($missing as $blog_id) {
            self::$blog_site_code_cache[$blog_id] = '';
        }

        foreach ($rows as $row) {
            $row_blog_id = isset($row['blog_id']) ? (int) $row['blog_id'] : 0;
            if ($row_blog_id > 0) {
                self::$blog_site_code_cache[$row_blog_id] = trim((string) ($row['tgs_site_code'] ?? ''));
            }
        }
    }

    public static function site_code_from_blogs_table($blog_id)
    {
        $blog_id = (int) $blog_id;
        if ($blog_id <= 0) {
            return '';
        }

        self::prime_site_code_cache([$blog_id]);
        return self::$blog_site_code_cache[$blog_id] ?? '';
    }

    public static function site_web_label($blog_id)
    {
        $blog_id = (int) $blog_id;
        if ($blog_id <= 0) {
            return '';
        }

        if (array_key_exists($blog_id, self::$blog_web_label_cache)) {
            return self::$blog_web_label_cache[$blog_id];
        }

        $site = function_exists('get_site') ? get_site($blog_id) : null;
        if (!$site && function_exists('get_blog_details')) {
            $site = get_blog_details($blog_id);
        }

        $domain = isset($site->domain) ? trim((string) $site->domain) : '';
        $path = isset($site->path) ? trim((string) $site->path) : '';
        $label = trim($domain . $path);
        $label = $label !== '' ? rtrim($label, '/') : '';

        self::$blog_web_label_cache[$blog_id] = $label;
        return $label;
    }

    public static function sites_info()
    {
        if (!class_exists('TGS_Hierarchy_Data')) {
            $hierarchy_file = WP_PLUGIN_DIR . '/tgs-multisite-hierarchy/includes/class-hierarchy-data.php';
            $main_file = WP_PLUGIN_DIR . '/tgs-multisite-hierarchy/tgs-multisite-hierarchy.php';
            if (!defined('TGS_HIERARCHY_OPTION_KEY')) {
                define('TGS_HIERARCHY_OPTION_KEY', 'tgs_multisite_hierarchy_data');
            }
            if (!defined('TGS_HIERARCHY_VERSION')) {
                define('TGS_HIERARCHY_VERSION', '1.0.0');
            }
            if (file_exists($hierarchy_file)) {
                require_once $hierarchy_file;
            } elseif (file_exists($main_file)) {
                require_once $main_file;
            }
        }

        if (class_exists('TGS_Hierarchy_Data')) {
            $sites = TGS_Hierarchy_Data::get_sites_info();
            return is_array($sites) ? $sites : [];
        }

        return [];
    }

    public static function hierarchy()
    {
        self::sites_info();
        if (class_exists('TGS_Hierarchy_Data')) {
            $hierarchy = TGS_Hierarchy_Data::get_hierarchy();
            return is_array($hierarchy) ? $hierarchy : [];
        }

        return [];
    }

    public static function descendants($blog_id)
    {
        $blog_id = (int) $blog_id;
        self::sites_info();
        if (class_exists('TGS_Hierarchy_Data')) {
            $descendants = TGS_Hierarchy_Data::get_all_descendants($blog_id);
            return array_values(array_unique(array_map('intval', (array) $descendants)));
        }

        return [];
    }

    public static function children_count_map()
    {
        $hierarchy = self::hierarchy();
        $count = [];
        foreach ($hierarchy as $bid => $parent_id) {
            if ($parent_id === null || $parent_id === '' || (int) $parent_id === 0) {
                continue;
            }
            $parent_id = (int) $parent_id;
            $count[$parent_id] = isset($count[$parent_id]) ? $count[$parent_id] + 1 : 1;
        }
        return $count;
    }

    public static function is_warehouse_blog($blog_id)
    {
        $blog_id = (int) $blog_id;
        $sites_info = self::sites_info();
        $children_count = self::children_count_map();
        $type = isset($sites_info[$blog_id]['type']) ? strtolower((string) $sites_info[$blog_id]['type']) : '';

        return in_array($type, ['warehouse', 'kho', 'branch', 'chi_nhanh'], true)
            || !empty($children_count[$blog_id]);
    }

    public static function real_target_sites($source_blog_id, $include_source = false)
    {
        $source_blog_id = (int) $source_blog_id;
        $sites_info = self::sites_info();
        $ids = self::descendants($source_blog_id);

        if (empty($ids) && is_multisite()) {
            $ids = array_map('intval', get_sites([
                'fields' => 'ids',
                'number' => 1000,
                'orderby' => 'blog_id',
                'order' => 'ASC',
            ]));
            $ids = array_values(array_filter($ids, static function ($id) use ($source_blog_id) {
                return (int) $id !== $source_blog_id;
            }));
        }

        if ($include_source) {
            array_unshift($ids, $source_blog_id);
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        self::prime_site_code_cache($ids);

        $targets = [];
        foreach ($ids as $id) {
            if ($id <= 0) {
                continue;
            }
            $info = isset($sites_info[$id]) && is_array($sites_info[$id]) ? $sites_info[$id] : [];
            if (!$include_source && self::is_warehouse_blog($id)) {
                continue;
            }
            $targets[] = [
                'blog_id' => $id,
                'name' => self::site_name($id),
                'code' => self::site_code($id, $info),
                'site_url' => self::site_web_label($id),
                'is_fake' => 0,
                'site_type' => self::is_warehouse_blog($id) ? 1 : 0,
            ];
        }

        return $targets;
    }

    public static function demo_sites($count, $start = 1)
    {
        $count = max(0, min(150, (int) $count));
        $start = max(1, (int) $start);
        $sites = [];

        for ($i = 0; $i < $count; $i++) {
            $n = $start + $i;
            $sites[] = [
                'blog_id' => -$n,
                'name' => 'Shop demo ' . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
                'code' => 'D' . str_pad((string) $n, 3, '0', STR_PAD_LEFT),
                'is_fake' => 1,
                'site_type' => 0,
            ];
        }

        return $sites;
    }

    public static function status_label($status)
    {
        $map = [
            'draft' => 'Nháp',
            'open' => 'Đang đăng ký',
            'approved' => 'Đã duyệt',
            'cancelled' => 'Đã hủy',
            'applied' => 'Đã áp dụng',
        ];

        return $map[$status] ?? (string) $status;
    }

    public static function format_decimal($value)
    {
        if ($value === null || $value === '') {
            return '';
        }

        $num = (float) $value;
        if (floor($num) === $num) {
            return (string) (int) $num;
        }

        return rtrim(rtrim(number_format($num, 3, '.', ''), '0'), '.');
    }

    public static function normalize_qty($value)
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }

        if (!is_numeric($value)) {
            return false;
        }

        $num = (float) $value;
        if ($num < 0) {
            return false;
        }

        return $num;
    }
}
