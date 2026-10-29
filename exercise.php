<?php
require_once __DIR__.'/includes/permissions.php';
require_role(ROLE_STUDENT);
redirect('student/exercises.php');
