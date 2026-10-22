# Gehihi App

Không gian chat và Research đa provider, xây trên Laravel 13 và Saola. Đã có bản MVP chạy cục bộ: khách không cần tài khoản, dự án có hướng dẫn chung, hội thoại phân nhánh, tài khoản/provider riêng, tệp và nghiên cứu có nguồn.

## Chạy ứng dụng

Yêu cầu PHP tương thích `composer.lock` (lock hiện tại cần PHP 8.4.1 trở lên), Composer, Node 22+, SQLite, PHP cURL/DOM/mbstring/fileinfo. PDF văn bản cần `pdftotext` của Poppler; thiếu chương trình này app báo rõ tệp chưa đọc được. Không hỗ trợ OCR.

```sh
composer install
npm ci
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm run build:all
php artisan serve --host=127.0.0.1 --port=8010 --no-reload
```

Ở terminal thứ hai, chạy worker để xử lý câu trả lời và Research:

```sh
php artisan queue:work --tries=1 --timeout=300
```

Mở [Gehihi trên localhost](http://127.0.0.1:8010). Môi trường hiện tại đã có `.env`, cơ sở dữ liệu và assets; giữ nguyên `APP_KEY` khi tiếp tục dùng dữ liệu hiện có vì key provider được mã hóa bằng khóa này.

## Cấu hình AI và tìm kiếm

- Tạo tài khoản từ Cài đặt để thêm provider cá nhân. Người đăng ký đầu tiên có quyền người dùng thông thường.
- Cấp quyền quản trị bằng `php artisan gehihi:admin your-email@example.com`. Lệnh sẽ hỏi mật khẩu nếu tài khoản chưa tồn tại.
- Quản trị mở Cài đặt → Hệ thống để thêm provider dùng chung cho khách. Cần một provider LLM; Tra cứu web/Research cần thêm provider tìm kiếm độc lập.
- Form cho phép tải model, nhập model thủ công, kiểm tra từng key, đặt mặc định, chỉ định model nhận ảnh/suy luận và bật/tắt. Để trống ô key khi sửa sẽ giữ key cũ.
- Model và key thật không được chép từ `seo-expert`. App hiện chưa có provider thật được cài sẵn.

Adapter văn bản: Gemini, OpenAI, Anthropic và API OpenAI-compatible; DeepSeek/Cerebras/NVIDIA chat qua transport tương thích. Tìm kiếm: SearXNG, Tavily, Serper, SerpAPI, Brave, Exa. Native web search, Vertex, tạo ảnh và hệ agent tùy biến chưa triển khai.

## Hành vi chính

- Mỗi lượt chụp snapshot hướng dẫn/revision dự án ở server. Lượt mới đọc hướng dẫn mới; run đang chạy/tiếp tục giữ snapshot của nó.
- Tin nhắn dạng cây: sửa câu hỏi/tạo lại giữ các nhánh. Có tìm kiếm, ghim, lưu trữ, chuyển dự án và xuất Markdown/JSON.
- Khách có workspace riêng tối đa 7 ngày; sau đăng nhập có thể nhập workspace khách. Chat tạm thời được dọn sau 24 giờ khi scheduler hoạt động.
- Research tạo kế hoạch → tìm kiếm → đọc trang → đánh giá phần thiếu → tìm bổ sung → báo cáo. Có sổ nguồn, tiến độ, dừng và tiếp tục checkpoint trong giới hạn còn lại.
- Key mã hóa ở server, kiểm tra scope mọi tài nguyên, CSRF cho mutation, chặn mạng riêng/metadata và redirect không hợp lệ khi gọi ra ngoài.
- Hạn mức mặc định: 50 lượt/ngày/workspace, 200.000 token/ngày, tối đa 2 run đồng thời. Token là reported/estimated/reserved/unknown tùy provider và trạng thái kết nối; chưa có tính phí tiền tệ.

## Phát triển và kiểm tra

Nguồn giao diện: `resources/saola/**/*.sao`, service TypeScript tại `resources/saola/web/app/services`. Sửa nguồn `.sao`, sau đó build; các thư mục `resources/js/saola` và Blade `web/admin` là đầu ra compiler. Shell Blade `_system` được duy trì thủ công.

```sh
npm run build:all
php artisan test
npm run test:ui
composer validate --no-check-publish
```

Worker phải được khởi động lại sau thay đổi backend. Trên máy chủ cần process supervisor cho worker và cron gọi `php artisan schedule:run` mỗi phút. Dùng HTTPS, `APP_DEBUG=false`, mail transport thật cho khôi phục mật khẩu; sao lưu cơ sở dữ liệu và `APP_KEY`. File cache/SQLite mặc định phục vụ một máy chủ; cần cấu hình hạ tầng chia sẻ trước khi chạy nhiều replica.

`GEHIHI_EGRESS_ALLOWLIST` chỉ dùng cho host self-host được quản trị tin cậy; không cần bật cho provider HTTPS công khai. Các fixture browser trong `tests/Browser` dùng cơ sở dữ liệu tạm riêng, không phải provider sản phẩm.

Chi tiết và phần còn thiếu: [Trạng thái triển khai](docs/IMPLEMENTATION.md), [Kế hoạch sản phẩm](docs/FEATURE_PLAN.md), [Kiến trúc định hướng](docs/ARCHITECTURE.md), [Khảo sát Gemini/SEO Expert](docs/RESEARCH.md).
