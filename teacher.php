<?php
require_once __DIR__.'/includes/permissions.php';
require_role(ROLE_TEACHER);
redirect('teacher/dashboard.php');
