# Kế hoạch tính năng Gehihi

Ngày: **22/10/2026**. Tài liệu định hướng sản phẩm. MVP đã có; xem [trạng thái triển khai và giới hạn thực tế](IMPLEMENTATION.md) để phân biệt phần đã làm với đích V1 đầy đủ.

## 1. Phạm vi và nguyên tắc

Gehihi là không gian trò chuyện AI đa provider trên Saola, tham khảo trải nghiệm Gemini và tổ chức dự án theo yêu cầu giống ChatGPT.

Yêu cầu đã xác định:

1. Dùng Saola Ecosystem và Laravel theo kiến trúc của Crazify.
2. Khách không có tài khoản vẫn dùng tính năng thông thường.
3. Người có tài khoản có thể tự thêm cấu hình provider.
4. Dự án có tên và hướng dẫn; các cuộc trò chuyện thuộc dự án được nạp hướng dẫn đó.
5. Hệ thống AI agent riêng phát triển ở phiên bản sau.

Đề xuất chia V1 thành chat, dự án, provider và Research; V1.1 mở rộng công cụ; V2 bổ sung hệ agent tùy biến tổng quát. Research nhiều bước có thể triển khai bằng pipeline chuyên dụng, không phụ thuộc việc hoàn thiện hệ agent riêng. Một tính năng đã phát hành cho sử dụng thông thường phải có đường dùng dành cho khách; đăng nhập phục vụ đồng bộ và cấu hình cá nhân. Khách dùng pool provider của hệ thống.

## 2. Nhóm người dùng

| Khả năng | Khách chưa đăng nhập | Tài khoản miễn phí | Quản trị viên |
| --- | --- | --- | --- |
| Chat và chọn chế độ/model công khai | Có | Có | Có |
| Dự án, hướng dẫn, nhiều hội thoại | Có, trong workspace khách | Có, đồng bộ tài khoản | Có trong workspace của mình |
| Tệp và công cụ đã phát hành | Có, theo capability và hạn mức hệ thống | Có; thêm khả năng từ provider riêng | Quản lý khả dụng toàn hệ thống |
| Tra cứu nhanh và Research sâu | Có, qua cấu hình tìm kiếm/model hệ thống | Có, chọn model tổng hợp và cấu hình tìm kiếm riêng nếu đã hỗ trợ | Quản lý nguồn tìm kiếm, model và budget |
| Lịch sử | Lưu workspace khách có thời hạn | Lưu và đồng bộ nhiều thiết bị | Quản lý chính sách lưu, không mặc định đọc chat riêng |
| Thêm provider/API key riêng | Không | Có | Có |
| Quản lý provider dùng chung | Không | Không | Có |
| Chuyển workspace khách vào tài khoản | Sau khi đăng nhập và chọn nhập | Có | Không chiếm quyền dữ liệu khách khác |

Hạn mức kiểm soát lượng sử dụng, chi phí và tải hệ thống. Không biến toàn bộ upload, dự án hoặc chat thành tính năng bắt buộc đăng nhập. Hạn mức số lượt/token, dung lượng, thời hạn lưu là tham số quản trị cần chốt trước phát hành, không hardcode theo gói Gemini.

## 3. V1 — chat, dự án, tệp, provider và Research

### 3.1. Vào là trò chuyện được

- Trang mở đầu vào thẳng workspace với ô nhập, gợi ý bắt đầu và tạo cuộc trò chuyện mới.
- Trả lời streaming; dừng, sao chép, Markdown, bảng, code block và thông báo lỗi rõ ràng.
- Sửa câu hỏi hoặc tạo lại câu trả lời tạo nhánh; không ghi đè lịch sử đã xem. Có chuyển giữa các nhánh.
- Tự đặt tên hội thoại; đổi tên, tìm kiếm tiêu đề/nội dung, ghim, lưu trữ và xóa có xác nhận.
- Chế độ **Tự động / Nhanh / Suy luận** chỉ xuất hiện khi có model phù hợp. Tự động ban đầu dùng quy tắc quản trị, không cần hệ agent.
- Có bộ chọn model chi tiết cho người muốn chọn trực tiếp; nhãn phân biệt “Hệ thống” và “Của bạn”.
- Mỗi câu trả lời có thể xem model/provider thực tế đã dùng, kể cả khi dự phòng thay đổi lựa chọn ban đầu.
- Chat tạm thời không vào lịch sử thông thường; giao diện nêu rõ chính sách lưu của Gehihi và khả năng xử lý dữ liệu của provider, không hứa “không lưu ở bất kỳ đâu”.
- Responsive cho máy tính/điện thoại, bàn phím, giao diện sáng/tối, tiếng Việt và tiếng Anh.

