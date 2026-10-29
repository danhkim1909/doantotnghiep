<?php
require_once __DIR__.'/includes/permissions.php';
require_role(ROLE_TEACHER);
$id=positive_int($_GET['id']??0);
redirect('teacher/courses.php'.($id?'?mode=edit&id='.$id:''));
