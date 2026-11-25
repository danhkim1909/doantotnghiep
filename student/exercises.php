<?php
/**
 * BrightFuture — Student exercises
 * File: student/exercises.php
 * PHP >= 8.0; canonical database baseline plus forward migrations.
 */
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/portal_ui.php';

$user = require_role(ROLE_STUDENT);
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
$studentId = (int) $user['id'];

$keyword = get_query('q');
$keyword = function_exists('mb_substr') ? mb_substr($keyword, 0, 100, 'UTF-8') : substr($keyword, 0, 100);
$status = get_query('status', 'all');
if (!in_array($status, ['all', 'pending', 'submitted', 'graded', 'overdue'], true)) {
    $status = 'all';
}
$courseId = positive_int($_GET['course_id'] ?? null);
$page = positive_int($_GET['page'] ?? null, 1);
$perPage = 9;
$pagination = paginate(0, 1, $perPage);
$coursesForFilter = [];
$exercises = [];
$counts = ['all' => 0, 'pending' => 0, 'submitted' => 0, 'graded' => 0, 'overdue' => 0];
$dataError = false;

/** Preserve active filters across pagination. */
function exercises_page_url(int $page, string $keyword, string $status, int $courseId): string
{
    return app_url('student/exercises.php?' . http_build_query([
        'q' => $keyword, 'status' => $status,
        'course_id' => $courseId > 0 ? $courseId : '', 'page' => $page
    ]));
}

function exercises_type_label(string $type): string
{
    return match ($type) {
        'quiz' => 'Trắc nghiệm',
        'coding' => 'Lập trình',
        'file_upload' => 'Nộp tệp',
        'text' => 'Tự luận',
        default => 'Bài tập'
    };
}

function exercises_status_label(string $status): string
{
    return match ($status) {
        'pending' => 'Chưa nộp',
        'submitted' => 'Đã nộp / chờ chấm',
        'graded' => 'Đã chấm',
        'returned' => 'Yêu cầu bổ sung',
        'overdue' => 'Quá hạn',
        default => 'Không xác định'
    };
}

require_once __DIR__ . '/../classes/Models/Exercise.php';

use BrightFuture\Models\Exercise;

try {
    $pdo = db();
    $exerciseModel = new Exercise($pdo);

    // Danh sách khóa học học viên đang tham gia để lọc
    $coursesForFilter = $exerciseModel->getStudentEnrolledCourses($studentId);

    // Thống kê số lượng bài tập theo trạng thái
    $counts = $exerciseModel->getStudentCounts($studentId, $courseId, $keyword);

    $totalItems = $status === 'all' ? $counts['all'] : $counts[$status];
    $pagination = paginate($totalItems, $page, $perPage);

    // Lấy danh sách bài tập phân trang
    $exercises = $exerciseModel->getStudentExercises(
        $studentId,
        [
            'course_id' => $courseId,
            'keyword' => $keyword,
            'status' => $status,
        ],
        $pagination['per_page'],
        $pagination['offset']
    );
} catch (Throwable $exception) {
    error_log('[BrightFuture Exercises] ' . $exception->getMessage());
    $dataError = true;
    $exercises = [];
    $counts = ['all' => 0, 'pending' => 0, 'submitted' => 0, 'graded' => 0, 'overdue' => 0];
    $pagination = paginate(0, 1, $perPage);
}

$pageTitle = 'Bài tập của tôi';
$pageDescription = 'Theo dõi bài tập, hạn nộp và kết quả chấm điểm trên BrightFuture';
$activeMenu = 'exercises';
$extraCss = ['css/bf-student-exercises.css'];

