# Kiến trúc đề xuất và trình tự triển khai

Ngày: **22/10/2026**. Đây là kiến trúc định hướng. MVP gộp Projects/Conversations/Files trong Workspace và Usage trong AiRuntime; API dùng middleware web/session để bảo vệ CSRF. Xem [trạng thái triển khai](IMPLEMENTATION.md) cho các khác biệt và giới hạn hiện tại.

## 1. Nền tảng

- Dùng Laravel và Saola theo dự án Crazify; đối chiếu phiên bản từ lockfile và khả năng tương thích khi khởi tạo.
- Frontend nguồn `.sao` tại `resources/saola`, TypeScript service cho request và streaming; Vite build.
- Context giao diện `web` và `admin`; context `api` cho API. Workspace chat nằm trong `web`, không tạo thêm context chỉ vì đổi layout.
- Luồng backend: route → controller/request → service → repository interface → model. Binding thuộc provider của module.
- Không sửa trực tiếp JavaScript/Blade đầu ra compiler. SSR shell và điều hướng SPA cùng dùng nguồn giao diện Saola.
- Không sao chép toàn bộ Crazify hoặc kiến trúc SEO-agent. Chỉ lấy bootstrap, cấu trúc module và quy ước đã phù hợp.

## 2. Ranh giới module

| Module | Trách nhiệm |
| --- | --- |
| Identity | Tài khoản, phiên khách, principal hiện tại, chuyển quyền sở hữu workspace khi nhập |
| Projects | Dự án, revision hướng dẫn, mặc định model, danh sách nguồn dự án |
| Conversations | Hội thoại, tin nhắn dạng cây, nhánh, tìm kiếm, lưu trữ, xuất |
| AiProviders | Provider/key/catalog/adapter, kiểm tra kết nối và quyền truy cập |
| AiRuntime | ContextBuilder, chọn model, thực thi và streaming, retry/fallback, trạng thái run |
| Files | Upload, trích xuất, đoạn nội dung, metadata tham chiếu và vòng đời tệp |
| Research | V1: search/read adapters, truy vấn/câu hỏi con, sổ bằng chứng, đánh giá độ phủ, checkpoint và tổng hợp báo cáo |
| Usage | Hạn mức, đặt chỗ budget, usage ledger, health và số liệu vận hành |
| Artifacts | V1.1: tài liệu Canvas, revision, ảnh và thư viện |

Không tạo module Agents ở V1 chỉ để chứa chat. V2 bổ sung AgentDefinition/AgentRun/ToolRegistry trên các hợp đồng thực thi đã có.

## 3. Khách là một chủ sở hữu dữ liệu thực sự

Backend tạo workspace và principal khách ngẫu nhiên qua cookie phiên an toàn, HttpOnly, SameSite phù hợp; không lấy `guest_id` do client tự khai làm bằng chứng quyền sở hữu. ID tài nguyên không thay thế kiểm tra quyền.

Mọi tài nguyên nghiệp vụ gắn `workspace_id`. Workspace có chủ sở hữu là tài khoản hoặc principal khách. Mỗi request resolve principal và phạm vi workspace ở server; mọi repository query phải được scope trước khi đọc/ghi.

Không lưu API key trong localStorage hoặc payload gửi về client. Cache frontend chỉ phục vụ trải nghiệm, không quyết định quyền. Cookie session cho mutation cần CSRF; việc tách context API không được làm mất bảo vệ tương ứng.

Đăng nhập có bước nhập workspace khách tường minh, kiểm tra phiên khách hiện tại và chuyển quyền trong transaction. Dùng migration marker/idempotency để tránh nhập lặp. Đăng xuất xóa cache tài khoản và cấp lại ngữ cảnh khách phù hợp.

Thời hạn lưu là cấu hình sản phẩm. Khi phát hành phải có thông báo rõ cho khách về khả năng mất lịch sử khi hết hạn hoặc mất phiên; không hứa đồng bộ khi chưa có tài khoản.

