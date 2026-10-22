# Gehihi App

Không gian chat và Research đa provider, xây trên Laravel 13 và Saola. Đã có bản MVP chạy cục bộ: khách không cần tài khoản, dự án có hướng dẫn chung, hội thoại phân nhánh, tài khoản/provider riêng, tệp và nghiên cứu có nguồn.

## Chạy ứng dụng

Yêu cầu PHP tương thích `composer.lock` (lock hiện tại cần PHP 8.4.1 trở lên), Composer, Node 22+, Docker (PostgreSQL 16), PHP cURL/DOM/mbstring/fileinfo/pdo_pgsql. PDF văn bản cần `pdftotext` của Poppler; thiếu chương trình này app báo rõ tệp chưa đọc được. Không hỗ trợ OCR.

```sh
composer install
npm ci
cp .env.example .env           # đặt DB_PASSWORD trước khi chạy Postgres
php artisan key:generate
docker compose up -d           # PostgreSQL ở 127.0.0.1:5433, kèm DB gehihi_testing cho phpunit
php artisan migrate
npm run build:all
php artisan saola:assets
php artisan serve --host=127.0.0.1 --port=8010 --no-reload
```

Ở terminal thứ hai, chạy worker để xử lý câu trả lời và Research:

```sh
php artisan queue:work --tries=1 --timeout=300
```

