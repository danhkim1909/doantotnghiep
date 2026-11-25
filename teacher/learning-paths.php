<?php
/**
 * ====================================================
 * BRIGHTFUTURE - THIẾT LẬP ĐIỀU KIỆN MỞ KHÓA (LEARNING PATHS)
 * File: teacher/learning-paths.php
 * PHP 8.1+ / MySQL PDO
 * ====================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/course_unlock.php';
require_once __DIR__ . '/../includes/portal_ui.php';

$user = require_role(ROLE_TEACHER);
$teacherId = (int) $user['id'];
$pdo = db();

$courseId = positive_int($_GET['course_id'] ?? $_POST['course_id'] ?? null);
if (!$courseId) {
    redirect('teacher/courses.php');
}

// Lấy thông tin khóa học thuộc quyền sở hữu của giảng viên
$stmt = $pdo->prepare('SELECT id, title, level FROM courses WHERE id = :id AND teacher_id = :tid AND deleted_at IS NULL');
$stmt->execute(['id' => $courseId, 'tid' => $teacherId]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    render_http_error(404, 'Không tìm thấy', 'Khóa học không tồn tại hoặc bạn không sở hữu khóa học này.', app_url('teacher/courses.php'));
}

if ($course['level'] === 'beginner') {
    render_http_error(422, 'Không áp dụng', 'Khóa học cấp độ Cơ bản (Beginner) luôn được mở tự do cho mọi học viên.', app_url('teacher/courses.php'));
}

$levelBefore = ($course['level'] === 'intermediate') ? 'beginner' : 'intermediate';
$levelBeforeLabel = ($levelBefore === 'beginner') ? 'Cơ bản (Beginner)' : 'Trung bình (Intermediate)';

// Lấy danh sách khóa học cấp độ trước để làm điều kiện tiên quyết
$stmt = $pdo->prepare(
    'SELECT id, title 
     FROM courses 
     WHERE teacher_id = :tid AND level = :lvl AND id != :cid AND deleted_at IS NULL 
     ORDER BY title ASC'
);
$stmt->execute(['tid' => $teacherId, 'lvl' => $levelBefore, 'cid' => $courseId]);
$previousCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lấy các bài kiểm tra trắc nghiệm (quiz) thuộc các khóa học cấp độ trước
$stmt = $pdo->prepare(
    "SELECT ex.id, ex.title, ch.course_id 
     FROM exercises ex 
     JOIN lessons l ON l.id = ex.lesson_id 
     JOIN course_chapters ch ON ch.id = l.chapter_id 
     JOIN courses c ON c.id = ch.course_id 
     WHERE c.teacher_id = :tid AND c.level = :lvl AND ex.exercise_type = 'quiz' AND c.deleted_at IS NULL
     ORDER BY c.title ASC, ex.title ASC"
);
$stmt->execute(['tid' => $teacherId, 'lvl' => $levelBefore]);
$quizTests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$errorMessage = '';

// Xử lý lưu hoặc xóa điều kiện mở khóa
if (is_post()) {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $stmt = $pdo->prepare('DELETE FROM course_unlock_rules WHERE course_id = :cid');
        $stmt->execute(['cid' => $courseId]);
        set_flash('success', 'Đã xóa điều kiện mở khóa. Khóa học sẽ tạm thời ẩn với học viên cho đến khi thiết lập lại.');
        redirect('teacher/learning-paths.php?course_id=' . $courseId);
    } elseif ($action === 'save') {
        $previousCourseId = positive_int($_POST['prerequisite_course_id'] ?? null);
        $testExerciseId = positive_int($_POST['required_test_exercise_id'] ?? null);
        $minScore = filter_var($_POST['min_score_percent'] ?? null, FILTER_VALIDATE_FLOAT);

        $validPrev = false;
        foreach ($previousCourses as $pc) {
            if ((int) $pc['id'] === $previousCourseId) {
                $validPrev = true;
                break;
            }
        }

        $validTest = false;
        foreach ($quizTests as $t) {
            if ((int) $t['id'] === $testExerciseId && (int) $t['course_id'] === $previousCourseId) {
                $validTest = true;
                break;
            }
        }

        if (!$validPrev || !$validTest || $minScore === false || $minScore < 0 || $minScore > 100) {
            $errorMessage = 'Vui lòng chọn khóa học tiên quyết, bài trắc nghiệm thuộc khóa đó và điểm đạt từ 0% đến 100%.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO course_unlock_rules (course_id, prerequisite_course_id, required_test_exercise_id, min_score_percent)
                     VALUES (:cid, :prev, :test, :min)
                     ON DUPLICATE KEY UPDATE 
                        prerequisite_course_id = VALUES(prerequisite_course_id),
                        required_test_exercise_id = VALUES(required_test_exercise_id),
                        min_score_percent = VALUES(min_score_percent)'
                );
                $stmt->execute([
                    'cid' => $courseId,
                    'prev' => $previousCourseId,
                    'test' => $testExerciseId,
                    'min' => $minScore,
                ]);
                set_flash('success', 'Đã lưu thiết lập điều kiện mở khóa thành công!');
                redirect('teacher/learning-paths.php?course_id=' . $courseId);
            } catch (Throwable $e) {
                error_log('[BrightFuture unlock rules] ' . $e->getMessage());
                $errorMessage = 'Không thể lưu điều kiện lúc này. Vui lòng kiểm tra lại cấu hình.';
            }
        }
    } else {
        $errorMessage = 'Thao tác không hợp lệ.';
    }
}

// Lấy quy tắc hiện tại (nếu có)
$stmt = $pdo->prepare('SELECT * FROM course_unlock_rules WHERE course_id = :id');
$stmt->execute(['id' => $courseId]);
$rule = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$portalRoleTitle = 'Không gian giảng dạy';
require_once __DIR__ . '/../views/layouts/portal_head.php';
echo bf_unified_sidebar($user, 'courses');
require_once __DIR__ . '/../views/layouts/portal_topbar.php';
?>

<main class="content" style="max-width: 900px; margin: 30px auto; padding: 0 20px 60px;">
    <div style="margin-bottom: 20px;">
        <a href="<?= e(app_url('teacher/courses.php')) ?>" style="color: #4f46e5; text-decoration: none; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
            ← Quay lại danh sách khóa học
        </a>
    </div>

    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 24px; font-weight: 700; color: #1e293b; margin: 0 0 6px;">
            Điều kiện mở khóa: <?= e($course['title']) ?>
        </h1>
        <p style="color: #64748b; font-size: 14px; margin: 0; line-height: 1.6;">
            Học viên chỉ có thể mở khóa và đăng ký học phần này sau khi hoàn thành khóa học tiên quyết (cấp độ <?= e($levelBeforeLabel) ?>) và đạt bài kiểm tra trắc nghiệm yêu cầu.
        </p>
    </div>

    <?= render_flash() ?>

    <?php if ($errorMessage): ?>
        <div style="padding: 14px 18px; border-radius: 12px; background: #fff1f2; border: 1px solid #fecdd3; color: #9f1239; margin-bottom: 20px; font-size: 14px;">
            <?= e($errorMessage) ?>
        </div>
    <?php endif; ?>

    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 28px; box-shadow: 0 4px 20px rgba(0,0,0,0.03);">
        <form method="POST" action="<?= e(app_url('teacher/learning-paths.php?course_id=' . $courseId)) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="course_id" value="<?= $courseId ?>">

            <div style="margin-bottom: 20px;">
                <label for="prereq" style="display: block; font-weight: 600; font-size: 14px; color: #1e293b; margin-bottom: 8px;">
                    1. Khóa học tiên quyết (Cấp độ: <?= e($levelBeforeLabel) ?>) <span style="color: #ef4444;">*</span>
                </label>
                <select name="prerequisite_course_id" id="prereq" required style="width: 100%; height: 44px; border: 1px solid #cbd5e1; border-radius: 10px; padding: 0 14px; font-size: 14px; background: #ffffff; color: #1e293b;">
                    <option value="">-- Chọn khóa học cần hoàn thành trước --</option>
                    <?php foreach ($previousCourses as $pc): ?>
                        <option value="<?= (int) $pc['id'] ?>" <?= ((int) ($rule['prerequisite_course_id'] ?? 0) === (int) $pc['id']) ? 'selected' : '' ?>>
                            <?= e($pc['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($previousCourses)): ?>
                    <p style="margin: 6px 0 0; font-size: 12px; color: #d97706;">
                        ⚠️ Bạn chưa có khóa học nào thuộc cấp độ <?= e($levelBeforeLabel) ?> để làm điều kiện tiên quyết.
                    </p>
                <?php endif; ?>
            </div>

            <div style="margin-bottom: 20px;">
                <label for="test" style="display: block; font-weight: 600; font-size: 14px; color: #1e293b; margin-bottom: 8px;">
                    2. Bài kiểm tra trắc nghiệm cuối khóa <span style="color: #ef4444;">*</span>
                </label>
                <select name="required_test_exercise_id" id="test" required style="width: 100%; height: 44px; border: 1px solid #cbd5e1; border-radius: 10px; padding: 0 14px; font-size: 14px; background: #ffffff; color: #1e293b;">
                    <option value="">-- Chọn bài kiểm tra trắc nghiệm --</option>
                    <?php foreach ($quizTests as $t): ?>
                        <option data-course="<?= (int) $t['course_id'] ?>" value="<?= (int) $t['id'] ?>" <?= ((int) ($rule['required_test_exercise_id'] ?? 0) === (int) $t['id']) ? 'selected' : '' ?>>
                            <?= e($t['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p style="margin: 6px 0 0; font-size: 12px; color: #64748b;">
                    Chỉ những bài tập loại <strong>Trắc nghiệm (Quiz)</strong> thuộc khóa học tiên quyết đã chọn mới được hiển thị.
                </p>
            </div>

            <div style="margin-bottom: 26px;">
                <label for="minScore" style="display: block; font-weight: 600; font-size: 14px; color: #1e293b; margin-bottom: 8px;">
                    3. Điểm số tối thiểu để vượt qua (%) <span style="color: #ef4444;">*</span>
                </label>
                <input 
                    type="number" 
                    id="minScore" 
                    name="min_score_percent" 
                    min="0" 
                    max="100" 
                    step="1" 
                    value="<?= e((string) ($rule['min_score_percent'] ?? 70)) ?>" 
                    required 
                    style="width: 100%; max-width: 200px; height: 44px; border: 1px solid #cbd5e1; border-radius: 10px; padding: 0 14px; font-size: 14px; background: #ffffff; color: #1e293b;"
                >
                <span style="font-size: 13px; color: #64748b; margin-left: 8px;">% (Mặc định: 70%)</span>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; gap: 14px; border-top: 1px solid #f1f5f9; padding-top: 20px; flex-wrap: wrap;">
                <button type="submit" style="display: inline-flex; align-items: center; gap: 8px; padding: 11px 22px; background: #4f46e5; color: #ffffff; border: 0; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background 0.2s ease;">
                    Lưu điều kiện mở khóa
                </button>
            </div>
        </form>

        <?php if (!empty($rule)): ?>
            <div style="margin-top: 24px; padding-top: 20px; border-top: 1px dashed #e2e8f0; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                <div>
                    <span style="font-size: 13px; color: #64748b; font-weight: 500;">
                        Khóa học này hiện đang có 1 điều kiện mở khóa hoạt động.
                    </span>
                </div>
                <form method="POST" action="<?= e(app_url('teacher/learning-paths.php?course_id=' . $courseId)) ?>" onsubmit="return confirm('Bạn có chắc chắn muốn xóa điều kiện tiên quyết? Khóa học này sẽ tạm thời bị ẩn với học viên.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="course_id" value="<?= $courseId ?>">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" style="background: #ef4444; color: #ffffff; border: 0; border-radius: 8px; padding: 8px 16px; font-size: 13px; font-weight: 600; cursor: pointer;">
                        Xóa điều kiện này
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const prereqSelect = document.getElementById('prereq');
    const testSelect = document.getElementById('test');

    function filterTestsByCourse() {
        const selectedCourseId = prereqSelect.value;
        let hasVisibleOptions = false;

        Array.from(testSelect.options).forEach(opt => {
            if (!opt.value) return; // Keep placeholder option
            const optCourse = opt.dataset.course;
            const matches = (optCourse === selectedCourseId);
            opt.hidden = !matches;
            if (matches) hasVisibleOptions = true;
            if (!matches && opt.selected) {
                testSelect.value = '';
            }
        });
    }

    prereqSelect.addEventListener('change', filterTestsByCourse);
    filterTestsByCourse();
});
</script>

<?php require_once __DIR__ . '/../views/layouts/portal_foot.php'; ?>
