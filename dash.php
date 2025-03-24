<?php
/*
 * SS-DASH Configuration
 */
$LOG_FILE = './misu2';
$LINES = 30;
$REFRESH = 2;
$TAIL_BUFFER = 8192;
$DATE_FORMATS = ['Y-m-d H:i:s', 'd.m.Y H:i:s', 'Y/m/d H:i:s']; // Supported date formats

$DTMF_CONTROL_PTY = '/dev/shm/svxlink_dtmf_ctrl';
$PTT_CONTROL_PTY = '/dev/shm/svxlink_ptt_ctrl';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    try {
        if (isset($_POST['dtmf'])) {
            $result = @file_put_contents($DTMF_CONTROL_PTY, $_POST['dtmf']);
            if ($result === false) {
                throw new Exception('Failed to write DTMF:' . error_get_last()['message']);
            }
        } elseif (isset($_POST['ptt'])) {
            $result = @file_put_contents($PTT_CONTROL_PTY, $_POST['ptt']);
            if ($result === false) {
                throw new Exception('Failed to write PTT: ' . error_get_last()['message']);
            }
        } elseif (isset($_POST['restart'])) {
            $output = @shell_exec('sudo systemctl restart svxlink');
            if ($output === null) {
                throw new Exception('Failed to restart service: ' . error_get_last()['message']);
            }
        } elseif (isset($_POST['stop'])) {
            $output = @shell_exec('sudo systemctl stop svxlink');
            if ($output === null) {
                throw new Exception('Failed to stop service: ' . error_get_last()['message']);
            }
        } elseif (isset($_POST['shutdown'])) {
            $output = @shell_exec('sudo shutdown -h now');
            if ($output === null) {
                throw new Exception('Failed to shutdown system: ' . error_get_last()['message']);
            }
        } elseif (isset($_POST['reboot'])) {
            $output = @shell_exec('sudo reboot');
            if ($output === null) {
                throw new Exception('Failed to reboot system: ' . error_get_last()['message']);
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

function tailCustom($filepath, $lines = 1, $adaptive = true) {
    $f = @fopen($filepath, "rb");
    if ($f === false) return false;

    $buffer = $adaptive ? ($lines < 2 ? 64 : ($lines < 10 ? 512 : 4096)) : 4096;
    fseek($f, -1, SEEK_END);

    if (fread($f, 1) != "\n") $lines -= 1;

    $output = '';
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

function formatDuration($seconds) {
    if ($seconds >= 60) {
        $minutes = floor($seconds / 60);
        $remaining = $seconds % 60;
        return sprintf("%dm %02ds", $minutes, $remaining);
    }
    return sprintf("%ds", $seconds);
}

function tgColor($tg) {
    $hue = crc32($tg) % 360;
    return "hsl($hue,70%,50%)";
}

function parseTimestamp($timestamp) {
    global $DATE_FORMATS;

    foreach ($DATE_FORMATS as $format) {
        $date = DateTime::createFromFormat($format, $timestamp);
        if ($date !== false) {
            return $date;
        }
    }
    return false;
}

function generateLogHtml() {
    global $LOG_FILE, $LINES, $dateFormat;

    $logContent = tailCustom($LOG_FILE, $LINES, true);
    $linesArray = explode("\n", $logContent);
    $durations = [];
    $activeStarts = [];
    $activeDurations = []; // Add this line
    $completedStarts = [];
    $now = new DateTime(); // Add this line

    foreach (array_reverse($linesArray) as $i => $line) {
        $originalIndex = count($linesArray) - 1 - $i;
        if (strlen($line) < 21) continue;

        $message = substr($line, 21);
        $timestamp = substr($line, 0, 19);

        if (preg_match('/ReflectorLogic: Talker stop on TG #(\d+): (.+)$/', $message, $matches)) {
            for ($j = $originalIndex - 1; $j >= 0; $j--) {
                $prevMessage = substr($linesArray[$j], 21);
                if (preg_match('/ReflectorLogic: Talker start on TG #(\d+): (.+)$/', $prevMessage, $startMatches)) {
                    if ($startMatches[1] == $matches[1] && $startMatches[2] == $matches[2]) {
                        $startTime = parseTimestamp(substr($linesArray[$j], 0, 19));
                        $stopTime = parseTimestamp($timestamp);
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

    foreach ($linesArray as $j => $line) {
        if (isset($completedStarts[$j])) continue;
        if (preg_match('/ReflectorLogic: Talker start on TG #(\d+): (.+)$/', substr($line, 21))) {
            $timestamp = substr($line, 0, 19);
            $startTime = parseTimestamp($timestamp);
            if ($startTime) {
                $duration = $now->getTimestamp() - $startTime->getTimestamp();
                $activeDurations[$j] = formatDuration($duration); // Add this line
            }
            $activeStarts[$j] = true;
        }
    }

    ob_start();
    foreach (array_reverse($linesArray, true) as $j => $line):
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
            ACTIVE (<?= $activeDurations[$j] ?? '0s' ?>)
        </span>
            <?php endif; ?>
        </div>
    <?php endforeach;
    return ob_get_clean();
}

if (isset($_GET['ajax'])) {
    header('Content-Type: text/html');
    echo generateLogHtml();
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" x-data="app()">
<head>
    <title>SS-DASH - SvxLink Monitor</title>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .blink { animation: blink 1.5s ease-in-out infinite; }
        @keyframes blink { 50% { opacity: 0.4; } }
        .log-container { height: min(90vh, 800px); }
        .control-btn { transition: all 0.2s ease; }
    </style>
</head>
<body class="bg-gray-900 h-screen overflow-hidden">
<div class="container mx-auto p-4 h-full flex flex-col gap-4" x-init="init()">
    <!-- Confirmation Dialog -->
    <template x-if="showConfirmation">
        <div class="fixed inset-0 bg-black/50 flex items-center justify-center p-4">
            <div class="bg-gray-800 rounded-lg p-6 max-w-md w-full">
                <h3 class="text-lg font-bold text-red-400 mb-4" x-text="`Confirm ${pendingAction}?`"></h3>
                <p class="text-gray-300 mb-6">This action cannot be undone. Are you sure?</p>
                <div class="flex justify-end gap-3">
                    <button @click="showConfirmation = false" class="px-4 py-2 text-gray-400 hover:text-white">
                        Cancel
                    </button>
                    <button @click="executeConfirmedCommand"
                            class="px-4 py-2 bg-red-600 hover:bg-red-500 rounded-lg text-white">
                        Confirm
                    </button>
                </div>
            </div>
        </div>
    </template>

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
                <button @click="togglePtt()"
                        :class="pttActive ? 'bg-green-600' : 'bg-gray-700 hover:bg-gray-600'"
                        class="control-btn text-white px-4 py-2 rounded-lg"
                        :disabled="loading"
                        title="Push-to-Talk Toggle">
                    <span x-show="!loading" x-text="pttActive ? '🎤 Release' : '🎤 Press'"></span>
                    <svg x-show="loading" class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </button>

                <!-- System Commands -->
                <div class="flex gap-2 border-l border-gray-700 pl-2">
                    <button @click="confirmAction('restart', '🔄 Restart Svxlink')"
                            class="control-btn bg-purple-600 hover:bg-purple-500 text-white p-2 rounded-lg"
                            title="Restart Service">
                        🔄
                    </button>

                    <button @click="confirmAction('stop', '⏹ Stop Svxlink')"
                            class="control-btn bg-red-600 hover:bg-red-500 text-white p-2 rounded-lg"
                            title="Stop Service">
                        ⏹
                    </button>

                    <button @click="confirmAction('reboot', '🔃 Reboot System')"
                            class="control-btn bg-orange-600 hover:bg-orange-500 text-white p-2 rounded-lg"
                            title="Reboot System">
                        🔃
                    </button>

                    <button @click="confirmAction('shutdown', '⏻ Shutdown System')"
                            class="control-btn bg-red-700 hover:bg-red-600 text-white p-2 rounded-lg"
                            title="Shutdown System">
                        ⏻
                    </button>
                </div>
            </div>
        </div>
    </header>

    <!-- DTMF Form -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <form @submit.prevent="sendDtmf()" class="bg-gray-800 p-4 rounded-lg">
            <div class="flex gap-2">
                <input type="text" x-model="dtmf" placeholder="Enter DTMF code"
                       class="flex-1 bg-gray-700 text-white px-4 py-2 rounded"
                       maxlength="10"
                       :disabled="loading">
                <button type="submit"
                        class="control-btn bg-blue-600 hover:bg-blue-500 text-white px-4 py-2 rounded"
                        :disabled="loading">
                    📡 Send
                </button>
            </div>
            <div x-show="error" class="text-red-400 text-sm mt-2" x-text="error"></div>
        </form>
    </div>

    <!-- Log Container -->
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