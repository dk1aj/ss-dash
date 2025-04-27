#!/bin/bash

# This function will be called when the script receives Ctrl+C (SIGINT)
cleanup() {
    echo "Stopping background processes..."
    # Kills the last background job (the Go process)
    kill $!
}

trap cleanup SIGINT

go run audio.go &
# Store the PID if you want more precise control: GO_PID=$!

php -S 0.0.0.0:8000