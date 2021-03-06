# Supervisord listener and symfony command. 

[Supervisor] [http://supervisord.org/] is a client/server system that allows its users to monitor and control a 
number of processes on UNIX-like operating systems.

The intention of this project is to create a Supervisord Listener written in PHP that schedule a task execution. 
In this case the task is the execution of a Symfony Command Application. 

### Problem
It's needed to execute 10 times a task within a week. Once execution every 10 minutes. 
 
### Solution with cron jobs
The common solution is to create a cron job that schedule that task.  

        Out of the target of this project 
    
### Alternative solution with Supervisord listeners.

An alternative solution is to use the advanced feature Supervisord Listeners
* http://supervisord.org/events.html#configuring-an-event-listener

supervisord_listener_state-diagram.jpg![image](https://user-images.githubusercontent.com/24995532/112201105-4d446900-8be6-11eb-84aa-be3719cb7bd7.png)


#### **Supervisord Event Listener implementation using PHP.**
 
  That schedule can be done using Supervisord TICK_60 event which is defined as (...) _an event type that may be 
  subscribed to for event listeners to receive “wake-up” notifications every 60 seconds_ (...)
 
  Currently Supervisord only supports TICK_5 (each 5 seconds), TICK_60 (each 60 seconds or 1 minute), TICK_3600 (each
  3600 seconds or 1 hour). The selected one to subscribe is **TICK_60** event because it's the closer 
  one to the frequency of 10 minutes (600 seconds).
 
  To control throttling information is used **Shared Memory Blocks** as storage. Because the task MUST run into a
  environment, it's sent the environment through an argument.
 
   The scheduled task to execute, when all conditions are met, is a Symfony Console App. As it's a Symfony app there
   is an environment variable that is required, **APP_ENV** variable. Through Supervisord is possible sending the
   environment variable value as argument. The value of `$argv` holds the value of that environmental variable. 
   It's set in the listener defined on Supervisord configuration. The sent value is the value of the variable at the 
   moment of Supervisord start running.
   
#### State Diagram to show the logic of the Listener Implementation

   
 
#### Installation:

* **Platform requirement**:  PHP v7 or higher.
* **Supervisord** v3.0 or higher.
* The current example runs within Linux OS. 
  
  
1. **Copy the php listener file on a directory**
 In this example we suggest the following linux path: 
`/tmp/supervisord_listener.php`. (The selected path can be different. Up to the developer the path)
 
2. **Config the listener on the Supervisord configuration**. 
It can be created within the config file `/etc/supervisord.conf` or within any 
 of those files included through `[include]` config option. In out case it's create the file 
 `/etc/supervisord.d/listeners.ini` and it's included on `/etc/supervisord.conf`:
  ``` 
 [include]
 files = supervisord.d/*.ini
 ```
    
 **Listener defined in `listeners.ini` file :**
  
```   
[eventlistener:listener]
command=php /tmp/supervisord_listener.php %(ENV_APP_ENV)s
events=TICK_60,PROCESS_STATE_RUNNING
user=apache

numprocs=1
```

Also you can include: `process_name=%(program_name)s-%(process_num)s` to set the process name.

Also it listens `PROCESS_STATE_RUNNING` event in order to clear shared memory blocks used to save counters values. 
http://supervisord.org/events.html#process-state-running-event-type 

As an argument it's sent `%(ENV_APP_ENV)s`. The scheduled task to execute, when all conditions are met, 
is a Symfony Console App. As it's a Symfony app there is an environment variable that is required, **APP_ENV** variable.
Through `supervisord` is possible sending the environment variable value as argument. The value of **$argv** holds 
the value of that environmental variable. It's set in the listener defined on supervisord configuration. 
The sent value is the value of the variable at the moment of Supervisord start running. 
                                              
http://supervisord.org/configuration.html#environment-variables
                                              

3. **Starting the listener using supervisorctl through the console** 
    
 - Reloading the listener by executing `supervisorctl reload`
 - Auxiliary we can check the status of the supervisord. Must be *RUNNING* state.
  Can be done by executing `supervisorctl status`
 - Auxiliary we can check the output of of the supervisord listener protocol. 
 It can be done by executing `supervisorctl tail listener`. This shows the switching between states.

 
Good Luck!     