Mở [Gehihi trên localhost](http://127.0.0.1:8010). Môi trường hiện tại đã có `.env` và assets; giữ nguyên `APP_KEY` khi tiếp tục dùng dữ liệu hiện có vì key provider được mã hóa bằng khóa này.

## Cấu hình AI và tìm kiếm

- Tạo tài khoản từ Cài đặt để thêm provider cá nhân. Người đăng ký đầu tiên có quyền người dùng thông thường.
- Cấp quyền quản trị bằng `php artisan gehihi:admin your-email@example.com`. Lệnh sẽ hỏi mật khẩu nếu tài khoản chưa tồn tại.
- Trang quản trị ở `/admin`: Tổng quan (lượt chạy, token, lỗi theo ngày), Provider (provider dùng chung cho khách, tình trạng key), Người dùng (cấp/thu quyền) và Hạn mức (lưu ở bảng `app_settings`, áp dụng ngay cả với worker đang chạy). Cần một provider LLM; Tra cứu web/Research cần thêm provider tìm kiếm độc lập.
- Form quản trị và cá nhân có lưới tick model, bộ lọc, thêm ID thủ công, chọn mặc định và đánh dấu nhận ảnh/suy luận theo từng model. Tải catalog không tự bật toàn bộ model; model đã lưu nhưng không còn trong catalog vẫn hiện để bỏ chọn. Để trống ô key khi sửa sẽ giữ key cũ.
- Google Cloud (Vertex AI) dùng API key của [Vertex AI Express Mode](https://docs.cloud.google.com/vertex-ai/generative-ai/docs/start/express-mode/overview), endpoint mặc định `https://aiplatform.googleapis.com/v1/publishers/google`. Endpoint cố định nằm trong code, không nhập hoặc lưu Base URL cho các provider cấu hình sẵn; chỉ OpenAI-compatible và SearXNG cho nhập URL riêng. Hỗ trợ kiểm tra key, chat/ảnh đầu vào và stream Gemini. Nút tải model đọc catalog Model Garden có phân trang; danh sách này không xác nhận quyền chạy của key. Nếu key không có quyền đọc catalog, thêm model ID thủ công rồi bấm kiểm tra. Chưa xác minh bằng API key Vertex thật.
- Model và key thật không được chép từ `seo-expert`. App hiện chưa có provider thật được cài sẵn.

Adapter văn bản: Gemini, Vertex AI (Express Mode), OpenAI, Anthropic và API OpenAI-compatible; DeepSeek/Cerebras/NVIDIA chat qua transport tương thích. Tìm kiếm: SearXNG, Tavily, Serper, SerpAPI, Brave, Exa. Native web search, Vertex bằng service account/OAuth, tạo ảnh và hệ agent tùy biến chưa triển khai.

## Hành vi chính

- Mỗi lượt chụp snapshot hướng dẫn/revision dự án ở server. Lượt mới đọc hướng dẫn mới; run đang chạy/tiếp tục giữ snapshot của nó.
- Tin nhắn dạng cây: sửa câu hỏi/tạo lại giữ các nhánh. Có tìm kiếm, ghim, lưu trữ, chuyển dự án và xuất Markdown/JSON.
- Khách có workspace riêng tối đa 7 ngày; sau đăng nhập có thể nhập workspace khách. Chat tạm thời được dọn sau 24 giờ khi scheduler hoạt động.
- Research tạo kế hoạch → tìm kiếm → đọc trang → đánh giá phần thiếu → tìm bổ sung → báo cáo. Có sổ nguồn, tiến độ, dừng và tiếp tục checkpoint trong giới hạn còn lại.
- Key mã hóa ở server, kiểm tra scope mọi tài nguyên, CSRF cho mutation, chặn mạng riêng/metadata và redirect không hợp lệ khi gọi ra ngoài.
- Hạn mức mặc định: 50 lượt/ngày/workspace, 200.000 token/ngày, tối đa 2 run đồng thời. Token là reported/estimated/reserved/unknown tùy provider và trạng thái kết nối; chưa có tính phí tiền tệ.

## Phát triển và kiểm tra

Kiến trúc multi-context modular của Saola: ba context `web` (`/`), `admin` (`/admin`, cần quyền quản trị) và `api` (`/api`). Mỗi module dưới `app/Modules/{Name}` tự khai báo route cho các context trong `ModuleServiceProvider::routes()`, controller tách theo `Http/Controllers/{Web,Admin,Api}`; module được tự phát hiện, không khai báo route trong `routes/web.php`.

| Module | web | admin | api |
| --- | --- | --- | --- |
| Workspace | màn chat | — | bootstrap, dự án, hội thoại, tệp |
| Identity | — | Người dùng | đăng nhập/đăng ký |
| AiProviders | — | Provider hệ thống | provider cá nhân |
| AiRuntime | — | Hạn mức | lượt chạy, usage |
| Dashboard | — | Tổng quan | — |

Nguồn giao diện: `resources/saola/{web,admin}/views/**/*.sao`; view admin nhận dữ liệu qua `@vars` + `@await` từ controller, thao tác gọi `App.Http` vào chính route của module. Service TypeScript tại `resources/saola/{web,admin}/app/services`. Sửa nguồn `.sao`, sau đó build; các thư mục `resources/js/saola` và Blade `web/admin` là đầu ra compiler. Shell Blade `_system` được duy trì thủ công.

```sh
npm run build:all
php artisan test               # chạy trên PostgreSQL gehihi_testing, cần docker compose up -d
npm run test:ui
composer validate --no-check-publish
```

Worker phải được khởi động lại sau thay đổi backend. Trên máy chủ cần process supervisor cho worker và cron gọi `php artisan schedule:run` mỗi phút. Dùng HTTPS, `APP_DEBUG=false`, mail transport thật cho khôi phục mật khẩu; sao lưu cơ sở dữ liệu và `APP_KEY`. Cache/session dạng file mặc định phục vụ một máy chủ; cần cấu hình hạ tầng chia sẻ trước khi chạy nhiều replica.

`GEHIHI_EGRESS_ALLOWLIST` chỉ dùng cho host self-host được quản trị tin cậy; không cần bật cho provider HTTPS công khai. Các fixture browser trong `tests/Browser` dùng cơ sở dữ liệu tạm riêng, không phải provider sản phẩm.

Chi tiết và phần còn thiếu: [Trạng thái triển khai](docs/IMPLEMENTATION.md), [Kế hoạch sản phẩm](docs/FEATURE_PLAN.md), [Kiến trúc định hướng](docs/ARCHITECTURE.md), [Khảo sát Gemini/SEO Expert](docs/RESEARCH.md).
