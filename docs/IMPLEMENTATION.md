# Trạng thái triển khai — 22/10/2026

Đây là MVP chạy được, có backend thực và giao diện Saola SSR/hydration. FEATURE_PLAN và ARCHITECTURE mô tả đích sản phẩm rộng hơn; chúng không phải danh sách mọi tính năng đã phát hành.

## Đã triển khai

| Phần | Hành vi hiện có |
| --- | --- |
| Khách/tài khoản | Workspace khách có thời hạn; đăng ký, đăng nhập, đăng xuất, khôi phục mật khẩu; nhập dữ liệu khách có kiểm tra token và idempotency |
| Dự án | CRUD, hướng dẫn có revision; mọi request LLM dùng snapshot hướng dẫn; chuyển hội thoại sang dự án có cảnh báo ngữ cảnh cũ |
| Chat | Worker gọi streaming provider, lưu nội dung tăng dần; UI cập nhật qua polling; SSE state endpoint; dừng, tạo lại, nhánh, tìm kiếm, ghim/lưu trữ, xuất |
| Provider | Scope hệ thống/cá nhân; key mã hóa, rotation/cooldown; discovery, kiểm tra từng key trên bản nháp, model mặc định, năng lực theo model; fallback LLM do người dùng chọn |
| Tệp | TXT/MD/CSV/code UTF-8, PDF văn bản qua Poppler, ảnh cho model vision; đọc ở worker (job `ExtractFile`), client tự chọn tệp khi đọc xong; giới hạn và trạng thái lỗi/đọc một phần; chọn nguồn theo dự án |
| Research | Lập kế hoạch, search adapter độc lập, đọc HTML/text, sổ nguồn, đánh giá độ phủ, bổ sung truy vấn, báo cáo Markdown và nguồn [Sx], checkpoint và tiếp tục |
| Quản trị/vận hành | CLI cấp admin; cấu hình pool dùng chung; quota số lượt/token/đồng thời; operation ledger; dọn dữ liệu hết hạn |

Mặc định Tra cứu web có tối đa 2 truy vấn/4 lần đọc trang/1 vòng/90 giây; Research sâu 8 truy vấn/12 lần đọc trang/3 vòng/240 giây. Mỗi search thử tối đa 4 request provider/key. Lượt tiếp tục không tự mở rộng giới hạn truy vấn/trang/vòng hoặc ngân sách token. Run hết giới hạn cần tạo nghiên cứu mới.

## Kiểm chứng đã thực hiện

- Backend: cách ly workspace và tài nguyên, snapshot revision, idempotency, phân nhánh, mã hóa key, quyền quản trị, cooldown, lỗi stream một phần, hủy, chặn worker cũ sau resume, giới hạn token/ảnh, năng lực theo model, probe tìm kiếm.
- Research: tìm/đọc và vòng bổ sung, trích dẫn ID không hợp lệ, phân biệt snippet/toàn văn, không có nguồn, tái sử dụng operation thành công khi tiếp tục, system fields gốc của Google/Anthropic.
- Frontend: nhánh đang chọn, nguồn đúng dự án, phản hồi mạng đến muộn sau điều hướng, CSRF request và loại bỏ HTML nguy hiểm từ Markdown.
- Browser: tạo dự án/hướng dẫn trên app cục bộ; chat và Research đi qua queue với provider mô phỏng trên cơ sở dữ liệu tạm độc lập; hiển thị báo cáo, nguồn và trạng thái hoàn tất.
- Build cả web/admin, TypeScript và kiểm tra Composer lock.

Chưa chạy xác thực bằng API key thật hoặc deploy qua proxy production. Tương thích adapter thực tế còn phụ thuộc model, endpoint, quyền key và schema upstream. Bản hiện tại không coi fixture thành bằng chứng chất lượng của model thật.

## Các giới hạn cần biết

- Research kiểm tra ID nguồn và URL lấy từ kết quả đã thu thập; chưa kiểm chứng từng nhận định/đoạn trích so với nguyên văn. Đánh giá độ phủ do model hỗ trợ, không bảo đảm kết luận đúng.
- Kế hoạch Research tự chạy, chưa có bước chỉnh dàn ý; chưa có cập nhật báo cáo theo revision, cache nguồn xuyên run hoặc giao diện đánh dấu bằng chứng theo từng nhận định. Nguồn chỉ đọc HTML/text, chưa render JavaScript/paywall.
- Tài liệu dài lấy phần văn bản đầu trong giới hạn và báo partial; chưa có truy hồi đoạn, embedding, số trang PDF, OCR/DOCX/XLSX/audio/video. Trích xuất cần worker hàng đợi đang chạy; thiếu worker thì tệp đứng ở trạng thái đang đọc.
- Chế độ Nhanh/Suy luận gửi tham số riêng từng hãng cho model đã khai báo hỗ trợ suy luận: Gemini 3 `thinkingLevel`, Gemini 2.5 `thinkingBudget`, Anthropic `thinking` (chỉ Suy luận, cần ngân sách > 2.048 token), OpenAI `reasoning_effort`. API OpenAI-compatible/DeepSeek/Cerebras/NVIDIA không nhận tham số này; chưa xác minh với key thật. Chế độ Nhanh dùng luồng chat thông thường, chưa có mapping model riêng theo tác vụ. Metadata năng lực do chủ cấu hình khai báo.
- Project/model default có API; UI chưa có màn hình riêng cho mọi chính sách chọn model, lịch sử revision hoặc lưu trữ dự án. Bản địa hóa giao diện chính có Việt/Anh; một số thông báo backend vẫn tiếng Việt.
- Chưa có dashboard health/chi phí theo tiền, giá model, ngân sách tiền toàn hệ thống, tổng quota dung lượng hoặc quản lý nhiều replica. Giới hạn khách theo workspace kết hợp throttle IP hiện tại cần được hiệu chỉnh trước khi mở công khai.
- Tệp/hội thoại/dự án xóa mềm, xem/khôi phục/xóa vĩnh viễn trong Cài đặt → Đã xóa gần đây; giữ 30 ngày rồi scheduler xóa hẳn. Hội thoại đã tách khỏi dự án lúc xóa dự án không tự quay lại dự án khi khôi phục. Chat tạm thời không xuất hiện trong lịch sử và được scheduler dọn; không cam kết provider bên ngoài không lưu dữ liệu.
- Chưa có Canvas, thư viện artifact, tạo ảnh, native search, Vertex qua service account/OAuth (mới có API key Express Mode) hoặc hệ agent tổng quát. Hệ agent riêng giữ cho phiên bản sau theo yêu cầu.