## 4. Dữ liệu chính

| Thực thể | Trường/quan hệ quan trọng |
| --- | --- |
| Workspace | Chủ sở hữu tài khoản hoặc principal khách, trạng thái, expires_at nếu có |
| Project | workspace_id, name, current_instruction_revision_id, mặc định model, trạng thái |
| ProjectInstructionRevision | project_id, revision, nội dung bất biến, thời điểm tạo |
| Conversation | workspace_id, project_id nullable, title, selected_leaf_id, chế độ/model override |
| Message | conversation_id, parent_id, role, content parts, trạng thái; quan hệ cây giữ nhánh |
| GenerationRun | request/idempotency ID, message cha, instruction revision, model yêu cầu/thực tế, trạng thái, snapshot ngữ cảnh |
| GenerationAttempt | run_id, thứ tự thử, provider/model, thời gian, lỗi đã lọc; nội dung stream gắn attempt |
| Provider | scope system/user, owner_user_id nullable, type, base URL, enabled, mặc định |
| ProviderKey | provider_id, encrypted secret, nhãn/fingerprint, priority, health/cooldown |
| Model | provider_id + model_id, enabled, nguồn catalog, capability theo thao tác và kết quả probe |
| ModelPolicy | Chủ thể/tác vụ/chế độ, model chính, fallback có thứ tự, đích được phép |
| File/Source/Chunk | workspace_id, chủ thể tham chiếu, storage key, trạng thái, provenance và vị trí trang/đoạn |
| UsageReservation/Event | Principal, run/attempt, token/chi phí thực hoặc ước tính, provider/model, trạng thái đối soát |
| ResearchRun/Step | GenerationRun, phạm vi câu hỏi/nguồn, policy giới hạn, tiến độ, checkpoint và lý do dừng |
| ResearchEvidence/ReportRevision | Nguồn và đoạn đã đọc, URL/ngày nguồn/ngày quan sát, citation theo nhận định và phiên bản báo cáo |
| Artifact/Revision | V1.1: owner, conversation/project, nội dung, revision, metadata phiên bản gốc |

Không lưu provider key vào snapshot ngữ cảnh. Các quan hệ chéo workspace phải được chặn kể cả client đưa cặp ID hợp lệ nhưng khác chủ sở hữu. Snapshot không cần nhân bản tệp lớn: lưu tham chiếu và version/hash của nội dung được sử dụng.

## 5. Hợp đồng ngữ cảnh dự án

Mọi đường gọi AI dùng chung `ContextBuilder` và `ExecutionRequest`, kể cả gọi lại, đổi model, title generation và tóm tắt nếu sử dụng LLM.

1. Xác minh principal, workspace, quyền trên hội thoại/dự án/nguồn.
2. Đọc project đang gắn với hội thoại và revision hướng dẫn hiện hành; backend không tin bản instruction từ client.
3. Chốt revision vào run cùng nhánh lịch sử, nguồn và lựa chọn model.
4. Dựng ngữ cảnh: quy tắc hệ thống → sở thích cá nhân có liên quan → hướng dẫn dự án → ngữ cảnh hội thoại → yêu cầu hiện tại. Hướng dẫn dự án thắng sở thích chung nếu mâu thuẫn, trong ranh giới quy tắc hệ thống.
5. Nạp nguồn như dữ liệu có đánh dấu xuất xứ, không cho nội dung tệp/web tự trở thành system instruction.
6. Tính ngân sách context. Có thể rút lịch sử hoặc nguồn; không tự cắt mất hướng dẫn dự án. Nếu hướng dẫn quá dài, trả yêu cầu rút ngắn rõ ràng trước khi tốn lượt gọi.
7. Mọi attempt trong cùng run giữ nguyên snapshot instruction; thao tác gửi tiếp theo mới đọc revision mới. Nếu cập nhật dự án giữa lúc run đang chạy, run hiện tại không đổi ngữ cảnh giữa chừng.

