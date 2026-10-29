<?php
require_once __DIR__ . '/social.php';
function bf_portal_begin(array $user,string $active,string $title): void {
 $role=(string)$user['role'];
 $home=$role==='admin'?'admin/dashboard.php':($role==='teacher'?'teacher/dashboard.php':'student/dashboard.php');
 $links=[['dashboard',$home,'▦','Tổng quan'],['courses','courses.php','▤','Khóa học'],['social','social.php','♧','Bạn bè & tin nhắn'],['rooms','rooms.php','◉','Phòng gọi nhóm'],['profile','profile.php','◯','Hồ sơ']];
 if($role==='teacher') array_splice($links,2,0,[['teaching','teacher/courses.php','▣','Giảng dạy']]);
 if($role==='admin') array_splice($links,2,0,[['admin','admin/users.php','♙','Quản trị người dùng'],['reports','admin/moderation.php','⚑','Duyệt báo cáo']]);
 ?>
<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($title)?> | BrightFuture</title><link rel="stylesheet" href="<?=e(app_url('css/bf-portal.css'))?>"></head>
<body><div class="bf-app"><aside class="bf-rail" id="bfRail"><a class="bf-logo" href="<?=e(app_url($home))?>"><span>⌘</span> BrightFuture</a><div class="bf-nav-title">KHÔNG GIAN LÀM VIỆC</div><nav class="bf-nav"><?php foreach($links as [$key,$path,$icon,$label]): ?><a class="<?= $active===$key?'active':'' ?>" <?= $active===$key?'aria-current="page"':'' ?> href="<?=e(app_url($path))?>"><span aria-hidden="true"><?=e($icon)?></span><?=e($label)?></a><?php endforeach;?></nav><div class="bf-user"><b><?=e($user['full_name'])?></b><small><?=e(bf_social_role($role))?></small><form method="post" action="<?=e(app_url('logout.php'))?>"><?=csrf_field()?><button class="bf-muted" type="submit">Đăng xuất</button></form></div></aside><div class="bf-shade" id="bfShade"></div><div class="bf-main"><header class="bf-top"><div><button type="button" id="bfMenu" class="bf-menu" aria-label="Mở hoặc thu gọn menu">☰</button><strong><?=e($title)?></strong></div><a href="<?=e(app_url($home))?>">Trang chủ của tôi ↗</a></header><main class="bf-container">
<?php }
function bf_portal_end():void { ?>
</main></div></div><script src="<?=e(app_url('js/bf-portal.js'))?>" defer></script></body></html>
<?php }
