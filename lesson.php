<?php
require_once __DIR__ . '/includes/permissions.php';
$id=positive_int($_GET['id']??null);
if($id<1){http_response_code(404);exit('Thiếu mã bài giảng.');}
redirect('student/learn.php?id='.$id);
