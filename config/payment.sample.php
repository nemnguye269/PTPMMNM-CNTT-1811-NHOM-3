<?php
/**
 * CẤU HÌNH NHẬN TIỀN THẬT QUA MÃ QR (VietQR)
 * ------------------------------------------------------------
 * Điền thông tin TÀI KHOẢN NGÂN HÀNG CỦA BẠN. Tiền khách chuyển sẽ vào đúng tài khoản này.
 * Có thể đặt bằng biến môi trường thay cho file này:
 *   GTFS_BANK_BIN, GTFS_BANK_NAME, GTFS_BANK_ACCOUNT, GTFS_BANK_HOLDER, GTFS_WEBHOOK_KEY
 *
 * Mã BIN một số ngân hàng: Vietcombank 970436 · MB 970422 · Techcombank 970407 · ACB 970416
 * BIDV 970418 · VietinBank 970415 · TPBank 970423 · VPBank 970432 · Agribank 970405 · Sacombank 970403
 * (danh sách đầy đủ: https://api.vietqr.io/v2/banks)
 */
return [
    'bank_bin'     => '',   // vd: '970422'
    'bank_name'    => '',   // vd: 'MB Bank'
    'account_no'   => '',   // số tài khoản nhận tiền
    'account_name' => '',   // tên chủ tài khoản, viết HOA không dấu, vd: 'NGUYEN VAN A'

    // Khóa bí mật để xác thực webhook từ dịch vụ báo biến động số dư (SePay, Casso...).
    // Tự đặt một chuỗi dài ngẫu nhiên và dán cùng chuỗi đó vào cấu hình webhook của dịch vụ.
    'webhook_key'  => '',
];
