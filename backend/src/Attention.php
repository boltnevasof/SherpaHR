<?php
declare(strict_types=1);

/**
 * "Требует внимания": fixed, explainable rules over the manager's active projects.
 * No scoring model or AI: every item lists the rules it matched and their facts.
 */
final class Attention
{
    public const DUE_SOON_HOURS = 48;
    public const STALE_DAYS = 3;

    /** Rule order is also display order; "high" rules are shown first. */
    public const RULES = [
        'overdue' => ['label' => 'Срок прошёл', 'severity' => 'high',
            'description' => 'Срок задачи уже наступил, а задача не в статусе «Готово».'],
        'blocked' => ['label' => 'Заблокирована', 'severity' => 'high',
            'description' => 'Статус «Заблокирована». Причину команда пишет в описании или комментариях — показываем последний комментарий.'],
        'mention' => ['label' => 'Ждут вашего ответа', 'severity' => 'high',
            'description' => 'После вашего последнего комментария участник обратился к вам по имени или к руководителю («руководитель», «руководителя»…). Правило снимается, когда вы ответите в задаче.'],
        'due_soon' => ['label' => 'Скоро срок', 'severity' => 'medium',
            'description' => 'До срока осталось не больше ' . self::DUE_SOON_HOURS . ' часов, задача не в статусе «Готово».'],
        'unassigned' => ['label' => 'Нет исполнителя', 'severity' => 'medium',
            'description' => 'У незавершённой задачи не назначен исполнитель.'],
        'stale' => ['label' => 'Нет движения', 'severity' => 'medium',
            'description' => 'Задача «Открыта» или «В работе», и ни изменений, ни комментариев не было ' . self::STALE_DAYS . ' дня и больше.'],
    ];

    public function __construct(private PDO $db) {}

