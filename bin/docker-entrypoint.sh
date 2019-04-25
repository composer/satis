#!/bin/sh

isCommand() {
  for cmd in \
    "add" \
    "build" \
    "help" \
    "init" \
    "list" \
    "purge"
  do
    if [ -z "${cmd#"$1"}" ]; then
      return 0
    fi
  done

  return 1
}

# check if the first argument passed in looks like a flag
if [ "$(printf %c "$1")" = '-' ]; then
  set -- /satis/bin/satis "$@"
# check if the first argument passed in is satis
elif [ "$1" = 'satis' ]; then
  shift
  set -- /satis/bin/satis "$@"
# check if the first argument passed in matches a known command
elif isCommand "$1"; then
  set -- /satis/bin/satis "$@"
fi

exec "$@"