### 3.2. Dự án và hướng dẫn dùng chung

- Tạo, đổi tên, sửa hướng dẫn, lưu trữ và xóa dự án.
- Trong dự án có danh sách hội thoại; hỗ trợ tạo mới, tìm kiếm và chuyển hội thoại vào/ra dự án.
- Chat ngoài dự án vẫn dùng bình thường.
- Trong header hội thoại hiện dự án hiện tại; mở xem hướng dẫn đang áp dụng và phiên bản của hướng dẫn.
- Có thể chọn model mặc định cho dự án; hội thoại được phép ghi đè.
- Mỗi lần gửi mới, tạo lại hoặc tiếp tục đều nạp hướng dẫn dự án ở backend. Retry, đổi model và bước gọi công cụ không được làm mất hướng dẫn.
- Sửa hướng dẫn có hiệu lực từ lượt tiếp theo; kết quả cũ giữ nguyên. Mỗi lượt lưu revision đã dùng để truy vết.
- Khi chuyển hội thoại sang dự án khác, hiển thị dự án/hướng dẫn đích trước khi xác nhận; lượt tiếp theo dùng hướng dẫn mới. Bỏ summary/cache cũ và đánh dấu điểm chuyển ngữ cảnh trong timeline.
- Nếu lịch sử chứa dữ liệu từ nguồn thuộc dự án cũ, phải cảnh báo và cho chọn tạo hội thoại sạch ở dự án mới. Không tiếp tục nạp tệp/nguồn cũ khi thiếu quyền.
- V1 không tự đọc các cuộc trò chuyện khác trong dự án. Ngữ cảnh chung gồm hướng dẫn và nguồn đã chọn; tránh ngầm trộn mọi lịch sử.

**Ví dụ nghiệm thu:** Dự án A có chỉ dẫn “Trả lời tiếng Việt, đơn vị tiền VND”. Tạo ba hội thoại, đổi model rồi tạo lại một câu trả lời: payload backend của mọi lượt vẫn có chỉ dẫn A. Đổi hướng dẫn sang tiếng Anh: các lượt mới dùng revision mới, câu trả lời cũ không thay đổi.

### 3.3. Tệp và nguồn dự án

- V1 hỗ trợ văn bản có thể trích xuất từ PDF, TXT/Markdown, CSV và mã nguồn; ảnh làm đầu vào khi model có vision. PDF scan cần OCR được báo là chưa hỗ trợ ở V1.
- Tệp có trạng thái tải lên → xử lý → sẵn sàng/lỗi; không cho AI nói đã đọc khi trích xuất thất bại.
- Gắn tệp vào một tin nhắn hoặc đưa vào nguồn dùng chung của dự án. Trước gửi, người dùng thấy các nguồn được chọn cho lượt đó.
- Trả lời từ tài liệu có tham chiếu tên tệp và trang/đoạn khi pipeline xác định được; không bịa số trang.
- Tệp dài được chia đoạn và chọn ngữ cảnh theo ngân sách. Báo rõ khi chỉ đọc được một phần, chưa xử lý hoặc cần thu hẹp câu hỏi.
- Hiển thị giới hạn loại tệp/dung lượng và khả năng model trước khi gửi. DOCX/XLSX, OCR và audio/video ingestion đưa vào V1.1 theo pipeline có thật.
- Khách cũng sử dụng được; tệp chịu chính sách lưu của workspace khách.

### 3.4. Tài khoản và chuyển dữ liệu khách

- Đăng ký, đăng nhập, đăng xuất, khôi phục tài khoản theo hệ auth Laravel/Saola.
- Tài khoản miễn phí dùng pool hệ thống ngay cả khi chưa thêm API key.
- Đồng bộ dự án, hội thoại, nhánh, nguồn và cài đặt trên nhiều thiết bị.
- Khi đăng nhập từ workspace khách, hiện lựa chọn nhập dữ liệu đang có; thao tác có thể thử lại mà không nhân bản.
- Đăng xuất phải tách workspace tài khoản khỏi workspace khách trên thiết bị; nút Back hoặc cache không được lộ dữ liệu tài khoản trước.
- Xuất hội thoại dạng Markdown/JSON; xóa hội thoại, dự án và tệp theo phạm vi rõ ràng.

