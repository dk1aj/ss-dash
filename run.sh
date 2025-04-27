#!/bin/bash

cleanup() {
    echo "Stopping background processes..."
    kill $GO_PID
}

trap cleanup SIGINT

# Go-Programm im Hintergrund starten
go run audio.go &
GO_PID=$!

# PHP-Server im Vordergrund starten
php -S 0.0.0.0:8000
