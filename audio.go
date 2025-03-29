package main

import (
	"encoding/binary"
	"fmt"
	"log"
	"math"
	"net"
	"net/http"
	"os"
	"os/signal"
	"sync"
	"time"

	"github.com/gorilla/websocket"
)

// Configure logging
var (
	logger *log.Logger
)

const (
	CHUNK            = 1024
	BYTES_PER_SAMPLE = 2
	CHANNELS         = 2
	REFERENCE_PEAK   = 32768
	WS_PORT          = ":8080"
	LOG_FILE         = "/tmp/ss-dash-audio.log"
)

var upgrader = websocket.Upgrader{
	CheckOrigin: func(r *http.Request) bool { return true },
}

type Client struct {
	conn *websocket.Conn
	send chan []byte
}

var (
	clients   = make(map[*Client]bool)
	clientsMu sync.Mutex
)

func init() {
	file, err := os.OpenFile(LOG_FILE, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644)
	if err != nil {
		log.Fatalf("Failed to open log file: %v", err)
	}
	logger = log.New(file, "", 0)
}

func logInfo(format string, v ...interface{}) {
	logger.Printf("%s - audio_monitor - INFO: %s\n",
		time.Now().Format("2006-01-02 15:04:05"),
		fmt.Sprintf(format, v...))
}

func logCritical(format string, v ...interface{}) {
	logger.Printf("%s - audio_monitor - CRITICAL: %s\n",
		time.Now().Format("2006-01-02 15:04:05"),
		fmt.Sprintf(format, v...))
}

func startAudioMonitor(done <-chan struct{}, dataChan chan<- string) {
	conn1, err := net.ListenPacket("udp", "127.0.0.1:10000")
	if err != nil {
		logCritical("Failed to bind UDP port 10000: %v", err)
		return
	}
	defer conn1.Close()

	conn2, err := net.ListenPacket("udp", "127.0.0.1:10001")
	if err != nil {
		logCritical("Failed to bind UDP port 10001: %v", err)
		return
	}
	defer conn2.Close()

	logInfo("UDP sockets bound to 127.0.0.1:10000 and 127.0.0.1:10001")

	var wg sync.WaitGroup
	wg.Add(2)

	processData := func(conn net.PacketConn, isFloat bool) {
		defer wg.Done()
		bufferSize := CHUNK * BYTES_PER_SAMPLE * CHANNELS
		if isFloat {
			bufferSize = CHUNK * 4 // float32 uses 4 bytes per sample
		}
		buffer := make([]byte, bufferSize)

		for {
			select {
			case <-done:
				return
			default:
				conn.SetReadDeadline(time.Now().Add(1 * time.Second))
				n, _, err := conn.ReadFrom(buffer)
				if err != nil {
					if netErr, ok := err.(net.Error); ok && netErr.Timeout() {
						continue
					}
					logCritical("Read error: %v", err)
					return
				}

				data := buffer[:n]
				var db float64

				if isFloat {
					samples := make([]float32, len(data)/4)
					for i := 0; i < len(samples); i++ {
						samples[i] = math.Float32frombits(binary.LittleEndian.Uint32(data[i*4 : (i+1)*4]))
					}

					peak := float32(0)
					for _, s := range samples {
						if abs := float32(math.Abs(float64(s))); abs > peak {
							peak = abs
						}
					}

					if peak == 0 {
						db = -30
					} else {
						db = 20 * math.Log10(float64(peak)+1e-40)
					}
					db = math.Max(-30, math.Min(3, db))
					dataChan <- fmt.Sprintf(`{"type":"tx","level":%.2f}`, db)
				} else {
					samples := make([]int16, len(data)/2)
					for i := 0; i < len(samples); i++ {
						samples[i] = int16(binary.LittleEndian.Uint16(data[i*2 : (i+1)*2]))
					}

					peak := int16(0)
					for _, s := range samples {
						if abs := int16(math.Abs(float64(s))); abs > peak {
							peak = abs
						}
					}

					peakRatio := float64(peak) / REFERENCE_PEAK
					db = 20 * math.Log10(peakRatio+1e-40)
					db = math.Max(-30, math.Min(3, db))
					dataChan <- fmt.Sprintf(`{"type":"rx","level":%.2f}`, db)
				}
			}
		}
	}

	go processData(conn1, true)
	go processData(conn2, false)

	wg.Wait()
	logInfo("Stopping audio monitor...")
}

func broadcastMessages(dataChan <-chan string) {
	for msg := range dataChan {
		clientsMu.Lock()
		for client := range clients {
			select {
			case client.send <- []byte(msg):
			default:
				close(client.send)
				delete(clients, client)
			}
		}
		clientsMu.Unlock()
	}
}

func handleWebSocket(w http.ResponseWriter, r *http.Request) {
	conn, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		logCritical("WebSocket upgrade failed: %v", err)
		return
	}

	client := &Client{
		conn: conn,
		send: make(chan []byte, 256),
	}

	clientsMu.Lock()
	clients[client] = true
	clientsMu.Unlock()

	go client.writePump()
}

func (c *Client) writePump() {
	defer func() {
		c.conn.Close()
		clientsMu.Lock()
		delete(clients, c)
		clientsMu.Unlock()
	}()

	for message := range c.send {
		err := c.conn.WriteMessage(websocket.TextMessage, message)
		if err != nil {
			break
		}
	}
}

func main() {
	dataChan := make(chan string, 100)
	done := make(chan struct{})

	go startAudioMonitor(done, dataChan)
	go broadcastMessages(dataChan)

	http.HandleFunc("/ws", handleWebSocket)

	go func() {
		logInfo("Starting WebSocket server on %s", WS_PORT)
		if err := http.ListenAndServe(WS_PORT, nil); err != nil {
			logCritical("HTTP server failed: %v", err)
			close(done)
		}
	}()

	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, os.Interrupt)
	<-sigChan

	logInfo("Shutting down...")
	close(done)
	time.Sleep(1 * time.Second)
}
