<?php

function bf_lesson_media_source(array $lesson): array
{
    $url = trim((string) ($lesson['video_url'] ?? ''));
    if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
        $parts = parse_url($url);
        if (is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https') {
            $host = strtolower((string) ($parts['host'] ?? ''));
            $path = (string) ($parts['path'] ?? '');
            $videoId = null;

            if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
                if ($path === '/watch') {
                    parse_str((string) ($parts['query'] ?? ''), $query);
                    $videoId = $query['v'] ?? null;
                } elseif (preg_match('~^/(embed|shorts)/([A-Za-z0-9_-]{11})$~', $path, $matches)) {
                    $videoId = $matches[2];
                }
            } elseif ($host === 'youtu.be') {
                $videoId = ltrim($path, '/');
            }

            if (is_string($videoId) && preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId)) {
                return [
                    'type' => 'youtube',
                    'url' => 'https://www.youtube-nocookie.com/embed/' . $videoId,
                ];
            }
            if (preg_match('~\.mp4$~i', $path)) {
                return ['type' => 'mp4', 'url' => $url];
            }
        }
    }

    $lessonId = (int) ($lesson['id'] ?? 0);
    if (
        ($lesson['video_source'] ?? '') === 'upload'
        && !empty($lesson['video_file_path'])
        && $lessonId > 0
    ) {
        return [
            'type' => 'mp4',
            'url' => app_url('api/video.php?lesson_id=' . $lessonId),
        ];
    }

    return ['type' => 'none', 'url' => ''];
}

function bf_lesson_has_video(array $lesson): bool
{
    return bf_lesson_media_source($lesson)['type'] !== 'none';
}

function bf_lesson_video_completion_allowed(array $lesson, int $savedSeconds): bool
{
    if (!bf_lesson_has_video($lesson)) {
        return true;
    }
    if ($savedSeconds < 1) {
        return false;
    }

    $duration = (int) ($lesson['video_duration_seconds'] ?? 0);
    if ($duration <= 0) {
        $duration = (int) ($lesson['duration_minutes'] ?? 0) * 60;
    }

    return $duration <= 0 || $savedSeconds >= (int) ceil($duration * 0.9);
}
