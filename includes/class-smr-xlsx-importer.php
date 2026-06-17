<?php

if (!defined('ABSPATH')) {
    exit;
}

class TGS_SMR_Xlsx_Importer
{
    const MAX_ROWS = 5000;
    const PREVIEW_LIMIT = 200;

    const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    const NS_DRAWING = 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing';
    const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    public static function import_uploaded_file($file)
    {
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        if (empty($file) || empty($file['tmp_name'])) {
            return new WP_Error('missing_file', 'Vui lòng chọn file Excel.');
        }

        if (!empty($file['error'])) {
            return new WP_Error('upload_error', 'Không tải được file Excel lên hệ thống.');
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            return new WP_Error('invalid_file_type', 'Chỉ hỗ trợ file .xlsx có ảnh nhúng trong cột Hình ảnh.');
        }

        if (!class_exists('ZipArchive')) {
            return new WP_Error('missing_zip', 'Máy chủ chưa bật ZipArchive nên chưa đọc được file .xlsx.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($file['tmp_name']);
        if ($opened !== true) {
            return new WP_Error('invalid_xlsx', 'Không mở được file Excel. Vui lòng kiểm tra lại file .xlsx.');
        }

        $sheet_path = self::first_sheet_path($zip);
        if (is_wp_error($sheet_path)) {
            $zip->close();
            return $sheet_path;
        }

        $sheet = self::read_xml($zip, $sheet_path);
        if (!$sheet) {
            $zip->close();
            return new WP_Error('invalid_sheet', 'Không đọc được sheet đầu tiên trong file Excel.');
        }

        $shared_strings = self::shared_strings($zip);
        $image_map = self::sheet_image_map($zip, $sheet_path);
        $image_cache = [];
        $rows = self::sheet_rows($sheet);
        $result_rows = [];
        $items = [];
        $uploaded_images = 0;
        $processed_rows = 0;

        foreach ($rows as $row_xml) {
            $row_number = (int) $row_xml['r'];
            if ($row_number <= 1) {
                continue;
            }

            $cells = self::row_cells($row_xml, $shared_strings);
            $barcode = trim((string) ($cells['A'] ?? ''));
            $name = trim((string) ($cells['B'] ?? ''));
            $image_cell = trim((string) ($cells['C'] ?? ''));
            $price_raw = trim((string) ($cells['D'] ?? ''));
            $has_image = !empty($image_map[$row_number]);

            if ($barcode === '' && $name === '' && $image_cell === '' && $price_raw === '' && !$has_image) {
                continue;
            }

            $processed_rows++;
            if ($processed_rows > self::MAX_ROWS) {
                $zip->close();
                return new WP_Error('too_many_rows', 'File Excel vượt quá ' . self::MAX_ROWS . ' dòng dữ liệu. Vui lòng tách file nhỏ hơn.');
            }

            $errors = [];
            $image_url = '';
            if ($name === '') {
                $errors[] = 'Thiếu tên hàng';
            }

            $price = self::normalize_price($price_raw);
            if (is_wp_error($price)) {
                $errors[] = $price->get_error_message();
                $price = null;
            }

            if ($has_image) {
                $uploaded = self::upload_image_from_zip($zip, $image_map[$row_number], $row_number, $image_cache);
                if (is_wp_error($uploaded)) {
                    $errors[] = $uploaded->get_error_message();
                } else {
                    $image_url = $uploaded['url'];
                    $uploaded_images += !empty($uploaded['new']) ? 1 : 0;
                }
            } elseif ($image_cell !== '' && filter_var($image_cell, FILTER_VALIDATE_URL)) {
                $image_url = esc_url_raw($image_cell);
            }

            if ($image_url === '') {
                $errors[] = 'Thiếu ảnh nhúng ở cột Hình ảnh';
            }

            $row = [
                'row_number' => $row_number,
                'supplier_barcode' => sanitize_text_field($barcode),
                'product_name' => sanitize_textarea_field($name),
                'thumbnail_url' => $image_url,
                'suggested_price' => $price,
                'errors' => $errors,
            ];
            $result_rows[] = $row;

            if (empty($errors)) {
                $items[] = [
                    'global_product_name_id' => '',
                    'product_sku' => '',
                    'supplier_barcode' => $row['supplier_barcode'],
                    'product_name' => $row['product_name'],
                    'thumbnail_url' => $row['thumbnail_url'],
                    'suggested_price' => $row['suggested_price'],
                    'product_description' => '',
                ];
            }
        }

        $zip->close();

        if ($processed_rows === 0) {
            return new WP_Error('empty_file', 'Không thấy dòng dữ liệu nào trong file Excel.');
        }

        return [
            'items' => $items,
            'rows' => array_slice($result_rows, 0, self::PREVIEW_LIMIT),
            'total_rows' => count($result_rows),
            'valid_count' => count($items),
            'error_count' => count($result_rows) - count($items),
            'uploaded_images' => $uploaded_images,
            'preview_limit' => self::PREVIEW_LIMIT,
        ];
    }

    private static function first_sheet_path($zip)
    {
        $workbook = self::read_xml($zip, 'xl/workbook.xml');
        if (!$workbook) {
            return new WP_Error('missing_workbook', 'File Excel thiếu workbook.xml.');
        }

        $workbook->registerXPathNamespace('m', self::NS_MAIN);
        $sheets = $workbook->xpath('//m:sheets/m:sheet');
        if (empty($sheets)) {
            return new WP_Error('missing_sheet', 'File Excel không có sheet dữ liệu.');
        }

        $rel_attrs = $sheets[0]->attributes(self::NS_REL);
        $rid = (string) ($rel_attrs['id'] ?? '');
        $rels = self::relationships($zip, 'xl/_rels/workbook.xml.rels');
        if ($rid !== '' && !empty($rels[$rid]['target'])) {
            return self::resolve_target('xl/workbook.xml', $rels[$rid]['target']);
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private static function shared_strings($zip)
    {
        $xml = self::read_xml($zip, 'xl/sharedStrings.xml');
        if (!$xml) {
            return [];
        }

        $xml->registerXPathNamespace('m', self::NS_MAIN);
        $items = $xml->xpath('//m:si') ?: [];
        $strings = [];
        foreach ($items as $si) {
            $si->registerXPathNamespace('m', self::NS_MAIN);
            $texts = $si->xpath('.//m:t') ?: [];
            $value = '';
            foreach ($texts as $text) {
                $value .= (string) $text;
            }
            $strings[] = $value;
        }
        return $strings;
    }

    private static function sheet_rows($sheet)
    {
        $sheet->registerXPathNamespace('m', self::NS_MAIN);
        return $sheet->xpath('//m:sheetData/m:row') ?: [];
    }

    private static function row_cells($row_xml, $shared_strings)
    {
        $row_xml->registerXPathNamespace('m', self::NS_MAIN);
        $cells = [];
        foreach (($row_xml->xpath('m:c') ?: []) as $cell) {
            $ref = (string) $cell['r'];
            $col = preg_replace('/\d+/', '', $ref);
            if ($col === '') {
                continue;
            }
            $cells[$col] = self::cell_value($cell, $shared_strings);
        }
        return $cells;
    }

    private static function cell_value($cell, $shared_strings)
    {
        $cell->registerXPathNamespace('m', self::NS_MAIN);
        $type = (string) $cell['t'];
        $values = $cell->xpath('m:v') ?: [];
        $raw = isset($values[0]) ? (string) $values[0] : '';

        if ($type === 's') {
            $idx = (int) $raw;
            return isset($shared_strings[$idx]) ? $shared_strings[$idx] : '';
        }

        if ($type === 'inlineStr') {
            $texts = $cell->xpath('.//m:t') ?: [];
            $value = '';
            foreach ($texts as $text) {
                $value .= (string) $text;
            }
            return $value;
        }

        return $raw;
    }

    private static function sheet_image_map($zip, $sheet_path)
    {
        $rels_path = dirname($sheet_path) . '/_rels/' . basename($sheet_path) . '.rels';
        $sheet_rels = self::relationships($zip, $rels_path);
        if (empty($sheet_rels)) {
            return [];
        }

        $images = [];
        foreach ($sheet_rels as $rel) {
            if (strpos($rel['type'], '/drawing') === false) {
                continue;
            }
            $drawing_path = self::resolve_target($sheet_path, $rel['target']);
            $images = self::drawing_image_map($zip, $drawing_path, $images);
        }
        return $images;
    }

    private static function drawing_image_map($zip, $drawing_path, $images)
    {
        $drawing = self::read_xml($zip, $drawing_path);
        if (!$drawing) {
            return $images;
        }

        $drawing_rels = self::relationships($zip, dirname($drawing_path) . '/_rels/' . basename($drawing_path) . '.rels');
        if (empty($drawing_rels)) {
            return $images;
        }

        $drawing->registerXPathNamespace('xdr', self::NS_DRAWING);
        $anchors = array_merge(
            $drawing->xpath('//xdr:oneCellAnchor') ?: [],
            $drawing->xpath('//xdr:twoCellAnchor') ?: []
        );

        foreach ($anchors as $anchor) {
            $anchor->registerXPathNamespace('xdr', self::NS_DRAWING);
            $anchor->registerXPathNamespace('a', self::NS_A);
            $from = $anchor->xpath('./xdr:from') ?: [];
            if (empty($from)) {
                continue;
            }

            $from[0]->registerXPathNamespace('xdr', self::NS_DRAWING);
            $from_col = (int) self::first_xpath_text($from[0], './xdr:col');
            $from_row = (int) self::first_xpath_text($from[0], './xdr:row');
            $to = $anchor->xpath('./xdr:to') ?: [];
            $to_col = $from_col;
            if (!empty($to)) {
                $to[0]->registerXPathNamespace('xdr', self::NS_DRAWING);
                $to_col = (int) self::first_xpath_text($to[0], './xdr:col');
            }

            $covers_image_column = $from_col === 2 || ($from_col <= 2 && $to_col >= 2);
            if (!$covers_image_column) {
                continue;
            }

            $blips = $anchor->xpath('.//a:blip') ?: [];
            if (empty($blips)) {
                continue;
            }

            $attrs = $blips[0]->attributes(self::NS_REL);
            $rid = (string) ($attrs['embed'] ?? '');
            if ($rid === '' || empty($drawing_rels[$rid]['target'])) {
                continue;
            }

            $row_number = $from_row + 1;
            if (empty($images[$row_number])) {
                $images[$row_number] = self::resolve_target($drawing_path, $drawing_rels[$rid]['target']);
            }
        }

        return $images;
    }

    private static function upload_image_from_zip($zip, $path, $row_number, &$cache)
    {
        $bytes = $zip->getFromName($path);
        if ($bytes === false || $bytes === '') {
            return new WP_Error('missing_image_file', 'Không đọc được file ảnh trong Excel');
        }

        $hash = md5($bytes);
        if (!empty($cache[$hash])) {
            return $cache[$hash] + ['new' => false];
        }

        $mime = self::image_mime($bytes, $path);
        if (!$mime) {
            return new WP_Error('unsupported_image', 'Ảnh trong Excel không đúng định dạng JPG/PNG/GIF/WebP');
        }

        $ext = self::image_extension($mime);
        $filename = sanitize_file_name('smr-import-row-' . $row_number . '-' . substr($hash, 0, 10) . '.' . $ext);
        $upload = wp_upload_bits($filename, null, $bytes);
        if (!empty($upload['error'])) {
            return new WP_Error('image_upload_failed', 'Không upload được ảnh: ' . $upload['error']);
        }

        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $mime,
            'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $upload['file']);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        if (!is_wp_error($metadata) && !empty($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        $cache[$hash] = [
            'attachment_id' => (int) $attachment_id,
            'url' => wp_get_attachment_url($attachment_id),
        ];
        return $cache[$hash] + ['new' => true];
    }

    private static function normalize_price($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return new WP_Error('missing_price', 'Thiếu giá bán lẻ đề xuất');
        }

        $clean = preg_replace('/[^\d,.\-]/u', '', $value);
        if ($clean === '' || $clean === '-') {
            return new WP_Error('invalid_price', 'Giá bán lẻ đề xuất không hợp lệ');
        }

        $comma_count = substr_count($clean, ',');
        $dot_count = substr_count($clean, '.');
        if ($comma_count > 0 && $dot_count > 0) {
            $last_comma = strrpos($clean, ',');
            $last_dot = strrpos($clean, '.');
            if ($last_comma > $last_dot) {
                $clean = str_replace('.', '', $clean);
                $clean = str_replace(',', '.', $clean);
            } else {
                $clean = str_replace(',', '', $clean);
            }
        } elseif ($comma_count > 0) {
            $clean = preg_match('/,\d{1,2}$/', $clean) ? str_replace(',', '.', $clean) : str_replace(',', '', $clean);
        } elseif ($dot_count > 0 && !preg_match('/\.\d{1,2}$/', $clean)) {
            $clean = str_replace('.', '', $clean);
        }

        if (!is_numeric($clean)) {
            return new WP_Error('invalid_price', 'Giá bán lẻ đề xuất không hợp lệ');
        }

        return max(0, (float) $clean);
    }

    private static function relationships($zip, $path)
    {
        $xml = self::read_xml($zip, $path);
        if (!$xml) {
            return [];
        }

        $rels = [];
        foreach (($xml->xpath('//*[local-name()="Relationship"]') ?: []) as $rel) {
            $attrs = $rel->attributes();
            $id = (string) ($attrs['Id'] ?? '');
            if ($id === '') {
                continue;
            }
            $rels[$id] = [
                'type' => (string) ($attrs['Type'] ?? ''),
                'target' => (string) ($attrs['Target'] ?? ''),
            ];
        }
        return $rels;
    }

    private static function read_xml($zip, $path)
    {
        $content = $zip->getFromName($path);
        if ($content === false || $content === '') {
            return null;
        }
        return simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) ?: null;
    }

    private static function resolve_target($base_file, $target)
    {
        $target = str_replace('\\', '/', (string) $target);
        if ($target === '') {
            return '';
        }
        if ($target[0] === '/') {
            return self::normalize_path(ltrim($target, '/'));
        }
        return self::normalize_path(dirname($base_file) . '/' . $target);
    }

    private static function normalize_path($path)
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    private static function first_xpath_text($xml, $query)
    {
        $nodes = $xml->xpath($query) ?: [];
        return isset($nodes[0]) ? (string) $nodes[0] : '';
    }

    private static function image_mime($bytes, $path)
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $by_ext = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];
        if (isset($by_ext[$ext])) {
            return $by_ext[$ext];
        }
        if (strncmp($bytes, "\x89PNG", 4) === 0) {
            return 'image/png';
        }
        if (strncmp($bytes, "\xFF\xD8", 2) === 0) {
            return 'image/jpeg';
        }
        if (strncmp($bytes, 'GIF8', 4) === 0) {
            return 'image/gif';
        }
        if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        return '';
    }

    private static function image_extension($mime)
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        return $map[$mime] ?? 'png';
    }
}
