<?php
/**
 ***********************************************************************************************************************
 ******** Supervisord Event Listener Implementation.
 ***********************************************************************************************************************
 *
 * The following is a Supervisord Event Listener implementation.
 *
 * The business requirement to solve is to schedule 10 Task executions, one every 10 minutes, every week.
 * The task to execute is a Symfony Console Application within a execution environment.
 *
 * That schedule can be done using Supervisord TICK_60 event which is defined as
 *  - An event type that may be subscribed to for event listeners to receive “wake-up” notifications every 60 seconds.
 *
 * Currently Supervisord only supports TICK_5 (each 5 seconds), TICK_60 (each 60 seconds or 1 minute), TICK_3600 (each
 * 3600 seconds or 1 hour). The selected one to subscribe is TICK_60 event because it's the closer one to the frequency
 * of 10 minutes (600 seconds).
 *
 * To control throttling information is used Shared Memory Blocks as storage. Because the task MUST run into a
 * environment, it's sent the environment through an argument.
 */

/**
 * ---------------------------------------------------------------------------------------------------------------------
 * The scheduled task to execute, when all conditions are met, is a Symfony Console App. As it's a Symfony app there
 * is an environment variable that is required, APP_ENV variable. Through `supervisord` is possible sending the
 * environment variable value as argument. The value of $argv holds the value of that environmental variable. It's set
 * in the listener defined on supervisord configuration. The sent value is the value of the variable at the moment of
 * supervisord start running.
 *
 * @link http://supervisord.org/configuration.html#environment-variables
 */
$env = $argv[1];

/**
 * ---------------------------------------------------------------------------------------------------------------------
 * This function implements the Supervisord Event Listener Notification Protocol. It listens every time Supervisord
 * triggers a TICK_60 event and sends the respective supervisord states to STDOUT resource. Once a TICK_60 event is
 * caught it's executed a callable function sent as argument which wraps the business logic of the task.
 *
 * @link http://supervisord.org/events.html#event-listener-notification-protocol
 *       http://supervisord.org/events.html#tick-60-event-type
 *
 * @param callable $callback Holds a pointer to a function responsible to execute a task in response to the supervisord
 *                           event.
 *
 * @return void
 */
function listenEvent($callback): void
{
    fwrite(STDOUT, "READY\n");

    while (true) {
        // Sanitizing supervisor event token: Token is sent though STDIN resource.
        if (!$token = trim(fgets(STDIN))) {
            break;
        }

        // Getting token as array (key => value)
        $headers = parseHeader($token);
        if (!array_key_exists('eventname', $headers)) {
            break;
        }

        // Sanitizing supervisor event token: Listening only the supported events.
        $eventName       = $headers['eventname'];
        $supportedEvents = ['TICK_5', 'PROCESS_STATE_RUNNING'];
        if (!in_array($eventName, $supportedEvents)) {
            break;
        }

        // Executing task
        $result = call_user_func($callback, $eventName);

        // Switching to supervisord state according task execution result.
        if (true === $result) {
            fwrite(STDOUT, "RESULT 2\nOK");
        } elseif (false === $result) {
            fwrite(STDOUT, "RESULT 4\nFAIL");
        } else {
            break;
        }

        fwrite(STDOUT, "READY\n");
    }
}

/**
 * ---------------------------------------------------------------------------------------------------------------------
 * The Supervisord Event Listener implemented on the function `listenEvent` receives an anonymous
 * function which holds the task to be executed every time TICK_60 event is triggered. This anonymous function has that
 * role, it's executed each time the event TICK_60 is triggered by Supervisord.
 *
 * === About the task to execute:
 * The task to execute is a Symfony Console App called trough exec() function. It requires the environment variables
 * APP_ENV to be set. Its value is passed by supervisord listener configuration and sent as an argument within $argv.
 *
 * === How to execute the task every 10 minutes (Rate and Period):
 * A requirements is to execute a total of 10 tasks executions within a week period. One task per 10 minutes within a
 * week. Supervisord lacks of an event like `TICK_N` where `N` is an arbitrary number of seconds. Currently only are
 * supported:
 *
 *  - TICK_5 (each 5 seconds)
 *  - TICK_60 (each 60 seconds or 1 minute)
 *  - TICK_3600` (each 3600 seconds or 1 hour).
 *
 * The selected one to subscribe is TICK_60 event because it's the closer one to the frequency of 10 minutes (600
 * seconds).
 *
 * It's responsibility of this function to determinate when has been passed 10 minutes. It calculates the times that
 * TICK_60 event has been caught to determinate that 10 minutes have passed and then execute an instance of the task
 * (Symfony Console App). So, if the event is triggered 10 times it's assumed has passed 10 minutes (60 seconds
 * multiplied by 10 times). Only then the task is ready to be executed.
 *
 * The save the count of TICK_60 events triggered it's used a Share Memory Block as storage. Each time the event is
 * triggered by supervisord an integer value is increment by one and written there. Once the counter arrives to 10 (max
 * number of TICK_60 events triggered for considering 10 minutes have passed) the block is cleared in order to be ready
 * to save the counter for next 10 minutes. (10 minuted  = 10 times TICK_60 triggered)
 *
 * To control that only 10 executions are performed is similar to the TICK_60 counter. In evey task execution a counter
 * is incremented by one and it's saved into Shared Memory Block. When the counter got to the maximum value of 10 no
 * more executions are performed until next week. Once the counter arrives to 1008 indicates a week has passed and the
 * counter and memory block are restarted.
 *
 * @param string $event     The name of the Supervisord event triggered. Currently the listener implementation caught
 *                          TICK_60 event to count 10 seconds until 10 minutes passes and PROCESS_STATE_RUNNING to
 *                          clear the storage when counters are saved, in out case Shared Memory Blocks.
 *
 * @return bool
 */