Khi rút gọn lịch sử, summary không thay thế khối hướng dẫn. Cache key phải chứa workspace/project/instruction revision, nhánh và nguồn liên quan. Chuyển dự án phải vô hiệu summary/context cache cũ.

V1 không có bộ nhớ tự động đọc mọi chat của dự án. Nếu sau này thêm memory xuyên hội thoại, phải có phạm vi và quyền riêng, không trộn vào context một cách vô hình.

## 6. Adapter provider và khả năng model

Adapter có các thao tác độc lập: liệt kê model, probe, tạo/stream văn bản, và các thao tác mở rộng như image/search. Registry xác định adapter nào thực hiện được thao tác, model metadata xác định model nào hỗ trợ.

Discovery thành công không đồng nghĩa model hỗ trợ mọi công cụ. Phân biệt vision input, tạo ảnh, search, reasoning options, structured output và streaming. Trạng thái “chưa kiểm tra” khác “không hỗ trợ”. Với model nhập tay cần kiểm tra hoặc cảnh báo trạng thái chưa xác minh trước khi dùng.

Giữ metadata theo provider: có/không cần key, base URL được sửa, discovery remote/curated, chuẩn transport và cơ chế xác thực. Tham số không tương thích model phải được bỏ hoặc từ chối có giải thích, không truyền chung một bộ `temperature/thinking` cho tất cả.

Provider OpenAI-compatible gọi từ máy chủ. `localhost` khi đó là máy chủ/container, không phải máy tính người đang mở trình duyệt. Dịch vụ local chỉ hỗ trợ trong môi trường self-host hoặc qua endpoint/bridge được cấu hình rõ ràng ở giai đoạn phù hợp.

Base URL người dùng nhập cần chống SSRF: kiểm tra host, phân giải DNS và redirect, chặn metadata/private network mặc định. Ngoại lệ local self-host qua allowlist quản trị, không qua checkbox do người dùng bất kỳ bật. Probe/discovery chịu cùng quy tắc egress với inference.

Key được mã hóa phía server, redaction trước log/lỗi, không trả lại secret đã lưu. Pool hệ thống và provider cá nhân có phạm vi khác nhau; fallback tuân thủ danh sách đích đã cho phép trong [kế hoạch tính năng](FEATURE_PLAN.md).

## 7. Streaming, nền và usage

- Chat streaming dùng SSE hoặc cơ chế HTTP streaming tương thích hạ tầng Laravel; xác minh buffering/reconnect ở proxy thật bằng một vertical slice trước khi mở rộng UI.
- Mỗi gửi có idempotency key, run ID và attempt ID. Client reconnect để xem lại trạng thái/nội dung đã nhận, không tự tạo lượt gọi provider mới.
- Event đề xuất: started, content_delta, sources, completed, failed, cancelled; kèm sequence ID để loại trùng khi nối lại.
- Worker/DB lưu trạng thái run; hủy chạy phải truyền abort đến transport khi hỗ trợ. Nếu provider không hủy được, báo trạng thái thật và vẫn đối soát usage phát sinh.
- Trích xuất tệp và các job dài dùng Laravel Queue; Redis phục vụ phối hợp rate limit, pool key và concurrency giữa replica. Không dùng bộ nhớ riêng từng process làm trạng thái duy nhất của hệ thống nhiều worker.
- Đặt chỗ hạn mức atomically trước inference; ghi usage thành công, dở dang hoặc lỗi theo thông tin thực tế. Khi không biết token do mất kết nối, đánh dấu chưa xác định và đối soát, không giả 0.
- App usage gắn logic run/attempt, tránh trừ lặp do client retry; chi phí nhiều provider attempt thực sự phát sinh vẫn phải được ghi riêng.
- Structured output/JSON validation chỉ áp cho tác vụ cần cấu trúc; chat tự nhiên không buộc trả JSON.

### Pipeline Research V1

