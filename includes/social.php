<?php
require_once __DIR__ . '/permissions.php';
function bf_pair(int $a,int $b): array {return [min($a,$b),max($a,$b)];}
function bf_social_blocked(PDO $db,int $a,int $b): bool {
 $s=$db->prepare('SELECT 1 FROM bf_blocks WHERE (blocker_id=? AND blocked_id=?) OR (blocker_id=? AND blocked_id=?) LIMIT 1');
 $s->execute([$a,$b,$b,$a]);return (bool)$s->fetchColumn();
}
function bf_social_friend(PDO $db,int $a,int $b): bool {
 if($a===$b || $a<1 || $b<1 || bf_social_blocked($db,$a,$b)) return false;
 [$lo,$hi]=bf_pair($a,$b);
 $s=$db->prepare('SELECT 1 FROM bf_friendships WHERE user_low=? AND user_high=? AND status=?');
 $s->execute([$lo,$hi,'accepted']);return (bool)$s->fetchColumn();
}
function bf_social_group_member(PDO $db,int $group,int $uid): bool {
 $s=$db->prepare('SELECT 1 FROM bf_chat_members WHERE group_id=? AND user_id=?');
 $s->execute([$group,$uid]);return (bool)$s->fetchColumn();
}
function bf_social_len(string $s):int {return function_exists('mb_strlen')?mb_strlen($s,'UTF-8'):strlen($s);}
function bf_social_role(string $role):string {return ['student'=>'Học viên','teacher'=>'Giáo viên','admin'=>'Quản trị viên'][$role]??$role;}