### 3.5. Provider cá nhân

- Mỗi tài khoản có nhiều cấu hình, kể cả nhiều cấu hình cùng loại provider.
- Form: tên, loại, base URL nếu hỗ trợ, API key, model cho phép, model mặc định và bật/tắt.
- Mở rộng nhiều key trong cùng provider và chuỗi model dự phòng; phần nâng cao có thể thu gọn trong UI.
- Nút **Tải model** và **Kiểm tra kết nối** dùng cấu hình đang nhập, không tự lưu; thất bại không làm mất nội dung form.
- Phân biệt model từ remote, curated và nhập thủ công; discovery không thành công vẫn giữ cấu hình/model đã lưu.
- Kiểm tra riêng từng key; trả trạng thái, độ trễ và lỗi đã che secret. Hiển thị thời gian kiểm tra gần nhất.
- Key đã lưu chỉ hiện trạng thái có/không và nhãn che; để trống giữ nguyên; thay, xóa và tắt là thao tác riêng rõ ràng.
- Provider có key không hợp lệ không khóa toàn bộ workspace. Người dùng vẫn có thể chọn model hệ thống.
- Tài khoản tự cấu hình không phải xin quyền admin. Mọi API đọc/sửa/test provider đều kiểm tra chủ sở hữu.

### 3.6. Provider/model của hệ thống

- Danh sách provider, pool key, catalog model, kiểm tra kết nối, trạng thái bật/tắt và các chế độ công khai.
- Quản trị chọn model khách được dùng, mapping Nhanh/Suy luận/Tự động và fallback cùng khả năng.
- Theo dõi key/model lỗi, rate limit, cooldown, độ trễ, token, chi phí ước tính và tỷ lệ thành công.
- Chi phí chưa có bảng giá đáng tin phải ghi “chưa xác định”; không hiển thị số 0 như miễn phí.
- Có hạn mức đồng thời, token/ngày, dung lượng và ngân sách tổng; đặt chỗ hạn mức trước khi gọi, đối soát sau khi hoàn tất hoặc dừng/lỗi.
- Provider riêng chịu hạn mức kỹ thuật của app và chi phí tại provider đó; không quảng cáo là không giới hạn.

### 3.7. Research — tìm kiếm, đọc nguồn và tổng hợp

Research là tính năng chính của V1, tham khảo các lớp tìm kiếm, thu thập bằng chứng và điều phối nghiên cứu của `seo-expert`. Phạm vi này gồm hai mức:

| Chế độ | Hành vi | Đầu ra |
| --- | --- | --- |
| Tra cứu nhanh | Tạo truy vấn, tìm web, đọc các nguồn phù hợp và tổng hợp trong một lượt hữu hạn | Câu trả lời có trích dẫn mở được và thông báo thiếu nguồn nếu có |
| Nghiên cứu sâu | Chia câu hỏi thành các mục, tìm và đọc nhiều nguồn, đánh giá độ phủ, tìm bổ sung các khoảng thiếu qua số vòng có giới hạn | Báo cáo có cấu trúc, nguồn theo nhận định, phần còn thiếu, tiến độ và lý do dừng |

Trải nghiệm:

- Composer có lựa chọn **Tra cứu web** và **Nghiên cứu sâu**; dùng trong hoặc ngoài dự án. Mọi bước LLM đều nhận hướng dẫn dự án từ snapshot của run.
- Người dùng nhập câu hỏi, bổ sung URL/tệp, chọn nguồn web/tài liệu dự án, ngôn ngữ, thị trường, ưu tiên hoặc loại trừ domain. Chỉ đưa dữ liệu riêng cần thiết vào truy vấn; trước chạy cho xem phạm vi nguồn và đích xử lý.
- Nghiên cứu sâu hiện dàn ý/câu hỏi con để người dùng có thể chỉnh, rồi chạy theo giới hạn đã chọn; không buộc xác nhận từng bước tìm/đọc.
- Hiện tiến độ tìm kiếm, đọc nguồn, bổ sung và tổng hợp; cho dừng, giữ kết quả một phần và tiếp tục từ checkpoint trong cùng quyền/ngân sách.
- Báo cáo lưu ngay trong hội thoại/dự án, hỗ trợ hỏi tiếp và xuất Markdown từ V1. Canvas/thư viện V1.1 bổ sung giao diện biên tập và quản lý tập trung.
- Trích dẫn gắn nhận định với URL/đoạn bằng chứng; phân biệt snippet tìm kiếm với trang đã đọc, thời điểm truy cập với ngày xuất bản. Nhận định suy luận, mâu thuẫn nguồn và thông tin chưa xác minh phải được thể hiện rõ.
- Cho cập nhật nghiên cứu: giữ báo cáo cũ thành phiên bản, tái sử dụng nguồn còn phù hợp và ghi rõ tuổi nguồn; không đánh dấu nguồn cache là vừa đọc mới.
- Hết budget hoặc thiếu nguồn vẫn trả bản tổng hợp một phần kèm lý do dừng. Không dùng lời văn đầy đủ để che phần nghiên cứu chưa hoàn thành.

