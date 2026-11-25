const { test, expect } = require('@playwright/test');

const password = 'Dev123456!';
const genericVerificationMessage =
  'Nếu địa chỉ email thuộc tài khoản chưa xác minh, hướng dẫn sẽ được gửi. '
  + 'Nếu bạn vừa yêu cầu gần đây, vui lòng đợi vài phút rồi thử lại.';

async function login(page, email) {
  await page.goto('login.php');
  await page.getByLabel('Địa chỉ email').fill(email);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: 'Đăng nhập' }).click();
}

test('student login opens student dashboard', async ({ page }) => {
  await login(page, 'student@brightfuture.local');
  await expect(page).toHaveURL(/student\/dashboard\.php$/);
  await expect(page.getByRole('heading', { name: /Xin chào/ })).toBeVisible();
});

test('teacher login opens teacher dashboard', async ({ page }) => {
  await login(page, 'teacher@brightfuture.local');
  await expect(page).toHaveURL(/teacher\/dashboard\.php$/);
  await expect(page.getByText(/Không gian giảng dạy|Tổng quan giảng dạy/)).toBeVisible();
});

test('admin login opens admin dashboard', async ({ page }) => {
  await login(page, 'admin@brightfuture.local');
  await expect(page).toHaveURL(/admin\/dashboard\.php$/);
  await expect(page.getByRole('heading', { name: /Xin chào/ })).toBeVisible();
});

test('student cannot open admin dashboard', async ({ page }) => {
  await login(page, 'student@brightfuture.local');
  await page.goto('admin/dashboard.php');
  await expect(page).toHaveURL(/admin\/dashboard\.php$/);
  await expect(page.getByRole('heading', { name: 'Không có quyền truy cập' })).toBeVisible();
});

test('profile page loads for a logged-in student', async ({ page }) => {
  await login(page, 'student@brightfuture.local');
  await page.goto('profile.php');
  await expect(page).toHaveURL(/profile\.php$/);
  await expect(page.getByRole('heading', { name: 'Hồ sơ của tôi' })).toBeVisible();
});

test('admin secondary pages render', async ({ page }) => {
  await login(page, 'admin@brightfuture.local');
  for (const path of [
    'admin/users.php',
    'admin/courses.php',
    'admin/reports.php',
    'admin/audit.php',
  ]) {
    await page.goto(path);
    await expect(page).toHaveURL(new RegExp(path.replace('.', '\\.') + '$'));
  }
});

test('teacher secondary pages render', async ({ page }) => {
  await login(page, 'teacher@brightfuture.local');
  for (const path of [
    'teacher/courses.php',
    'teacher/lessons.php',
    'teacher/exercises.php',
    'teacher/submissions.php',
  ]) {
    await page.goto(path);
    await expect(page).toHaveURL(new RegExp(path.replace('.', '\\.') + '$'));
  }
});

test('student secondary pages render', async ({ page }) => {
  await login(page, 'student@brightfuture.local');
  for (const path of [
    'student/my-courses.php',
    'student/progress.php',
    'student/exercises.php',
  ]) {
    await page.goto(path);
    await expect(page).toHaveURL(new RegExp(path.replace('.', '\\.') + '$'));
  }
});

test('public course and community pages render', async ({ page }) => {
  await page.goto('courses.php');
  await expect(page).toHaveURL(/courses\.php$/);
  await expect(page.getByRole('heading', { name: 'Khám phá khóa học' })).toBeVisible();

  await page.goto('course.php?id=4');
  await expect(page).toHaveURL(/course\.php\?id=4$/);
  await expect(page.locator('h1').first()).toBeVisible();
  await expect(page.locator('#reviews')).toBeVisible();

  await login(page, 'student@brightfuture.local');
  await page.goto('social.php');
  await expect(page).toHaveURL(/social\.php$/);
  await expect(page.getByRole('heading', { name: 'Cộng đồng BrightFuture' })).toBeVisible();

  await page.goto('rooms.php');
  await expect(page).toHaveURL(/rooms\.php$/);
  await expect(page.locator('h1').filter({ hasText: 'Phòng gọi' }).first()).toBeVisible();
});

test('notifications, submissions and certificate routes render', async ({ page }) => {
  await login(page, 'student@brightfuture.local');
  await page.goto('student/notifications.php');
  await expect(page).toHaveURL(/student\/notifications\.php$/);

  await page.goto('certificate.php');
  await expect(page).toHaveURL(/certificate\.php$/);

  await page.context().clearCookies();
  await login(page, 'teacher@brightfuture.local');
  await page.goto('teacher/notifications.php');
  await expect(page).toHaveURL(/teacher\/notifications\.php$/);

  await page.goto('teacher/submissions.php');
  await expect(page).toHaveURL(/teacher\/submissions\.php$/);
});

