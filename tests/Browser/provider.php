<?php

// Local browser QA fixture. Never configure this as a production provider.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/article') {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<html><head><title>Nguồn kiểm thử Gehihi</title></head><body><main>'.str_repeat('Đây là dữ liệu kiểm thử cục bộ cho pipeline tìm kiếm, đọc nguồn và tổng hợp báo cáo. ', 20).'</main></body></html>';

    return;
}
header('Content-Type: application/json; charset=UTF-8');
if ($path === '/search') {
    echo json_encode(['results' => [['title' => 'Nguồn kiểm thử cục bộ', 'url' => 'http://127.0.0.1:8011/article', 'content' => 'Dữ liệu mô phỏng để kiểm tra Research.']]], JSON_UNESCAPED_UNICODE);

    return;
}
$body = json_decode(file_get_contents('php://input'), true);
$prompt = $body['messages'][1]['content'] ?? '';
$text = match (true) {
    str_starts_with($prompt, 'Lập kế hoạch') => json_encode(['questions' => ['Kiểm tra nguồn và tổng hợp'], 'queries' => ['Gehihi browser QA']]),
    str_starts_with($prompt, 'Đánh giá') => json_encode(['complete' => true, 'missing' => [], 'queries' => []]),
    str_starts_with($prompt, 'Viết báo cáo') => "## Báo cáo kiểm thử Research\n\nPipeline đã tìm kiếm và đọc nội dung nguồn cục bộ [S1].\n\n### Kết luận\nLuồng nghiên cứu, lưu nguồn và hiển thị Markdown hoạt động với dữ liệu mô phỏng.\n\n### Thông tin còn thiếu\nCần cấu hình provider thật để sử dụng dữ liệu thực tế.",
    default => "**Gehihi đã sẵn sàng.** Đây là phản hồi từ provider kiểm thử cục bộ.\n\n- Hội thoại được lưu theo dự án.\n- Hướng dẫn dự án được nạp vào lượt chạy.\n- Bạn có thể chuyển sang Tra cứu web hoặc Nghiên cứu sâu.",
};
if ($path === '/v1/models') {
    echo json_encode(['data' => [['id' => 'local-qa-model']]]);

    return;
}
header('Content-Type: text/event-stream');
foreach (mb_str_split($text, 16) as $piece) {
    echo 'data: '.json_encode(['choices' => [['delta' => ['content' => $piece]]]], JSON_UNESCAPED_UNICODE)."\n\n";
    flush();
    usleep(15000);
}
echo 'data: '.json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 80]])."\n\ndata: [DONE]\n\n";
