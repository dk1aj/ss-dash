<?php
/*
 * SS-DASH Configuration
 */
$LOG_FILE         = './misu2';
$LINES            = 30;
$REFRESH          = 2;
$TAIL_BUFFER      = 8192;
$DATE_FORMATS     = ['Y-m-d H:i:s', 'd.m.Y H:i:s', 'Y/m/d H:i:s']; // Supported date formats

$DTMF_CONTROL_PTY = '/dev/shm/svxlink_dtmf_ctrl';
$PTT_CONTROL_PTY  = '/dev/shm/svxlink_ptt_ctrl';

/**
 * Handle POST requests for controlling the system or writing DTMF/PTT.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    try {
        if (isset($_POST['dtmf'])) {
            $result = @file_put_contents($DTMF_CONTROL_PTY, $_POST['dtmf']);
            if ($result === false) {
                throw new Exception('Failed to write DTMF: '. lastPhpError());
            }
        } elseif (isset($_POST['ptt'])) {
            $result = @file_put_contents($PTT_CONTROL_PTY, $_POST['ptt']);
            if ($result === false) {
                throw new Exception('Failed to write PTT: '. lastPhpError());
            }
        } elseif (isset($_POST['restart'])) {
            $output = shell_exec('sudo systemctl restart svxlink 2>&1');
            if ($output === null) {
                throw new Exception('Failed to restart service: '. lastPhpError());
            }
        } elseif (isset($_POST['stop'])) {
            $output = shell_exec('sudo systemctl stop svxlink 2>&1');
            if ($output === null) {
                throw new Exception('Failed to stop service: '. lastPhpError());
            }
        } elseif (isset($_POST['shutdown'])) {
            $output = shell_exec('sudo shutdown -h now 2>&1');
            if ($output === null) {
                throw new Exception('Failed to shutdown system: '. lastPhpError());
            }
        } elseif (isset($_POST['reboot'])) {
            $output = shell_exec('sudo reboot 2>&1');
            if ($output === null) {
                throw new Exception('Failed to reboot system: '. lastPhpError());
            }
        } else {
            throw new Exception('Invalid request parameters');
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

/**
 * Helper to grab the last PHP error in string form.
 */
