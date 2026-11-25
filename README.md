# 🎓 BrightFuture — Nền Tảng Giáo Dục & Học Tập Trực Tuyến Hiện Đại

> **BrightFuture** là hệ thống quản lý học tập trực tuyến (LMS - Learning Management System) toàn diện, được thiết kế theo tiêu chuẩn công nghiệp: giao diện hiện đại, sạch sẽ (chống "mác template AI"), tối ưu bảo mật, kiến trúc Model Hướng đối tượng (OOP) vững chắc, hỗ trợ phòng gọi video WebRTC thời gian thực và trải nghiệm người dùng chuẩn mực.

---

## 🌟 Tính Năng Nổi Bật

### 🧑‍🎓 Dành Cho Học Viên (Student)
- **Landing Page & Khám phá khóa học**: Giao diện trang chủ chuyên nghiệp, thống kê thực tế từ database, tìm kiếm nhanh (`Ctrl + K`), lọc khóa học theo danh mục, cấp độ và số sao đánh giá.
- **Learning Studio thông minh**:
  - Tự động lưu và khôi phục vị trí xem video (`resume playback`).
  - Tự động đánh dấu hoàn thành bài học khi xem đủ 90% thời lượng (chỉ áp dụng với bài giảng có video).
  - Hỗ trợ hoàn thành bài học văn bản/mã nguồn không cần video.
  - Phím tắt bàn phím: `Space` (Dừng/Phát), `Alt + →` / `Alt + ←` (Chuyển bài), `Esc` (Đóng mục lục), `Ctrl + S` (Lưu ghi chú tức thì).
  - Chế độ tập trung (**Focus Mode**): Ẩn các thanh menu điều hướng để tập trung tối đa vào bài giảng.
  - Khung **Ghi chú cá nhân (Student Notes)** tích hợp cạnh bài giảng, tự động lưu sau 1.2s (Debounce).
- **Làm bài tập & Thi trắc nghiệm thông minh**:
  - Hỗ trợ bài tập trắc nghiệm linh hoạt số lượng câu hỏi (**Dynamic Quiz Sizing**: từ 1 đến nhiều câu), tự động tính điểm theo tỷ lệ phần trăm chính xác.
  - **Bộ đếm thời gian làm bài (Quiz Timer)**: Đếm ngược thời gian thực, cảnh báo khi sắp hết giờ và tự động nộp bài khi hết giờ để chống gian lận.
  - Hỗ trợ nộp bài tập lập trình (Coding), tự luận văn bản và tải tệp đính kèm (`file_upload`).
  - Hỗ trợ nộp lại bài khi giảng viên yêu cầu chỉnh sửa/bổ sung mà không bị chặn bởi giới hạn số lần nộp.
- **Chuỗi ngày học tập (Learning Streak 🔥) & Thống kê tuần**:
  - Tự động theo dõi số ngày học tập liên tục để khích lệ tinh thần học viên.
  - Biểu đồ phân bổ 7 ngày trong tuần trực quan ngay trên Dashboard.
- **Đánh giá khóa học (Course Reviews)**: Gửi nhận xét và chấm điểm sao (1 - 5 ⭐) cho khóa học đã tham gia.
- **Chứng chỉ điện tử & Xác thực bảo mật**:
  - Tự động cấp chứng chỉ khi hoàn thành 100% nội dung khóa học.
  - Watermark bảo mật chìm xoay góc chống làm giả (`BRIGHTFUTURE ACADEMY · OFFICIAL VERIFIED`).
  - Tối ưu in ấn và xuất PDF chuẩn khổ ngang A4 (`@media print` tự động ẩn thanh điều hướng).
  - Trang tra cứu công khai `certificate.php?verify=CODE` để nhà tuyển dụng xác minh tính xác thực.
- **Thảo luận & Hỏi đáp**: Đặt câu hỏi theo từng bài giảng, nhận phản hồi trực tiếp từ giảng viên phụ trách kèm thông báo Toast thời gian thực.