test('core public and dashboard pages avoid horizontal overflow on mobile', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 800 });
  await page.goto('courses.php');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();

  await page.goto('course.php?id=4');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();

  await login(page, 'student@brightfuture.local');
  await page.goto('student/dashboard.php');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
});

test('login and public pages have basic accessible names', async ({ page }) => {
  await page.goto('login.php');
  await expect(page.getByRole('textbox', { name: 'Địa chỉ email' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Gửi lại email' })).toHaveAttribute(
    'href',
    /resend-verification\.php$/
  );
  await expect(page.getByRole('button', { name: 'Đăng nhập' })).toBeVisible();

  await page.goto('courses.php');
  await expect(page.getByRole('searchbox', { name: 'Tìm khóa học' })).toBeVisible();

  for (const viewport of [
    { width: 768, height: 900 },
    { width: 1440, height: 900 },
  ]) {
    await page.setViewportSize(viewport);
    await page.goto('courses.php');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
  }
});

test('verification resend does not reveal whether an email is registered', async ({ page }) => {
  await page.goto('resend-verification.php');
  await expect(page.getByRole('heading', { name: 'Gửi lại email xác minh' })).toBeVisible();
  await page.getByLabel('Địa chỉ email').fill('not-registered@brightfuture.local');
  await page.getByRole('button', { name: 'Gửi email xác minh' }).click();
  await expect(page.getByRole('status')).toHaveText(genericVerificationMessage);
});

test('verification resend uses the same response for verified accounts', async ({ page }) => {
  await page.goto('resend-verification.php');
  await page.getByLabel('Địa chỉ email').fill('student@brightfuture.local');
  await page.getByRole('button', { name: 'Gửi email xác minh' }).click();
  await expect(page.getByRole('status')).toHaveText(genericVerificationMessage);
});

test('student can complete a text lesson without video playback', async ({ page }) => {
  await login(page, 'student@brightfuture.local');
  await page.goto('courses.php');

  const courseList = await page.evaluate(async () => {
    const response = await fetch(
      'api/courses.php?q=BrightFuture%20Development%20Completion%20Test%20Course',
      { credentials: 'same-origin' }
    );
    return response.json();
  });
  const course = courseList.data.find(
    (item) => item.title === 'BrightFuture Development Completion Test Course'
  );
  expect(course).toBeTruthy();

  const courseDetail = await page.evaluate(async (courseId) => {
    const response = await fetch(`api/courses.php?id=${courseId}`, {
      credentials: 'same-origin',
    });
    return response.json();
  }, course.id);
  const lesson = courseDetail.data.chapters
    .flatMap((chapter) => chapter.lessons)
    .find((item) => item.title === 'Development Text Lesson Completion Test');
  expect(lesson).toBeTruthy();

  await page.goto(`student/learn.php?id=${lesson.id}`);
  const csrfToken = await page.locator('input[name="csrf_token"]').first().inputValue();
  const videoPositionResponse = await page.evaluate(async ({ lessonId, csrf }) => {
    const response = await fetch('../api/progress.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf,
      },
      body: JSON.stringify({
        action: 'position',
        lesson_id: lessonId,
        position_seconds: 10,
      }),
    });
    return response.status;
  }, { lessonId: lesson.id, csrf: csrfToken });
  expect(videoPositionResponse).toBe(422);

  const completedLabel = page.locator('.completed-label');
  if (await completedLabel.count() === 0) {
    await page.getByRole('button', { name: 'Đánh dấu đã học xong' }).click();
  }
  await expect(completedLabel).toHaveText('Đã hoàn thành bài học.');
});

test('landing page renders public hero and course cards for guests', async ({ page }) => {
  await page.goto('index.php');
  await expect(page).toHaveURL(/index\.php$/);
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Khám phá khóa học' }).first()).toBeVisible();
});

test('public certificate verification page handles lookup', async ({ page }) => {
  await page.goto('certificate.php?verify=00000000000000000000000000000000');
  await expect(page).toHaveURL(/certificate\.php\?verify=/);
  await expect(page.getByRole('heading', { name: /Không tìm thấy chứng chỉ|Chứng chỉ hợp lệ/ })).toBeVisible();
});
