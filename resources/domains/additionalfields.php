<?php
/**
 * Additional domain fields (thông tin chủ thể) for the Tino Domain registrar module.
 * Copy to <whmcs>/resources/domains/additionalfields.php (merge if you already have one).
 *
 * Tỉnh/Thành phố and Phường/Xã options are loaded from api.tino.vn (cached 1 day in the
 * WHMCS DB by TinoApi) so the submitted names match the registrar exactly.
 * includes/hooks/tinowhmcs.php narrows the Phường/Xã list to the chosen Tỉnh/Thành phố.
 */

require_once __DIR__ . '/../../modules/registrars/tinowhmcs/lib/TinoApi.php';

$tinoStates = \TinoWhmcs\TinoApi::states();
$tinoWardsByState = \TinoWhmcs\TinoApi::wards();
$tinoWards = $tinoWardsByState ? array_values(array_unique(call_user_func_array('array_merge', array_values($tinoWardsByState)))) : [];

$tinoOptions = function (array $names) {
    return implode(',', str_replace(',', '&comma;', $names));
};
// ponytail: every ward (~3300) is rendered in one <select>; the hook JS filters by province. Switch to AJAX if page weight matters.
$tinoStateField = $tinoStates
    ? ['Type' => 'dropdown', 'Options' => $tinoOptions($tinoStates), 'Default' => 'Thành phố Hồ Chí Minh']
    : ['Type' => 'text', 'Size' => '60', 'Description' => 'Nhập đúng tên Tỉnh/Thành phố (vd: Thành phố Hồ Chí Minh)'];
$tinoWardField = $tinoWards
    ? ['Type' => 'dropdown', 'Options' => $tinoOptions($tinoWards)]
    : ['Type' => 'text', 'Size' => '60', 'Description' => 'Nhập đúng tên Phường/Xã (vd: Phường Bến Nghé)'];

$tinoFields = [
    ['Name' => 'chu_the', 'DisplayName' => '<b>Chủ thể đăng ký tên miền</b>', 'Type' => 'display', 'Description' => '<span style="color:red">Thông tin có dấu * là bắt buộc. Nhập đúng theo CCCD / Giấy phép kinh doanh.</span>'],
    ['Name' => 'owner_type', 'DisplayName' => 'Loại chủ thể', 'Type' => 'radio', 'Options' => 'ind|Cá nhân,org|Tổ chức / Doanh nghiệp', 'Default' => 'ind', 'Required' => true],
    ['Name' => 'owner_name', 'DisplayName' => 'Họ tên chủ thể (cá nhân)', 'Type' => 'text', 'Size' => '60', 'Required' => true],
    ['Name' => 'owner_gender', 'DisplayName' => 'Giới tính', 'Type' => 'dropdown', 'Options' => 'Nam,Nữ', 'Required' => true],
    ['Name' => 'owner_birthday', 'DisplayName' => 'Ngày sinh (dd/mm/YYYY)', 'Type' => 'text', 'Size' => '20', 'Required' => true],
    ['Name' => 'owner_person_id', 'DisplayName' => 'Số CCCD', 'Type' => 'text', 'Size' => '20', 'Required' => true],
    ['Name' => 'owner_phone', 'DisplayName' => 'Số điện thoại', 'Type' => 'text', 'Size' => '20', 'Required' => true],
    ['Name' => 'owner_email', 'DisplayName' => 'Email chủ thể', 'Type' => 'text', 'Size' => '60', 'Description' => 'Bỏ trống sẽ dùng email tài khoản'],
    ['Name' => 'owner_address', 'DisplayName' => 'Địa chỉ (số nhà, đường)', 'Type' => 'text', 'Size' => '80', 'Required' => true],
    ['Name' => 'owner_state', 'DisplayName' => 'Tỉnh / Thành phố', 'Required' => true] + $tinoStateField,
    ['Name' => 'owner_city', 'DisplayName' => 'Phường / Xã', 'Required' => true] + $tinoWardField,
    ['Name' => 'to_chuc', 'DisplayName' => '<b>Thông tin tổ chức / doanh nghiệp</b>', 'Type' => 'display', 'Description' => '<span style="color:red">Chỉ điền khi chủ thể là tổ chức / doanh nghiệp</span>'],
    ['Name' => 'congty_ten', 'DisplayName' => 'Tên tổ chức / doanh nghiệp', 'Type' => 'text', 'Size' => '80'],
    ['Name' => 'congty_mst', 'DisplayName' => 'Mã số thuế', 'Type' => 'text', 'Size' => '20'],
    ['Name' => 'congty_hkd', 'DisplayName' => 'Mã số hộ kinh doanh (nếu là HKD)', 'Type' => 'text', 'Size' => '20'],
    ['Name' => 'congty_chucvu', 'DisplayName' => 'Chức vụ người đại diện', 'Type' => 'text', 'Size' => '40'],
];

$tinoTlds = [
    '.vn', '.com.vn', '.net.vn', '.org.vn', '.edu.vn', '.gov.vn', '.info.vn', '.biz.vn', '.name.vn', '.pro.vn',
    '.health.vn', '.ac.vn', '.io.vn', '.id.vn', '.com', '.net', '.org', '.info', '.biz', '.xyz', '.top', '.co',
];
foreach ($tinoTlds as $tinoTld) {
    foreach ($tinoFields as $tinoField) {
        $additionaldomainfields[$tinoTld][] = $tinoField + ['LangVar' => ''];
    }
}
unset($tinoStates, $tinoWardsByState, $tinoWards, $tinoOptions, $tinoStateField, $tinoWardField, $tinoFields, $tinoTlds, $tinoTld, $tinoField);