Luồng chuyên dụng: nhận câu hỏi/phạm vi → chia mục → tìm → đọc → ghi bằng chứng → đánh giá phần thiếu → tìm bổ sung có giới hạn → tổng hợp → kiểm tra citation → lưu báo cáo. Luồng tra cứu nhanh rút gọn số bước/vòng, dùng cùng hợp đồng nguồn và budget.

Tham khảo `seo-expert/src/lib/research/{web-search,market-research}.ts` và `src/lib/seo-tools/deep-search/research-orchestrator.ts`; tổng quát hóa câu hỏi thay vì giữ các mục SEO như thị trường/đối thủ. Port hành vi sang Laravel, không kéo theo schema tổ chức/chiến dịch hoặc agent runtime của SEO Expert.

Search/read provider độc lập với model tổng hợp. Mỗi operation có ID để ghi usage và checkpoint idempotent; search, crawl và LLM đều tính vào budget. Queue xử lý run dài, UI nối lại để xem trạng thái; tiếp tục run phải xác minh quyền, budget còn lại và giữ instruction snapshot. Đổi câu hỏi/phạm vi/hướng dẫn tạo run/revision mới, chỉ tái sử dụng nguồn khi còn phù hợp và được phép.

Source ledger giữ nội dung/đoạn thực đã thu thập, kiểu nguồn (snippet/page/file), provenance, ngày xuất bản nếu biết và thời điểm quan sát. Bộ kiểm tra không chấp nhận citation ngoài nguồn đã thu thập hoặc đoạn trích không có trong evidence; trạng thái khớp trích dẫn không đồng nghĩa nhận định chắc chắn đúng. Report giữ phần thiếu và lý do dừng, kể cả khi đạt trần tài nguyên.

Research V1 được phép có nhiều vòng, checkpoint, dừng/tiếp tục và kết quả một phần. Hệ agent tổng quát V2 tái sử dụng pipeline này; tính năng Research không phụ thuộc agent builder/tool marketplace/scheduler.

## 8. Đường triển khai

| Mốc | Kết quả review được | Phụ thuộc/cổng hoàn tất |
| --- | --- | --- |
| M0 — khởi tạo | Bootstrap Saola/Laravel, module discovery, layout web/admin, build/typecheck | Đối chiếu Crazify, không mang theo nghiệp vụ của Crazify |
| M1 — chat khách thật | Một provider hệ thống → backend streaming → UI, gửi/dừng/lỗi/idempotency | Model/key triển khai và hạ tầng stream hoạt động |
| M2 — dự án | Workspace ownership, revision hướng dẫn, nhiều hội thoại, nhánh và tìm kiếm | Kiểm tra context payload và cách ly hai khách/tài khoản |
| M3 — tài khoản/provider | Auth, nhập workspace khách, provider cá nhân, discovery/probe, catalog/model policies | Secret không lộ, owner isolation, hành vi dự phòng rõ |
| M4a — Research | Tra cứu nhanh, nghiên cứu sâu có giới hạn, search/read adapters, nguồn dẫn, checkpoint và báo cáo Markdown | Khách dùng được; kiểm tra nguồn, budget, dừng/tiếp tục và hướng dẫn dự án |
| M4b — V1 hoàn chỉnh | Tệp/nguồn, usage/quota/health, responsive và xuất dữ liệu | Các cổng nghiệm thu trong FEATURE_PLAN đều đạt; giới hạn Research kiểm tra xuyên search/crawl/LLM |
| M5 — V1.1 | Canvas, thư viện và biên tập báo cáo Research; công cụ khác phát hành từng phần | Capability và pipeline thật cho từng công cụ |
| M6 — V2 | Thiết kế và triển khai hệ agent riêng | Chốt riêng tool permission, task lifecycle, memory và đánh giá |

Không ước lượng ngày hoàn thành trước khi làm M1 và kiểm tra hạ tầng/provider thực tế. Tiêu chí nghiệm thu dựa trên hành vi người dùng và hợp đồng backend; không đánh giá việc nạp hướng dẫn chỉ qua một câu trả lời ngẫu nhiên của model.
