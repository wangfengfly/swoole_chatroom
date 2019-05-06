#!/bin/bash

source /etc/profile

if [ -z "$1" ]; then 
    echo "Usage: sh websocket_server.sh start|stop|restart"
    exit
fi

remove(){
	echo "clean data..."
	rm -f /dev/shm/acc_fd* && rm -f /dev/shm/fd_acc* && rm -rf /dev/shm/ch_*
	echo "done."
}

command=$1
if [ $command = "start" ]; then
    remove
    nohup php websocket_server.php &
elif [ $command = "stop" ]; then
    remove
    ps -ef |grep websocket_server.php|awk '{print $2}'|xargs kill -9
elif [ $command = "restart" ]; then 
    remove
    ps -ef |grep websocket_server.php|awk '{print $2}'|xargs kill -9
    nohup php websocket_server.php &
else 
    echo "command is not valid. Usage: sh websocket_server.sh start|stop|restart"
    exit
fi 