function lastPhpError(): string
{
    $error = error_get_last();
    return $error ? $error['message'] : 'Unknown error';
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
 * Format a duration in seconds as either "XXm YYs" or "ZZs".
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
 * Generate a background color for a given talkgroup by hashing the talkgroup string.
 */
function tgColor(string $tg): string
{
    $hue = crc32($tg) % 360;
    return "hsl($hue,70%,40%)";
}

/**
 * Attempt to parse a timestamp using the predefined date formats.
 */
function parseTimestamp(string $timestamp): DateTime|false
{
    global $DATE_FORMATS;

    foreach ($DATE_FORMATS as $format) {
        $date = DateTime::createFromFormat($format, $timestamp);
        if ($date !== false) {
            return $date;
        }
    }
    return false;
}

/**
 * Generate the HTML for the log, with talker start/stop durations and active talkers.
 */
function generateLogHtml(): string
{
    global $LOG_FILE, $LINES;

    $logContent = tailCustom($LOG_FILE, $LINES, true);
    if ($logContent === false) {
        return '<div class="text-red-500 p-3">Error reading log file.</div>';
    }

    $linesArray = explode("\n", $logContent);
    $lineCount  = count($linesArray);
    if ($lineCount === 0) {
        return '<div class="p-3 text-gray-400">Log is empty.</div>';
    }

    // Parse the log lines in chronological order (index 0 is oldest).
    // We'll track talkers so we can match start/stop.
    $talkers       = []; // key => 'tg|callsign', value => ['startIndex', 'startTime']
    $durations     = []; // durations[ lineIndexOfStop ] = "formattedDuration"
    $activeIndexes = []; // For lines that are still active.

    $now = new DateTime();

    foreach ($linesArray as $idx => $line) {
        if (strlen($line) < 21) {
            continue;
        }
        $timestampStr = substr($line, 0, 19);
        $message      = substr($line, 21);
        $timeObj      = parseTimestamp($timestampStr);

        if (!$timeObj) {
            // If we can't parse the date, skip
            continue;
        }

        // Check for talker start
        if (preg_match('/ReflectorLogic: Talker start on TG #(\d+): (.+)$/', $message, $matches)) {
            $tg       = $matches[1];
            $callsign = $matches[2];

            $key = "$tg|$callsign";
            $talkers[$key] = [
                'startIndex' => $idx,
                'startTime'  => $timeObj->getTimestamp()
            ];
        }
        // Check for talker stop
        elseif (preg_match('/ReflectorLogic: Talker stop on TG #(\d+): (.+)$/', $message, $matches)) {
            $tg       = $matches[1];
            $callsign = $matches[2];
            $key      = "$tg|$callsign";

            if (isset($talkers[$key])) {
                $startData  = $talkers[$key];
                $stopTime   = $timeObj->getTimestamp();
                $duration   = $stopTime - $startData['startTime'];
                $stopIndex  = $idx;

                // We'll associate the computed duration with the stop line.
                $durations[$stopIndex] = formatDuration($duration);

                // Clear from active
                unset($talkers[$key]);
            }
        }
    }

    // Anything left in $talkers is still active
    foreach ($talkers as $key => $info) {
        $durationSoFar = $now->getTimestamp() - $info['startTime'];
        $activeIndexes[$info['startIndex']] = formatDuration($durationSoFar);
    }

    // Now generate HTML in *reverse* order so newest lines appear at the top
    ob_start();

    for ($i = $lineCount - 1; $i >= 0; $i--) {
        $line = $linesArray[$i];
        if (strlen($line) < 21) {
            continue;
        }

        $message = substr($line, 21);
        $lineHtml = htmlspecialchars($line);

        // Replace TG # and Callsign with colored spans
        $lineHtml = preg_replace_callback(
            '/TG #(\d+)/',
            fn($m) => '<span style="background-color:' . tgColor($m[1]) . '" class="font-bold">TG #' . $m[1] . '</span>',
            $lineHtml
        );
        // highlight callsign at the end if present
        $lineHtml = preg_replace_callback(
            '/: ([A-Z0-9]+)$/',
            fn($m) => ': <span style="background-color:' . tgColor($m[1]) . '" class="font-bold px-1">' . $m[1] . '</span>',
            $lineHtml
        );

        // Determine styling
        $isTalkerStart = str_starts_with($message, 'ReflectorLogic: Talker start');
        $isTalkerStop  = str_starts_with($message, 'ReflectorLogic: Talker stop');

        $rowClasses = [];
        if ($isTalkerStart) {
            $rowClasses[] = 'bg-green-900/20';
        } elseif ($isTalkerStop) {
            $rowClasses[] = 'bg-gray-700/30';
        }
        $rowClasses[] = 'border-b border-gray-700 p-3 font-mono text-sm text-gray-300 hover:bg-gray-700/20 transition-colors flex justify-between items-center';

        // Print the row
        echo '<div class="' . implode(' ', $rowClasses) . '">';
        echo '<span class="flex-1">' . $lineHtml . '</span>';

        if (isset($durations[$i])) {
            echo '<span class="text-green-400 text-xs bg-green-900/30 px-2 py-1 rounded ml-4">' .
                $durations[$i] .
                '</span>';
        } elseif (isset($activeIndexes[$i])) {
            echo '<span class="text-green-400 text-xs blink px-2 py-1">ACTIVE (' .
                $activeIndexes[$i] .
                ')</span>';
        }

        echo '</div>';
    }

    return ob_get_clean();
}

/**
 * If ?ajax is set, we output only the log HTML and exit.
 */
if (isset($_GET['ajax'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo generateLogHtml();
    exit;
}

// If you reach here, presumably you render some HTML page that includes JS to poll ?ajax
?>
<!DOCTYPE html>
<html lang="en" x-data="app()">
<head>
    <title>SS-DASH - SvxLink Monitor</title>

    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Flowbite (Optional) -->
    <link
            rel="stylesheet"
            href="https://unpkg.com/flowbite@1.6.5/dist/flowbite.min.css"
    />
    <script defer src="https://unpkg.com/flowbite@1.6.5/dist/flowbite.js"></script>

    <style>
        .blink {
            animation: blink 1.5s ease-in-out infinite;
        }
        @keyframes blink {
            50% { opacity: 0.4; }
        }
        .log-container {
            height: min(90vh, 800px);
        }
        .control-btn {
            transition: all 0.2s ease;
        }
    </style>
</head>
<body class="bg-gray-900 h-screen overflow-hidden">
<div class="container mx-auto p-4 h-full flex flex-col gap-4" x-init="init()">

    <!-- Confirmation Dialog -->
    <template x-if="showConfirmation">
        <!-- Overlay -->
        <div class="fixed inset-0 flex items-center justify-center bg-black/50 p-4">
            <!-- Modal Box -->
            <div class="bg-gray-800 rounded-lg p-6 max-w-md w-full"
                 role="dialog"
                 aria-modal="true">
                <h3 class="text-lg font-bold text-red-400 mb-4" x-text="`Confirm ${pendingAction}?`"></h3>
                <p class="text-gray-300 mb-6">This action cannot be undone. Are you sure?</p>
                <div class="flex justify-end gap-3">
                    <!-- CANCEL -->
                    <button
                            @click="showConfirmation = false"
                            type="button"
                            class="px-4 py-2 text-gray-400 hover:text-white rounded-lg border border-gray-500 hover:border-white"
                    >
                        Cancel
                    </button>
                    <!-- CONFIRM -->
                    <button
                            @click="executeConfirmedCommand"
                            type="button"
                            class="px-4 py-2 bg-red-600 hover:bg-red-500 text-white font-medium rounded-lg"
                    >
                        Confirm
                    </button>
                </div>
            </div>
        </div>
    </template>

    <!-- Header -->
    <header class="flex items-center justify-between gap-4 flex-wrap">
        <h1 class="text-2xl text-green-400 font-mono">
            SS-DASH :: Talker Activity Monitor
        </h1>

        <div class="flex items-center gap-4">
            <!-- PTT Status -->
            <div class="flex items-center gap-2 text-sm text-gray-400">
                <span class="h-3 w-3 rounded-full bg-green-500 animate-pulse"></span>
                <span x-text="pttActive ? 'PTT ACTIVE' : 'PTT READY'"></span>
            </div>

            <!-- Main Controls -->
            <div class="flex gap-2 items-center">
                <!-- PTT Toggle -->
                <button
                        @click="togglePtt()"
                        :class="pttActive ? 'bg-green-600' : 'bg-gray-700 hover:bg-gray-600'"
                        class="control-btn text-white px-4 py-2 rounded-lg"
                        :disabled="loading"
                        title="Push-to-Talk Toggle"
                >
                    <span x-show="!loading" x-text="pttActive ? '🎤 Release' : '🎤 Press'"></span>
                    <svg x-show="loading" class="animate-spin h-5 w-5 text-white"
                         xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10"
                                stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor"
                              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2
                                 5.291A7.962 7.962 0 014 12H0c0 3.042
                                 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </button>

                <!-- System Commands -->
                <div class="flex gap-2 border-l border-gray-700 pl-2">
                    <button
                            @click="confirmAction('restart', '🔄 Restart Svxlink')"
                            class="control-btn bg-purple-600 hover:bg-purple-500 text-white p-2 rounded-lg"
                            title="Restart Service"
                    >
                        🔄
                    </button>
                    <button
                            @click="confirmAction('stop', '⏹ Stop Svxlink')"
                            class="control-btn bg-red-600 hover:bg-red-500 text-white p-2 rounded-lg"
                            title="Stop Service"
                    >
                        ⏹
                    </button>
                    <button
                            @click="confirmAction('reboot', '🔃 Reboot System')"
                            class="control-btn bg-orange-600 hover:bg-orange-500 text-white p-2 rounded-lg"
                            title="Reboot System"
                    >
                        🔃
                    </button>
                    <button
                            @click="confirmAction('shutdown', '⏻ Shutdown System')"
                            class="control-btn bg-red-700 hover:bg-red-600 text-white p-2 rounded-lg"
                            title="Shutdown System"
                    >
                        ⏻
                    </button>
                </div>
            </div>
        </div>
    </header>

    <!-- DTMF Form -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
        <form @submit.prevent="sendDtmf()" class="bg-gray-800 p-4 rounded-lg">
            <div class="flex items-center gap-2">
                <input
                        type="text"
                        x-model="dtmf"
                        placeholder="Enter DTMF code"
                        class="flex-1 bg-gray-700 text-white px-4 py-1 rounded"
                        maxlength="10"
                        :disabled="loading"
                />
                <button
                        type="submit"
                        class="bg-blue-600 hover:bg-blue-500 text-white px-4 py-1 rounded"
                        :disabled="loading"
                >
                    ➤
                </button>
            </div>
            <div x-show="error" class="text-red-400 text-sm mt-2" x-text="error"></div>
        </form>
    </div>

    <!-- Log Container (unchanged "hackish" vibe) -->
    <div class="log-container bg-gray-800 rounded-lg border border-green-400 overflow-y-auto">
        <?= generateLogHtml() ?>
    </div>

    <!-- Footer -->
    <footer class="text-gray-500 text-xs font-mono flex justify-between items-center">
        <div>
            Auto-refresh: <?= $REFRESH ?>s | Log lines: <?= $LINES ?> | Buffer: <?= $TAIL_BUFFER ?>
        </div>
        <div x-text="status"></div>
    </footer>
</div>

<script>
    function app() {
        return {
            pttActive: false,
            dtmf: '',
            loading: false,
            error: null,
            status: 'Connected',
            showConfirmation: false,
            pendingAction: null,
            pendingCommand: null,

            init() {
                // Periodically fetch the latest logs and update status
                setInterval(() => {
                    this.updateLogs();
                    this.status = `Last update: ${new Date().toLocaleTimeString()}`;
                }, <?= $REFRESH * 1000 ?>);
            },

            confirmAction(command, action) {
                this.pendingCommand = command;
                this.pendingAction = action;
                this.showConfirmation = true;
            },

            async executeConfirmedCommand() {
                this.showConfirmation = false;
                try {
                    this.loading = true;
                    await this.sendRequest({ [this.pendingCommand]: '1' });
                } catch (err) {
                    this.error = err.message;
                    setTimeout(() => this.error = null, 3000);
                } finally {
                    this.loading = false;
                    this.pendingCommand = null;
                }
            },

            async updateLogs() {
                try {
                    const response = await fetch('?ajax=1');
                    const html = await response.text();
                    document.querySelector('.log-container').innerHTML = html;
                } catch (err) {
                    console.error('Log update failed:', err);
                }
            },

            async togglePtt() {
                try {
                    this.loading = true;
                    const pttValue = this.pttActive ? '0' : '1';
                    await this.sendRequest({ ptt: pttValue });
                    this.pttActive = !this.pttActive;
                } catch (err) {
                    this.error = err.message;
                    setTimeout(() => this.error = null, 3000);
                } finally {
                    this.loading = false;
                }
            },

            async sendDtmf() {
                if (!this.dtmf) return;
                try {
                    this.loading = true;
                    await this.sendRequest({ dtmf: this.dtmf });
                    this.dtmf = '';
                } catch (err) {
                    this.error = err.message;
                    setTimeout(() => this.error = null, 3000);
                } finally {
                    this.loading = false;
                }
            },

            async sendRequest(data) {
                const response = await fetch('', {
                    method: 'POST',
                    body: new URLSearchParams(data)
                });

                if (!response.ok) {
                    const error = await response.json();
                    throw new Error(error.error || 'Request failed');
                }

                return response.json();
            }
        }
    }
</script>
</body>
</html>