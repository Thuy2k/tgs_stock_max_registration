<?php
if (!defined('ABSPATH')) {
    exit;
}

$current_blog_id = get_current_blog_id();
$is_warehouse = TGS_SMR_Helper::is_warehouse_blog($current_blog_id);
$current_name = TGS_SMR_Helper::current_blog_name();
?>

<div class="tgs-smr-app" id="tgs-smr-app" data-is-warehouse="<?php echo esc_attr($is_warehouse ? '1' : '0'); ?>">
    <div class="tgs-smr-toolbar">
        <div>
            <h4>Đăng ký tồn max sản phẩm mới</h4>
            <div class="text-muted">
                <?php echo esc_html($current_name); ?> · Blog ID <?php echo esc_html((string) $current_blog_id); ?>
            </div>
        </div>
        <div class="tgs-smr-actions">
            <?php if ($is_warehouse): ?>
                <button type="button" class="btn btn-primary" data-smr-tab-target="create">
                    <i class="bx bx-plus-circle"></i> Tạo phiếu
                </button>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-secondary" id="smrReloadAll">
                <i class="bx bx-refresh"></i> Tải lại
            </button>
        </div>
    </div>

    <div class="tgs-smr-tabs">
        <button type="button" class="active" data-smr-tab="requests">
            <i class="bx bx-list-ul"></i> Danh sách phiếu
        </button>
        <?php if ($is_warehouse): ?>
            <button type="button" data-smr-tab="create">
                <i class="bx bx-plus-circle"></i> Tạo phiếu đăng ký
            </button>
        <?php endif; ?>
        <button type="button" data-smr-tab="review" class="d-none" id="smrReviewTab">
            <i class="bx bx-table"></i> Rà soát phiếu
        </button>
    </div>

    <section class="tgs-smr-panel active" data-smr-panel="requests">
        <div class="tgs-smr-panel-head">
            <div>
                <h5>Phiếu đăng ký</h5>
                <p><?php echo $is_warehouse ? 'Kho xem toàn bộ phiếu đã tạo cho shop con.' : 'Shop chỉ thấy phiếu được kho giao cho site hiện tại.'; ?></p>
            </div>
            <div class="tgs-smr-filter">
                <input type="search" class="form-control" id="smrRequestSearch" placeholder="Tìm mã phiếu, tiêu đề...">
                <select class="form-select" id="smrRequestStatus">
                    <option value="">Tất cả trạng thái</option>
                    <option value="open">Đang đăng ký</option>
                    <option value="approved">Đã duyệt</option>
                    <option value="cancelled">Đã hủy</option>
                    <option value="applied">Đã áp dụng</option>
                </select>
            </div>
        </div>
        <div class="tgs-smr-list" id="smrRequestList"></div>
    </section>

    <?php if ($is_warehouse): ?>
        <section class="tgs-smr-panel" data-smr-panel="create">
            <div class="tgs-smr-panel-head">
                <div>
                    <h5>Tạo phiếu đăng ký</h5>
                    <p>Tìm sản phẩm global hoặc nhập sản phẩm mới, sau đó chọn shop con theo phân cấp multisite.</p>
                </div>
            </div>

            <div class="tgs-smr-create-grid">
                <div>
                    <label class="form-label">Tiêu đề phiếu</label>
                    <input type="text" class="form-control" id="smrRequestTitle" placeholder="VD: Đăng ký max hàng Meiji tháng này">
                </div>
                <div>
                    <label class="form-label">Ghi chú</label>
                    <input type="text" class="form-control" id="smrRequestNote" placeholder="Nội dung shop cần lưu ý">
                </div>
            </div>

            <div class="tgs-smr-create-columns">
                <div class="tgs-smr-create-products">
                    <div class="tgs-smr-subhead">
                        <strong>Sản phẩm trong phiếu</strong>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="smrCancelProductBtn">
                            <i class="bx bx-refresh"></i> Nhập sản phẩm mới
                        </button>
                    </div>

                    <div class="tgs-smr-product-form" id="smrProductForm">
                        <input type="hidden" id="smrProductGlobalId">
                        <input type="hidden" id="smrProductSku">

                        <div class="tgs-smr-global-lookup">
                            <label class="form-label" for="smrGlobalSearchInput">Tìm sản phẩm global</label>
                            <div class="tgs-smr-global-searchbox">
                                <i class="bx bx-search"></i>
                                <input type="search" class="form-control" id="smrGlobalSearchInput" placeholder="Gõ SKU, tên hàng hoặc barcode">
                            </div>
                            <div class="tgs-smr-global-results d-none" id="smrGlobalResults"></div>
                            <div class="tgs-smr-global-selected" id="smrGlobalSelected">
                                Sản phẩm mới chưa có SKU. Nếu cần SKU, hãy liên hệ quản trị admin để tạo sản phẩm global trước.
                            </div>
                        </div>

                        <div class="tgs-smr-product-editor">
                            <div class="tgs-smr-image-panel">
                                <label class="form-label">Ảnh sản phẩm *</label>
                                <div class="tgs-smr-image-preview is-empty" id="smrProductImagePreview">
                                    <img src="" alt="" class="d-none" id="smrProductImage">
                                    <div class="tgs-smr-image-placeholder" id="smrProductImagePlaceholder">
                                        <i class="bx bx-image-add"></i>
                                        <span>Chọn ảnh hoặc dán URL</span>
                                    </div>
                                </div>
                                <div class="tgs-smr-image-actions">
                                    <button type="button" class="btn btn-outline-primary" id="smrPickImageBtn">
                                        <i class="bx bx-upload"></i> Chọn / upload ảnh
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" id="smrClearImageBtn">
                                        <i class="bx bx-x"></i> Xóa ảnh
                                    </button>
                                </div>
                                <input type="url" class="form-control" id="smrProductThumb" placeholder="Hoặc dán URL ảnh">
                            </div>

                            <div class="tgs-smr-product-fields">
                                <div>
                                    <label class="form-label">Tên hàng</label>
                                    <textarea class="form-control" id="smrProductName" rows="3"></textarea>
                                </div>
                                <div class="tgs-smr-field-grid">
                                    <div>
                                        <label class="form-label">Giá đề xuất</label>
                                        <input type="number" class="form-control" id="smrProductPrice" min="0" step="1">
                                    </div>
                                    <div>
                                        <label class="form-label">Barcode NCC</label>
                                        <input type="text" class="form-control" id="smrProductBarcode">
                                    </div>
                                </div>
                                <div>
                                    <label class="form-label">Mô tả</label>
                                    <input type="text" class="form-control" id="smrProductDesc">
                                </div>
                                <div class="tgs-smr-form-actions">
                                    <button type="button" class="btn btn-primary" id="smrSaveProductBtn">
                                        <i class="bx bx-plus"></i> Thêm vào phiếu
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tgs-smr-picker tgs-smr-selected-products" id="smrProductPicker"></div>
                </div>

                <div>
                    <div class="tgs-smr-subhead">
                        <strong>Chọn shop</strong>
                        <label class="tgs-smr-checkline">
                            <input type="checkbox" id="smrSelectAllShops"> Tất cả shop thật
                        </label>
                    </div>
                    <div class="tgs-smr-picker" id="smrShopPicker"></div>
                    <label class="tgs-smr-checkline mt-3">
                        <input type="checkbox" id="smrIncludeDemo" checked>
                        Thêm shop demo cho đủ dữ liệu thuyết trình
                    </label>
                    <div class="tgs-smr-demo-line">
                        <span>Số shop demo</span>
                        <input type="number" class="form-control" id="smrDemoCount" min="0" max="150" value="65">
                    </div>
                </div>
            </div>

            <div class="tgs-smr-form-actions">
                <button type="button" class="btn btn-primary" id="smrCreateRequestBtn">
                    <i class="bx bx-check-circle"></i> Tạo phiếu
                </button>
            </div>
        </section>
    <?php endif; ?>

    <section class="tgs-smr-panel" data-smr-panel="review">
        <div class="tgs-smr-review-head">
            <div>
                <h5 id="smrReviewTitle">Rà soát phiếu</h5>
                <p id="smrReviewMeta"></p>
            </div>
            <div class="tgs-smr-actions">
                <a href="#" class="btn btn-outline-primary d-none" id="smrExportBtn">
                    <i class="bx bx-download"></i> Xuất Excel
                </a>
                <?php if ($is_warehouse): ?>
                    <button type="button" class="btn btn-success" id="smrApproveBtn">Duyệt</button>
                    <button type="button" class="btn btn-warning" id="smrApplyBtn">Áp dụng max</button>
                    <button type="button" class="btn btn-outline-danger" id="smrCancelRequestBtn">Hủy</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="tgs-smr-matrix-wrap" id="smrMatrixWrap"></div>
        <div class="tgs-smr-log" id="smrLog"></div>
    </section>
</div>

<div class="tgs-smr-toast" id="smrToast"></div>
