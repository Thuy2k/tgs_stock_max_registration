(function ($) {
    'use strict';

    var $app = $('#tgs-smr-app');
    var state = {
        isWarehouse: $app.data('is-warehouse') === 1 || $app.data('is-warehouse') === '1',
        shops: [],
        currentRequest: null,
        imageFrame: null,
        globalSearchSeq: 0,
        saveTimers: {},
        excelImportItems: [],
        selectedProducts: [],
        selectedShopIds: {}
    };

    function ajax(action, data) {
        return $.ajax({
            url: TgsSmr.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: $.extend({
                action: 'tgs_smr_' + action,
                nonce: TgsSmr.nonce
            }, data || {})
        }).then(function (res) {
            if (!res || !res.success) {
                return $.Deferred().reject(res && res.data && res.data.message ? res.data.message : 'Có lỗi xảy ra.').promise();
            }
            return res.data;
        }, function (xhr) {
            var msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
                ? xhr.responseJSON.data.message
                : 'Không kết nối được máy chủ.';
            return $.Deferred().reject(msg).promise();
        });
    }

    function ajaxUpload(action, formData) {
        formData.append('action', 'tgs_smr_' + action);
        formData.append('nonce', TgsSmr.nonce);
        return $.ajax({
            url: TgsSmr.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: formData,
            processData: false,
            contentType: false
        }).then(function (res) {
            if (!res || !res.success) {
                return $.Deferred().reject(res && res.data && res.data.message ? res.data.message : 'Có lỗi xảy ra.').promise();
            }
            return res.data;
        }, function (xhr) {
            var msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
                ? xhr.responseJSON.data.message
                : 'Không kết nối được máy chủ.';
            return $.Deferred().reject(msg).promise();
        });
    }

    function toast(message) {
        var $toast = $('#smrToast');
        $toast.text(message).addClass('show');
        clearTimeout($toast.data('timer'));
        $toast.data('timer', setTimeout(function () {
            $toast.removeClass('show');
        }, 2600));
    }

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function money(value) {
        if (value === null || value === undefined || value === '') return '';
        var n = Number(value || 0);
        if (Number.isNaN(n)) return '';
        return n.toLocaleString('vi-VN') + ' đ';
    }

    function qty(value) {
        if (value === null || value === undefined || value === '') return '';
        var n = Number(value);
        if (Number.isNaN(n)) return '';
        return Number.isInteger(n) ? String(n) : String(n).replace(/0+$/, '').replace(/\.$/, '');
    }

    function debounce(fn, wait) {
        var timer;
        return function () {
            var context = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(context, args);
            }, wait);
        };
    }

    function normalizeSearch(value) {
        var text = String(value == null ? '' : value).toLowerCase();
        if (typeof text.normalize === 'function') {
            text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        return text.replace(/đ/g, 'd').replace(/\s+/g, ' ').trim();
    }

    function shopSearchText(shop) {
        return [
            shop && shop.name,
            shop && shop.code,
            shop && shop.site_url,
            shop && shop.blog_id
        ].join(' ');
    }

    function getFilteredShops() {
        var keyword = normalizeSearch($('#smrShopSearchInput').val() || '');
        if (!keyword) return state.shops;
        return state.shops.filter(function (shop) {
            return normalizeSearch(shopSearchText(shop)).indexOf(keyword) !== -1;
        });
    }

    function rememberShopSelection(id, checked) {
        id = String(id || '');
        if (!id) return;
        if (checked) {
            state.selectedShopIds[id] = true;
        } else {
            delete state.selectedShopIds[id];
        }
    }

    function pruneSelectedShops() {
        var valid = {};
        state.shops.forEach(function (shop) {
            valid[String(shop.blog_id)] = true;
        });
        Object.keys(state.selectedShopIds).forEach(function (id) {
            if (!valid[id]) delete state.selectedShopIds[id];
        });
    }

    function selectedRealShopCount() {
        var valid = {};
        var count = 0;
        state.shops.forEach(function (shop) {
            valid[String(shop.blog_id)] = true;
        });
        Object.keys(state.selectedShopIds).forEach(function (id) {
            if (valid[id]) count++;
        });
        return count;
    }

    function updateShopSearchCount(visibleCount) {
        var total = state.shops.length;
        var selected = selectedRealShopCount();
        var text = total ? (visibleCount + '/' + total + ' shop') : '';
        if (selected) {
            text += ' · đã chọn ' + selected;
        }
        $('#smrShopSearchCount').text(text);
    }

    function switchTab(tab) {
        $('.tgs-smr-tabs button').removeClass('active');
        $('[data-smr-tab="' + tab + '"], [data-smr-tab-target="' + tab + '"]').addClass('active');
        $('.tgs-smr-panel').removeClass('active');
        $('[data-smr-panel="' + tab + '"]').addClass('active');
    }

    function renderEmpty($target, message) {
        $target.html('<div class="tgs-smr-card"><span class="text-muted">' + esc(message) + '</span></div>');
    }

    function productImageUrl() {
        return $.trim($('#smrProductThumb').val() || '');
    }

    function updateProductImagePreview() {
        var url = productImageUrl();
        var $preview = $('#smrProductImagePreview');
        var $image = $('#smrProductImage');
        var $placeholder = $('#smrProductImagePlaceholder');
        if (url) {
            $preview.removeClass('is-empty is-invalid');
            $image.attr('src', url).removeClass('d-none');
            $placeholder.addClass('d-none');
            return;
        }
        $preview.addClass('is-empty');
        $image.attr('src', '').addClass('d-none');
        $placeholder.removeClass('d-none');
    }

    function setProductImage(url) {
        $('#smrProductThumb').val(url || '');
        updateProductImagePreview();
    }

    function openImagePicker() {
        if (!window.wp || !wp.media) {
            toast('Không mở được thư viện ảnh.');
            return;
        }
        if (!state.imageFrame) {
            state.imageFrame = wp.media({
                title: 'Chọn ảnh sản phẩm',
                button: {text: 'Dùng ảnh này'},
                library: {type: 'image'},
                multiple: false
            });
            state.imageFrame.on('select', function () {
                var attachment = state.imageFrame.state().get('selection').first();
                if (!attachment) return;
                setProductImage(attachment.toJSON().url || '');
            });
        }
        state.imageFrame.open();
    }

    function renderGlobalSelected(product) {
        var $box = $('#smrGlobalSelected');
        if (product && product.global_product_name_id) {
            $box.removeClass('is-new').addClass('is-global').html(
                '<strong>Đã chọn sản phẩm global</strong><br>' +
                '<span>SKU: ' + esc(product.global_product_sku || '-') + ' · ' + esc(product.global_product_name || '') + '</span>'
            );
            return;
        }
        $box.removeClass('is-global').addClass('is-new').html('Sản phẩm mới chưa có SKU. Nếu cần SKU, hãy liên hệ quản trị admin để tạo sản phẩm global trước.');
    }

    function hideGlobalResults() {
        $('#smrGlobalResults').addClass('d-none').empty().removeData('items');
    }

    function renderGlobalResults(items, keyword) {
        var $box = $('#smrGlobalResults');
        if (!keyword) {
            hideGlobalResults();
            return;
        }
        if (!items.length) {
            $box.removeClass('d-none').html('<div class="tgs-smr-global-empty">Không tìm thấy sản phẩm phù hợp.</div>');
            return;
        }
        $box.removeClass('d-none').html(items.map(function (item, index) {
            var image = item.global_product_thumbnail
                ? '<img src="' + esc(item.global_product_thumbnail) + '" alt="">'
                : '<span class="tgs-smr-global-noimg"><i class="bx bx-image"></i></span>';
            return '<button type="button" class="tgs-smr-global-result" data-index="' + esc(index) + '">' +
                image +
                '<span class="tgs-smr-global-info">' +
                    '<strong>' + esc(item.global_product_name || '(Chưa có tên)') + '</strong>' +
                    '<small>SKU: ' + esc(item.global_product_sku || '-') +
                    ' · Barcode: ' + esc(item.global_product_barcode_main || '-') +
                    ' · Giá: ' + esc(money(item.global_product_price_after_tax) || '-') + '</small>' +
                '</span>' +
            '</button>';
        }).join(''));
        $box.data('items', items);
    }

    function searchGlobalProducts() {
        var keyword = $.trim($('#smrGlobalSearchInput').val() || '');
        if (keyword.length < 2) {
            hideGlobalResults();
            return;
        }
        var seq = ++state.globalSearchSeq;
        $('#smrGlobalResults').removeClass('d-none').html('<div class="tgs-smr-global-empty">Đang tìm...</div>');
        ajax('search_global_products', {keyword: keyword}).then(function (data) {
            if (seq !== state.globalSearchSeq) return;
            renderGlobalResults(data.items || [], keyword);
        }, function (message) {
            if (seq !== state.globalSearchSeq) return;
            $('#smrGlobalResults').removeClass('d-none').html('<div class="tgs-smr-global-empty">' + esc(message) + '</div>');
        });
    }

    var debouncedSearchGlobalProducts = debounce(searchGlobalProducts, 250);

    function applyGlobalProduct(product) {
        if (!product) return;
        $('#smrProductGlobalId').val(product.global_product_name_id || '');
        $('#smrProductSku').val(product.global_product_sku || '');
        $('#smrProductName').val(product.global_product_name || '');
        setProductImage(product.global_product_thumbnail || '');
        $('#smrProductPrice').val(product.global_product_price_after_tax || '');
        $('#smrProductBarcode').val(product.global_product_barcode_main || '');
        $('#smrGlobalSearchInput').val('');
        renderGlobalSelected(product);
        hideGlobalResults();
        toast('Đã lấy thông tin sản phẩm đã có');
    }

    function resetProductForm() {
        $('#smrProductGlobalId').val('');
        $('#smrProductSku').val('');
        $('#smrProductName').val('');
        setProductImage('');
        $('#smrProductPrice').val('');
        $('#smrProductBarcode').val('');
        $('#smrProductDesc').val('');
        $('#smrGlobalSearchInput').val('');
        renderGlobalSelected(null);
        hideGlobalResults();
    }

    function productClientId(prefix) {
        return (prefix || 'p') + '_' + Date.now() + '_' + Math.floor(Math.random() * 100000);
    }

    function renderProductPicker() {
        var $picker = $('#smrProductPicker');
        if (!$picker.length) return;
        if (!state.selectedProducts.length) {
            renderEmpty($picker, 'Chưa có sản phẩm nào trong phiếu. Tìm sản phẩm global hoặc nhập sản phẩm mới rồi bấm "Thêm vào phiếu".');
            return;
        }
        $picker.html(state.selectedProducts.map(function (p) {
            var img = p.thumbnail_url ? '<img src="' + esc(p.thumbnail_url) + '" alt="">' : '<span class="tgs-smr-global-noimg"><i class="bx bx-image"></i></span>';
            return '<div class="tgs-smr-selected-product" data-product-key="' + esc(p.client_id) + '">' +
                '<div class="tgs-smr-selected-product-media">' + img + '</div>' +
                '<div class="tgs-smr-selected-product-info">' +
                    '<strong>' + esc(p.product_name) + '</strong>' +
                    '<small>' + (p.product_sku ? 'SKU: ' + esc(p.product_sku) : 'Sản phẩm mới chưa có SKU') +
                    ' · Barcode NCC: ' + esc(p.supplier_barcode || '-') + '</small>' +
                '</div>' +
                '<button type="button" class="btn btn-sm btn-outline-danger smr-remove-selected-product" data-product-key="' + esc(p.client_id) + '"><i class="bx bx-trash"></i></button>' +
            '</div>';
        }).join(''));
    }

    function setExcelStatus(message, type) {
        $('#smrExcelStatus')
            .removeClass('is-ok is-error is-loading')
            .addClass(type ? 'is-' + type : '')
            .text(message || '');
    }

    function resetExcelImportState(clearFile) {
        state.excelImportItems = [];
        $('#smrExcelImportBtn').prop('disabled', true);
        $('#smrExcelPreview').empty();
        setExcelStatus('', '');
        if (clearFile) {
            $('#smrExcelFile').val('');
        }
    }

    function openExcelImportModal() {
        resetExcelImportState(true);
        $('#smrExcelModal').removeClass('d-none').attr('aria-hidden', 'false');
    }

    function closeExcelImportModal() {
        $('#smrExcelModal').addClass('d-none').attr('aria-hidden', 'true');
    }

    function renderExcelPreview(data) {
        var rows = data.rows || [];
        var total = Number(data.total_rows || 0);
        var errors = Number(data.error_count || 0);
        var valid = Number(data.valid_count || 0);
        var previewLimit = Number(data.preview_limit || rows.length || 0);
        var canImport = valid > 0 && errors === 0;
        state.excelImportItems = data.items || [];
        $('#smrExcelImportBtn').prop('disabled', !canImport);

        var summaryClass = errors ? 'is-error' : 'is-ok';
        var summary = '<div class="tgs-smr-import-summary ' + summaryClass + '">' +
            '<strong>' + esc(valid) + '/' + esc(total) + ' dòng hợp lệ</strong>' +
            '<span>Ảnh đã upload: ' + esc(data.uploaded_images || 0) + '</span>';
        if (total > rows.length) {
            summary += '<span>Đang xem trước ' + esc(rows.length) + '/' + esc(total) + ' dòng đầu.</span>';
        }
        summary += '</div>';

        if (!rows.length) {
            $('#smrExcelPreview').html(summary);
            return;
        }

        var table = '<div class="tgs-smr-excel-table-wrap"><table class="tgs-smr-excel-preview-table">' +
            '<thead><tr>' +
                '<th>Dòng</th>' +
                '<th>Mã BARCODE</th>' +
                '<th>Tên hàng</th>' +
                '<th>Hình ảnh</th>' +
                '<th>Giá bán lẻ đề xuất</th>' +
                '<th>Kiểm tra</th>' +
            '</tr></thead><tbody>';

        rows.forEach(function (row) {
            var rowErrors = row.errors || [];
            var price = Number(row.suggested_price || 0);
            table += '<tr class="' + (rowErrors.length ? 'has-error' : 'is-valid') + '">' +
                '<td>' + esc(row.row_number || '') + '</td>' +
                '<td>' + esc(row.supplier_barcode || '') + '</td>' +
                '<td>' + esc(row.product_name || '') + '</td>' +
                '<td>' + (row.thumbnail_url ? '<img src="' + esc(row.thumbnail_url) + '" alt="">' : '-') + '</td>' +
                '<td>' + esc(price.toLocaleString('vi-VN')) + ' đ</td>' +
                '<td>' + (rowErrors.length ? esc(rowErrors.join('; ')) : 'OK') + '</td>' +
            '</tr>';
        });

        table += '</tbody></table></div>';
        $('#smrExcelPreview').html(summary + table);

        if (canImport) {
            setExcelStatus('Dữ liệu hợp lệ, có thể import vào phiếu.', 'ok');
        } else {
            setExcelStatus('File còn dòng lỗi. Vui lòng sửa Excel rồi kiểm tra lại.', 'error');
        }
    }

    function importExcelItemsToRequest() {
        if (!state.excelImportItems.length) {
            toast('Chưa có dữ liệu Excel hợp lệ để import.');
            return;
        }

        state.excelImportItems.forEach(function (item) {
            state.selectedProducts.push({
                client_id: productClientId('excel'),
                global_product_name_id: item.global_product_name_id || '',
                product_sku: item.product_sku || '',
                product_name: item.product_name || '',
                thumbnail_url: item.thumbnail_url || '',
                suggested_price: item.suggested_price,
                supplier_barcode: item.supplier_barcode || '',
                product_description: item.product_description || ''
            });
        });
        var count = state.excelImportItems.length;
        renderProductPicker();
        closeExcelImportModal();
        resetExcelImportState(true);
        toast('Đã import ' + count + ' sản phẩm vào phiếu');
    }

    function loadShops() {
        if (!state.isWarehouse) return $.Deferred().resolve().promise();
        return ajax('payload_shops').then(function (data) {
            state.shops = data.real_shops || [];
            pruneSelectedShops();
            $('#smrIncludeDemo').prop('checked', false).prop('disabled', true);
            $('#smrDemoCount').val(0).prop('disabled', true);
            renderShopPicker();
        }, toast);
    }

    function renderShopPicker() {
        var $picker = $('#smrShopPicker');
        var shops = getFilteredShops();
        var total = state.shops.length;
        var keyword = $.trim($('#smrShopSearchInput').val() || '');
        if (!$picker.length) return;
        updateShopSearchCount(shops.length);

        if (!total) {
            renderEmpty($picker, 'Chưa có shop con thật trong hierarchy.');
            syncShopSelectionState();
            return;
        }

        if (!shops.length) {
            renderEmpty($picker, keyword ? 'Không tìm thấy shop phù hợp.' : 'Chưa có shop con thật trong hierarchy.');
            syncShopSelectionState();
            return;
        }

        $picker.html(shops.map(function (s) {
            var id = String(s.blog_id || '');
            var checked = state.selectedShopIds[id] ? ' checked' : '';
            var code = s.code || '-';
            var siteUrl = s.site_url || '';
            return '<label class="tgs-smr-pick-row">' +
                '<input type="checkbox" class="smr-pick-shop" value="' + esc(id) + '"' + checked + '>' +
                '<span><strong>' + esc(s.name) + '</strong><br>' +
                    '<small>Mã: ' + esc(code) + ' · ID: ' + esc(s.blog_id) + '</small>' +
                    (siteUrl ? '<small>Website: ' + esc(siteUrl) + '</small>' : '') +
                '</span>' +
            '</label>';
        }).join(''));
        syncShopSelectionState();
    }

    function syncShopSelectionState() {
        var $shops = $('.smr-pick-shop');
        var selectedCount = selectedRealShopCount();
        var total = state.shops.length;
        $shops.each(function () {
            $(this).closest('.tgs-smr-pick-row').toggleClass('is-selected', this.checked);
        });
        $('#smrSelectAllShops')
            .prop('checked', total > 0 && selectedCount === total)
            .prop('indeterminate', selectedCount > 0 && selectedCount < total);
        updateShopSearchCount(getFilteredShops().length);
    }

    function loadRequests() {
        return ajax('list_requests', {
            mode: state.isWarehouse ? 'warehouse' : 'shop',
            status: $('#smrRequestStatus').val() || '',
            search: $('#smrRequestSearch').val() || ''
        }).then(function (data) {
            renderRequests(data.items || []);
        }, toast);
    }

    function renderRequests(rows) {
        var $list = $('#smrRequestList');
        if (!rows.length) {
            renderEmpty($list, 'Chưa có phiếu nào.');
            return;
        }
        $list.html(rows.map(function (r) {
            return '<div class="tgs-smr-card">' +
                '<div><strong>' + esc(r.request_title || r.request_code) + '</strong> ' +
                '<span class="tgs-smr-badge status-' + esc(r.status) + '">' + esc(r.status_label || r.status) + '</span><br>' +
                '<small>' + esc(r.request_code) + ' · ' + esc(r.item_count) + ' sản phẩm · ' + esc(r.real_shop_count || r.shop_count) + ' shop thật · đã điền ' + esc(r.filled_cells || 0) + ' ô</small></div>' +
                '<div class="tgs-smr-actions"><button type="button" class="btn btn-sm btn-primary smr-open-request" data-id="' + esc(r.request_id) + '">Mở rà soát</button></div>' +
            '</div>';
        }).join(''));
    }

    function openRequest(id) {
        return ajax('get_request', {request_id: id}).then(function (data) {
            state.currentRequest = data;
            renderReview(data);
            $('#smrReviewTab').removeClass('d-none');
            switchTab('review');
        }, toast);
    }

    function renderLogs(logs) {
        var $log = $('#smrLog');
        if (!logs.length) {
            $log.html('');
            return;
        }
        $log.html('<h6>Nhật ký thay đổi gần đây</h6>' + logs.map(function (log) {
            return '<div class="tgs-smr-log-row"><strong>' + esc(logActionLabel(log.action)) + '</strong> · ' +
                esc(log.note || '') + '<br><small>' + esc(log.created_at || '') + '</small></div>';
        }).join(''));
    }

    function logActionLabel(action) {
        var map = {
            warehouse_update_cell: 'Kho cập nhật ô đăng ký',
            shop_update_cell: 'Shop cập nhật ô đăng ký',
            create_request: 'Tạo phiếu',
            update_item_sku: 'Cập nhật SKU',
            update_item_name: 'Cập nhật tên hàng',
            delete_item: 'Xóa dòng sản phẩm',
            approve_request: 'Duyệt phiếu',
            cancel_request: 'Hủy phiếu',
            apply_request: 'Áp dụng tồn max'
        };
        return map[action] || action || '';
    }

    function renderReview(data) {
        var request = data.request || {};
        var isOwner = Number(request.source_blog_id) === Number(TgsSmr.currentBlogId);
        var locked = ['approved', 'cancelled', 'applied'].indexOf(request.status) !== -1;
        var itemInfoLocked = ['cancelled', 'applied'].indexOf(request.status) !== -1;

        $('#smrReviewTitle').text((request.request_title || request.request_code || 'Phiếu đăng ký') + ' · ' + (request.status_label || request.status || ''));
        $('#smrReviewMeta').text((request.request_code || '') + ' · Kho tạo: ' + (request.source_blog_name_cache || request.source_blog_id || ''));
        $('#smrExportBtn').toggleClass('d-none', !isOwner).attr('href', data.export_url || '#');
        $('#smrApproveBtn, #smrCancelRequestBtn').toggle(isOwner && !locked);
        $('#smrApplyBtn').toggle(isOwner && request.status === 'approved');

        var html = '<table class="tgs-smr-matrix"><thead><tr>' +
            '<th class="col-sku tgs-smr-sticky-1" rowspan="2">Mã SKU</th>' +
            '<th class="col-name tgs-smr-sticky-2" rowspan="2">Tên hàng</th>' +
            '<th class="col-image tgs-smr-sticky-3" rowspan="2">Hình ảnh</th>' +
            '<th class="col-price tgs-smr-sticky-4" rowspan="2">Giá bán lẻ<br>đề xuất</th>' +
            '<th class="col-total tgs-smr-sticky-5" rowspan="2">Tổng cộng</th>';
        (data.shops || []).forEach(function (shop) {
            html += '<th class="col-shop">' + esc(shop.target_blog_code_cache || shop.target_blog_id) + '</th>';
        });
        html += '</tr><tr>';
        (data.shops || []).forEach(function (shop) {
            html += '<th class="col-shop">' + esc(shop.target_blog_name_cache) + '</th>';
        });
        html += '</tr></thead><tbody>';

        (data.items || []).forEach(function (item) {
            var total = 0;
            var cells = [];
            (data.shops || []).forEach(function (shop) {
                var v = data.values && data.values[item.request_item_id] ? data.values[item.request_item_id][shop.request_shop_id] : null;
                if (v && v.max_qty !== null && v.max_qty !== '') total += Number(v.max_qty || 0);
                cells.push({shop: shop, value: v});
            });

            html += '<tr data-item-id="' + esc(item.request_item_id) + '" data-product-name="' + esc(item.product_name || '') + '">' +
                '<td class="tgs-smr-sticky-1 col-sku">';
            if (isOwner && !itemInfoLocked) {
                html += '<div class="tgs-smr-sku-editor">' +
                    '<input type="text" class="tgs-smr-sku-input smr-item-sku" data-item-id="' + esc(item.request_item_id) + '" data-saved-sku="' + esc(item.product_sku || '') + '" data-global-id="' + esc(item.global_product_name_id || '') + '" value="' + esc(item.product_sku || '') + '" placeholder="Nhập SKU">' +
                    '<button type="button" class="btn btn-sm btn-outline-primary smr-save-sku" data-item-id="' + esc(item.request_item_id) + '">Lưu SKU</button>';
                html += item.product_sku && item.global_product_name_id
                    ? '<small class="tgs-smr-sku-status is-valid">Đã khớp global</small>'
                    : '<small class="tgs-smr-sku-status is-warning">' + (item.product_sku ? 'Cần lưu SKU để kiểm tra global' : 'Chưa có SKU') + '</small>';
                html += '</div>';
            } else {
                html += '<span class="' + (item.product_sku ? '' : 'tgs-smr-missing-sku') + '">' + esc(item.product_sku || 'Chưa có SKU') + '</span>';
            }
            html += '</td><td class="tgs-smr-sticky-2 col-name">';
            if (isOwner && !itemInfoLocked) {
                html += '<textarea class="tgs-smr-name-input smr-item-name" rows="3" data-item-id="' + esc(item.request_item_id) + '" data-saved-name="' + esc(item.product_name || '') + '">' + esc(item.product_name || '') + '</textarea><small class="tgs-smr-name-status"></small>';
            } else {
                html += '<div class="product-name">' + esc(item.product_name || '') + '</div>';
            }
            if (isOwner && !locked) {
                html += '<button type="button" class="btn btn-sm btn-outline-danger mt-2 smr-delete-item" data-item-id="' + esc(item.request_item_id) + '">Xóa dòng</button>';
            }
            html += '</td>' +
                '<td class="tgs-smr-sticky-3 col-image">' + (item.thumbnail_url ? '<img class="tgs-smr-product-img" src="' + esc(item.thumbnail_url) + '" alt="">' : '') + '</td>' +
                '<td class="tgs-smr-sticky-4 col-price price">' + money(item.suggested_price) + '</td>' +
                '<td class="tgs-smr-sticky-5 col-total total">' + qty(total) + '</td>';

            cells.forEach(function (entry) {
                var v = entry.value || {};
                var canEdit = !locked && (isOwner || Number(entry.shop.target_blog_id) === Number(TgsSmr.currentBlogId));
                if (canEdit) {
                    html += '<td class="col-shop">' +
                        '<input type="number" min="0" step="1" class="tgs-smr-cell-input smr-cell-value" data-item-id="' + esc(item.request_item_id) + '" data-shop-id="' + esc(entry.shop.request_shop_id) + '" value="' + esc(qty(v.max_qty)) + '">' +
                        '<input type="text" class="tgs-smr-cell-note smr-cell-note" data-item-id="' + esc(item.request_item_id) + '" data-shop-id="' + esc(entry.shop.request_shop_id) + '" value="' + esc(v.note || '') + '" placeholder="Ghi chú">' +
                    '</td>';
                } else {
                    html += '<td class="col-shop"><div class="tgs-smr-cell-readonly">' + esc(qty(v.max_qty)) + '</div>' + (v.note ? '<small>' + esc(v.note) + '</small>' : '') + '</td>';
                }
            });
            html += '</tr>';
        });
        html += '</tbody></table>';
        $('#smrMatrixWrap').html(html);
        renderLogs(data.logs || []);
    }

    function saveCell($input) {
        var itemId = $input.data('item-id');
        var shopId = $input.data('shop-id');
        var key = itemId + ':' + shopId;
        clearTimeout(state.saveTimers[key]);
        state.saveTimers[key] = setTimeout(function () {
            var req = state.currentRequest && state.currentRequest.request;
            if (!req) return;
            ajax('save_cell', {
                request_id: req.request_id,
                request_item_id: itemId,
                request_shop_id: shopId,
                max_qty: $('.smr-cell-value[data-item-id="' + itemId + '"][data-shop-id="' + shopId + '"]').val(),
                note: $('.smr-cell-note[data-item-id="' + itemId + '"][data-shop-id="' + shopId + '"]').val(),
                source: Number(req.source_blog_id) === Number(TgsSmr.currentBlogId) ? 'warehouse' : 'shop'
            }).then(function () {
                toast('Đã lưu ô đăng ký');
                openRequest(req.request_id);
            }, toast);
        }, 450);
    }

    function saveItemSkuNow($input, silent) {
        var req = state.currentRequest && state.currentRequest.request;
        if (!req) return $.Deferred().resolve().promise();
        var itemId = $input.data('item-id');
        var sku = $.trim($input.val() || '');
        var $status = $input.closest('.tgs-smr-sku-editor').find('.tgs-smr-sku-status');
        clearTimeout(state.saveTimers['sku:' + itemId]);
        if (!sku) {
            $input.addClass('is-invalid');
            $status.removeClass('is-valid is-warning').addClass('is-invalid').text('Vui lòng nhập SKU');
            return $.Deferred().reject('Vui lòng nhập SKU.').promise();
        }
        $input.removeClass('is-invalid');
        $status.removeClass('is-valid is-invalid').addClass('is-warning').text('Đang kiểm tra SKU global...');
        return ajax('save_item_sku', {
            request_id: req.request_id,
            request_item_id: itemId,
            product_sku: sku
        }).then(function (data) {
            $input.val(data.product_sku || '');
            $input.data('saved-sku', data.product_sku || '').attr('data-saved-sku', data.product_sku || '');
            $input.data('global-id', data.global_product_name_id || '').attr('data-global-id', data.global_product_name_id || '');
            $input.removeClass('is-invalid');
            $status.removeClass('is-warning is-invalid').addClass('is-valid').text('Đã khớp global');
            if (!silent) toast('Đã lưu SKU');
            return data;
        }, function (message) {
            $input.addClass('is-invalid');
            $input.data('global-id', '').attr('data-global-id', '');
            $status.removeClass('is-valid is-warning').addClass('is-invalid').text(message);
            return $.Deferred().reject(message).promise();
        });
    }

    function markSkuDirty($input) {
        var value = $.trim($input.val() || '');
        var saved = $.trim($input.data('saved-sku') || '');
        var globalId = $input.data('global-id') || '';
        var $status = $input.closest('.tgs-smr-sku-editor').find('.tgs-smr-sku-status');
        $input.removeClass('is-invalid');
        if (!value) {
            $input.data('global-id', '').attr('data-global-id', '');
            $status.removeClass('is-valid is-invalid').addClass('is-warning').text('Chưa có SKU');
        } else if (value === saved && globalId) {
            $status.removeClass('is-warning is-invalid').addClass('is-valid').text('Đã khớp global');
        } else {
            $input.data('global-id', '').attr('data-global-id', '');
            $status.removeClass('is-valid is-invalid').addClass('is-warning').text('Bấm Lưu SKU để kiểm tra global');
        }
    }

    function saveItemNameNow($input, silent) {
        var req = state.currentRequest && state.currentRequest.request;
        if (!req) return $.Deferred().resolve().promise();
        var itemId = $input.data('item-id');
        var name = $.trim($input.val() || '');
        var $row = $input.closest('tr');
        var $status = $row.find('.tgs-smr-name-status');
        clearTimeout(state.saveTimers['name:' + itemId]);
        if (!name) {
            $input.addClass('is-invalid');
            $status.removeClass('is-saved').addClass('is-invalid').text('Vui lòng nhập tên hàng');
            return $.Deferred().reject('Vui lòng nhập tên hàng.').promise();
        }
        $input.removeClass('is-invalid');
        $status.removeClass('is-saved is-invalid').text('Đang lưu...');
        return ajax('save_item_name', {
            request_id: req.request_id,
            request_item_id: itemId,
            product_name: name
        }).then(function (data) {
            $input.val(data.product_name || '');
            $input.data('saved-name', data.product_name || '').attr('data-saved-name', data.product_name || '');
            $row.data('product-name', data.product_name || '').attr('data-product-name', data.product_name || '');
            $status.removeClass('is-invalid').addClass('is-saved').text('Đã lưu tên');
            if (!silent) toast('Đã lưu tên hàng');
            return data;
        }, function (message) {
            $input.addClass('is-invalid');
            $status.removeClass('is-saved').addClass('is-invalid').text(message);
            return $.Deferred().reject(message).promise();
        });
    }

    function saveItemName($input) {
        var key = 'name:' + $input.data('item-id');
        clearTimeout(state.saveTimers[key]);
        state.saveTimers[key] = setTimeout(function () {
            saveItemNameNow($input, true).then(null, toast);
        }, 450);
    }

    function saveVisibleNames() {
        var saves = [];
        $('.smr-item-name').each(function () {
            var $input = $(this);
            if ($.trim($input.val() || '') !== $.trim($input.data('saved-name') || '')) {
                saves.push(saveItemNameNow($input, true));
            }
        });
        return saves.length ? $.when.apply($, saves) : $.Deferred().resolve().promise();
    }

    function findMissingSkus() {
        var missing = [];
        var invalid = [];
        $('#smrMatrixWrap tbody tr').each(function () {
            var $row = $(this);
            var $input = $row.find('.smr-item-sku');
            var value = $input.length ? $input.val() : $.trim($row.find('.col-sku').text());
            var productName = $row.data('product-name') || 'Dòng sản phẩm chưa có tên';
            if (!$.trim(value || '') || $.trim(value) === 'Chưa có SKU') {
                missing.push(productName);
            } else if ($input.length && !$input.data('global-id')) {
                invalid.push(productName + ' (' + $.trim(value) + ')');
            }
        });
        return {missing: missing, invalid: invalid};
    }

    function bindEvents() {
        $(document).on('click', '[data-smr-tab], [data-smr-tab-target]', function () {
            switchTab($(this).data('smr-tab') || $(this).data('smr-tab-target'));
        });
        $('#smrReloadAll').on('click', function () {
            loadRequests();
            loadShops();
        });
        $('#smrRequestStatus').on('change', loadRequests);
        $('#smrRequestSearch').on('input', debounce(loadRequests, 300));
        $('#smrCancelProductBtn').on('click', resetProductForm);
        $('#smrOpenExcelImportBtn').on('click', openExcelImportModal);
        $('[data-smr-excel-close]').on('click', closeExcelImportModal);
        $('#smrExcelFile').on('change', function () {
            resetExcelImportState(false);
        });
        $('#smrExcelCheckBtn').on('click', function () {
            var fileInput = $('#smrExcelFile')[0];
            var file = fileInput && fileInput.files ? fileInput.files[0] : null;
            if (!file) {
                setExcelStatus('Vui lòng chọn file Excel .xlsx.', 'error');
                return;
            }
            if (!/\.xlsx$/i.test(file.name || '')) {
                setExcelStatus('Chỉ hỗ trợ file .xlsx.', 'error');
                return;
            }

            var formData = new FormData();
            formData.append('file', file);
            $('#smrExcelCheckBtn').prop('disabled', true);
            $('#smrExcelImportBtn').prop('disabled', true);
            $('#smrExcelPreview').empty();
            setExcelStatus('Đang đọc file, upload ảnh và kiểm tra dữ liệu...', 'loading');

            ajaxUpload('import_products_excel', formData).then(function (data) {
                renderExcelPreview(data);
            }, function (message) {
                state.excelImportItems = [];
                $('#smrExcelImportBtn').prop('disabled', true);
                $('#smrExcelPreview').empty();
                setExcelStatus(message, 'error');
            }).always(function () {
                $('#smrExcelCheckBtn').prop('disabled', false);
            });
        });
        $('#smrExcelImportBtn').on('click', importExcelItemsToRequest);
        $('#smrPickImageBtn, #smrProductImagePreview').on('click', openImagePicker);
        $('#smrClearImageBtn').on('click', function () { setProductImage(''); });
        $('#smrProductThumb').on('input change', updateProductImagePreview);
        $('#smrGlobalSearchInput').on('input', function () {
            if ($('#smrProductGlobalId').val()) {
                $('#smrProductGlobalId').val('');
                $('#smrProductSku').val('');
                renderGlobalSelected(null);
            }
            debouncedSearchGlobalProducts();
        });
        $(document).on('click', '.tgs-smr-global-result', function () {
            var items = $('#smrGlobalResults').data('items') || [];
            applyGlobalProduct(items[Number($(this).data('index'))]);
        });
        $(document).on('click', function (event) {
            if ($(event.target).closest('.tgs-smr-global-lookup').length) return;
            hideGlobalResults();
        });
        $('#smrSaveProductBtn').on('click', function () {
            var imageUrl = productImageUrl();
            var productName = $.trim($('#smrProductName').val() || '');
            if (!productName) {
                toast('Vui lòng nhập tên hàng.');
                $('#smrProductName').focus();
                return;
            }
            if (!imageUrl) {
                toast('Vui lòng chọn hoặc dán URL ảnh sản phẩm.');
                $('#smrProductImagePreview').addClass('is-invalid');
                return;
            }
            state.selectedProducts.push({
                client_id: productClientId('p'),
                global_product_name_id: $('#smrProductGlobalId').val() || '',
                product_sku: $('#smrProductSku').val() || '',
                product_name: productName,
                thumbnail_url: imageUrl,
                suggested_price: $('#smrProductPrice').val(),
                supplier_barcode: $('#smrProductBarcode').val(),
                product_description: $('#smrProductDesc').val()
            });
            renderProductPicker();
            resetProductForm();
            toast('Đã thêm sản phẩm vào phiếu');
        });
        $(document).on('click', '.smr-remove-selected-product', function () {
            var key = String($(this).data('product-key'));
            state.selectedProducts = state.selectedProducts.filter(function (p) {
                return String(p.client_id) !== key;
            });
            renderProductPicker();
        });
        $('#smrSelectAllShops').on('change', function () {
            var checked = this.checked;
            state.shops.forEach(function (shop) {
                rememberShopSelection(shop.blog_id, checked);
            });
            $('.smr-pick-shop').prop('checked', checked);
            syncShopSelectionState();
        });
        $(document).on('change', '.smr-pick-shop', function () {
            rememberShopSelection(this.value, this.checked);
            syncShopSelectionState();
        });
        $('#smrShopSearchInput').on('input', debounce(renderShopPicker, 160));
        $('#smrCreateRequestBtn').on('click', function () {
            var products = state.selectedProducts.map(function (p) {
                return {
                    global_product_name_id: p.global_product_name_id || '',
                    product_name: p.product_name || '',
                    thumbnail_url: p.thumbnail_url || '',
                    suggested_price: p.suggested_price || '',
                    supplier_barcode: p.supplier_barcode || '',
                    product_description: p.product_description || ''
                };
            });
            var shops = Object.keys(state.selectedShopIds);
            if (!products.length) {
                toast('Vui lòng thêm ít nhất 1 sản phẩm vào phiếu.');
                $('#smrProductName').focus();
                return;
            }
            ajax('create_request', {
                request_title: $('#smrRequestTitle').val(),
                note: $('#smrRequestNote').val(),
                products_json: JSON.stringify(products),
                shop_ids_json: JSON.stringify(shops),
                include_demo: 0,
                demo_count: 0
            }).then(function (data) {
                toast('Đã tạo phiếu');
                state.selectedProducts = [];
                state.selectedShopIds = {};
                renderProductPicker();
                renderShopPicker();
                resetProductForm();
                loadRequests();
                openRequest(data.request_id);
            }, toast);
        });
        $(document).on('click', '.smr-open-request', function () { openRequest($(this).data('id')); });
        $(document).on('input', '.smr-cell-value, .smr-cell-note', function () { saveCell($(this)); });
        $(document).on('input', '.smr-item-sku', function () { markSkuDirty($(this)); });
        $(document).on('click', '.smr-save-sku', function () {
            var itemId = $(this).data('item-id');
            saveItemSkuNow($('.smr-item-sku[data-item-id="' + itemId + '"]'), false).then(null, toast);
        });
        $(document).on('input', '.smr-item-name', function () { saveItemName($(this)); });
        $('#smrApproveBtn').on('click', function () {
            var req = state.currentRequest && state.currentRequest.request;
            if (!req) return;
            saveVisibleNames().then(function () {
                return ajax('update_status', {request_id: req.request_id, status: 'approved'});
            }).then(function () {
                toast('Đã duyệt phiếu');
                openRequest(req.request_id);
                loadRequests();
            }, toast);
        });
        $('#smrCancelRequestBtn').on('click', function () {
            var req = state.currentRequest && state.currentRequest.request;
            if (!req || !window.confirm('Hủy phiếu này?')) return;
            ajax('update_status', {request_id: req.request_id, status: 'cancelled'}).then(function () {
                toast('Đã hủy phiếu');
                openRequest(req.request_id);
                loadRequests();
            }, toast);
        });
        $('#smrApplyBtn').on('click', function () {
            var req = state.currentRequest && state.currentRequest.request;
            if (!req) return;
            var check = findMissingSkus();
            if (check.missing.length || check.invalid.length) {
                var msg = '';
                if (check.missing.length) msg += 'Còn dòng chưa có SKU: ' + check.missing.slice(0, 5).join(', ') + '. ';
                if (check.invalid.length) msg += 'Còn SKU chưa lưu/không khớp global: ' + check.invalid.slice(0, 5).join(', ') + '. ';
                toast(msg + 'Vui lòng lưu SKU hợp lệ trước khi áp dụng max.');
                return;
            }
            saveVisibleNames().then(function () {
                return ajax('apply_request', {request_id: req.request_id});
            }).then(function (data) {
                toast('Đã áp dụng max cho ' + (data.applied_count || 0) + ' dòng cấu hình');
                openRequest(req.request_id);
                loadRequests();
            }, toast);
        });
        $(document).on('click', '.smr-delete-item', function () {
            var req = state.currentRequest && state.currentRequest.request;
            if (!req || !window.confirm('Xóa dòng sản phẩm này khỏi phiếu?')) return;
            ajax('delete_item', {
                request_id: req.request_id,
                request_item_id: $(this).data('item-id')
            }).then(function () {
                toast('Đã xóa dòng');
                openRequest(req.request_id);
                loadRequests();
            }, toast);
        });
    }

    $(function () {
        bindEvents();
        renderProductPicker();
        updateProductImagePreview();
        renderGlobalSelected(null);
        loadRequests();
        loadShops();
    });
})(jQuery);
