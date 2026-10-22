# Khảo sát Gemini và các dự án tham chiếu

Ngày khảo sát: **22/10/2026**, múi giờ Việt Nam.

## 1. Phương pháp và mức độ xác minh

Khảo sát trực tiếp cửa sổ Google Chrome đã đăng nhập Gemini do người dùng chỉ định. Dùng các màn hình tạo mới, menu model, công cụ, giới thiệu sổ ghi chú và tùy chỉnh; không dùng nội dung hội thoại cũ làm dữ liệu nghiên cứu.

Đồng thời đối chiếu tài liệu chính thức của Google và mã nguồn tại `seo-expert`, `saola-ecosystem/crazify`. Những khả năng chỉ xuất hiện trên giao diện không được coi là đã kiểm thử đầu cuối hay đã có API tương ứng.

## 2. Những gì quan sát trực tiếp

| Khu vực | Quan sát tại tài khoản đang mở | Ý nghĩa cho Gehihi |
| --- | --- | --- |
| Màn hình chính | Sidebar hội thoại, tìm kiếm, thư viện, đã lên lịch, tùy chỉnh, video và sổ ghi chú | Giữ chat làm trung tâm; đưa công cụ vào composer và sản phẩm đã tạo vào thư viện |
| Composer | Tải nội dung, chọn chế độ/model, nhập giọng nói, chat tạm thời | Một ô nhập thống nhất; chức năng phụ có điểm truy cập rõ ràng |
| Model | Menu hiển thị 3.5 Flash-Lite, 3.8 Flash, 3.1 Pro, 4 Argon và mục cấp độ tư duy | Cho chọn theo nhu cầu nhanh/suy luận; quản trị ánh xạ sang model thực tế |
| Tệp | Tải tài liệu, dữ liệu, mã nguồn; thêm từ Drive | Tệp cần pipeline đọc và thông báo trạng thái, không chỉ nút upload |
| Công cụ | Tạo ảnh, tạo video, tạo nhạc, Canvas, Deep Research, học có hướng dẫn | Phân kỳ; kiểm tra capability trước khi bật từng công cụ |
| Deep Research | Sau khi chọn hiện nút Nguồn, mặc định Google Tìm kiếm; menu có Tìm kiếm, Gmail, Drive, Cuộc trò chuyện | Chọn nguồn tường minh; khả năng kết nối dữ liệu riêng cần thiết kế riêng |
| Sổ ghi chú | Màn giới thiệu nêu phân chia hội thoại theo chủ đề, nguồn tài liệu và chỉ dẫn tùy chỉnh; tài khoản này hiển thị tối đa 100 nguồn | Dự án nên sở hữu hướng dẫn và nguồn, dùng lại khi trò chuyện |
| Tùy chỉnh | Các tab Khám phá, Trình kết nối, Kỹ năng; tạo kỹ năng cùng Gemini, thủ công hoặc tải lên | Tham khảo cho preset hướng dẫn và hệ agent tương lai |

Tên model và giới hạn trên đây là ảnh chụp trạng thái của một tài khoản tại thời điểm khảo sát. Không coi nhãn trong Gemini web là model ID API, bằng chứng quyền truy cập API hoặc cam kết tương thích cho Gehihi.

### Mẫu dùng thử Canvas

- Đã chọn Canvas và gửi yêu cầu tạo tài liệu “Mẫu lập kế hoạch cuối tuần”, gồm mục tiêu, ba việc và bảng lịch hai ngày, hoàn toàn bằng dữ liệu giả.
- Giao diện nhận câu lệnh và chuyển sang trạng thái tạo nội dung nhưng chưa xuất hiện kết quả trong thời gian quan sát.
- Đã bấm dừng, mở lại hội thoại và xác nhận thông báo “Bạn đã dừng câu trả lời này”.
- Gemini đã lưu một hội thoại mẫu mang tên “Mẫu Lập Kế Hoạch Cuối Tuần”. Không xóa hội thoại này.
- Chưa xác minh được trình soạn thảo, sửa từng đoạn, phiên bản tài liệu hoặc xuất Canvas bằng thử nghiệm trực tiếp. Những phần đó dưới đây chỉ dựa trên tài liệu Google.

Không chạy thử tạo ảnh/video/nhạc, không cấp quyền kết nối Drive/Gmail, không tạo sổ ghi chú hoặc kỹ năng mới. Mức quan sát này đủ cho đề xuất tính năng ban đầu, chưa đủ để đánh giá chất lượng model hay chi phí vận hành.

