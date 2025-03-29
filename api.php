<?php
/**
 * SS-DASH Configuration
 */
date_default_timezone_set('Europe/Bucharest'); // Set your timezone
define('LOG_FILE', './misu2');
define('DEFAULT_LOG_LINES', 30);
define('TAIL_BUFFER', 8192);
define('DTMF_CONTROL_PTY', '/dev/shm/svxlink_dtmf_ctrl');
define('PTT_CONTROL_PTY', '/dev/shm/svxlink_ptt_ctrl');
define('DATE_FORMATS', serialize(['Y-m-d H:i:s', 'd.m.Y H:i:s', 'Y/m/d H:i:s']));

/**
 * Parse the request method and endpoint from query parameters.
 * e.g. GET /api.php?endpoint=log&lines=30
 */
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$endpoint = $_GET['endpoint'] ?? $_POST['endpoint'] ?? '';

switch ($requestMethod) {
    case 'GET':
        if ($endpoint === 'log') {
            handleGetLog();
            exit;
        }
        // If no match, return 404
        sendJsonError('Not Found', 404);
        exit;

    case 'POST':
        // Decide which endpoint is being used
        switch ($endpoint) {
            case 'dtmf':
                handleDtmfControl();
                exit;
            case 'ptt':
                handlePttControl();
                exit;
            case 'restart':
                handleServiceAction('restart');
                exit;
            case 'stop':
                handleServiceAction('stop');
                exit;
            case 'shutdown':
                handleServiceAction('shutdown');
                exit;
            case 'reboot':
                handleServiceAction('reboot');
                exit;
            default:
                sendJsonError('Not Found', 404);
                exit;
        }

    default:
        sendJsonError('Not Found', 404);
        exit;
}

/**
 * ==============================
 *          HANDLERS
 * ==============================
 */

/**
 * GET /api.php?endpoint=log&lines=XX
 * Returns log lines in JSON.
 */
function handleGetLog(): void
{
    $linesParam = $_GET['lines'] ?? DEFAULT_LOG_LINES;
    $lines = is_numeric($linesParam) ? (int) $linesParam : DEFAULT_LOG_LINES;

    $logContent = tailCustom(LOG_FILE, $lines, true);
    if ($logContent === false) {
        sendJsonError('Error reading log file', 500);
        return;
    }

    $linesArray = explode("\n", $logContent);
    $parsedData = parseLogLines($linesArray);

    sendJsonSuccess([
        'logEntries' => $parsedData
    ]);
}

/**
 * POST /api.php?endpoint=dtmf
 * Expects JSON: { "dtmf": "..." }
 */
function handleDtmfControl(): void
{
    $payload = parseJsonFromBody();
    if (!isset($payload['dtmf'])) {
        sendJsonError('Missing "dtmf" in request body', 400);
        return;
    }

    try {
        $result = @file_put_contents(DTMF_CONTROL_PTY, $payload['dtmf']);
        if ($result === false) {
            throw new \Exception('Failed to write DTMF: ' . lastPhpError());
        }
        sendJsonSuccess(['message' => 'DTMF command written successfully']);
    } catch (\Exception $e) {
        sendJsonError($e->getMessage(), 400);
    }
}

/**
 * POST /api.php?endpoint=ptt
 * Expects JSON: { "ptt": "0 or 1" }
 */
function handlePttControl(): void
{
    $payload = parseJsonFromBody();
    if (!isset($payload['ptt'])) {
        sendJsonError('Missing "ptt" in request body', 400);
        return;
    }

    try {
        $result = @file_put_contents(PTT_CONTROL_PTY, $payload['ptt']);
        if ($result === false) {
            throw new \Exception('Failed to write PTT: ' . lastPhpError());
        }
        sendJsonSuccess(['message' => 'PTT command written successfully']);
    } catch (\Exception $e) {
        sendJsonError($e->getMessage(), 400);
    }
}

/**
 * POST /api.php?endpoint=restart|stop|shutdown|reboot
 */
function handleServiceAction(string $action): void
{
    $validActions = ['restart', 'stop', 'shutdown', 'reboot'];
    if (!in_array($action, $validActions, true)) {
        sendJsonError('Unsupported system action', 400);
        return;
    }

    $commandMap = [
        'restart'  => 'sudo systemctl restart svxlink 2>&1',
        'stop'     => 'sudo systemctl stop svxlink 2>&1',
        'shutdown' => 'sudo shutdown -h now 2>&1',
        'reboot'   => 'sudo reboot 2>&1',
    ];

    try {
        $output = shell_exec($commandMap[$action]);
        if ($output === null) {
            throw new \Exception("Failed to {$action}: " . lastPhpError());
        }
        sendJsonSuccess(["message" => "System action '{$action}' executed"]);
    } catch (\Exception $e) {
        sendJsonError($e->getMessage(), 400);
    }
}

/**
 * ==============================
 *      HELPER FUNCTIONS
 * ==============================
 */

/**
 * Return the last PHP error, or "Unknown error" if none found.
 */
function lastPhpError(): string
{
    $error = error_get_last();
    return $error ? $error['message'] : 'Unknown error';
}