$taskExecutor = function (string $event) use ($env) {
    // Once supervisord process starts running this prepares shared memory blocks to save auxiliary counters.
    if ('PROCESS_STATE_RUNNING' === $event) {
        $ticksResourceId      = shmop_open(2, "c", 0600, 1);
        $throttlingResourceId = shmop_open(3, "c", 0600, 1008);
        flushCounter($ticksResourceId);
        flushCounter($throttlingResourceId);

        return true;
    }

    // Stating opening the shared memory block to save the counter for TICK_60 event. Counter of 10 minutes.
    $ticksResourceId = shmop_open(2, "c", 0600, 1);
    $ticks           = incrementCounter($ticksResourceId, 1);

    $maxTens       = 2;   // 10 tens represents 10x60 seconds. 10 Minutes.
    $maxExecutions = 2;   // Maximum 10 executions of the task (the symfony console app) within q singe week.
    $tensInWeek    = 6; // Count of tens within a week. 60480/600 = 1008. Once arrive to it a week is passed.

    if ($maxTens === $ticks) {
        // Flushing ticks storage. Getting ready to count others 10 TICK_60 events more.
        flushCounter($ticksResourceId);

        // Controlling executions counter. No more than 10 per week. If so, execute, otherwise skip.
        $throttlingResourceId = shmop_open(3, "c", 0600, 1008);
        $executionCount       = incrementCounter($throttlingResourceId, 1008);
        $notMaxedExecutions   = $executionCount <= $maxExecutions;
        $weekArrived          = $tensInWeek === $executionCount;

        // If the max rate of execution within a week hasn't passed yet, execute. Otherwise counter is only increased
        // within the respective shared memory block.
        if ($notMaxedExecutions) {
            putenv('APP_ENV=' . $env);
            exec('php /var/www/backoffice/bin/console notification:pos-overloaded-queue-monitoring');
        }

        // If a week has passed then restart the counter of executed inspections and restart to execute again.
        // It's done by restarting the respective counter within the respective shared memory block.
        if ($weekArrived) {
            flushCounter($throttlingResourceId);
        }
    }

    return true;
};

/**
 * ---------------------------------------------------------------------------------------------------------------------
 * The following function parses the token sent by supervisord whenever events are triggered. It wraps the keys and the
 * respective values into an array and returns it.
 *
 * @param string $headerToken Supervisor token sent whenever the events are triggered.
 *
 * @return array
 */
function parseHeader(string $headerToken): array
{
    $parsed = [];
    foreach (explode(' ', $headerToken) as $pair) {
        if (!strpos($pair, ':')) {
            continue;
        }

        list($key, $value) = array_map('trim', explode(':', $pair));
        $parsed[$key] = $value;
    }

    return $parsed;
}

/**
 * ---------------------------------------------------------------------------------------------------------------------
 * Shared memory is being used as storage of those values that count:
 *  - number of TICK_60 events triggered by supervisord
 *  - number of time that the task (symfony command app) has been executed.
 *
 * Use this function every time it's needed to increment those values. If there isn't value yet about either
 * number of triggered TICK_60 events by supervisord or number of time that the task has been executed, it's saved 1.
 * Otherwise the function read that value from Shared Memory Block and increase it by one.
 *
 * @param resource $resourceId Represents the Shared Memory Block holding the value to increase.
 * @param int      $size       Represents the size of bytes that needs to be read in case the value already exists.
 *
 * @return int Returns the new counter value.
 */
function incrementCounter($resourceId, $size): int
{
    $value = (int)shmop_read($resourceId, 0, $size);
    if (!$value) {
        $value = 0;
    }
    $value += 1;
    shmop_write($resourceId, $value, 0);

    return $value;
}

/**
 * ---------------------------------------------------------------------------------------------------------------------
 * Use this function to remove a Shared Memory Block Resource.
 *
 * @param resource $resourceId Holds the shared memory block resource used to be removed.
 *
 * @return void
 */
function flushCounter($resourceId): void
{
    shmop_delete($resourceId);
    shmop_close($resourceId);
}


/**
 ***********************************************************************************************************************
 ***********************************************************************************************************************
 ********** The following is the entry point of the code expected to be executed each TICK_60 is triggered.   **********
 ***********************************************************************************************************************
 ***********************************************************************************************************************
 */
listenEvent($taskExecutor);