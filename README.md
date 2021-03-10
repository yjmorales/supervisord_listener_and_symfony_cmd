# Supervisord listener and symfony command. 

[Supervisor] [http://supervisord.org/] is a client/server system that allows its users to monitor and control a number of 
processes on UNIX-like operating systems.

The intention of this project is to create a Supervisor Listener that schedule the execution of a task. 
In this case could be a symfony command. 

### Problem
Execute a task evert `1 minute`. Let's say that our task is a symfony command 
 
### Solution with cron jobs
The common solution is to create a cron job that schedule that task. 

   Out of the target of this project 
    
### Alternative solution with Supervisord listeners.

An alternative solution is to use the advanced feature Supervisord Listeners
http://supervisord.org/events.html#configuring-an-event-listener

Steps: 
1. Copy the python listener on `tmp/supervisord_python_listener.py`. (The selected path can be different. Up to the developer the path)
2. Create the supervisor listener. It can be created within the config file `/etc/supervisord.conf` or within any 
 of those files included through `[include]` config option. In out case it's create the file 
 `/etc/supervisord.d/listeners.ini` and it's included on `/etc/supervisord.conf`:
  ``` 
 [include]
 files = supervisord.d/*.ini
 ```
    
 **Listener defined in `listeners.ini` file :**
  
```   
[eventlistener:symfony_command_scheduler_listener]
command=/tmp/supervisord_python_listener.py php /var/www/symfony/bin/console command
events=TICK_60`
```

### Alternative solution with supervisor program
The downside of Supervisord Listeners is that at least it's needed one listener that adds another failure possibility. 
For example if the listener is not correctly implemented the schedules will fail. 
Another approach is to use a program and auto-restart it with a delay of 1 minute:

```
[program:symfony_command_scheduler_program]
command=bash -c 'sleep 60 && exec php /var/www/symfony/bin/console command'
autorestart=true   
```
