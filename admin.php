<?php
require_once __DIR__.'/includes/permissions.php';
require_role(ROLE_ADMIN);
redirect('admin/dashboard.php');
