#!/bin/sh

sed -i 's/exec gosu user/exec/g' /home/user/.super_doubao/super-doubao-runtime/entrypoint.sh
pkill -f start_server.py