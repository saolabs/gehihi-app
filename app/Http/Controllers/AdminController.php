<?php

namespace App\Http\Controllers;

use Saola\Core\Http\Controllers\BaseWebController;

abstract class AdminController extends BaseWebController
{
    protected $context = 'admin';

    /** Kết quả thao tác (POST/PUT/DELETE) gửi về App.Http của view; trang GET dùng response() tự suy view từ tên route. */
    protected function result(array $data, int $status = 200)
    {
        return $this->response($data, null, ['forceJson' => true, 'status' => $status]);
    }
}
