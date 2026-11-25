<?php
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/portal_ui.php';
require_once __DIR__ . '/../includes/icons.php';

$admin = require_role(ROLE_ADMIN);
header('Cache-Control: no-store, private, no-cache, must-revalidate');
header('Pragma: no-cache');

$actionFilter = get_query('action');
$targetFilter = get_query('target');
$actorFilter = positive_int($_GET['actor'] ?? null);
$page = positive_int($_GET['page'] ?? null, 1);
$actionFilter = substr(clean_text($actionFilter), 0, 80);
$targetFilter = substr(clean_text($targetFilter), 0, 80);

$rows = [];
$actors = [];
$pagination = paginate(0, $page, 20);
$loadError = false;

try {
    $pdo = db();
    $actors = $pdo->query(
        'SELECT id, full_name FROM users WHERE role = "admin" ORDER BY full_name, id'
    )->fetchAll();

    $where = ['1=1'];
    $params = [];

    if ($actionFilter !== '') {
        $where[] = 'l.action LIKE :action ESCAPE "!"';
        $params['action'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $actionFilter) . '%';
    }
    if ($targetFilter !== '') {
        $where[] = 'l.target_type LIKE :target ESCAPE "!"';
        $params['target'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $targetFilter) . '%';
    }
    if ($actorFilter > 0) {
        $where[] = 'l.actor_id = :actor';
        $params['actor'] = $actorFilter;
    }

    $whereSql = implode(' AND ', $where);
    $count = $pdo->prepare("SELECT COUNT(*) FROM bf_audit_logs l WHERE {$whereSql}");
    foreach ($params as $key => $value) {
        $count->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }
    $count->execute();
    $pagination = paginate((int) $count->fetchColumn(), $page, 20);

    $sql = "SELECT l.id, l.actor_id, l.action, l.target_type, l.target_id,
                   l.metadata, l.ip_address, l.user_agent, l.created_at,
                   u.full_name AS actor_name
            FROM bf_audit_logs l
            LEFT JOIN users u ON u.id = l.actor_id
            WHERE {$whereSql}
            ORDER BY l.created_at DESC, l.id DESC
            LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} catch (Throwable $exception) {
    error_log('[BrightFuture Admin Audit] ' . $exception->getMessage());
    $loadError = true;
}

$filterQuery = array_filter([
    'action' => $actionFilter,
    'target' => $targetFilter,
    'actor' => $actorFilter,
], static fn ($value) => $value !== '' && $value !== 0);

function audit_action_label(string $action): string
{
    return match ($action) {
        'user.update' => 'Cập nhật tài khoản',
        'user.delete' => 'Xóa tài khoản',
        'course.update' => 'Cập nhật khóa học',
        'course.toggle_status' => 'Đổi trạng thái khóa học',
        'course.delete' => 'Xóa khóa học',
        'course.archive' => 'Lưu trữ khóa học',
        'course.restore' => 'Khôi phục khóa học',
        'user.archive' => 'Lưu trữ tài khoản',
        'user.restore' => 'Khôi phục tài khoản',
        'report.resolve' => 'Xử lý báo cáo',
        'report.dismiss' => 'Bỏ qua báo cáo',
        'login.success' => 'Đăng nhập thành công',
        'login.success_2fa_pending' => 'Đăng nhập chờ xác thực hai bước',
        'login.failure' => 'Đăng nhập thất bại',
        'login.unverified' => 'Đăng nhập tài khoản chưa xác minh',
        'login.2fa_success' => 'Xác thực hai bước thành công',
        'login.2fa_failure' => 'Xác thực hai bước thất bại',
        'logout.success' => 'Đăng xuất',
        'totp.enable' => 'Bật xác thực hai bước',
        'totp.disable' => 'Tắt xác thực hai bước',
        'admin.export' => 'Xuất dữ liệu CSV',
        default => $action,
    };
}
?>
<?php
$pageTitle = 'Nhật ký quản trị - ' . APP_NAME;
$portalRole = ROLE_ADMIN;
$topbarTitle = 'Không gian quản trị';
$extraCss = ['css/bf-admin-audit.css'];
require_once __DIR__ . '/../views/layouts/portal_head.php';
?>
<?= bf_unified_sidebar($admin, 'audit') ?>
<?php require_once __DIR__ . '/../views/layouts/portal_topbar.php'; ?>

<main class="content container">
    <header class="audit-heading">
        <h1>Nhật ký quản trị</h1>
        <p>Chỉ dành cho quản trị viên; theo dõi tài khoản, khóa học và báo cáo đã thay đổi.</p>
        <p style="margin-top:10px"><a href="<?= e(app_url('admin/export.php?type=audit&' . http_build_query(['action'=>$actionFilter,'target'=>$targetFilter,'actor'=>$actorFilter]))) ?>">Tải CSV nhật ký</a></p>
    </header>
    <form class="audit-filter" method="get">
        <input type="search" name="action" value="<?= e($actionFilter) ?>" maxlength="80" placeholder="Hành động, ví dụ user.update">
        <input type="search" name="target" value="<?= e($targetFilter) ?>" maxlength="80" placeholder="Đối tượng, ví dụ course">
        <select name="actor" aria-label="Lọc người thực hiện">
            <option value="">Tất cả quản trị viên</option>
            <?php foreach ($actors as $actor): ?>
                <option value="<?= (int) $actor['id'] ?>" <?= $actorFilter === (int) $actor['id'] ? 'selected' : '' ?>>
                    <?= e($actor['full_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="audit-button" type="submit">Lọc</button>
        <a class="audit-clear" href="<?= e(app_url('admin/audit.php')) ?>">Xóa lọc</a>
    </form>
    <?php if ($loadError): ?>
        <div class="audit-panel"><div class="audit-error">Không thể tải nhật ký. Hãy chạy migration 0002 và kiểm tra MySQL.</div></div>
    <?php else: ?>
        <div class="audit-panel">
            <div class="audit-scroll">
                <table class="audit-table">
                    <thead><tr><th>Thời gian</th><th>Người thực hiện</th><th>Hành động</th><th>Đối tượng</th><th>Thông tin</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="6" class="audit-empty">Chưa có dữ liệu nhật ký.</td></tr>
                    <?php else: foreach ($rows as $row): ?>
                        <tr>
                            <td><?= e(format_datetime($row['created_at'])) ?></td>
                            <td><strong><?= e($row['actor_name'] ?? 'Tài khoản đã xóa') ?></strong><br><small>#<?= (int) $row['actor_id'] ?></small></td>
                            <td><span class="audit-action"><?= e(audit_action_label((string) $row['action'])) ?></span><br><small><?= e($row['action']) ?></small></td>
                            <td><strong><?= e($row['target_type']) ?></strong><br><small>#<?= $row['target_id'] !== null ? (int) $row['target_id'] : '—' ?></small></td>
                            <td><div class="audit-meta"><?= e($row['metadata'] ?: '—') ?></div></td>
                            <td><small><?= e($row['ip_address'] ?: '—') ?></small></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?= bf_pagination($pagination, 'admin/audit.php', $filterQuery) ?>
    <?php endif; ?>
</main>
<?php require_once __DIR__ . '/../views/layouts/portal_foot.php'; ?>
</body>
</html>
