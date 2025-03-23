<?php
/*
 * SS-DASH Configuration
 */
$LOG_FILE = './misu2';  // Path to SvxLink log file
$LINES = 30;                      // Number of lines to display
$REFRESH = 2;                       // Refresh interval in seconds
$TAIL_BUFFER = 8192;                    // Increased buffer for better tailing

function tailCustom($filepath, $lines = 1, $adaptive = true)
{
    $f = @fopen($filepath, "rb");
    if ($f === false) return false;

    $buffer = $adaptive ? ($lines < 2 ? 64 : ($lines < 10 ? 512 : 4096)) : 4096;
    fseek($f, -1, SEEK_END);

    if (fread($f, 1) != "\n") $lines -= 1;

    $output = '';
    $chunk = '';

    while (ftell($f) > 0 && $lines >= 0) {
        $seek = min(ftell($f), $buffer);
        fseek($f, -$seek, SEEK_CUR);
        $output = ($chunk = fread($f, $seek)) . $output;
        fseek($f, -mb_strlen($chunk, '8bit'), SEEK_CUR);
        $lines -= substr_count($chunk, "\n");
    }

    while ($lines++ < 0) {
        $output = substr($output, strpos($output, "\n") + 1);
    }

    fclose($f);
    return trim($output);
}

function formatDuration($seconds)
{
    if ($seconds >= 60) {
        $minutes = floor($seconds / 60);
        $remaining = $seconds % 60;
        return sprintf("%dm %02ds", $minutes, $remaining);
    }
    return sprintf("%ds", $seconds);
}

function tgColor($tg)
{
    $hue = crc32($tg) % 360;
    return "hsl($hue,70%,50%)";
}

// Process log data
$logContent = tailCustom($LOG_FILE, $LINES, true);
$linesArray = explode("\n", $logContent);
$durations = [];
$activeStarts = [];
$completedStarts = [];

foreach (array_reverse($linesArray) as $i => $line) {
    $originalIndex = count($linesArray) - 1 - $i;

    if (strlen($line) < 21) continue;

    $message = substr($line, 21);
    $timestamp = substr($line, 0, 19);

    if (preg_match('/ReflectorLogic: Talker stop on TG #(\d+): (.+)$/', $message, $matches)) {
        // Look forward in time (backward in array) for matching start
        for ($j = $originalIndex - 1; $j >= 0; $j--) {
            $prevMessage = substr($linesArray[$j], 21);
            if (preg_match('/ReflectorLogic: Talker start on TG #(\d+): (.+)$/', $prevMessage, $startMatches)) {
                if ($startMatches[1] == $matches[1] && $startMatches[2] == $matches[2]) {
                    $startTime = DateTime::createFromFormat('d.m.Y H:i:s', substr($linesArray[$j], 0, 19));
                    $stopTime = DateTime::createFromFormat('d.m.Y H:i:s', $timestamp);
                    if ($startTime && $stopTime) {
                        $duration = $stopTime->getTimestamp() - $startTime->getTimestamp();
                        $durations[$originalIndex] = formatDuration($duration);
                        $completedStarts[$j] = true;
                    }
                    break;
                }
            }
        }
    }
}

// Identify active transmissions (starts without stops)
foreach ($linesArray as $j => $line) {
    if (isset($completedStarts[$j])) continue;
    if (preg_match('/ReflectorLogic: Talker start on TG #(\d+): (.+)$/', substr($line, 21))) {
        $activeStarts[$j] = true;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>SS-DASH - SvxLink Monitor</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta http-equiv="refresh" content="<?= $REFRESH ?>">
    <style>
        .blink {
            animation: blink 1.5s ease-in-out infinite;
        }

        @keyframes blink {
            50% {
                opacity: 0.4;
            }
        }

        .log-container {
            height: 90vh;
        }
    </style>
</head>
<body class="bg-gray-900 h-screen overflow-hidden">
<div class="container mx-auto p-4 h-full flex flex-col">
    <h1 class="text-2xl text-green-400 mb-4 font-mono border-b border-green-400 pb-2">
        SS-DASH :: Talker Activity Monitor
    </h1>

    <div class="log-container bg-gray-800 rounded-lg border border-green-400 overflow-y-auto">
        <?php foreach (array_reverse($linesArray, true) as $j => $line):
            if (strlen($line) < 21) continue;

            $message = substr($line, 21);
            $isTalkerStart = strpos($message, 'ReflectorLogic: Talker start') === 0;
            $isTalkerStop = strpos($message, 'ReflectorLogic: Talker stop') === 0;
            $hasDuration = isset($durations[$j]);
            $isActive = isset($activeStarts[$j]);

            $formattedLine = preg_replace_callback(
                '/TG #(\d+)/',
                function ($m) {
                    return '<span style="background-color:' . tgColor($m[1]) . '" class="font-bold">TG #' . $m[1] . '</span>';
                },
                htmlspecialchars($line)
            );

            $formattedLine = preg_replace_callback(
                '/: ([A-Z0-9]+)$/',
                function ($m) {
                    return ': <span style="background-color:' . tgColor($m[1]) . '" class="font-bold px-1">' . $m[1] . '</span>';
                },
                $formattedLine
            );

            ?>
            <div class="<?= $isTalkerStart ? 'bg-green-900/20' : '' ?>
                <?= $isTalkerStop ? 'bg-gray-700/30' : '' ?>
                border-b border-gray-700 p-3 font-mono text-sm
                text-gray-300 hover:bg-gray-700/20 transition-colors
                flex justify-between items-center">

                <span class="flex-1"><?= $formattedLine ?></span>

                <?php if ($hasDuration): ?>
                    <span class="text-green-400 text-xs bg-green-900/30 px-2 py-1 rounded ml-4">
                        <?= $durations[$j] ?>
                    </span>
                <?php elseif ($isActive): ?>
                    <span class="text-green-400 text-xs blink px-2 py-1">
                        ACTIVE
                    </span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="mt-4 text-gray-500 text-xs font-mono">
        Auto-refresh: <?= $REFRESH ?>s | Log lines: <?= $LINES ?> | Buffer: <?= $TAIL_BUFFER ?>
    </div>
</div>
</body>
</html>