---

### 👨‍🏫 Dành Cho Giảng Viên (Teacher)
- **Quy trình đăng ký chuẩn mực**: Giảng viên đăng ký hồ sơ trực tuyến kèm thông tin chuyên môn giảng dạy và kinh nghiệm; quyền giảng dạy được kích hoạt sau khi Quản trị viên (Admin) phê duyệt.
- **Quản lý khóa học & Bài giảng**:
  - Tạo khóa học, phân chia theo từng chương (Chapters), soạn thảo bài giảng (Video, Mã nguồn, Văn bản) và thiết lập bài tập.
  - Cơ chế bảo vệ chất lượng: Chặn bật công khai khóa học rỗng (chưa có bài học).
- **Soạn thảo & Chấm bài tập**:
  - Tạo bài tập trắc nghiệm linh hoạt câu hỏi (chặn công khai nếu chưa có câu hỏi nào).
  - Chấm bài nộp của học viên, nhập điểm số, nhận xét góp ý hoặc yêu cầu học viên làm lại/bổ sung.
- **Thống kê Phổ điểm Trực quan (Grade Analytics)**:
  - Biểu đồ phân bổ điểm số của lớp theo 4 mức chuẩn giáo dục: Giỏi/Xuất sắc (≥85%), Khá (70% - 84%), Trung bình (50% - 69%), Chưa đạt (<50%).
  - Tự động tính điểm trung bình của cả lớp.

---

### 📹 Phòng Gọi Nhóm Trực Tuyến (WebRTC Meeting & Green Room)
- **Gọi video WebRTC Full-Mesh**: Gọi nhóm trực tiếp trong trình duyệt, hỗ trợ tối đa 6 người cùng lúc, không cần cài đặt phần mềm bên thứ ba.
- **Phòng chờ chuyên nghiệp (Green Room / Waiting Lobby)**:
  - Trải nghiệm trước khi vào phòng tương tự Zoom / Google Meet.
  - Soi gương kiểm tra hình ảnh Camera và kiểm tra âm thanh Micro trước khi tham gia.
  - Tự động giải phóng luồng phần cứng khi chuyển trang hoặc vào phòng.
  - Bảo mật phòng họp bằng Mật khẩu (Password Protected Room).
- **Cơ chế Khóa Đa Tab (Multi-Tab Protection)**:
  - Sử dụng `BroadcastChannel` phát hiện và cảnh báo khi người dùng mở cùng 1 phòng trên nhiều tab, ngăn chặn xung đột tín hiệu WebRTC.
- **Chống Rời Phòng Đột Ngột**: Cảnh báo khi người dùng vô tình bấm vào liên kết menu hoặc đóng tab khi đang trong cuộc gọi.
- **Thông báo sự kiện trong Chat**: Tự động thông báo khi có thành viên tham gia hoặc rời phòng.

---

### 🛡️ Dành Cho Quản Trị Viên (Admin)
- **Phê duyệt Giảng viên**: Bảng điều khiển riêng biệt hiển thị các đơn đăng ký giảng viên mới, hỗ trợ duyệt nhanh hoặc từ chối có lưu vết kiểm toán.
- **Quản trị người dùng & Phân quyền**: Quản lý tài khoản (Học viên, Giảng viên, Quản trị viên), hỗ trợ tìm kiếm, lọc trạng thái, khóa hoặc khôi phục tài khoản (Soft Delete).
- **Kiểm toán & Báo cáo**: Ghi nhận toàn bộ hoạt động nhạy cảm vào bảng `bf_audit_logs` (ai thực hiện, thao tác gì, lúc nào, địa chỉ IP nào).
- **Điều phối nội dung**: Quản lý và kiểm duyệt các khóa học trên toàn hệ thống.

---

## 🔒 Kiến Trúc & Bảo Mật

