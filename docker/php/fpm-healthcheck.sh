#!/bin/sh
# Ask FPM whether it is alive, using cgi-fcgi to speak the FastCGI
# protocol directly. A plain HTTP check would test nginx, not FPM.
set -e

SCRIPT_NAME=/fpm-ping \
SCRIPT_FILENAME=/fpm-ping \
REQUEST_METHOD=GET \
cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1
