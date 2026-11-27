<?php
declare(strict_types=1);

// Black-box checks for GET /api/attention and the comment flow it relies on.
// Same harness as smoke.php: disposable SQLite and server, seeded "now" so relative
// deadlines in the demo data land at known distances from the real clock.
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "This PHP interpreter needs pdo_sqlite. Use the Compose test command from README.\n");
    exit(1);
}
$directory = sys_get_temp_dir() . '/project-desk-attention-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$socket) {
    throw new RuntimeException($errstr);
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$base = 'http://' . $address;
$environment = getenv();
$environment['DB_PATH'] = $directory . '/test.sqlite';
$environment['SEED_NOW'] = gmdate('Y-m-d\TH:i:s\Z');
$process = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/../router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']],
    $pipes, dirname(__DIR__, 2), $environment);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start test server');
}
fclose($pipes[0]);
$count = 0;
$exitStatus = 0;

function request(string $method, string $path, mixed $body = null, int $user = 1): array
{
    global $base;
    $options = ['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 5,
        'header' => "Content-Type: application/json\r\nX-Demo-User: $user\r\n",
    ]];
    if ($body !== null) {
        $options['http']['content'] = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);
    }
    $raw = file_get_contents($base . $path, false, stream_context_create($options));
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    return [(int) $match[1], json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
}

function check(bool $condition, string $message): void
{
    global $count;
    ++$count;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

/** @return array<int, string[]> task id => matched rule codes */
function attention(int $user = 1): array
{
    [$status, $response] = request('GET', '/api/attention', null, $user);
    if ($status !== 200) {
        throw new RuntimeException("FAIL: attention returned $status");
    }
    $out = [];
    foreach ($response['data']['items'] as $item) {
        $out[$item['task']['id']] = array_column($item['reasons'], 'code');
    }
    return $out;
}

try {
    for ($attempt = 0; $attempt < 50 && @file_get_contents($base . '/api/health') === false; ++$attempt) {
        usleep(100000);
    }
    check($attempt < 50, 'server starts with fresh SQLite');

    [$status, $response] = request('GET', '/api/attention');
    $data = $response['data'];
    check($status === 200 && array_column($data['projects'], 'id') === [1, 2, 3], 'Anna: only active projects she leads');
    check(array_column($data['rules'], 'code') === ['overdue', 'blocked', 'mention', 'due_soon', 'unassigned', 'stale'], 'rules are published with the data');
    $items = attention();
    check(array_keys($items) === [1, 5, 7, 2], 'Anna: expected tasks in priority order');
    check($items[1] === ['blocked', 'mention', 'due_soon'], '#1 blocked, asks the manager, due tomorrow');
    check($items[5] === ['overdue', 'blocked'], '#5 blocked and overdue');
    check($items[7] === ['mention', 'unassigned'], '#7 unassigned task with a direct request (hidden from old task list)');
    check($items[2] === ['overdue'], '#2 overdue');
    check(!isset($items[10]) && !isset($items[11]), 'archive and foreign projects excluded');
    $item = array_values(array_filter($data['items'], fn($i) => $i['task']['id'] === 7))[0];
    check(str_contains($item['reasons'][0]['detail'], 'Анна, помоги') && $item['last_comment']['author'] === 'Борис · разработчик', 'reason quotes the comment that triggered it');

    check(attention(2) === [], 'Boris leads nothing: empty list');
    [, $response] = request('GET', '/api/attention', null, 2);
    check($response['data']['projects'] === [], 'Boris: no projects disclosed');
    check(array_keys(attention(3)) === [11], 'Vera sees her own project only');
    check(request('GET', '/api/attention', null, 999)[0] === 401, 'unknown persona rejected');

    // Acting from the card resolves the rules that were acted upon.
    [$status, $response] = request('POST', '/api/tasks/7/comments', ['body' => 'Владельцем будет Борис, договорилась с командами.']);
    check($status === 201 && $response['data']['author'] === 'Анна · руководитель' && $response['data']['task_id'] === 7, 'manager reply saved with author');
    check(attention()[7] === ['unassigned'], 'reply removes "waiting for your answer"');
    request('POST', '/api/tasks/7/comments', ['body' => 'Анна, спасибо!'], 2);
    check(attention()[7] === ['mention', 'unassigned'], 'new request after the reply shows again');
    request('POST', '/api/tasks/7/comments', ['body' => 'Принято.']);
    [, $task] = request('GET', '/api/tasks/7');
    check(count($task['data']['comments']) === 4, 'comments persist in task detail');
    [$status] = request('PATCH', '/api/tasks/7', ['assignee_id' => 2, 'version' => $task['data']['version']]);
    check($status === 200 && !isset(attention()[7]), 'assigning removes the task from the list');
    [, $task] = request('GET', '/api/tasks/5');
    request('PATCH', '/api/tasks/5', ['status' => 'in_progress', 'due_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 7 * 86400), 'version' => $task['data']['version']]);
    check(!isset(attention()[5]), 'unblocked task with new deadline leaves the list');
    [, $task] = request('GET', '/api/tasks/6');
    request('PATCH', '/api/tasks/6', ['due_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600), 'version' => $task['data']['version']]);
    check(attention()[6] === ['due_soon'], 'deadline within 48 hours appears');
    [, $task] = request('GET', '/api/tasks/2');
    request('PATCH', '/api/tasks/2', ['status' => 'done', 'version' => $task['data']['version']]);
    check(!isset(attention()[2]), 'done tasks are never listed');

    // Comment validation and access.
    check(request('POST', '/api/tasks/1/comments', ['body' => str_repeat('я', 2501)])[0] === 422, 'comment over 5000 bytes rejected');
    check(request('POST', '/api/tasks/1/comments', ['body' => 'x', 'user_id' => 2])[0] === 422, 'comment author cannot be spoofed via body');
    check(request('POST', '/api/tasks/11/comments', ['body' => 'x'])[0] === 404, 'foreign task cannot receive comments');
    check(request('POST', '/api/tasks/10/comments', ['body' => 'x'])[0] === 409, 'archived task cannot receive comments');
    [$status, $response] = request('POST', '/api/tasks/1/comments', ['body' => "  Две строки\nтекста  "], 3);
    check($status === 201 && $response['data']['body'] === "Две строки\nтекста" && $response['data']['user_id'] === 3, 'comment trimmed, line breaks kept');

    // Unassigned tasks stay in the regular project list (was dropped by INNER JOIN).
    [, $response] = request('GET', '/api/projects/2/tasks');
    check(array_column($response['data'], 'id') === [5, 6, 7], 'project list includes every task');
    [, $response] = request('POST', '/api/projects/2/tasks', ['title' => 'Без исполнителя']);
    [, $list] = request('GET', '/api/projects/2/tasks');
    [, $projects] = request('GET', '/api/projects');
    check(count($list['data']) === 4 && $projects['data'][1]['task_count'] === 4, 'task count matches list');
    check(in_array('unassigned', attention()[$response['data']['id']], true), 'new unassigned task appears in attention');
    echo "PASS: $count attention checks\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL . file_get_contents($directory . '/server.log'));
    $exitStatus = 1;
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}

exit($exitStatus);