/**
 * Safely parse a JSON body from the request.
 */
function parseJsonFromBody(): array
{
    $input = file_get_contents('php://input');
    if (!$input) {
        return [];
    }
    $decoded = json_decode($input, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Tail the end of a file to retrieve a specified number of lines.
 */
function tailCustom(string $filepath, int $lines = 1, bool $adaptive = true): string|false
{
    $f = fopen($filepath, 'rb');
    if ($f === false) {
        return false;
    }

    // Adaptive buffer size
    $buffer = $adaptive
        ? ($lines < 2 ? 64 : ($lines < 10 ? 512 : 4096))
        : 4096;

    fseek($f, -1, SEEK_END);
    // Check if the last character is a newline; if not, we reduce $lines by 1
    if (fread($f, 1) !== "\n") {
        $lines--;
    }

    $output = '';
    while (ftell($f) > 0 && $lines >= 0) {
        $seek = min(ftell($f), $buffer);
        fseek($f, -$seek, SEEK_CUR);
        $chunk   = fread($f, $seek);
        $output  = $chunk . $output;
        fseek($f, -strlen($chunk), SEEK_CUR);
        $lines  -= substr_count($chunk, "\n");
    }

    while ($lines++ < 0) {
        // Remove the first line from output
        $output = substr($output, strpos($output, "\n") + 1);
    }

    fclose($f);
    return trim($output);
}

/**
 * Parse array of log lines to detect talker start/stop durations and active talkers.
 */
function parseLogLines(array $linesArray): array
{
    $formats = unserialize(DATE_FORMATS);
    if (empty($linesArray)) {
        return [];
    }

    $talkers       = []; // key => 'tg|callsign', value => ['startIndex', 'startTime']
    $durations     = []; // durations[ lineIndexOfStop ] = number of seconds
    $activeIndexes = [];

    $now = new DateTime();

    // Process in ascending order
    foreach ($linesArray as $idx => $line) {
        if (strlen($line) < 21) {
            continue;
        }
        $timestampStr = substr($line, 0, 19);
        $message      = substr($line, 21);
        $timeObj      = parseTimestamp($timestampStr, $formats);

        if (!$timeObj) {
            continue;
        }

        // Talker start
        if (preg_match('/ReflectorLogic: Talker start on TG #(\d+): (.+)$/', $message, $matches)) {
            $tg       = $matches[1];
            $callsign = $matches[2];
            $key      = "$tg|$callsign";

            $talkers[$key] = [
                'startIndex' => $idx,
                'startTime'  => $timeObj->getTimestamp()
            ];
        }
        // Talker stop
        elseif (preg_match('/ReflectorLogic: Talker stop on TG #(\d+): (.+)$/', $message, $matches)) {
            $tg       = $matches[1];
            $callsign = $matches[2];
            $key      = "$tg|$callsign";

            if (isset($talkers[$key])) {
                $startData  = $talkers[$key];
                $stopTime   = $timeObj->getTimestamp();
                $duration   = $stopTime - $startData['startTime'];
                $stopIndex  = $idx;
                $durations[$stopIndex] = $duration;
                unset($talkers[$key]);
            }
        }
    }

    // Remaining talkers are still active
    foreach ($talkers as $key => $info) {
        $durationSoFar = $now->getTimestamp() - $info['startTime'];
        $activeIndexes[$info['startIndex']] = $durationSoFar;
    }

    // Build final array in ascending order
    $result = [];
    foreach ($linesArray as $i => $line) {
        if (strlen($line) < 21) {
            continue;
        }
        $timestampStr = substr($line, 0, 19);
        $message      = substr($line, 21);
        $timeObj      = parseTimestamp($timestampStr, $formats);
        if (!$timeObj) {
            continue;
        }

        $durationSec = $durations[$i] ?? null;
        $isActive    = array_key_exists($i, $activeIndexes);

        if ($isActive) {
            $durationSec = $activeIndexes[$i];
        }

        $result[] = [
            'timestamp' => $timeObj->format('Y-m-d H:i:s'),
            'message'   => $message,
            'duration'  => $durationSec ? formatDuration($durationSec) : null,
            'active'    => $isActive,
        ];
    }

    return $result;
}

/**
 * Attempt to parse a timestamp using the predefined date formats.
 */
function parseTimestamp(string $timestamp, array $formats): DateTime|false
{
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $timestamp);
        if ($date !== false) {
            return $date;
        }
    }
    return false;
}

/**
 * Format a duration in seconds as "XXm YYs" or "ZZs".
 */
function formatDuration(int $seconds): string
{
    if ($seconds >= 60) {
        $minutes   = floor($seconds / 60);
        $remaining = $seconds % 60;
        return sprintf("%dm %02ds", $minutes, $remaining);
    }
    return sprintf("%ds", $seconds);
}

/**
 * Send a success JSON response with HTTP 200 by default.
 */
function sendJsonSuccess(array $data, int $statusCode = 200): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => true], $data));
}

/**
 * Send an error JSON response with a given status code.
 */
function sendJsonError(string $errorMessage, int $statusCode = 400): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'error'   => $errorMessage
    ]);
}