Provider và chất lượng:

- Tách **provider tìm kiếm/đọc trang** khỏi **provider/model tổng hợp**. Model không có native search vẫn nghiên cứu được qua lớp tìm kiếm ngoài và nội dung đã đọc.
- Tham khảo SearXNG và cấu hình SerpAPI, Serper, Tavily, Brave, Exa trong `seo-expert`; chỉ công khai adapter sau khi kiểm tra. Native search của model là đường bổ sung nếu adapter thực sự hỗ trợ và có thể trả provenance.
- Admin cấu hình thứ tự dự phòng hoặc phân phối nguồn, pool key, hạn mức truy vấn/trang/vòng/thời gian/token/chi phí; account có thể thêm search provider riêng theo adapter được hỗ trợ. Key LLM không mặc nhiên dùng được cho dịch vụ tìm kiếm khác.
- Khách dùng được cả hai chế độ qua pool hệ thống, chịu hạn mức hữu hạn. Provider cá nhân và mọi fallback tiếp tục tuân thủ scope/đích được phép của người dùng.
- Nguồn chỉ hỗ trợ một phần thì độ phủ phải ghi nhận tương ứng. Kiểm tra URL/citation và đoạn trích bằng code; đủ nhiều link không tự đồng nghĩa kết luận đã được chứng minh.

Phạm vi V1 không hứa ngang toàn bộ Gemini Deep Research; đây là pipeline Research chuyên dụng có các bước và giới hạn rõ, được nghiệm thu bằng nguồn thực và hành vi dừng/tiếp tục.

## 4. Chính sách model và thực thi

Danh mục loại provider tham khảo `seo-expert`: OpenAI, Anthropic, Google Gemini, Google Vertex, DeepSeek, Cerebras, OpenAI-compatible, NVIDIA chat, NVIDIA ảnh. V1 nên hoàn thiện luồng chat với Google/OpenAI/Anthropic/OpenAI-compatible trước; adapter còn lại là các đầu việc rõ ràng, chỉ đưa vào menu khi đã có kiểm tra phù hợp. Vertex cần lớp thông tin xác thực/transport riêng; chưa coi là một ô API key chung đã đủ.

Không chốt model ID từ nhãn Gemini web hoặc danh sách gợi ý cũ. Model phải được quản trị/người dùng cấu hình và kiểm tra bằng endpoint thực tế.

### Thứ tự chọn model đề xuất

1. Lựa chọn tường minh của lượt hiện tại hoặc hội thoại.
2. Cấu hình dự án cho loại tác vụ.
3. Mặc định cá nhân cho loại tác vụ, nếu có tài khoản.
4. Cấu hình hệ thống cho loại tác vụ/chế độ.

Mỗi cấu hình có model chính và danh sách dự phòng có thứ tự. Bỏ trùng cặp provider/model; kiểm tra scope, model bật, capability và hạn mức trước gọi. Các nhãn chế độ là ánh xạ cấu hình, không phải model ID.

Với lựa chọn tường minh, chỉ chạy các dự phòng đã được người dùng/quản trị cho phép cho lựa chọn đó. Nếu không còn ứng viên hợp lệ, báo lỗi kèm lựa chọn đổi model; không lặng lẽ đi tiếp qua mọi tầng mặc định.

