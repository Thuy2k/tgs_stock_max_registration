<?php

if (!defined('ABSPATH')) {
    exit;
}

class TGS_SMR_Helper
{
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
                'number' => 200,
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

        $targets = [];
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
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
