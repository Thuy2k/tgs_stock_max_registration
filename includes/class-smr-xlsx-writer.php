<?php

if (!defined('ABSPATH')) {
    exit;
}

class TGS_SMR_Xlsx_Writer
{
    public static function build_import_template_workbook()
    {
        $rows = self::import_template_rows();
        $images = [];
        $sheet_xml = self::build_import_template_sheet_xml($rows, $images);
        $tmp = wp_tempnam('tgs-smr-import-template.xlsx');
        if (!$tmp) {
            $tmp = tempnam(sys_get_temp_dir(), 'tgs-smr-template-');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            return '';
        }

        $zip->addFromString('[Content_Types].xml', self::content_types_xml(true, $images));
        $zip->addFromString('_rels/.rels', self::root_rels_xml());
        $zip->addFromString('docProps/core.xml', self::core_xml(['request_title' => 'Mẫu nhập sản phẩm đăng ký max']));
        $zip->addFromString('docProps/app.xml', self::app_xml());
        $zip->addFromString('xl/workbook.xml', self::workbook_xml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbook_rels_xml());
        $zip->addFromString('xl/styles.xml', self::styles_xml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet_xml);
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', self::sheet_rels_xml());
        $zip->addFromString('xl/drawings/drawing1.xml', self::drawing_xml($images));
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', self::drawing_rels_xml($images));
        foreach ($images as $image) {
            $zip->addFromString('xl/media/' . $image['file_name'], $image['bytes']);
        }

        $zip->close();
        $binary = file_get_contents($tmp);
        @unlink($tmp);

        return $binary ?: '';
    }

    public static function build_request_workbook($data)
    {
        $request = $data['request'];
        $items = $data['items'];
        $shops = $data['shops'];
        $values = $data['values'];
        $images = [];

        $sheet_xml = self::build_sheet_xml($request, $items, $shops, $values, $images);
        $has_images = !empty($images);
        $tmp = wp_tempnam('tgs-smr.xlsx');
        if (!$tmp) {
            $tmp = tempnam(sys_get_temp_dir(), 'tgs-smr-');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            return '';
        }

        $zip->addFromString('[Content_Types].xml', self::content_types_xml($has_images, $images));
        $zip->addFromString('_rels/.rels', self::root_rels_xml());
        $zip->addFromString('docProps/core.xml', self::core_xml($request));
        $zip->addFromString('docProps/app.xml', self::app_xml());
        $zip->addFromString('xl/workbook.xml', self::workbook_xml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbook_rels_xml());
        $zip->addFromString('xl/styles.xml', self::styles_xml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet_xml);

        if ($has_images) {
            $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', self::sheet_rels_xml());
            $zip->addFromString('xl/drawings/drawing1.xml', self::drawing_xml($images));
            $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', self::drawing_rels_xml($images));
            foreach ($images as $image) {
                $zip->addFromString('xl/media/' . $image['file_name'], $image['bytes']);
            }
        }

        $zip->close();
        $binary = file_get_contents($tmp);
        @unlink($tmp);

        return $binary ?: '';
    }

    public static function build_existing_request_workbook($data)
    {
        $request = $data['request'];
        $items = $data['items'];
        $sheet_xml = self::build_existing_sheet_xml($request, $items);
        $tmp = wp_tempnam('tgs-smr-existing.xlsx');
        if (!$tmp) {
            $tmp = tempnam(sys_get_temp_dir(), 'tgs-smr-existing-');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            return '';
        }

        $zip->addFromString('[Content_Types].xml', self::content_types_xml(false, []));
        $zip->addFromString('_rels/.rels', self::root_rels_xml());
        $zip->addFromString('docProps/core.xml', self::core_xml($request));
        $zip->addFromString('docProps/app.xml', self::app_xml());
        $zip->addFromString('xl/workbook.xml', self::workbook_xml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbook_rels_xml());
        $zip->addFromString('xl/styles.xml', self::styles_xml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet_xml);
        $zip->close();

        $binary = file_get_contents($tmp);
        @unlink($tmp);

        return $binary ?: '';
    }

