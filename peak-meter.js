/*
 * # Copyright (c) 2024 by Silviu Stroe (brainic.io)
 * #
 * # This program is free software: you can redistribute it and/or modify
 * # it under the terms of the GNU General Public License as published by
 * # the Free Software Foundation, either version 3 of the License, or
 * # (at your option) any later version.
 * #
 * # This program is distributed in the hope that it will be useful,
 * # but WITHOUT ANY WARRANTY; without even the implied warranty of
 * # MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * # GNU General Public License for more details.
 * #
 * # You should have received a copy of the GNU General Public License
 * # along with this program. If not, see <http://www.gnu.org/licenses/>.
 * #
 * # Created on 6/22/24, 11:01 AM
 * #
 * # Author: Silviu Stroe
 */
document.addEventListener('DOMContentLoaded', function () {
    // ------------------------------------------------------------
    // Configuration & State
    // ------------------------------------------------------------
    const minDb = -30;
    const maxDb = 3;

    // Current peak levels and last update timestamps
    let peakLevelRX = minDb;
    let peakLevelTX = minDb;
    let lastUpdateRX = Date.now();
    let lastUpdateTX = Date.now();

    // DOM references
    const volumeLevelRX  = document.getElementById('volumeLevelRX');
    const peakLevelBarRX = document.getElementById('peakLevelRX');
    const volumeLevelTX  = document.getElementById('volumeLevelTX');
    const peakLevelBarTX = document.getElementById('peakLevelTX');

    // ------------------------------------------------------------
    // WebSocket Connection
    // ------------------------------------------------------------
    // Connect to your Go app’s WebSocket. Adjust host/port if needed.
    const socket = new WebSocket(`ws://${location.hostname}:8080/ws`);

    socket.onopen = () => {
        console.log('WebSocket connected');
    };

    socket.onmessage = (event) => {
        try {
            const data = JSON.parse(event.data);
            if (data.type === 'rx') {
                updateLevel(data, volumeLevelRX, peakLevelBarRX, 'RX');
            } else if (data.type === 'tx') {
                updateLevel(data, volumeLevelTX, peakLevelBarTX, 'TX');
            }
        } catch (err) {
            console.error('Failed to parse message', event.data, err);
        }
    };

    socket.onerror = (error) => {
        console.error('WebSocket error', error);
    };

    socket.onclose = () => {
        console.warn('WebSocket disconnected');
    };

    // ------------------------------------------------------------
    // Meter Update & Rendering
    // ------------------------------------------------------------
    function updateLevel(data, volumeLevelElem, peakLevelElem, meterType) {
        const level = parseFloat(data.level);
        // Convert level in dB to a percentage for the bar
        let percentage = ((level - minDb) / (maxDb - minDb)) * 100;
        percentage = Math.max(0, Math.min(percentage, 100));

        // Update the bar’s width
        volumeLevelElem.style.width = `${percentage.toFixed(2)}%`;
        volumeLevelElem.style.backgroundColor = getColorForLevel(level);

        // Update peak if this reading is higher than the previous peak
        if (meterType === 'RX') {
            if (level > peakLevelRX || Date.now() - lastUpdateRX > 200) {
                peakLevelRX = level;
                lastUpdateRX = Date.now();
            }
        } else if (meterType === 'TX') {
            if (level > peakLevelTX || Date.now() - lastUpdateTX > 200) {
                peakLevelTX = level;
                lastUpdateTX = Date.now();
            }
        }

        // Update the peak marker (the thin vertical bar)
        let currentPeak = meterType === 'RX' ? peakLevelRX : peakLevelTX;
        let peakPercent = ((currentPeak - minDb) / (maxDb - minDb)) * 100;
        peakPercent = Math.max(0, Math.min(peakPercent, 100));
        peakLevelElem.style.left = `${peakPercent.toFixed(2)}%`;
    }

    function updatePeakDecay() {
        const now = Date.now();

        // Decay RX peak
        if (now - lastUpdateRX > 100) {
            peakLevelRX = Math.max(minDb, peakLevelRX - 0.5);
            lastUpdateRX = now;
            let peakPercentRX = ((peakLevelRX - minDb) / (maxDb - minDb)) * 100;
            peakPercentRX = Math.max(0, Math.min(peakPercentRX, 100));
            peakLevelBarRX.style.left = `${peakPercentRX.toFixed(2)}%`;
        }

        // Decay TX peak
        if (now - lastUpdateTX > 100) {
            peakLevelTX = Math.max(minDb, peakLevelTX - 0.5);
            lastUpdateTX = now;
            let peakPercentTX = ((peakLevelTX - minDb) / (maxDb - minDb)) * 100;
            peakPercentTX = Math.max(0, Math.min(peakPercentTX, 100));
            peakLevelBarTX.style.left = `${peakPercentTX.toFixed(2)}%`;
        }

        requestAnimationFrame(updatePeakDecay);
    }

    // Color logic (simplify or adjust thresholds as you like)
    function getColorForLevel(dB) {
        if (dB <= -60) {
            return 'black';  // Essentially silent
        } else if (dB <= -18) {
            return 'green';  // Low-level signal
        } else if (dB <= -6) {
            return 'yellow'; // Medium-loud
        } else {
            return 'red';    // High-level, near clipping
        }
    }

    // Start the meter decay animation
    requestAnimationFrame(updatePeakDecay);
});