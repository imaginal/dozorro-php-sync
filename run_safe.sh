#!/bin/bash

INIFILE=config/config.ini
PIDFILE=`awk -F= '$1~/pidfile/{print $2}' $INIFILE`

run_safe()
{
  while true
  do
    php run_dz.php $INIFILE
    sleep 5
    test -s $PIDFILE || exit
  done
}

case "$1" in
start)
  if [ -s $PIDFILE ]; then
    echo $PIDFILE exists, exit
    exit 1
  fi
  nohup $0 -d &
  sleep 1
  ;;
stop)
  test -s $PIDFILE && kill `cat $PIDFILE`
  ;;
-d)
  run_safe
  ;;
*)
  echo "Usage: $0 {start|stop}"
  exit 1
  ;;
esac

