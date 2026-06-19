<?php
if (!defined('ABSPATH')) {
    exit;
}

$current_blog_id = get_current_blog_id();
$is_warehouse = TGS_SMR_Helper::is_warehouse_blog($current_blog_id);
$current_name = TGS_SMR_Helper::current_blog_name();
?>

<div class="tgs-smr-app tgs-smr-existing-app" id="tgs-smr-existing-app" data-is-warehouse="<?php echo esc_attr($is_warehouse ? '1' : '0'); ?>">
    <div class="tgs-smr-toolbar">
        <div>
            <h4>Đăng ký Max cho sản phẩm đã có mã hàng</h4>
            <div class="text-muted">
                <?php echo esc_html($current_name); ?> · Blog ID <?php echo esc_html((string) $current_blog_id); ?>
            </div>
        </div>
        <div class="tgs-smr-actions">
            <?php if (!$is_warehouse): ?>
                <button type="button" class="btn btn-primary" id="smreOpenCreateBtn">
                    <i class="bx bx-plus-circle"></i> Tạo phiếu đăng ký
                </button>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-secondary" id="smreReloadBtn">
                <i class="bx bx-refresh"></i> Tải lại
            </button>
        </div>
    </div>

    <div class="tgs-smr-tabs">
        <button type="button" class="active" data-smre-tab="requests">
            <i class="bx bx-list-ul"></i> Danh sách phiếu
        </button>
        <?php if (!$is_warehouse): ?>
            <button type="button" data-smre-tab="create">
                <i class="bx bx-edit"></i> Tạo phiếu
            </button>
        <?php endif; ?>
        <button type="button" data-smre-tab="detail" class="d-none" id="smreDetailTab">
            <i class="bx bx-check-square"></i> Rà soát phiếu
        </button>
    </div>

    <section class="tgs-smr-panel active" data-smre-panel="requests">
        <div class="tgs-smr-panel-head">
            <div>
                <h5>Phiếu cập nhật Max</h5>
                <p><?php echo $is_warehouse ? 'Kho xem phiếu shop con gửi lên, ưu tiên phiếu chờ duyệt ở đầu danh sách.' : 'Shop tạo và theo dõi trạng thái cập nhật Max của chính shop hiện tại.'; ?></p>
            </div>
            <div class="tgs-smr-filter">
                <input type="search" class="form-control" id="smreRequestSearch" placeholder="Tìm mã phiếu, SKU, tên hàng, ghi chú...">
                <select class="form-select" id="smreRequestStatus">
                    <option value="">Tất cả trạng thái</option>
                    <option value="submitted">Chờ kho duyệt</option>
                    <option value="approved">Đã duyệt</option>
                    <option value="applied">Đã cập nhật max</option>
                    <option value="cancelled">Đã hủy</option>
                </select>
            </div>
        </div>
        <div class="tgs-smr-list" id="smreRequestList"></div>
    </section>

    <?php if (!$is_warehouse): ?>
        <section class="tgs-smr-panel" data-smre-panel="create">
            <div class="tgs-smr-panel-head">
                <div>
                    <h5>Tạo phiếu đăng ký Max</h5>
                    <p>Fill toàn bộ sản phẩm hoặc tìm nhanh theo SKU/tên hàng. Dòng trống hoặc Max mới không đổi sẽ tự bỏ qua khi tạo phiếu.</p>
                </div>
                <div class="tgs-smr-actions">
                    <button type="button" class="btn btn-outline-primary" id="smreFillAllBtn">
                        <i class="bx bx-grid-alt"></i> Fill toàn bộ sản phẩm
                    </button>
                    <button type="button" class="btn btn-primary" id="smreCreateSubmitBtn">
                        <i class="bx bx-send"></i> Tạo phiếu
                    </button>
                </div>
            </div>

            <div class="tgs-smr-field-grid smre-create-meta">
                <div>
                    <label class="form-label">Tiêu đề phiếu</label>
                    <input type="text" class="form-control" id="smreCreateTitle" placeholder="VD: Đăng ký cập nhật max tháng này">
                </div>
                <div>
                    <label class="form-label">Ghi chú phiếu</label>
                    <input type="text" class="form-control" id="smreCreateNote" placeholder="Ghi chú tổng quan cho kho">
                </div>
            </div>

            <div class="smre-product-tools">
                <div class="tgs-smr-global-searchbox">
                    <i class="bx bx-search"></i>
                    <input type="search" class="form-control" id="smreProductSearch" placeholder="Tìm SKU, tên hàng, barcode...">
                </div>
                <button type="button" class="btn btn-outline-secondary" id="smreSearchProductsBtn">
                    <i class="bx bx-search-alt"></i> Tìm
                </button>
                <span class="text-muted" id="smreProductCount">Chưa tải sản phẩm</span>
            </div>

            <div class="smre-table-wrap">
                <table class="smre-grid-table" id="smreProductTable">
                    <thead>
                    <tr>
                        <th style="width: 150px;">Mã hàng</th>
                        <th>Tên hàng</th>
                        <th style="width: 130px;">Max hiện tại</th>
                        <th style="width: 150px;">Max mới</th>
                        <th style="width: 260px;">Ghi chú dòng</th>
                        <th style="width: 170px;">Cảnh báo</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr><td colspan="6" class="text-center text-muted">Bấm Fill toàn bộ sản phẩm hoặc tìm SKU để bắt đầu.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <section class="tgs-smr-panel" data-smre-panel="detail">
        <div class="tgs-smr-review-head">
            <div>
                <h5 id="smreDetailTitle">Rà soát phiếu</h5>
                <p id="smreDetailMeta" class="text-muted"></p>
            </div>
            <div class="tgs-smr-actions" id="smreDetailActions"></div>
        </div>

        <div class="smre-stats" id="smreStats"></div>

        <div class="tgs-smr-field-grid">
            <div>
                <label class="form-label">Ghi chú shop</label>
                <textarea class="form-control" id="smreDetailNote" rows="2" placeholder="Ghi chú của shop"></textarea>
            </div>
            <div>
                <label class="form-label">Ghi chú kho</label>
                <textarea class="form-control" id="smreWarehouseNote" rows="2" placeholder="Kho ghi chú khi rà soát"></textarea>
            </div>
        </div>

        <div class="smre-table-wrap">
            <table class="smre-grid-table" id="smreDetailTable">
                <thead>
                <tr>
                    <th style="width: 145px;">Mã hàng</th>
                    <th>Tên hàng</th>
                    <th style="width: 120px;">Max lúc tạo</th>
                    <th style="width: 120px;">Max hiện tại</th>
                    <th style="width: 120px;">Max shop đề xuất</th>
                    <th style="width: 140px;">Max kho chốt</th>
                    <th style="width: 230px;">Ghi chú shop</th>
                    <th style="width: 230px;">Ghi chú kho</th>
                    <th style="width: 170px;">Cảnh báo</th>
                </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <div class="tgs-smr-history">
            <h6>Log thay đổi</h6>
            <div id="smreLogList" class="tgs-smr-log-list"></div>
        </div>
    </section>

    <div class="tgs-smr-toast" id="smreToast"></div>
</div>
