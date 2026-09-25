#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ ! -f "$DIR/bot_bin" ] || ! "$DIR/bot_bin" --version >/dev/null 2>&1; then
    g++ -O2 "$DIR/agent.cpp" -o "$DIR/bot_bin"
fi
exec "$DIR/bot_bin"