- Khách chỉ dùng provider hệ thống công khai.
- Tài khoản chỉ dùng provider của chính mình và provider hệ thống được công khai.
- Provider cá nhân lỗi mặc định không tự gửi dữ liệu sang pool hệ thống hoặc một nhà cung cấp mới; người dùng có thể bật dự phòng và thấy các đích được phép.
- Nhiều key khỏe được phân phối luân phiên; lỗi quota/rate limit có cooldown phù hợp, không xoay key vô hạn.
- Retry có trần tổng thời gian/số lần ở một lớp điều phối. Không nhân retry của SDK × queue × fallback.
- Không fallback từ model có vision sang model chỉ nhận chữ khi yêu cầu chứa ảnh; không âm thầm bỏ tệp/công cụ để gọi được.
- Khi đã stream ra nội dung, lỗi phải giữ bản trả lời dở và trạng thái lỗi. “Thử lại” tạo attempt mới, tránh ghép nội dung từ hai model thành một câu trả lời.
- Chỉ hiển thị trạng thái suy luận/tóm tắt mà API cho phép; không coi yêu cầu lộ toàn bộ suy nghĩ nội bộ là một tính năng.

## 5. V1.1 — công cụ mở rộng

Các công cụ dưới đây đều cần đường dùng cho khách qua cấu hình hệ thống, bên cạnh provider cá nhân tương thích.

| Tính năng | Trải nghiệm đề xuất | Điều kiện phát hành |
| --- | --- | --- |
| Canvas tài liệu | Chat cạnh trình soạn thảo; sửa toàn bộ hoặc phần chọn; lịch sử phiên bản, khôi phục, tải Markdown | Cập nhật theo version, không ghi đè bản sửa tay đang mới hơn |
| Thư viện | Tập trung tài liệu/ảnh/báo cáo đã tạo theo dự án và hội thoại gốc | Phân quyền theo owner, có tìm kiếm và tải xuống |
| Sinh ảnh | Prompt, xem ảnh, tải xuống, liên kết hội thoại; chỉnh ảnh nếu adapter hỗ trợ | Model sinh ảnh thật; phân biệt vision với image generation |
| Học có hướng dẫn | Hỏi từng bước, gợi ý, bài luyện và kiểm tra câu trả lời | Preset hội thoại có cấu trúc, giữ ngữ cảnh dự án; không cần engine agent |
| Nhập giọng nói/đọc câu trả lời | Thu âm chủ động, xem/sửa bản chép trước gửi; bật đọc riêng | Có cơ chế quyền mic và thông báo đích xử lý âm thanh |
| Mở rộng đọc tệp | DOCX/XLSX, OCR, trích xuất bảng | Có extractor, giới hạn rõ và báo độ phủ đọc |
| Chia sẻ kết quả | Link chỉ đọc cho bản được chọn; xem trước, thu hồi | Không mặc định public toàn dự án hay hướng dẫn/tệp gốc |

Research đã có ở V1; V1.1 đưa báo cáo vào Canvas và thư viện để biên tập/tái sử dụng thuận tiện hơn. Video, nhạc, Drive/Gmail và Canvas chạy code là các hướng khảo sát tiếp, chưa nằm trong cam kết V1/V1.1.

## 6. V2 — hệ AI agent riêng

- Tạo agent với tên, hướng dẫn, model, bộ công cụ và nguồn tri thức.
- Tách agent definition khỏi project; một agent có thể dùng trong nhiều dự án mà vẫn nhận hướng dẫn của dự án hiện tại.
- Lập kế hoạch, thực thi nhiều bước, trạng thái từng bước, điều kiện dừng, budget và giới hạn thời gian.
- Cho agent tùy biến gọi pipeline Research của V1 như một công cụ; mở rộng chiến lược nghiên cứu theo nhiệm vụ của agent.
- Tác vụ nền, lịch chạy, thông báo, lịch sử thực thi và phê duyệt hành động có tác động.
- Kết nối dịch vụ ngoài theo quyền cụ thể, tool registry, secrets vault và audit.
- Memory có phạm vi, quản lý kỹ năng/preset, đánh giá chất lượng; cộng tác nhiều agent chỉ khi có nhu cầu đã xác định.

V1 triển khai pipeline Research chuyên dụng cùng định danh run, adapter, context và sự kiện. Hệ agent tổng quát, marketplace kỹ năng, scheduler và hạ tầng đa agent dành cho V2; vòng lặp tìm bổ sung có giới hạn của Research không bị hoãn theo các hạng mục này.