- **Mô hình Lớp Hướng Đối Tượng (OOP Models)**:
  - Tách bạch tầng xử lý dữ liệu chuẩn Clean Architecture: `classes/Database.php` (Singleton PDO), `Course.php`, `Exercise.php`, `Lesson.php`, `Review.php`, `User.php`.
- **Hệ thống Layout Thống nhất (Unified Layout Architecture)**:
  - Mọi trang Portal đều dùng chung bộ 4 layout chuẩn: `portal_head.php`, `bf_unified_sidebar`, `portal_topbar.php`, `portal_foot.php`.
  - Icon chuẩn hóa 100% bằng vector SVG sắc nét (`bf_icon()`).
- **Gửi Email thật qua Giao thức SMTP**:
  - Tích hợp gửi email xác thực tài khoản, quên mật khẩu và đặt lại mật khẩu an toàn qua Gmail SMTP (`STARTTLS`).
- **Hệ thống Design Token tập trung (`css/tokens.css`)**: Font chữ Google Font Inter, màu sắc, khoảng cách, bo góc và đổ bóng nhất quán.
- **Bảo mật Request & Chống Tấn công**:
  - 100% truy vấn dữ liệu dùng **PDO Prepared Statements** (chống SQL Injection tuyệt đối).
  - Toàn bộ dữ liệu hiển thị qua hàm `e()` để chống **Cross-Site Scripting (XSS)**.
  - Toàn bộ form POST đều có **CSRF Token** xác thực với `hash_equals()`.
  - Bảo vệ Session: Chống Session Fixation, Session Hijacking, đổi Session ID định kỳ mỗi 15 phút.
  - Xác thực 2 bước: Hỗ trợ mã xác thực **TOTP 2FA** (Google Authenticator) kèm mã dự phòng.
  - Tệp bài tập học viên được lưu trong thư mục riêng biệt ngoài web root và được bảo vệ tải qua phân quyền chặt chẽ.
  - Giới hạn tần suất (**Rate Limiting**): Chống tấn công brute-force đăng nhập và giới hạn yêu cầu đặt lại mật khẩu.

---

## 🚀 Hướng Dẫn Cài Đặt & Chạy Cục Bộ (Localhost)

### Yêu Cầu Môi Trường
- **Laragon** (khuyên dùng trên Windows) hoặc XAMPP / Docker
- **PHP**: Phiên bản 8.1 trở lên
- **MySQL**: Phiên bản 8.0+
- **Node.js**: Phiên bản 18+ (để chạy bộ kiểm thử tự động Playwright)

### Các Bước Thực Hiện

1. **Khởi động dịch vụ trong Laragon**:
   - Mở phần mềm Laragon và bấm nút **Start All** (khởi chạy Apache và MySQL).

2. **Cấu hình Cơ sở dữ liệu**:
   - Mở trình quản lý MySQL (HeidiSQL hoặc phpMyAdmin) và tạo database:
     ```sql
     CREATE DATABASE IF NOT EXISTS `brightfuture` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     ```
   - Tạo tệp `.env` từ `.env.example` và cấu hình thông số kết nối:
     ```ini
     DB_HOST=127.0.0.1
     DB_PORT=3306
     DB_NAME=brightfuture
     DB_USER=root
     DB_PASS=
     ```

3. **Chạy Migration Cơ sở dữ liệu**:
   - Mở terminal trong thư mục dự án và chạy lệnh sau để tự động áp dụng 14 bản cập nhật cơ sở dữ liệu:
     ```bash
     php scripts/migrate.php migrate
     ```
   - Để kiểm tra trạng thái các bản cập nhật:
     ```bash
     php scripts/migrate.php status
     ```

4. **Truy cập Ứng dụng**:
   - Mở trình duyệt và truy cập:
     ```
     http://localhost/BrightFuture-Educational-Website-main/
     ```

---

## 🧪 Chạy Kiểm Thử Tự Động (Testing)

Dự án đi kèm bộ kiểm thử tự động toàn diện theo chuẩn Zero Regression:

### 1. Kiểm thử Tích hợp & Cú pháp Backend (PHP Unit & Integration Test)
Kiểm tra kết nối DB, toàn vẹn bảng, 14 migrations, token CSRF, TOTP 2FA, rate limits, và cú pháp PHP:
```bash
php scripts/test.php
```
*(Kết quả: **43/43 tests PASS - 100%**)*

### 2. Kiểm thử Trải nghiệm Đầu cuối (End-to-End Playwright Test)
Mô phỏng hành vi người dùng thực tế trên trình duyệt Chromium (đăng nhập đa vai trò, học bài, làm bài tập, xem chứng chỉ, giao diện mobile 360px):
```bash
npx playwright test
```
*(Kết quả: **17/17 tests PASS - 100%**)*

---

## 🔑 Tài Khoản Trải Nghiệm Mẫu (Demo Accounts)

Mật khẩu mặc định cho toàn bộ tài khoản thử nghiệm là: `Dev123456!`

| Vai trò | Địa chỉ Email | Chức năng chính |
|---------|---------------|-----------------|
| **Học viên (Student)** | `student@brightfuture.local` | Học bài, làm trắc nghiệm/code, ghi chú, xem và in chứng chỉ, gọi nhóm |
| **Giảng viên (Teacher)** | `teacher@brightfuture.local` | Quản lý khóa học, tạo bài giảng, bài tập, chấm điểm, thống kê phổ điểm |
| **Quản trị viên (Admin)** | `admin@brightfuture.local` | Duyệt ứng viên giảng viên, quản trị người dùng, xem audit log |

---

## 📁 Cấu Trúc Thư Mục Dự Án

```text
├── admin/                  # Không gian quản trị viên (Users, Courses, Moderation, Audit)
├── api/                    # RESTful endpoints (Notes, Courses, Progress, Submissions, AI)
├── classes/                # Tầng Model OOP (Database, Course, Exercise, Lesson, Review, User)
├── config/                 # Cấu hình Database (.env) và Ứng dụng
├── css/                    # Hệ thống Design Tokens & CSS module chuẩn hóa (bf-*.css)
│   ├── tokens.css          # Design Tokens (màu sắc, typography, spacing)
│   └── ...
├── database/               # Database Schema & 14 Migrations tuần tự
│   ├── schema.sql          # Baseline schema hoàn chỉnh
│   └── migrations/         # 14 file migration (0001 -> 0014)
├── includes/               # Thư viện dùng chung (Auth, Permissions, Portal UI, Icons, Mail)
├── js/                     # Vanilla JavaScript module (Meeting WebRTC, Toast, Shortcuts)
├── scripts/                # Công cụ dòng lệnh CLI (migrate.php, test.php, cleanup-data.php)
├── student/                # Không gian học viên (Dashboard, Learn, Exercises, My Courses)
├── teacher/                # Không gian giảng viên (Dashboard, Courses, Lessons, Submissions)
├── tests/                  # Bộ kiểm thử E2E tự động Playwright (role-smoke.spec.js)
├── views/                  # Bộ layout cổng dùng chung (portal_head, topbar, foot)
├── certificate.php         # Xem và tra cứu chứng chỉ điện tử chuẩn in ấn A4
├── course.php              # Chi tiết khóa học & mục lục chương trình
├── courses.php             # Danh mục và bộ lọc tìm kiếm khóa học
├── discussions.php         # Thảo luận và hỏi đáp bài học
├── index.php               # Landing Page trang chủ công khai
├── rooms.php               # Phòng gọi video nhóm WebRTC & Phòng chờ (Green Room)
└── login.php / register.php# Đăng nhập & Đăng ký tài khoản
```

---
*Dự án tốt nghiệp được xây dựng với mục tiêu: Kiến trúc sạch (Clean Architecture), tính năng hoàn thiện, bảo mật cao và trải nghiệm người dùng hiện đại.*