    public function forManager(int $userId, DateTimeImmutable $now): array
    {
        $manager = $this->first('SELECT * FROM users WHERE id=?', [$userId]);
        // Projects the user leads and can still change; archive is history, not work.
        $projects = $this->rows('SELECT p.id, p.name FROM projects p JOIN project_members m ON m.project_id=p.id AND m.user_id=p.owner_id
            WHERE p.owner_id=? AND p.archived=0 ORDER BY p.id', [$userId]);
        $items = [];
        $ids = array_column($projects, 'id');
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $tasks = $this->rows("SELECT t.*, p.name AS project_name, u.name AS assignee_name FROM tasks t
                JOIN projects p ON p.id=t.project_id LEFT JOIN users u ON u.id=t.assignee_id
                WHERE t.project_id IN ($marks) AND t.status<>'done' ORDER BY t.id", $ids);
            foreach ($tasks as $task) {
                $comments = $this->rows('SELECT c.*, u.name AS author FROM comments c JOIN users u ON u.id=c.user_id WHERE c.task_id=? ORDER BY c.id', [$task['id']]);
                $reasons = $this->reasons($task, $comments, $manager, $now);
                if (!$reasons) {
                    continue;
                }
                $last = $comments ? end($comments) : null;
                $items[] = [
                    'task' => array_diff_key($task, ['project_name' => 1, 'assignee_name' => 1]),
                    'project_name' => $task['project_name'],
                    'assignee_name' => $task['assignee_name'],
                    'reasons' => $reasons,
                    'comment_count' => count($comments),
                    'last_comment' => $last ? ['author' => $last['author'], 'body' => $last['body'], 'created_at' => $last['created_at']] : null,
                    'last_activity_at' => $this->lastActivity($task, $comments)->format('Y-m-d\TH:i:s\Z'),
                ];
            }
        }
        usort($items, fn(array $a, array $b) => [$this->high($b), count($b['reasons']), $a['task']['due_at'] ?? '9999', $a['task']['id']]
            <=> [$this->high($a), count($a['reasons']), $b['task']['due_at'] ?? '9999', $b['task']['id']]);
        $rules = [];
        foreach (self::RULES as $code => $rule) {
            $rules[] = ['code' => $code] + $rule;
        }
        return [
            'generated_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'projects' => $projects,
            'rules' => $rules,
            'items' => $items,
        ];
    }

    private function reasons(array $task, array $comments, array $manager, DateTimeImmutable $now): array
    {
        $out = [];
        $due = $task['due_at'] ? $this->time($task['due_at']) : null;
        if ($due && $due <= $now) {
            $out[] = $this->reason('overdue', 'Срок был ' . $this->date($due) . ' — прошло ' . $this->span($now, $due));
        }
        if ($task['status'] === 'blocked') {
            $out[] = $this->reason('blocked', 'Последнее изменение задачи ' . $this->span($now, $this->time($task['updated_at'])) . ' назад');
        }
        $mention = $this->unansweredMention($comments, $manager);
        if ($mention) {
            $out[] = $this->reason('mention', $mention['author'] . ', ' . $this->date($this->time($mention['created_at'])) . ': «' . $this->excerpt($mention['body']) . '»');
        }
        if ($due && $due > $now && $due <= $now->modify('+' . self::DUE_SOON_HOURS . ' hours')) {
            $out[] = $this->reason('due_soon', 'Срок ' . $this->date($due) . ' — через ' . $this->span($due, $now));
        }
        if ($task['assignee_id'] === null) {
            $out[] = $this->reason('unassigned', 'Исполнитель не назначен');
        }
        $activity = $this->lastActivity($task, $comments);
        if (in_array($task['status'], ['open', 'in_progress'], true) && $activity <= $now->modify('-' . self::STALE_DAYS . ' days')) {
            $out[] = $this->reason('stale', 'Последняя активность ' . $this->span($now, $activity) . ' назад');
        }
        return $out;
    }

    /** First comment by someone else after the manager's own last comment that addresses the manager. */
    private function unansweredMention(array $comments, array $manager): ?array
    {
        $firstName = trim(explode('·', $manager['name'])[0]);
        // "Анна" → stem "Анн" to match "Анне", "Анну"; a name without vowel ending is used as is.
        $stem = preg_replace('/[аяеоыиуюь]$/u', '', mb_strtolower($firstName));
        $pattern = '/(?<!\p{L})(' . preg_quote($stem, '/') . '\p{L}{0,3}|руководител\p{L}*)(?!\p{L})/iu';
        $found = null;
        foreach ($comments as $comment) {
            if ((int) $comment['user_id'] === (int) $manager['id']) {
                $found = null;
            } elseif ($found === null && $stem !== '' && preg_match($pattern, $comment['body'])) {
                $found = $comment;
            }
        }
        return $found;
    }

    private function lastActivity(array $task, array $comments): DateTimeImmutable
    {
        $times = [$task['updated_at'], ...array_column($comments, 'created_at')];
        return $this->time(max($times));
    }

    private function high(array $item): int
    {
        return count(array_filter($item['reasons'], fn(array $r) => $r['severity'] === 'high'));
    }

    private function reason(string $code, string $detail): array
    {
        return ['code' => $code, 'label' => self::RULES[$code]['label'], 'severity' => self::RULES[$code]['severity'], 'detail' => $detail];
    }

    private function span(DateTimeImmutable $later, DateTimeImmutable $earlier): string
    {
        $hours = intdiv(max(0, $later->getTimestamp() - $earlier->getTimestamp()), 3600);
        if ($hours < 24) {
            return $hours < 1 ? 'меньше часа' : $hours . ' ' . $this->plural($hours, 'час', 'часа', 'часов');
        }
        $days = intdiv($hours, 24);
        return $days . ' ' . $this->plural($days, 'день', 'дня', 'дней');
    }

    private function plural(int $n, string $one, string $few, string $many): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }
        return $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) ? $few : $many;
    }

    private function excerpt(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text);
        return mb_strlen($text) > 140 ? rtrim(mb_substr($text, 0, 139)) . '…' : $text;
    }

    private function date(DateTimeImmutable $time): string
    {
        return $time->format('d.m.Y H:i') . ' UTC';
    }

    private function time(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function first(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: throw new ApiError('Неизвестный демо-пользователь', 401);
    }

    private function rows(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