## 7. Bố cục giao diện đề xuất

| Vùng | Nội dung |
| --- | --- |
| Sidebar | Chat mới, tìm kiếm, dự án, hội thoại gần đây; thư viện khi V1.1 phát hành |
| Header | Tên hội thoại, dự án/hướng dẫn hiện hành, model, thao tác hội thoại |
| Nội dung | Tin nhắn, nhánh trả lời, nguồn, trạng thái chạy/lỗi |
| Composer | Ô nhập, tệp/nguồn, chế độ, Tra cứu web/Nghiên cứu sâu, công cụ tương thích, gửi/dừng |
| Panel phải | Hướng dẫn/nguồn dự án; Canvas khi dùng tài liệu |
| Cài đặt người dùng | Giao diện, dữ liệu, provider riêng, model mặc định |
| Admin | Provider LLM/tìm kiếm, catalog, chế độ công khai, hạn mức, health và usage |

Khách thấy “Đăng nhập để đồng bộ và thêm provider riêng” ở vị trí phụ; không bị modal đăng ký chặn gửi tin đầu tiên.

## 8. Cổng nghiệm thu trước phát hành V1

| Tình huống | Kết quả bắt buộc |
| --- | --- |
| Mở cửa sổ mới chưa đăng nhập | Chat thật chạy bằng pool hệ thống; tạo được dự án, hướng dẫn và tải tệp hợp lệ |
| Pool hệ thống chưa cấu hình/hết hạn mức | Báo trạng thái thực, giữ câu hỏi/nguồn, không giả câu trả lời thành công |
| Hai người dùng hoặc hai phiên khách độc lập | Không đọc được hội thoại, nguồn, provider hay run của nhau qua URL/API |
| Sửa hướng dẫn, retry, fallback, tóm tắt lịch sử | Payload luôn mang đúng hướng dẫn/revision và phạm vi hiện tại |
| Đăng nhập và nhập workspace khách hai lần | Dữ liệu đúng chủ sở hữu, không nhân bản, không mất nhánh/tệp |
| Tắt model hoặc API key lỗi | Lỗi dễ hiểu; dự phòng tuân thủ scope/capability/quyền đã chọn |
| Tải model/test provider thất bại | Không mất form, không tự lưu, không trả key về trình duyệt/log |
| Dừng stream, mất mạng rồi thử lại | Không nhân tin nhắn/lượt tính usage; bản dở được phân biệt với bản hoàn tất |
| Vượt hạn mức đồng thời | Không cho nhiều request đua nhau vượt budget; tài nguyên giữ chỗ được giải phóng |
| Tệp đọc lỗi/đọc một phần | Người dùng thấy trạng thái/độ phủ thực; model không được nhận nội dung giả |
| Khách chọn Research với model không có native search | Lớp tìm kiếm ngoài thu thập nguồn thật, model tổng hợp có trích dẫn và giữ hướng dẫn dự án |
| Tìm được snippet nhưng đọc trang thất bại | Báo độ phủ một phần, không gắn nhãn đã đọc đầy đủ hoặc tạo trích dẫn giả |
| Nghiên cứu nhiều vòng bị dừng/hết budget/mất mạng | Giữ checkpoint và báo cáo một phần; tiếp tục không thu phí lại cùng operation đã có kết quả |
| Cập nhật báo cáo từ nguồn cache | Giữ revision cũ, tuổi nguồn và quyền truy cập; không giả thời điểm kiểm tra mới |
| Mobile, reload, điều hướng SPA | Dự án, nhánh, nguồn và trạng thái hội thoại được phục hồi đúng |

## 9. Các mặc định còn cần chốt khi triển khai

- Model công khai thực tế, ngân sách vận hành và bảng giá nếu cần ước tính chi phí.
- Hạn mức mỗi phiên/ngày, concurrency, dung lượng; cơ chế chống lạm dụng không bắt tài khoản cho đường dùng thông thường.
- Thời hạn lưu workspace khách/tệp/chat tạm thời và thời gian phục hồi sau xóa.
- Provider nào hoàn thiện cùng đợt phát hành đầu; các adapter còn lại phải hiện trong backlog, không hiện như đã hỗ trợ.
- Nhận diện thương hiệu và UI chi tiết. Đây là phạm vi thiết kế tiếp theo; chưa chọn hệ thống thanh toán/gói trả phí.