## 3. Đối chiếu tài liệu chính thức

| Nguồn | Điều xác nhận | Cách áp dụng |
| --- | --- | --- |
| [Gemini Apps](https://support.google.com/gemini/answer/13275745?hl=en) | Có đổi model và chat tạm thời; một số chức năng có điều kiện đăng nhập | Gehihi chủ động thiết kế quyền khách theo yêu cầu riêng |
| [Notebooks](https://support.google.com/gemini/answer/16972047?hl=en) | Gom chủ đề, nguồn và chỉ dẫn trong không gian làm việc | Dùng làm tham chiếu gần nhất cho dự án có hướng dẫn |
| [Canvas](https://support.google.com/gemini/answer/16047321?hl=en) | Cộng tác tạo/sửa tài liệu và code trong vùng làm việc riêng | Đề xuất khung chat bên cạnh tài liệu ở V1.1 |
| [Deep Research](https://support.google.com/gemini/answer/15719111?hl=en) | Nghiên cứu từ web và nguồn bổ sung, có hạn mức | V1 có tra cứu và nghiên cứu nhiều vòng hữu hạn, tham khảo thêm pipeline sẵn có trong SEO Expert |
| [Tải và phân tích tệp](https://support.google.com/gemini/answer/14903178?hl=en) | Tệp trở thành đầu vào để hỏi đáp/phân tích | Pipeline nhập, trích xuất, kiểm tra dung lượng và trạng thái đọc |
| [Gems](https://support.google.com/gemini/answer/15146780?hl=en) | Trợ lý được cấu hình bằng chỉ dẫn | Tham khảo khái niệm preset; không đồng nhất với hệ agent tự chủ |

Giao diện khảo sát đang dùng nhãn “Kỹ năng”, trong khi tài liệu Gems mô tả một khái niệm cấu hình trợ lý liên quan. Chưa xác minh đây là cùng một hệ thống. Giới hạn nguồn của notebooks còn phụ thuộc gói và trạng thái triển khai; không sao chép con số của tài khoản khảo sát vào Gehihi.

## 4. `seo-expert`: những cơ chế đáng kế thừa

Các đường dẫn bên dưới trỏ tới mã nguồn tham chiếu, không phải thành phần đã có trong Gehihi.

| Cơ chế | Nguồn mã | Kết luận |
| --- | --- | --- |
| Catalog provider | [catalog.ts](../../../seo-expert/src/lib/llm/catalog.ts) | Có OpenAI, Anthropic, Google, Vertex, DeepSeek, Cerebras, OpenAI-compatible, NVIDIA và NVIDIA ảnh; metadata quy định loại tác vụ và cách lấy model |
| Adapter thực thi | [registry.ts](../../../seo-expert/src/lib/llm/registry.ts) | Tách provider khỏi model ID; đường gọi ảnh khác đường gọi văn bản |
| Discovery | [provider-model-discovery.ts](../../../seo-expert/src/lib/admin/provider-model-discovery.ts) | Tách model từ API và model cấu hình sẵn; xử lý phân trang và host không hỗ trợ liệt kê |
| Kiểm tra kết nối | [run.ts](../../../seo-expert/src/lib/llm/run.ts) | Probe nhỏ, kiểm tra từng key độc lập, che secret trong lỗi |
| Pool key | [provider-key-pool.ts](../../../seo-expert/src/lib/llm/provider-key-pool.ts) và [provider-key-rotation.ts](../../../seo-expert/src/lib/llm/provider-key-rotation.ts) | Phân phối giữa key khỏe, cooldown, phối hợp qua Redis giữa worker |
| Dự phòng model | [model-fallbacks.ts](../../../seo-expert/src/lib/llm/model-fallbacks.ts) | Mỗi lựa chọn có danh sách dự phòng có thứ tự; loại trùng provider/model |
| Chọn cấu hình thực thi | [run-agent.ts](../../../seo-expert/src/lib/agents/run-agent.ts) | Có phạm vi nền tảng/tổ chức/dự án/tác vụ và kiểm tra provider thuộc phạm vi được phép |

Kế thừa **quy tắc và hành vi**, triển khai lại theo PHP/Laravel; không bê runtime Next.js/BullMQ sang Gehihi. Các ID gợi ý trong source không chứng minh model đang dùng được bằng API key của hệ thống mới. Cần discovery/probe khi triển khai.

Gehihi cần mở rộng capability tới cấp model và từng thao tác: streaming, nhận ảnh, sinh ảnh, đọc tài liệu, tìm kiếm, reasoning, structured output. Capability cấp provider trong source tham chiếu chưa đủ để bật toàn bộ công cụ cho mọi model.

### Bổ sung: Research đã có nền tảng tham chiếu rõ ràng

Rà soát tiếp sau góp ý của người dùng xác nhận phạm vi SEO Expert rộng hơn quản lý model/provider:

| Thành phần | Nguồn mã | Hành vi xác nhận từ source |
| --- | --- | --- |
| Tìm web | [web-search.ts](../../../seo-expert/src/lib/research/web-search.ts) | Có SearXNG và tìm kiếm qua cấu hình provider, lọc domain, ghi attempts/limitations |
| Cấu hình search | [search-provider-config.ts](../../../seo-expert/src/lib/research/search-provider-config.ts) | Pool key cho SerpAPI, Serper, Tavily, Brave, Exa; thứ tự fallback hoặc round-robin |
| Thu thập nghiên cứu | [market-research.ts](../../../seo-expert/src/lib/research/market-research.ts) | Tìm/đọc có giới hạn truy vấn, request, trang, thời gian; đánh giá coverage và hỗ trợ checkpoint/nguồn cũ |
| Tổng hợp và đối chiếu | [research-agent.ts](../../../seo-expert/src/lib/builder/research-agent.ts) | Thu packet và SERP, gọi model tổng hợp, reconcile bằng chứng và ghi mức grounding/giới hạn |
| Nghiên cứu nhiều vòng | [research-orchestrator.ts](../../../seo-expert/src/lib/seo-tools/deep-search/research-orchestrator.ts) | State/task theo vòng, thu thập/đánh giá, checkpoint, tái sử dụng và xuất bản kết quả có nguồn |

Đây là xác nhận khả năng từ source, chưa chạy lại provider thật trong phiên khảo sát Gehihi. Tìm kiếm ngoài và tổng hợp bằng LLM là hai lớp riêng: model không có native search vẫn có thể tham gia Research. Các quy tắc thị trường/SEO trong source cần tổng quát hóa khi port sang Gehihi.

Điều chỉnh đề xuất: Research nhanh và sâu có giới hạn thuộc V1. Agent tùy biến tổng quát vẫn ở V2; không dùng việc hoãn agent để hoãn pipeline Research đã có mẫu tham chiếu.

## 5. `crazify`: nền tảng Saola

Đã đối chiếu [README](../../crazify/README.md), [composer.json](../../crazify/composer.json), [package.json](../../crazify/package.json), [ModuleServiceProvider](../../crazify/app/Providers/ModuleServiceProvider.php) và [AiProvider](../../crazify/app/Modules/AiSettings/Models/AiProvider.php).

- Laravel backend, Saola views `.sao`, TypeScript client, Vite; hai view context `web/admin` và API context riêng.
- Backend theo module: route → controller → service → repository → model; module tự đăng ký provider và binding.
- View nguồn ở `resources/saola`; JavaScript/Blade sinh ra không phải nơi sửa trực tiếp.
- Provider có secret mã hóa và ẩn khỏi serialization; cấu hình admin là một điểm khởi đầu phù hợp.
- Crazify yêu cầu đăng nhập cho workspace và cấu hình AI dành cho admin. Gehihi phải bổ sung quyền khách và provider cá nhân; không sao chép nguyên middleware/phạm vi hiện có.
- Một số đoạn README về trạng thái admin không hoàn toàn đồng nhất với phần hướng dẫn AI/mail; dùng source của context/provider làm căn cứ kiến trúc.

## 6. Kết luận sản phẩm

Ưu tiên trải nghiệm vào là dùng được, hội thoại theo dự án, Research có nguồn và ngữ cảnh đáng tin cậy. Đưa quản lý provider vào cấu hình người dùng/admin; composer hiện chế độ, model và Tra cứu web/Nghiên cứu sâu từ V1. Canvas và thư viện mở rộng ở V1.1. Hệ agent tùy biến tổng quát cùng lịch chạy và kết nối hành động ngoài thuộc V2; Research chuyên dụng nhiều vòng không cần chờ giai đoạn đó.
