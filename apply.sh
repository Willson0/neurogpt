#!/bin/sh
# Запускать в корне NeuroGPT: sh apply.sh
unzip -o neurogpt-songs-patch.zip -x apply.sh
rm -f frontend/src/views/ChatView.vue
echo done
