(function ($) {
    'use strict';

    var state = {
        isWarehouse: $('#tgs-smr-app').data('is-warehouse') === 1 || $('#tgs-smr-app').data('is-warehouse') === '1',
        products: [],
        shops: [],
        currentRequest: null,
        imageFrame: null,
        saveTimers: {}
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
                var msg = res && res.data && res.data.message ? res.data.message : 'Có lỗi xảy ra.';
                return $.Deferred().reject(msg).promise();
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
        }, 2400));
    }

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function money(value) {
        var n = Number(value || 0);
        if (!n) return '';
        return n.toLocaleString('vi-VN') + ' đ';
    }

    function qty(value) {
        if (value === null || value === undefined || value === '') return '';
        var n = Number(value);
        if (Number.isNaN(n)) return '';
        return Number.isInteger(n) ? String(n) : String(n).replace(/0+$/, '').replace(/\.$/, '');
    }

    function setProductImage(url) {
        $('#smrProductThumb').val(url || '');
        updateProductImagePreview();
    }

    function updateProductImagePreview() {
        var url = $.trim($('#smrProductThumb').val() || '');
        var $preview = $('#smrProductImagePreview');
        var $image = $('#smrProductImage');
        var $placeholder = $('#smrProductImagePlaceholder');

        if (url) {
            $preview.removeClass('is-empty');
            $image.attr('src', url).removeClass('d-none');
            $placeholder.addClass('d-none');
            return;
        }

        $preview.addClass('is-empty');
        $image.attr('src', '').addClass('d-none');
        $placeholder.removeClass('d-none');
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
                var data = attachment.toJSON();
                setProductImage(data.url || '');
            });
        }

        state.imageFrame.open();
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

    function loadProducts() {
        return ajax('list_temp_products').then(function (data) {
            state.products = data.items || [];
            renderProducts();
            renderProductPicker();
        }, toast);
    }

    function renderProducts() {
        var $list = $('#smrProductList');
        if (!state.products.length) {
            renderEmpty($list, 'Chưa có sản phẩm mới. Bấm "Thêm sản phẩm" để tạo dòng đầu tiên.');
            return;
        }
        $list.html(state.products.map(function (p) {
            var img = p.thumbnail_url ? '<img src="' + esc(p.thumbnail_url) + '" alt="" class="tgs-smr-product-img" style="width:64px;height:64px;margin:0;">' : '<span class="tgs-smr-badge">Chưa có ảnh</span>';
            return '<div class="tgs-smr-card" data-product-id="' + esc(p.temp_product_id) + '">' +
                '<div style="display:flex;gap:12px;align-items:center;">' + img +
                    '<div><strong>' + esc(p.product_name) + '</strong><br>' +
                    '<small>SKU: ' + esc(p.product_sku || '(trống)') + ' · Barcode NCC: ' + esc(p.supplier_barcode || '-') + '</small></div>' +
                '</div>' +
                '<div class="tgs-smr-actions">' +
                    '<button type="button" class="btn btn-sm btn-outline-primary smr-edit-product">Sửa</button>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger smr-delete-product">Xóa</button>' +
                '</div>' +
            '</div>';
        }).join(''));
    }

    function renderProductPicker() {
        var $picker = $('#smrProductPicker');
        if (!$picker.length) return;
        if (!state.products.length) {
            renderEmpty($picker, 'Chưa có sản phẩm để chọn.');
            return;
        }
        $picker.html(state.products.map(function (p) {
            return '<label class="tgs-smr-pick-row">' +
                '<input type="checkbox" class="smr-pick-product" value="' + esc(p.temp_product_id) + '">' +
                '<span><strong>' + esc(p.product_name) + '</strong><br><small>SKU: ' + esc(p.product_sku || '(trống)') + '</small></span>' +
            '</label>';
        }).join(''));
    }

    function loadShops() {
        if (!state.isWarehouse) return $.Deferred().resolve().promise();
        return ajax('payload_shops').then(function (data) {
            state.shops = data.real_shops || [];
            if (data.recommended_demo_count != null) {
                $('#smrDemoCount').val(data.recommended_demo_count);
            }
            renderShopPicker();
        }, toast);
    }

    function renderShopPicker() {
        var $picker = $('#smrShopPicker');
        if (!state.shops.length) {
            renderEmpty($picker, 'Chưa có shop con thật trong hierarchy. Vẫn có thể dùng shop demo để thuyết trình.');
            return;
        }
        $picker.html(state.shops.map(function (s) {
            return '<label class="tgs-smr-pick-row">' +
                '<input type="checkbox" class="smr-pick-shop" value="' + esc(s.blog_id) + '">' +
                '<span><strong>' + esc(s.name) + '</strong><br><small>ID: ' + esc(s.blog_id) + ' · Mã: ' + esc(s.code) + '</small></span>' +
            '</label>';
        }).join(''));
    }

    function loadRequests() {
        var mode = state.isWarehouse ? 'warehouse' : 'shop';
        return ajax('list_requests', {
            mode: mode,
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
                '<div>' +
                    '<strong>' + esc(r.request_title || r.request_code) + '</strong> ' +
                    '<span class="tgs-smr-badge status-' + esc(r.status) + '">' + esc(r.status_label || r.status) + '</span><br>' +
                    '<small>' + esc(r.request_code) + ' · ' + esc(r.item_count) + ' sản phẩm · ' + esc(r.real_shop_count || r.shop_count) + ' shop thật · đã điền ' + esc(r.filled_cells || 0) + ' ô</small>' +
                '</div>' +
                '<div class="tgs-smr-actions">' +
                    '<button type="button" class="btn btn-sm btn-primary smr-open-request" data-id="' + esc(r.request_id) + '">Mở review</button>' +
                '</div>' +
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

    function renderReview(data) {
        var request = data.request;
        var isOwner = Number(request.source_blog_id) === Number(TgsSmr.currentBlogId);
        var locked = ['approved', 'cancelled', 'applied'].indexOf(request.status) !== -1;
        $('#smrReviewTitle').text((request.request_title || request.request_code) + ' · ' + (request.status_label || request.status));
        $('#smrReviewMeta').text(request.request_code + ' · Kho tạo: ' + (request.source_blog_name_cache || request.source_blog_id));
        $('#smrExportBtn').toggleClass('d-none', !isOwner).attr('href', data.export_url || '#');
        $('#smrApproveBtn, #smrCancelRequestBtn').toggle(isOwner && !locked);
        $('#smrApplyBtn').toggle(isOwner && (request.status === 'approved' || request.status === 'applied'));

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
            html += '<tr data-item-id="' + esc(item.request_item_id) + '">' +
                '<td class="tgs-smr-sticky-1 col-sku">' + esc(item.product_sku || '') + '</td>' +
                '<td class="tgs-smr-sticky-2 col-name"><div class="product-name">' + esc(item.product_name) + '</div>';
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
                        '<input type="number" min="0" step="1" class="tgs-smr-cell-input smr-cell-value" ' +
                        'data-item-id="' + esc(item.request_item_id) + '" data-shop-id="' + esc(entry.shop.request_shop_id) + '" ' +
                        'value="' + esc(qty(v.max_qty)) + '">' +
                        '<input type="text" class="tgs-smr-cell-note smr-cell-note" ' +
                        'data-item-id="' + esc(item.request_item_id) + '" data-shop-id="' + esc(entry.shop.request_shop_id) + '" ' +
                        'value="' + esc(v.note || '') + '" placeholder="Ghi chú">' +
                    '</td>';
                } else {
                    html += '<td class="col-shop"><div class="tgs-smr-cell-readonly">' + esc(qty(v.max_qty)) + '</div>' +
                        (v.note ? '<small>' + esc(v.note) + '</small>' : '') + '</td>';
                }
            });
            html += '</tr>';
        });
        html += '</tbody></table>';
        $('#smrMatrixWrap').html(html);
        renderLogs(data.logs || []);
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
            delete_item: 'Xóa dòng sản phẩm',
            approve_request: 'Duyệt phiếu',
            cancel_request: 'Hủy phiếu',
            apply_request: 'Áp dụng tồn max'
        };
        return map[action] || action || '';
    }

    function saveCell($input) {
        var itemId = $input.data('item-id');
        var shopId = $input.data('shop-id');
        var key = itemId + ':' + shopId;
        clearTimeout(state.saveTimers[key]);
        state.saveTimers[key] = setTimeout(function () {
            var $value = $('.smr-cell-value[data-item-id="' + itemId + '"][data-shop-id="' + shopId + '"]');
            var $note = $('.smr-cell-note[data-item-id="' + itemId + '"][data-shop-id="' + shopId + '"]');
            var req = state.currentRequest && state.currentRequest.request ? state.currentRequest.request : null;
            if (!req) return;
            ajax('save_cell', {
                request_id: req.request_id,
                request_item_id: itemId,
                request_shop_id: shopId,
                max_qty: $value.val(),
                note: $note.val(),
                source: Number(req.source_blog_id) === Number(TgsSmr.currentBlogId) ? 'warehouse' : 'shop'
            }).then(function () {
                toast('Đã lưu ô đăng ký');
                openRequest(req.request_id);
            }, toast);
        }, 450);
    }

    function resetProductForm() {
        $('#smrProductId').val('');
        $('#smrProductSku').val('');
        $('#smrProductName').val('');
        setProductImage('');
        $('#smrProductPrice').val('');
        $('#smrProductBarcode').val('');
        $('#smrProductDesc').val('');
    }

    function bindEvents() {
        $(document).on('click', '[data-smr-tab], [data-smr-tab-target]', function () {
            var tab = $(this).data('smr-tab') || $(this).data('smr-tab-target');
            switchTab(tab);
        });

        $('#smrReloadAll').on('click', function () {
            loadRequests();
            loadProducts();
            loadShops();
        });
        $('#smrRequestStatus').on('change', loadRequests);
        $('#smrRequestSearch').on('input', debounce(loadRequests, 300));

        $('#smrNewProductBtn').on('click', function () {
            resetProductForm();
            $('#smrProductForm').removeClass('d-none');
        });
        $('#smrCancelProductBtn').on('click', function () {
            $('#smrProductForm').addClass('d-none');
        });
        $('#smrRefreshProducts').on('click', loadProducts);
        $('#smrPickImageBtn, #smrProductImagePreview').on('click', openImagePicker);
        $('#smrClearImageBtn').on('click', function () {
            setProductImage('');
        });
        $('#smrProductThumb').on('input change', updateProductImagePreview);

        $('#smrSaveProductBtn').on('click', function () {
            ajax('save_temp_product', {
                temp_product_id: $('#smrProductId').val(),
                product_sku: $('#smrProductSku').val(),
                product_name: $('#smrProductName').val(),
                thumbnail_url: $('#smrProductThumb').val(),
                suggested_price: $('#smrProductPrice').val(),
                supplier_barcode: $('#smrProductBarcode').val(),
                product_description: $('#smrProductDesc').val()
            }).then(function () {
                toast('Đã lưu sản phẩm');
                $('#smrProductForm').addClass('d-none');
                loadProducts();
            }, toast);
        });

        $(document).on('click', '.smr-edit-product', function () {
            var id = $(this).closest('.tgs-smr-card').data('product-id');
            var p = state.products.find(function (x) { return Number(x.temp_product_id) === Number(id); });
            if (!p) return;
            $('#smrProductId').val(p.temp_product_id);
            $('#smrProductSku').val(p.product_sku || '');
            $('#smrProductName').val(p.product_name || '');
            setProductImage(p.thumbnail_url || '');
            $('#smrProductPrice').val(p.suggested_price || '');
            $('#smrProductBarcode').val(p.supplier_barcode || '');
            $('#smrProductDesc').val(p.product_description || '');
            $('#smrProductForm').removeClass('d-none');
            switchTab('products');
        });

        $(document).on('click', '.smr-delete-product', function () {
            if (!confirm('Xóa sản phẩm tạm này?')) return;
            var id = $(this).closest('.tgs-smr-card').data('product-id');
            ajax('delete_temp_product', {temp_product_id: id}).then(function () {
                toast('Đã xóa sản phẩm');
                loadProducts();
            }, toast);
        });

        $('#smrSelectAllShops').on('change', function () {
            $('.smr-pick-shop').prop('checked', this.checked);
        });

        $('#smrCreateRequestBtn').on('click', function () {
            var products = $('.smr-pick-product:checked').map(function () { return this.value; }).get();
            var shops = $('.smr-pick-shop:checked').map(function () { return this.value; }).get();
            ajax('create_request', {
                request_title: $('#smrRequestTitle').val(),
                note: $('#smrRequestNote').val(),
                temp_product_ids_json: JSON.stringify(products),
                shop_ids_json: JSON.stringify(shops),
                include_demo: $('#smrIncludeDemo').is(':checked') ? 1 : 0,
                demo_count: $('#smrDemoCount').val()
            }).then(function (data) {
                toast('Đã tạo phiếu');
                loadRequests();
                openRequest(data.request_id);
            }, toast);
        });

        $(document).on('click', '.smr-open-request', function () {
            openRequest($(this).data('id'));
        });

        $(document).on('input', '.smr-cell-value, .smr-cell-note', function () {
            saveCell($(this));
        });

        $(document).on('click', '.smr-delete-item', function () {
            if (!confirm('Xóa dòng sản phẩm khỏi phiếu?')) return;
            var req = state.currentRequest.request;
            ajax('delete_item', {
                request_id: req.request_id,
                request_item_id: $(this).data('item-id')
            }).then(function () {
                toast('Đã xóa dòng');
                openRequest(req.request_id);
            }, toast);
        });

        $('#smrApproveBtn').on('click', function () {
            updateStatus('approved', 'Duyệt phiếu này?');
        });
        $('#smrCancelRequestBtn').on('click', function () {
            updateStatus('cancelled', 'Hủy phiếu này? Shop sẽ không sửa được nữa.');
        });
        $('#smrApplyBtn').on('click', function () {
            if (!confirm('Áp dụng max vào bảng cấu hình tồn kho của các shop thật?')) return;
            var req = state.currentRequest.request;
            ajax('apply_request', {request_id: req.request_id}).then(function (data) {
                toast('Đã áp dụng ' + data.applied_count + ' dòng cấu hình');
                loadRequests();
                openRequest(req.request_id);
            }, toast);
        });

        $('#smrGlobalSearchBtn').on('click', function () {
            var keyword = prompt('Nhập SKU, tên hàng hoặc barcode cần tìm trong sản phẩm global:');
            if (!keyword) return;
            ajax('search_global_products', {keyword: keyword}).then(function (data) {
                var items = data.items || [];
                if (!items.length) {
                    toast('Không tìm thấy sản phẩm global');
                    return;
                }
                var p = items[0];
                $('#smrProductSku').val(p.global_product_sku || '');
                $('#smrProductName').val(p.global_product_name || '');
                setProductImage(p.global_product_thumbnail || '');
                $('#smrProductPrice').val(p.global_product_price_after_tax || '');
                $('#smrProductBarcode').val(p.global_product_barcode_main || '');
                toast('Đã lấy sản phẩm global đầu tiên tìm thấy');
            }, toast);
        });
    }

    function updateStatus(status, message) {
        if (!confirm(message)) return;
        var req = state.currentRequest.request;
        ajax('update_status', {request_id: req.request_id, status: status}).then(function () {
            toast('Đã cập nhật trạng thái');
            loadRequests();
            openRequest(req.request_id);
        }, toast);
    }

    function debounce(fn, wait) {
        var t;
        return function () {
            clearTimeout(t);
            t = setTimeout(fn, wait);
        };
    }

    $(function () {
        bindEvents();
        loadRequests();
        if (state.isWarehouse) {
            loadProducts();
            loadShops();
        }
    });
})(jQuery);
