<?php
// Disable legacy public diagnostic endpoint, which previously exposed filesystem paths.
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
exit('Không tìm thấy trang.');
