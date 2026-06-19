(function ($) {
    'use strict';

    var $app = $('#tgs-smr-existing-app');
    if (!$app.length) return;

    var state = {
        isWarehouse: $app.data('is-warehouse') === 1 || $app.data('is-warehouse') === '1',
        products: [],
        current: null,
        requestTimer: null,
        productTimer: null
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

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function qty(value) {
        if (value === null || value === undefined || value === '') return '';
        var n = Number(String(value).replace(',', '.'));
        if (Number.isNaN(n)) return '';
        return Number.isInteger(n) ? String(n) : String(n).replace(/0+$/, '').replace(/\.$/, '');
    }

    function numberValue(value) {
        if (value === null || value === undefined || $.trim(String(value)) === '') return null;
        var n = Number(String(value).replace(',', '.'));
        return Number.isNaN(n) ? false : n;
    }

    function toast(message) {
        var $toast = $('#smreToast');
        $toast.text(message).addClass('show');
        clearTimeout($toast.data('timer'));
        $toast.data('timer', setTimeout(function () {
            $toast.removeClass('show');
        }, 2600));
    }

    function switchTab(tab) {
        $('[data-smre-tab]').removeClass('active');
        $('[data-smre-tab="' + tab + '"]').addClass('active');
        $('[data-smre-panel]').removeClass('active');
        $('[data-smre-panel="' + tab + '"]').addClass('active');
        if (tab === 'detail') {
            $('#smreDetailTab').removeClass('d-none').addClass('active');
        }
    }

    function renderEmpty($target, message) {
        $target.html('<div class="tgs-smr-card"><span class="text-muted">' + esc(message) + '</span></div>');
    }

    function loadRequests() {
        var $list = $('#smreRequestList');
        renderEmpty($list, 'Đang tải danh sách phiếu...');
        return ajax('existing_list_requests', {
            mode: state.isWarehouse ? 'warehouse' : 'shop',
            status: $('#smreRequestStatus').val() || '',
            search: $('#smreRequestSearch').val() || ''
        }).then(function (data) {
            renderRequests(data.items || []);
        }, function (msg) {
            renderEmpty($list, msg);
        });
    }

    function renderRequests(items) {
        var $list = $('#smreRequestList');
        if (!items.length) {
            renderEmpty($list, 'Chưa có phiếu đăng ký max nào.');
            return;
        }

        $list.html(items.map(function (row) {
            var statusClass = 'smre-status-' + esc(row.status || '');
            return '<div class="tgs-smr-card smre-request-card">' +
                '<div>' +
                    '<div class="tgs-smr-card-title">' +
                        '<span>' + esc(row.request_title || row.request_code) + '</span>' +
                        '<span class="smre-status ' + statusClass + '">' + esc(row.status_label || row.status) + '</span>' +
                    '</div>' +
                    '<div class="text-muted">' + esc(row.request_code || '') + ' · ' + esc(row.shop_blog_name_cache || '') + ' · ' + esc(row.changed_count || row.item_count || 0) + ' SKU thay đổi</div>' +
                    '<div class="text-muted smre-summary">' + esc(row.summary || row.note || '-') + '</div>' +
                '</div>' +
                '<button type="button" class="btn btn-primary smre-open-request" data-id="' + esc(row.request_id) + '">' +
                    '<i class="bx bx-show"></i> Mở rà soát' +
                '</button>' +
            '</div>';
        }).join(''));
    }

    function loadProducts(all) {
        var $tbody = $('#smreProductTable tbody');
        $tbody.html('<tr><td colspan="6" class="text-center text-muted">Đang tải sản phẩm...</td></tr>');
        return ajax('existing_products', {
            all: all ? 1 : 0,
            search: $('#smreProductSearch').val() || ''
        }).then(function (data) {
            state.products = data.items || [];
            $('#smreProductCount').text('Đã tải ' + state.products.length + ' mã' + (data.is_truncated ? ' (đang giới hạn)' : ''));
            renderProductRows();
        }, function (msg) {
            $tbody.html('<tr><td colspan="6" class="text-center text-danger">' + esc(msg) + '</td></tr>');
        });
    }

    function renderProductRows() {
        var $tbody = $('#smreProductTable tbody');
        if (!state.products.length) {
            $tbody.html('<tr><td colspan="6" class="text-center text-muted">Không tìm thấy sản phẩm.</td></tr>');
            return;
        }

        $tbody.html(state.products.map(function (p, idx) {
            return '<tr data-index="' + idx + '">' +
                '<td><strong>' + esc(p.product_sku || p.global_product_sku) + '</strong></td>' +
                '<td>' + esc(p.product_name || p.global_product_name) + '</td>' +
                '<td class="text-end smre-current-max">' + esc(qty(p.current_max_qty || 0)) + '</td>' +
                '<td><input type="number" min="0" step="0.001" class="form-control form-control-sm smre-new-max" placeholder="Max mới"></td>' +
                '<td><input type="text" class="form-control form-control-sm smre-row-note" placeholder="Ghi chú dòng"></td>' +
                '<td class="smre-warning-cell text-muted">-</td>' +
            '</tr>';
        }).join(''));
    }

    function updateCreateWarnings() {
        $('#smreProductTable tbody tr[data-index]').each(function () {
            var $tr = $(this);
            var p = state.products[Number($tr.data('index'))] || {};
            var current = numberValue(p.current_max_qty || 0) || 0;
            var proposed = numberValue($tr.find('.smre-new-max').val());
            var $warning = $tr.find('.smre-warning-cell');

            $tr.removeClass('smre-row-changed smre-row-invalid');
            if (proposed === null) {
                $warning.text('-').removeClass('text-danger text-success').addClass('text-muted');
            } else if (proposed === false) {
                $tr.addClass('smre-row-invalid');
                $warning.text('Max mới không hợp lệ').removeClass('text-muted text-success').addClass('text-danger');
            } else if (Math.abs(proposed - current) > 0.0001) {
                $tr.addClass('smre-row-changed');
                $warning.text('Sẽ cập nhật: ' + qty(current) + ' → ' + qty(proposed)).removeClass('text-muted text-danger').addClass('text-success');
            } else {
                $warning.text('Không đổi, sẽ bỏ qua').removeClass('text-muted text-success').addClass('text-danger');
            }
        });
    }

    function collectCreateItems() {
        var items = [];
        $('#smreProductTable tbody tr[data-index]').each(function () {
            var $tr = $(this);
            var p = state.products[Number($tr.data('index'))] || {};
            var proposed = numberValue($tr.find('.smre-new-max').val());
            var current = numberValue(p.current_max_qty || 0) || 0;
            if (proposed === null || proposed === false || Math.abs(proposed - current) <= 0.0001) {
                return;
            }
            items.push({
                product_sku: p.product_sku || p.global_product_sku || '',
                proposed_max_qty: proposed,
                shop_note: $tr.find('.smre-row-note').val() || ''
            });
        });
        return items;
    }

    function createRequest() {
        var items = collectCreateItems();
        if (!items.length) {
            toast('Chưa có dòng nào có Max mới khác Max hiện tại.');
            return;
        }
        ajax('existing_create_request', {
            request_title: $('#smreCreateTitle').val() || '',
            note: $('#smreCreateNote').val() || '',
            items_json: JSON.stringify(items)
        }).then(function (data) {
            toast('Đã tạo phiếu đăng ký Max.');
            $('#smreCreateTitle, #smreCreateNote').val('');
            state.products = [];
            renderProductRows();
            loadRequests();
            openRequest(data.request_id);
        }, toast);
    }

    function openRequest(id) {
        ajax('existing_get_request', {request_id: id}).then(function (data) {
            state.current = data;
            renderDetail(data);
            switchTab('detail');
        }, toast);
    }

    function renderDetail(data) {
        var request = data.request || {};
        var items = data.items || [];
        $('#smreDetailTitle').text((request.request_title || 'Phiếu đăng ký Max') + ' · ' + (request.status_label || request.status || ''));
        $('#smreDetailMeta').text((request.request_code || '') + ' · ' + (request.shop_blog_name_cache || '') + ' · ' + (request.changed_count || items.length) + ' SKU');
        $('#smreDetailNote').val(request.note || '').prop('disabled', !data.can_shop_edit);
        $('#smreWarehouseNote').val(request.warehouse_note || '').prop('disabled', !data.can_warehouse_review);
        renderStats(data);
        renderDetailActions(data);
        renderDetailRows(data);
        renderLogs(data.logs || []);
    }

    function renderStats(data) {
        var stats = data.stats || {};
        $('#smreStats').html([
            '<span><strong>' + esc(stats.item_count || 0) + '</strong> SKU thay đổi</span>',
            '<span><strong>' + esc(stats.snapshot_changed || 0) + '</strong> dòng max hiện tại đã lệch lúc tạo</span>',
            '<span><strong>' + esc((data.request && data.request.status_label) || '') + '</strong> trạng thái</span>'
        ].join(''));
    }

    function renderDetailActions(data) {
        var html = '';
        if (data.export_url) {
            html += '<a class="btn btn-outline-secondary" href="' + esc(data.export_url) + '"><i class="bx bx-download"></i> Xuất Excel</a>';
        }
        if (data.can_shop_edit) {
            html += '<button type="button" class="btn btn-primary" id="smreSaveShopBtn"><i class="bx bx-save"></i> Lưu phiếu</button>';
        }
        if (data.can_warehouse_review) {
            html += '<button type="button" class="btn btn-outline-primary" id="smreSaveWarehouseBtn"><i class="bx bx-note"></i> Lưu ghi chú kho</button>';
        }
        if (data.can_warehouse_review && data.request.status === 'submitted') {
            html += '<button type="button" class="btn btn-success" id="smreApproveBtn"><i class="bx bx-check"></i> Duyệt phiếu</button>';
            html += '<button type="button" class="btn btn-outline-danger" id="smreCancelBtn"><i class="bx bx-x"></i> Hủy</button>';
        }
        if (data.can_apply && data.request.status === 'approved') {
            html += '<button type="button" class="btn btn-success" id="smreApplyBtn"><i class="bx bx-upload"></i> Cập nhật max cho shop</button>';
        }
        $('#smreDetailActions').html(html);
    }

    function renderDetailRows(data) {
        var canShop = !!data.can_shop_edit;
        var canWarehouse = !!data.can_warehouse_review;
        var rows = (data.items || []).map(function (item) {
            var warn = item.snapshot_changed ? 'Max hiện tại đã khác lúc tạo phiếu' : '-';
            return '<tr data-item-id="' + esc(item.item_id) + '" data-sku="' + esc(item.product_sku) + '">' +
                '<td><strong>' + esc(item.product_sku) + '</strong></td>' +
                '<td>' + esc(item.product_name) + '</td>' +
                '<td class="text-end">' + esc(item.current_max_qty_display || qty(item.current_max_qty)) + '</td>' +
                '<td class="text-end ' + (item.snapshot_changed ? 'text-danger fw-bold' : '') + '">' + esc(item.latest_max_qty || '0') + '</td>' +
                '<td>' + (canShop ? '<input type="number" min="0" step="0.001" class="form-control form-control-sm smre-detail-proposed" value="' + esc(qty(item.proposed_max_qty)) + '">' : '<strong>' + esc(item.proposed_max_qty_display || qty(item.proposed_max_qty)) + '</strong>') + '</td>' +
                '<td>' + (canWarehouse ? '<input type="number" min="0" step="0.001" class="form-control form-control-sm smre-warehouse-max" placeholder="Để trống nếu theo shop" value="' + esc(qty(item.warehouse_max_qty)) + '">' : esc(item.warehouse_max_qty_display || '-')) + '</td>' +
                '<td>' + (canShop ? '<input type="text" class="form-control form-control-sm smre-detail-shop-note" value="' + esc(item.shop_note || '') + '">' : esc(item.shop_note || '-')) + '</td>' +
                '<td>' + (canWarehouse ? '<input type="text" class="form-control form-control-sm smre-detail-warehouse-note" value="' + esc(item.warehouse_note || '') + '">' : esc(item.warehouse_note || '-')) + '</td>' +
                '<td class="' + (item.snapshot_changed ? 'text-danger fw-bold' : 'text-muted') + '">' + esc(warn) + '</td>' +
            '</tr>';
        }).join('');
        $('#smreDetailTable tbody').html(rows || '<tr><td colspan="9" class="text-center text-muted">Phiếu chưa có dòng SKU.</td></tr>');
    }

    function renderLogs(logs) {
        if (!logs.length) {
            $('#smreLogList').html('<div class="text-muted">Chưa có log thay đổi.</div>');
            return;
        }
        $('#smreLogList').html(logs.map(function (log) {
            return '<div class="tgs-smr-log-item">' +
                '<strong>' + esc(log.action || '') + '</strong>' +
                '<span>' + esc(log.created_at || '') + ' · User #' + esc(log.actor_user_id || '') + ' · Blog #' + esc(log.actor_blog_id || '') + '</span>' +
                '<p>' + esc(log.note || log.new_value || '-') + '</p>' +
            '</div>';
        }).join(''));
    }

    function collectDetailShopItems() {
        var items = [];
        $('#smreDetailTable tbody tr[data-sku]').each(function () {
            var $tr = $(this);
            items.push({
                product_sku: $tr.data('sku'),
                proposed_max_qty: $tr.find('.smre-detail-proposed').val(),
                shop_note: $tr.find('.smre-detail-shop-note').val() || ''
            });
        });
        return items;
    }

    function collectWarehouseItems() {
        var items = [];
        $('#smreDetailTable tbody tr[data-item-id]').each(function () {
            var $tr = $(this);
            items.push({
                item_id: $tr.data('item-id'),
                warehouse_max_qty: $tr.find('.smre-warehouse-max').val(),
                warehouse_note: $tr.find('.smre-detail-warehouse-note').val() || ''
            });
        });
        return items;
    }

    function saveShopRequest() {
        if (!state.current || !state.current.request) return;
        ajax('existing_save_request', {
            request_id: state.current.request.request_id,
            request_title: state.current.request.request_title || '',
            note: $('#smreDetailNote').val() || '',
            items_json: JSON.stringify(collectDetailShopItems())
        }).then(function () {
            toast('Đã lưu phiếu.');
            openRequest(state.current.request.request_id);
            loadRequests();
        }, toast);
    }

    function saveWarehouseReview(reload) {
        if (!state.current || !state.current.request) return;
        reload = reload !== false;
        return ajax('existing_save_warehouse_review', {
            request_id: state.current.request.request_id,
            warehouse_note: $('#smreWarehouseNote').val() || '',
            items_json: JSON.stringify(collectWarehouseItems())
        }).then(function () {
            toast('Đã lưu ghi chú kho.');
            if (reload) {
                openRequest(state.current.request.request_id);
            }
            return true;
        }, function (msg) {
            toast(msg);
            return $.Deferred().reject(msg).promise();
        });
    }

    function updateStatus(status) {
        if (!state.current || !state.current.request) return;
        ajax('existing_update_status', {
            request_id: state.current.request.request_id,
            status: status
        }).then(function () {
            toast(status === 'approved' ? 'Đã duyệt phiếu.' : 'Đã hủy phiếu.');
            openRequest(state.current.request.request_id);
            loadRequests();
        }, toast);
    }

    function applyRequest() {
        if (!state.current || !state.current.request) return;
        ajax('existing_apply_request', {
            request_id: state.current.request.request_id
        }).then(function (data) {
            toast('Đã cập nhật max cho ' + (data.applied_count || 0) + ' SKU.');
            openRequest(state.current.request.request_id);
            loadRequests();
        }, toast);
    }

    function bindEvents() {
        $('#smreReloadBtn').on('click', loadRequests);
        $('#smreOpenCreateBtn').on('click', function () { switchTab('create'); });
        $('[data-smre-tab]').on('click', function () { switchTab($(this).data('smre-tab')); });
        $('#smreRequestStatus').on('change', loadRequests);
        $('#smreRequestSearch').on('input', function () {
            clearTimeout(state.requestTimer);
            state.requestTimer = setTimeout(loadRequests, 250);
        });
        $('#smreRequestList').on('click', '.smre-open-request', function () {
            openRequest($(this).data('id'));
        });
        $('#smreFillAllBtn').on('click', function () { loadProducts(true); });
        $('#smreSearchProductsBtn').on('click', function () { loadProducts(false); });
        $('#smreProductSearch').on('input', function () {
            clearTimeout(state.productTimer);
            state.productTimer = setTimeout(function () { loadProducts(false); }, 350);
        });
        $('#smreProductTable').on('input', '.smre-new-max', updateCreateWarnings);
        $('#smreCreateSubmitBtn').on('click', createRequest);
        $('#smreDetailActions').on('click', '#smreSaveShopBtn', saveShopRequest);
        $('#smreDetailActions').on('click', '#smreSaveWarehouseBtn', saveWarehouseReview);
        $('#smreDetailActions').on('click', '#smreApproveBtn', function () {
            saveWarehouseReview(false).then(function () { updateStatus('approved'); });
        });
        $('#smreDetailActions').on('click', '#smreCancelBtn', function () { updateStatus('cancelled'); });
        $('#smreDetailActions').on('click', '#smreApplyBtn', function () {
            saveWarehouseReview(false).then(applyRequest);
        });
    }

    bindEvents();
    loadRequests();
})(jQuery);