    private static function build_import_template_sheet_xml($rows_data, &$images)
    {
        $rows = [];
        $rows[] = self::row_xml(1, [
            self::cell(1, 1, 'Mã BARCODE', 's', 1),
            self::cell(1, 2, 'Tên hàng', 's', 1),
            self::cell(1, 3, 'Hình ảnh', 's', 1),
            self::cell(1, 4, 'Giá bán lẻ đề xuất', 's', 1),
        ], 28);

        $row_num = 2;
        foreach ($rows_data as $row) {
            $rows[] = '<row r="' . $row_num . '" ht="132" customHeight="1">'
                . self::cell($row_num, 1, $row['barcode'], 's', 5)
                . self::cell($row_num, 2, $row['name'], 's', 6)
                . self::cell($row_num, 3, 'Ảnh demo', 's', 7)
                . self::cell($row_num, 4, $row['price'], 'n', 8)
                . '</row>';

            $images[] = [
                'bytes' => self::demo_image_png($row['color'], $row['label']),
                'ext' => 'png',
                'width' => 120,
                'height' => 120,
                'row' => $row_num,
                'col' => 3,
                'id' => count($images) + 1,
                'file_name' => 'image' . (count($images) + 1) . '.png',
            ];
            $row_num++;
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews><sheetView workbookViewId="0"><pane xSplit="4" ySplit="1" topLeftCell="E2" activePane="bottomRight" state="frozen"/><selection pane="bottomRight" activeCell="E2" sqref="E2"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . '<cols><col min="1" max="1" width="22" customWidth="1"/><col min="2" max="2" width="42" customWidth="1"/><col min="3" max="3" width="20" customWidth="1"/><col min="4" max="4" width="26" customWidth="1"/></cols>'
            . '<sheetData>' . implode('', $rows) . '</sheetData>'
            . '<pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
            . '<drawing r:id="rId1"/>'
            . '</worksheet>';
    }

    private static function build_existing_sheet_xml($request, $items)
    {
        $rows = [];
        $rows[] = self::row_xml(1, [
            self::cell(1, 1, 'Mã phiếu', 's', 1),
            self::cell(1, 2, (string) ($request['request_code'] ?? ''), 's', 4),
            self::cell(1, 3, 'Shop', 's', 1),
            self::cell(1, 4, (string) ($request['shop_blog_name_cache'] ?? ''), 's', 4),
            self::cell(1, 5, 'Trạng thái', 's', 1),
            self::cell(1, 6, (string) ($request['status_label'] ?? $request['status'] ?? ''), 's', 4),
        ], 24);
        $rows[] = self::row_xml(2, [
            self::cell(2, 1, 'Ghi chú shop', 's', 1),
            self::cell(2, 2, (string) ($request['note'] ?? ''), 's', 6),
            self::cell(2, 3, 'Ghi chú kho', 's', 1),
            self::cell(2, 4, (string) ($request['warehouse_note'] ?? ''), 's', 6),
        ], 36);
        $rows[] = self::row_xml(4, [
            self::cell(4, 1, 'SKU', 's', 1),
            self::cell(4, 2, 'Tên hàng', 's', 1),
            self::cell(4, 3, 'Max lúc tạo phiếu', 's', 1),
            self::cell(4, 4, 'Max hiện tại', 's', 1),
            self::cell(4, 5, 'Max shop đề xuất', 's', 1),
            self::cell(4, 6, 'Max kho chốt', 's', 1),
            self::cell(4, 7, 'Ghi chú shop', 's', 1),
            self::cell(4, 8, 'Ghi chú kho', 's', 1),
            self::cell(4, 9, 'Cảnh báo', 's', 1),
        ], 28);

        $row_num = 5;
        foreach ((array) $items as $item) {
            $warning = !empty($item['snapshot_changed']) ? 'Max hiện tại đã khác max lúc tạo phiếu' : '';
            $rows[] = self::row_xml($row_num, [
                self::cell($row_num, 1, (string) ($item['product_sku'] ?? ''), 's', 5),
                self::cell($row_num, 2, (string) ($item['product_name'] ?? ''), 's', 6),
                self::cell($row_num, 3, self::number_or_blank($item['current_max_qty'] ?? null), 'n', 10),
                self::cell($row_num, 4, self::number_or_blank($item['latest_max_qty'] ?? null), 'n', 10),
                self::cell($row_num, 5, self::number_or_blank($item['proposed_max_qty'] ?? null), 'n', 10),
                self::cell($row_num, 6, self::number_or_blank($item['warehouse_max_qty'] ?? null), 'n', 10),
                self::cell($row_num, 7, (string) ($item['shop_note'] ?? ''), 's', 6),
                self::cell($row_num, 8, (string) ($item['warehouse_note'] ?? ''), 's', 6),
                self::cell($row_num, 9, $warning, 's', $warning ? 8 : 6),
            ], 42);
            $row_num++;
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A5" sqref="A5"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . '<cols>'
            . '<col min="1" max="1" width="18" customWidth="1"/>'
            . '<col min="2" max="2" width="48" customWidth="1"/>'
            . '<col min="3" max="6" width="18" customWidth="1"/>'
            . '<col min="7" max="9" width="34" customWidth="1"/>'
            . '</cols>'
            . '<sheetData>' . implode('', $rows) . '</sheetData>'
            . '<mergeCells count="1"><mergeCell ref="D2:I2"/></mergeCells>'
            . '<pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
            . '</worksheet>';
    }

    private static function import_template_rows()
    {
        return [
            [
                'barcode' => '8930000000012',
                'name' => 'Sản phẩm demo A 400g',
                'price' => 380000,
                'color' => '#1F5AA6',
                'label' => 'A',
            ],
            [
                'barcode' => '8930000000029',
                'name' => 'Sản phẩm demo B 800g',
                'price' => 420000,
                'color' => '#16A34A',
                'label' => 'B',
            ],
        ];
    }

    private static function demo_image_png($color, $label)
    {
        if (function_exists('imagecreatetruecolor')) {
            $hex = ltrim((string) $color, '#');
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            $im = imagecreatetruecolor(160, 160);
            $white = imagecolorallocate($im, 255, 255, 255);
            $accent = imagecolorallocate($im, $r, $g, $b);
            $soft = imagecolorallocate($im, min(255, $r + 70), min(255, $g + 70), min(255, $b + 70));
            $dark = imagecolorallocate($im, 31, 41, 55);
            imagefilledrectangle($im, 0, 0, 159, 159, $white);
            imagefilledellipse($im, 80, 76, 112, 112, $soft);
            imagefilledellipse($im, 80, 76, 82, 82, $accent);
            imagefilledrectangle($im, 48, 114, 112, 128, $accent);
            imagestring($im, 5, 73, 64, (string) $label, $white);
            imagestring($im, 3, 50, 136, 'DEMO', $dark);
            ob_start();
            imagepng($im);
            $bytes = ob_get_clean();
            imagedestroy($im);
            if ($bytes) {
                return $bytes;
            }
        }

        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAKAAAACgCAIAAABY6wU0AAABMElEQVR4nO3QQQ3AIADAQEDYf8eVQwEUciufB3S3zNx5Y8A7BKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqACoAKgAqADc3gFoZwJhs9G2IAAAAABJRU5ErkJggg=='
        );
    }

    private static function build_sheet_xml($request, $items, $shops, $values, &$images)
    {
        $col_count = 5 + count($shops);
        $rows = [];

        $rows[] = self::row_xml(1, self::header_row_1($shops), 24);
        $rows[] = self::row_xml(2, self::header_row_2($shops), 30);

        $row_num = 3;
        foreach ($items as $item) {
            $cells = [];
            $cells[] = self::cell($row_num, 1, (string) ($item['product_sku'] ?? ''), 's', 5);
            $cells[] = self::cell($row_num, 2, (string) ($item['product_name'] ?? ''), 's', 6);
            $cells[] = self::cell($row_num, 3, self::image_cell_text($item), 's', 7);
            $cells[] = self::cell($row_num, 4, self::number_or_blank($item['suggested_price'] ?? null), 'n', 8);

            $shop_start = self::col_name(6) . $row_num;
            $shop_end = self::col_name($col_count) . $row_num;
            $cells[] = self::formula_cell($row_num, 5, "SUM({$shop_start}:{$shop_end})", 9);

            $shop_col = 6;
            foreach ($shops as $shop) {
                $value = $values[(int) $item['request_item_id']][(int) $shop['request_shop_id']] ?? null;
                $qty = $value && $value['max_qty'] !== null ? $value['max_qty'] : null;
                $cells[] = self::cell($row_num, $shop_col, self::number_or_blank($qty), 'n', 10);
                $shop_col++;
            }

            $rows[] = '<row r="' . $row_num . '" ht="120" customHeight="1">' . implode('', $cells) . '</row>';

            $image = self::fetch_image($item['thumbnail_url'] ?? '');
            if ($image) {
                $image['row'] = $row_num;
                $image['col'] = 3;
                $image['id'] = count($images) + 1;
                $image['file_name'] = 'image' . $image['id'] . '.' . $image['ext'];
                $images[] = $image;
            }

            $row_num++;
        }

        $merge_cells = [
            'A1:A2',
            'B1:B2',
            'C1:C2',
            'D1:D2',
            'E1:E2',
        ];

        $sheet_views = '<sheetViews><sheetView workbookViewId="0">'
            . '<pane xSplit="5" ySplit="2" topLeftCell="F3" activePane="bottomRight" state="frozen"/>'
            . '<selection pane="bottomRight" activeCell="F3" sqref="F3"/>'
            . '</sheetView></sheetViews>';

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . $sheet_views
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . self::cols_xml($shops)
            . '<sheetData>' . implode('', $rows) . '</sheetData>'
            . '<mergeCells count="' . count($merge_cells) . '">';

        foreach ($merge_cells as $ref) {
            $xml .= '<mergeCell ref="' . $ref . '"/>';
        }

        $xml .= '</mergeCells>'
            . '<pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>';

        if (!empty($images)) {
            $xml .= '<drawing r:id="rId1"/>';
        }

        $xml .= '</worksheet>';

        return $xml;
    }

    private static function header_row_1($shops)
    {
        $cells = [
            self::cell(1, 1, 'Mã SKU', 's', 1),
            self::cell(1, 2, 'Tên hàng', 's', 1),
            self::cell(1, 3, 'Hình ảnh', 's', 1),
            self::cell(1, 4, 'Giá bán lẻ đề xuất', 's', 1),
            self::cell(1, 5, 'Tổng cộng', 's', 1),
        ];
        $col = 6;
        foreach ($shops as $shop) {
            $code = (string) ($shop['target_blog_code_cache'] ?: $shop['target_blog_id']);
            $cells[] = self::cell(1, $col++, $code, 's', 2);
        }
        return $cells;
    }

    private static function header_row_2($shops)
    {
        $cells = [
            self::cell(2, 1, '', 's', 1),
            self::cell(2, 2, '', 's', 1),
            self::cell(2, 3, '', 's', 1),
            self::cell(2, 4, '', 's', 1),
            self::cell(2, 5, '', 's', 1),
        ];
        $col = 6;
        foreach ($shops as $shop) {
            $cells[] = self::cell(2, $col++, (string) $shop['target_blog_name_cache'], 's', 3);
        }
        return $cells;
    }

    private static function row_xml($row_num, $cells, $height = null)
    {
        $attrs = ' r="' . (int) $row_num . '"';
        if ($height !== null) {
            $attrs .= ' ht="' . (float) $height . '" customHeight="1"';
        }
        return '<row' . $attrs . '>' . implode('', $cells) . '</row>';
    }

    private static function cell($row, $col, $value, $type = 's', $style = 0)
    {
        $ref = self::col_name($col) . $row;
        $style_attr = $style ? ' s="' . (int) $style . '"' : '';

        if ($type === 'n') {
            if ($value === '' || $value === null) {
                return '<c r="' . $ref . '"' . $style_attr . '/>';
            }
            return '<c r="' . $ref . '"' . $style_attr . '><v>' . self::num($value) . '</v></c>';
        }

        return '<c r="' . $ref . '" t="inlineStr"' . $style_attr . '><is><t>' . self::esc($value) . '</t></is></c>';
    }

    private static function formula_cell($row, $col, $formula, $style = 0)
    {
        $ref = self::col_name($col) . $row;
        $style_attr = $style ? ' s="' . (int) $style . '"' : '';
        return '<c r="' . $ref . '"' . $style_attr . '><f>' . self::esc($formula) . '</f><v>0</v></c>';
    }

    private static function cols_xml($shops)
    {
        $xml = '<cols>';
        $xml .= '<col min="1" max="1" width="15" customWidth="1"/>';
        $xml .= '<col min="2" max="2" width="42" customWidth="1"/>';
        $xml .= '<col min="3" max="3" width="18" customWidth="1"/>';
        $xml .= '<col min="4" max="4" width="17" customWidth="1"/>';
        $xml .= '<col min="5" max="5" width="13" customWidth="1"/>';
        if (!empty($shops)) {
            $xml .= '<col min="6" max="' . (5 + count($shops)) . '" width="14" customWidth="1"/>';
        }
        $xml .= '</cols>';
        return $xml;
    }

    private static function styles_xml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0 &quot;đ&quot;"/><numFmt numFmtId="165" formatCode="#,##0"/></numFmts>'
            . '<fonts count="3">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="12"/><name val="Times New Roman"/></font>'
            . '<font><b/><sz val="13"/><name val="Times New Roman"/></font>'
            . '</fonts>'
            . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEAF2FF"/></patternFill></fill></fills>'
            . '<borders count="3">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FF000000"/></left><right style="thin"><color rgb="FF000000"/></right><top style="thin"><color rgb="FF000000"/></top><bottom style="thin"><color rgb="FF000000"/></bottom><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFB7B7B7"/></left><right style="thin"><color rgb="FFB7B7B7"/></right><top style="thin"><color rgb="FFB7B7B7"/></top><bottom style="thin"><color rgb="FFB7B7B7"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="11">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="2" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="2" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="2" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="164" fontId="1" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="165" fontId="1" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="165" fontId="0" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private static function fetch_image($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $bytes = null;
        $upload = wp_upload_dir();
        if (!empty($upload['baseurl']) && strpos($url, $upload['baseurl']) === 0) {
            $path = $upload['basedir'] . str_replace('/', DIRECTORY_SEPARATOR, substr($url, strlen($upload['baseurl'])));
            if (is_readable($path)) {
                $bytes = file_get_contents($path);
            }
        }

        if ($bytes === null && preg_match('#^https?://#i', $url)) {
            $response = wp_remote_get($url, ['timeout' => 4, 'redirection' => 2]);
            if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) < 400) {
                $body = wp_remote_retrieve_body($response);
                if (is_string($body) && strlen($body) <= 3 * 1024 * 1024) {
                    $bytes = $body;
                }
            }
        }

        if (!$bytes) {
            return null;
        }

        $info = @getimagesizefromstring($bytes);
        if (!$info || empty($info['mime'])) {
            return null;
        }

        $mime = strtolower($info['mime']);
        $ext = null;
        if ($mime === 'image/jpeg') {
            $ext = 'jpg';
        } elseif ($mime === 'image/png') {
            $ext = 'png';
        }

        if (!$ext && function_exists('imagecreatefromstring')) {
            $im = @imagecreatefromstring($bytes);
            if ($im) {
                ob_start();
                imagepng($im);
                $bytes = ob_get_clean();
                imagedestroy($im);
                $ext = 'png';
            }
        }

        if (!$ext) {
            return null;
        }

        return [
            'bytes' => $bytes,
            'ext' => $ext,
            'width' => (int) ($info[0] ?? 120),
            'height' => (int) ($info[1] ?? 120),
        ];
    }

    private static function drawing_xml($images)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">';

        foreach ($images as $image) {
            $id = (int) $image['id'];
            $row = (int) $image['row'] - 1;
            $col = (int) $image['col'] - 1;
            $xml .= '<xdr:twoCellAnchor editAs="oneCell">'
                . '<xdr:from><xdr:col>' . $col . '</xdr:col><xdr:colOff>95250</xdr:colOff><xdr:row>' . $row . '</xdr:row><xdr:rowOff>95250</xdr:rowOff></xdr:from>'
                . '<xdr:to><xdr:col>' . ($col + 1) . '</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>' . ($row + 1) . '</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>'
                . '<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="' . $id . '" name="Product image ' . $id . '"/><xdr:cNvPicPr/></xdr:nvPicPr>'
                . '<xdr:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId' . $id . '"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
                . '<xdr:spPr><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/>'
                . '</xdr:twoCellAnchor>';
        }

        return $xml . '</xdr:wsDr>';
    }

    private static function drawing_rels_xml($images)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($images as $image) {
            $xml .= '<Relationship Id="rId' . (int) $image['id'] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/' . self::esc_attr($image['file_name']) . '"/>';
        }
        return $xml . '</Relationships>';
    }

    private static function sheet_rels_xml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>'
            . '</Relationships>';
    }

    private static function content_types_xml($has_images, $images)
    {
        $has_png = false;
        $has_jpg = false;
        foreach ($images as $image) {
            if ($image['ext'] === 'png') {
                $has_png = true;
            }
            if ($image['ext'] === 'jpg') {
                $has_jpg = true;
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>';
        if ($has_png) {
            $xml .= '<Default Extension="png" ContentType="image/png"/>';
        }
        if ($has_jpg) {
            $xml .= '<Default Extension="jpg" ContentType="image/jpeg"/>';
        }
        $xml .= '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        if ($has_images) {
            $xml .= '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
        }
        return $xml . '</Types>';
    }

    private static function root_rels_xml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private static function workbook_xml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Trang tính1" sheetId="1" r:id="rId1"/></sheets>'
            . '<calcPr calcId="0" fullCalcOnLoad="1"/>'
            . '</workbook>';
    }

    private static function workbook_rels_xml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private static function core_xml($request)
    {
        $created = gmdate('Y-m-d\TH:i:s\Z');
        $title = self::esc((string) ($request['request_title'] ?? 'Đăng ký tồn max'));
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . $title . '</dc:title><dc:creator>TGS Stock Max Registration</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private static function app_xml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>TGS</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop>'
            . '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant></vt:vector></HeadingPairs>'
            . '<TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>Trang tính1</vt:lpstr></vt:vector></TitlesOfParts>'
            . '</Properties>';
    }

    private static function image_cell_text($item)
    {
        return !empty($item['thumbnail_url']) ? 'Ảnh sản phẩm' : '';
    }

    private static function number_or_blank($value)
    {
        if ($value === null || $value === '') {
            return '';
        }
        return (float) $value;
    }

    private static function col_name($index)
    {
        $index = (int) $index;
        $name = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $name = chr(65 + $mod) . $name;
            $index = (int) (($index - $mod) / 26);
        }
        return $name;
    }

    private static function num($value)
    {
        $num = (float) $value;
        if (abs($num) < 0.0000005) {
            return '0';
        }

        return rtrim(rtrim(number_format($num, 6, '.', ''), '0'), '.');
    }

    private static function esc($value)
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private static function esc_attr($value)
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