require_once __DIR__ . '/../views/layouts/portal_head.php';
?>
<?= bf_unified_sidebar($user, 'exercises') ?>
<?php require_once __DIR__ . '/../views/layouts/portal_topbar.php'; ?>
<main class="content exercises-content">
        <div class="breadcrumb"><a href="<?= e(app_url('student/dashboard.php')) ?>">Tổng quan</a> / <span>Bài tập</span></div>
        <div class="heading"><div><h1>Bài tập của tôi</h1><p>Theo dõi bài tập, hạn nộp, số lần thực hiện và kết quả chấm điểm.</p></div><a class="explore" href="<?= e(app_url('courses.php')) ?>">Khám phá khóa học →</a></div>
        <?php if ($dataError): ?>
            <div class="alert" role="alert">Không tải được danh sách bài tập. Hãy kiểm tra kết nối MySQL, schema baseline và trạng thái migration.</div>
        <?php endif; ?>
        <section class="summary" aria-label="Thống kê bài tập">
            <?php foreach (['all'=>'Tất cả bài tập','pending'=>'Chưa nộp','submitted'=>'Chờ chấm','graded'=>'Đã chấm','overdue'=>'Quá hạn'] as $key => $label): ?>
                <div class="summary-item"><span><?= e($label) ?></span><strong><?= format_number($counts[$key]) ?></strong></div>
            <?php endforeach; ?>
        </section>
        <section class="filter-box" aria-label="Tìm kiếm và lọc bài tập">
            <form class="filter-form" method="GET" action="<?= e(app_url('student/exercises.php')) ?>">
                <input class="field" type="search" name="q" value="<?= e($keyword) ?>" maxlength="100" placeholder="Tìm bài tập, bài học hoặc khóa học..." aria-label="Từ khóa">
                <select class="field" name="course_id" aria-label="Khóa học">
                    <option value="">Tất cả khóa học</option>
                    <?php foreach ($coursesForFilter as $course): ?>
                        <option value="<?= (int)$course['id'] ?>" <?= $courseId === (int)$course['id'] ? 'selected' : '' ?>><?= e($course['title']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="field" name="status" aria-label="Trạng thái">
                    <?php foreach (['all'=>'Tất cả trạng thái','pending'=>'Chưa nộp','submitted'=>'Chờ chấm','graded'=>'Đã chấm','overdue'=>'Quá hạn'] as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="search-btn" type="submit">Tìm kiếm</button>
            </form>
            <div class="filter-meta"><span>Tìm thấy <strong><?= format_number($pagination['total_items']) ?></strong> bài tập</span>
                <?php if ($keyword !== '' || $courseId > 0 || $status !== 'all'): ?><a href="<?= e(app_url('student/exercises.php')) ?>">Xóa bộ lọc</a><?php endif; ?>
            </div>
        </section>
        <section class="grid" aria-label="Danh sách bài tập">
            <?php if (empty($exercises)): ?>
                <div class="empty"><div class="bf-empty-icon" style="margin: 0 auto 16px;"><?= bf_icon('exercises') ?></div><h2><?= $dataError ? 'Chưa thể hiển thị bài tập' : 'Không tìm thấy bài tập phù hợp' ?></h2>
                    <p><?= $dataError ? 'Vui lòng thử lại sau khi kiểm tra cơ sở dữ liệu.' : 'Nếu bạn chưa thấy bài tập, hãy kiểm tra khóa học đã đăng ký hoặc thay đổi bộ lọc.' ?></p>
                    <a class="explore" href="<?= e(app_url('student/my-courses.php')) ?>">Xem khóa học của tôi</a>
                </div>
            <?php else: ?>
                <?php foreach ($exercises as $ex): ?>
                    <?php
                    $displayStatus = (string)$ex['display_status'];
                    $score = $ex['latest_score'];
                    $deadline = $ex['deadline'];
                    $used = (int)$ex['attempts_used'];
                    $maxAttempts = $ex['max_attempts'] === null ? null : (int)$ex['max_attempts'];
                    $outOfAttempts = $maxAttempts !== null && $used >= $maxAttempts;
                    $deadlinePassed = $deadline !== null && strtotime((string)$deadline) < time();
                    $canSubmitAgain = !$deadlinePassed && (!$outOfAttempts || $displayStatus === 'returned');
                    ?>
                    <article class="exercise">
                        <div class="card-top"><span class="type-tag"><?= e(exercises_type_label((string)$ex['exercise_type'])) ?></span><span class="status <?= e($displayStatus) ?>"><?= e(exercises_status_label($displayStatus)) ?></span></div>
                        <h2><?= e($ex['title']) ?></h2>
                        <p class="location"><?= e($ex['course_title']) ?> · <?= e($ex['lesson_title']) ?></p>
                        <p class="desc"><?= e(truncate_text(clean_text((string)($ex['description'] ?? '')), 130) ?: 'Xem yêu cầu và hướng dẫn để thực hiện bài tập.') ?></p>
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;flex-wrap:wrap">
                            <span style="font-size:11px;font-weight:600;padding:4px 8px;border-radius:6px;background:#f1f5f9;color:#475569">
                                ⏱️ Ước tính: <?= (string)$ex['exercise_type'] === 'quiz' ? '15–20 phút' : '30–45 phút' ?>
                            </span>
                            <span style="font-size:11px;font-weight:600;padding:4px 8px;border-radius:6px;background:#eef2ff;color:#4f46e5">
                                🎯 Điểm tối đa: <?= e(format_number((float)$ex['max_score'], 0)) ?>đ
                            </span>
                        </div>
                        <div class="metadata">
                            <div><span>Hạn nộp</span><strong><?= $deadline !== null ? e(format_datetime((string)$deadline)) : 'Không giới hạn' ?></strong></div>
                            <div><span>Số lần đã nộp</span><strong><?= $used ?><?= $maxAttempts !== null ? ' / ' . $maxAttempts : ' / Không giới hạn' ?></strong></div>
                            <div><span>Điểm gần nhất</span><strong><?= $score !== null ? e(format_number((float)$score, 2)) . ' / ' . e(format_number((float)$ex['max_score'], 2)) : 'Chưa có điểm' ?></strong></div>
                            <?php if ($ex['submitted_at'] !== null): ?><div><span>Lần nộp gần nhất</span><strong><?= e(format_datetime((string)$ex['submitted_at'])) ?></strong></div><?php endif; ?>
                        </div>
                        <?php if ($deadline !== null): ?>
                            <div class="countdown-box" data-deadline="<?= e(date('c', strtotime((string)$deadline))) ?>">
                                <span class="cd-label">⏳ <span class="cd-text">Còn lại:</span></span>
                                <span class="cd-val">Đang tính...</span>
                            </div>
                        <?php endif; ?>
                        <div style="display:flex;flex-direction:column;gap:8px;margin-top:14px">
                            <a class="action" style="margin-top:0" href="<?= e(exercise_url((int)$ex['id'])) ?>">
                                <?= $displayStatus === 'graded' ? 'Xem kết quả bài tập' : ($canSubmitAgain ? 'Xem / làm bài tập' : 'Xem chi tiết bài tập') ?> →
                            </a>
                            <?php if ((string)$ex['exercise_type'] === 'quiz' && !empty($ex['submission_id'])): ?>
                                <button type="button" class="btn-view-quick" data-sub-id="<?= (int)$ex['submission_id'] ?>" style="align-self:center">
                                    ⚡ Xem nhanh kết quả trắc nghiệm
                                </button>
                            <?php endif; ?>
                            <?php if (!empty($ex['latest_file_path']) && !empty($ex['submission_id'])): ?>
                                <a class="action" style="margin-top:0;background:#f1f5f9;color:#334155" href="<?= e(app_url('api/submissions.php?action=download&id=' . (int)$ex['submission_id'])) ?>">Tải tệp bài nộp gần nhất</a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
        <?php if ($pagination['total_pages'] > 1): ?>
            <?php $start = max(1, min($pagination['current_page'] - 2, $pagination['total_pages'] - 4)); $end = min($pagination['total_pages'], $start + 4); ?>
            <nav class="pagination" aria-label="Phân trang">
                <?php if ($pagination['has_previous']): ?><a class="page" href="<?= e(exercises_page_url($pagination['previous_page'], $keyword, $status, $courseId)) ?>" aria-label="Trang trước">←</a><?php endif; ?>
                <?php for ($n = $start; $n <= $end; $n++): ?><a class="page <?= $n === $pagination['current_page'] ? 'active' : '' ?>" <?= $n === $pagination['current_page'] ? 'aria-current="page"' : '' ?> href="<?= e(exercises_page_url($n, $keyword, $status, $courseId)) ?>"><?= $n ?></a><?php endfor; ?>
                <?php if ($pagination['has_next']): ?><a class="page" href="<?= e(exercises_page_url($pagination['next_page'], $keyword, $status, $courseId)) ?>" aria-label="Trang sau">→</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </main>

    <!-- Modal Xem nhanh kết quả trắc nghiệm -->
    <div id="quizResultModal" class="bf-modal-backdrop" aria-hidden="true" role="dialog" aria-labelledby="modalQuizTitle">
        <div class="bf-modal-content">
            <div class="bf-modal-header">
                <h3 id="modalQuizTitle">Chi tiết kết quả trắc nghiệm</h3>
                <button type="button" class="bf-modal-close" id="closeQuizModal" aria-label="Đóng">&times;</button>
            </div>
            <div class="bf-modal-body" id="modalQuizBody">
                <div style="text-align:center;padding:20px;color:#64748b">Đang tải dữ liệu kết quả...</div>
            </div>
            <div class="bf-modal-footer">
                <button type="button" class="explore" id="btnOkQuizModal" style="padding:8px 20px;cursor:pointer">Đóng</button>
            </div>
        </div>
    </div>

    <script>
    (function(){
        // 1. Xử lý Countdown Timer cho Deadline
        function updateCountdowns() {
            const now = new Date().getTime();
            document.querySelectorAll('.countdown-box[data-deadline]').forEach(box => {
                const deadlineTime = new Date(box.getAttribute('data-deadline')).getTime();
                const diff = deadlineTime - now;
                const valEl = box.querySelector('.cd-val');
                const textEl = box.querySelector('.cd-text');

                box.classList.remove('urgent', 'overdue', 'safe');

                if (isNaN(deadlineTime)) {
                    valEl.textContent = '--';
                    return;
                }

                if (diff <= 0) {
                    box.classList.add('overdue');
                    if (textEl) textEl.textContent = 'Hết hạn:';
                    valEl.textContent = 'Đã kết thúc';
                    return;
                }

                const days = Math.floor(diff / (1000 * 60 * 60 * 24));
                const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((diff % (1000 * 60)) / 1000);

                if (days < 1) {
                    box.classList.add('urgent');
                    if (textEl) textEl.textContent = 'Sắp hết hạn:';
                } else {
                    box.classList.add('safe');
                    if (textEl) textEl.textContent = 'Còn lại:';
                }

                if (days > 0) {
                    valEl.textContent = `${days} ngày ${hours} giờ ${minutes}p`;
                } else if (hours > 0) {
                    valEl.textContent = `${hours} giờ ${minutes}p ${seconds}s`;
                } else {
                    valEl.textContent = `${minutes}p ${seconds}s`;
                }
            });
        }
        updateCountdowns();
        setInterval(updateCountdowns, 1000);

        // 2. Modal xem nhanh kết quả trắc nghiệm
        const modal = document.getElementById('quizResultModal');
        const modalBody = document.getElementById('modalQuizBody');
        const closeBtn = document.getElementById('closeQuizModal');
        const okBtn = document.getElementById('btnOkQuizModal');

        function closeModal() {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
        }

        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (okBtn) okBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
        });

        document.querySelectorAll('.btn-view-quick[data-sub-id]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const subId = btn.getAttribute('data-sub-id');
                modalBody.innerHTML = '<div style="text-align:center;padding:25px;color:#64748b">⏳ Đang tải kết quả trắc nghiệm...</div>';
                modal.classList.add('open');
                modal.setAttribute('aria-hidden', 'false');

                try {
                    const res = await fetch(`<?= e(app_url('api/submissions.php')) ?>?id=${encodeURIComponent(subId)}`, {
                        credentials: 'same-origin'
                    });
                    const resData = await res.json();

                    if (!res.ok || !resData.success || !resData.data) {
                        modalBody.innerHTML = `<div style="color:#ef4444;text-align:center;padding:15px">⚠️ ${resData.message || 'Không tìm thấy kết quả nộp bài.'}</div>`;
                        return;
                    }

                    const d = resData.data;
                    const isGraded = d.status === 'graded';
                    const passed = d.score !== null && d.max_score > 0 && (d.score >= d.max_score * 0.5);

                    let html = `
                        <div style="text-align:center;margin-bottom:20px">
                            <div style="font-size:42px;margin-bottom:8px">${passed ? '🎉' : '✍️'}</div>
                            <h4 style="margin:0 0 6px;font-size:18px;font-weight:700;color:#0f172a">${d.exercise_title || 'Bài trắc nghiệm'}</h4>
                            <p style="margin:0;font-size:13px;color:#64748b">${d.course_title || ''}</p>
                        </div>
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:18px;font-size:13px">
                            <div><span style="color:#64748b">Lần thực hiện:</span> <strong>Lần ${d.attempt_number}</strong></div>
                            <div><span style="color:#64748b">Trạng thái:</span> <strong style="color:${isGraded ? '#166534' : '#b45309'}">${isGraded ? 'Đã chấm điểm' : 'Chờ chấm'}</strong></div>
                            <div><span style="color:#64748b">Điểm đạt được:</span> <strong style="font-size:16px;color:${passed ? '#16a34a' : '#dc2626'}">${d.score !== null ? Number(d.score).toFixed(2) : '--'} / ${Number(d.max_score).toFixed(2)}</strong></div>
                            <div><span style="color:#64748b">Đánh giá:</span> <strong style="color:${passed ? '#16a34a' : '#dc2626'}">${passed ? 'ĐẠT (>= 50%)' : 'CHƯA ĐẠT'}</strong></div>
                        </div>
                    `;

                    if (d.feedback) {
                        html += `
                            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:14px;font-size:13px;color:#1e40af;line-height:1.6">
                                <strong>💡 Nhận xét từ hệ thống:</strong><br>${d.feedback}
                            </div>
                        `;
                    }

                    if (d.submitted_at) {
                        html += `
                            <p style="margin:16px 0 0;font-size:12px;color:#94a3b8;text-align:right">
                                Thời gian nộp: ${d.submitted_at}
                            </p>
                        `;
                    }

                    modalBody.innerHTML = html;
                } catch (err) {
                    modalBody.innerHTML = '<div style="color:#ef4444;text-align:center;padding:15px">⚠️ Có lỗi xảy ra khi tải kết quả. Vui lòng thử lại sau!</div>';
                }
            });
        });
    })();
    </script>
<?php require_once __DIR__ . '/../views/layouts/portal_foot.php'; ?>
