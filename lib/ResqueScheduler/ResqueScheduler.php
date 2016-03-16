<?php
/**
* ResqueScheduler core class to handle scheduling of jobs in the future.
*
* @package		ResqueScheduler
* @author		Chris Boulton <chris@bigcommerce.com> (Original)
* @author      Wan Qi Chen <kami@kamisama.me>
* @copyright	(c) 2012 Chris Boulton
* @license		http://www.opensource.org/licenses/mit-license.php
*/
namespace ResqueScheduler;

class ResqueScheduler
{
    // Name of the scheduler queue
    // Should be as unique as possible
    const QUEUE_NAME = '_schdlr_';

    /**
     * Enqueue a job in a given number of seconds from now.
     *
     * Identical to Resque::enqueue, however the first argument is the number
     * of seconds before the job should be executed.
     *
     * @param   int     $in             Number of seconds from now when the job should be executed.
     * @param   string  $queue          The name of the queue to place the job in.
     * @param   string  $class          The name of the class that contains the code to execute the job.
     * @param   array   $args           Any optional arguments that should be passed when the job is executed.
     * @param   boolean $trackStatus    Set to true to be able to monitor the status of a job.
     * @return  string                  Job ID
     */
    public static function enqueueIn($in, $queue, $class, array $args = array(), $trackStatus = false)
    {
        return self::enqueueAt(time() + $in, $queue, $class, $args, $trackStatus);
    }

    /**
     * Enqueue a job for execution at a given timestamp.
     *
     * Identical to Resque::enqueue, however the first argument is a timestamp
     * (either UNIX timestamp in integer format or an instance of the DateTime
     * class in PHP).
     *
     * @param   DateTime|int $at            Instance of PHP DateTime object or int of UNIX timestamp.
     * @param   string       $queue         The name of the queue to place the job in.
     * @param   string       $class         The name of the class that contains the code to execute the job.
     * @param   array        $args          Any optional arguments that should be passed when the job is executed.
     * @param   boolean      $trackStatus   Set to true to be able to monitor the status of a job.
     * @return  string                      Job ID
     */
    public static function enqueueAt($at, $queue, $class, $args = array(), $trackStatus = false)
    {
        self::validateJob($class, $queue);

        $args['id'] = md5(uniqid('', true));
        $args['s_time'] = time();
        $job = self::jobToHash($queue, $class, $args, $trackStatus);
        self::delayedPush($at, $job);

        if ($trackStatus) {
            \Resque_Job_Status::create($args['id'], Job\Status::STATUS_SCHEDULED);
        }

        \Resque_Event::trigger(
            'afterSchedule',
            array(
                'at'    => $at,
                'queue' => $queue,
                'class' => $class,
                'args'  => $args,
            )
        );

        return $args['id'];
    }

    /**
     * Directly append an item to the delayed queue schedule.
     *
     * @param DateTime|int $timestamp Timestamp job is scheduled to be run at.
     * @param array        $item      Hash of item to be pushed to schedule.
     */
    public static function delayedPush($timestamp, $item)
    {
        $timestamp = self::getTimestamp($timestamp);
        $redis = \Resque::redis();

        if ($item['queue'] == 'notification') {
            $key = "{$item['queue']}:{$item['args'][0][1]}";
            $value = self::QUEUE_NAME . ':' . $timestamp;
            $redis->hset("job_scheduled_store", $key, $value);
        }

        $redis->rpush(self::QUEUE_NAME . ':' . $timestamp, json_encode($item));

        $redis->zadd(self::QUEUE_NAME, $timestamp, $timestamp);
    }

    /**
     * Get the total number of jobs in the delayed schedule.
     *
     * @return int Number of scheduled jobs.
     */
    public static function getDelayedQueueScheduleSize()
    {
        return (int) \Resque::redis()->zcard(self::QUEUE_NAME);
    }

    /**
     * Get the number of jobs for a given timestamp in the delayed schedule.
     *
     * @param  DateTime|int $timestamp Timestamp
     * @return int          Number of scheduled jobs.
     */
    public static function getDelayedTimestampSize($timestamp)
    {
        $timestamp = self::getTimestamp($timestamp);

        return \Resque::redis()->llen(self::QUEUE_NAME . ':' . $timestamp, $timestamp);
    }

    /**
     * Remove a delayed job from the queue
     *
     * note: you must specify exactly the same
     * queue, class and arguments that you used when you added
     * to the delayed queue
     *
     * also, this is an expensive operation because all delayed keys have tobe
     * searched
     *
     * @param $queue
     * @param $class
     * @param $args
     * @return int number of jobs that were removed
     */
    public static function removeDelayed($queue, $class, $args)
    {
        $destroyed=0;
        $item = json_encode(self::jobToHash($queue, $class, $args));
        $redis = \Resque::redis();

        foreach ($redis->keys(self::QUEUE_NAME . ':*') as $key) {
            $key = $redis->removePrefix($key);
            $destroyed += $redis->lrem($key, 0, $item);
        }

        return $destroyed;
    }

    /**
     * removed a delayed job queued for a specific timestamp
     *
     * note: you must specify exactly the same
     * queue, class and arguments that you used when you added
     * to the delayed queue
     *
     * @param $timestamp
     * @param $queue
     * @param $class
     * @param $args
     * @return mixed
     */
    public static function removeDelayedJobFromTimestamp($timestamp, $queue, $class, $args)
    {
        $key = self::QUEUE_NAME . ':' . self::getTimestamp($timestamp);
        $item = json_encode(self::jobToHash($queue, $class, $args));
        $redis = \Resque::redis();
        $count = $redis->lrem($key, 0, $item);
        self::cleanupTimestamp($key, $timestamp);

        return $count;
    }

    /**
     * Generate hash of all job properties to be saved in the scheduled queue.
     *
     * @param string $queue Name of the queue the job will be placed on.
     * @param string $class Name of the job class.
     * @param array  $args  Array of job arguments.
     */

    private static function jobToHash($queue, $class, $args, $trackStatus)
    {
        return array(
            'class' => $class,
            'args'  => array($args),
            'queue' => $queue,
            'track' => $trackStatus
        );
    }

    /**
     * If there are no jobs for a given key/timestamp, delete references to it.
     *
     * Used internally to remove empty delayed: items in Redis when there are
     * no more jobs left to run at that timestamp.
     *
     * @param string $key       Key to count number of items at.
     * @param int    $timestamp Matching timestamp for $key.
     */
    private static function cleanupTimestamp($key, $timestamp)
    {
        $timestamp = self::getTimestamp($timestamp);
        $redis = \Resque::redis();

        if ($redis->llen($key) == 0) {
            $redis->del($key);
            $redis->zrem(self::QUEUE_NAME, $timestamp);
        }
    }

    /**
     * Convert a timestamp in some format in to a unix timestamp as an integer.
     *
     * @param  DateTime|int                              $timestamp Instance of DateTime or UNIX timestamp.
     * @return int                                       Timestamp
     * @throws ResqueScheduler_InvalidTimestampException
     */
    private static function getTimestamp($timestamp)
    {
        if ($timestamp instanceof \DateTime) {
            $timestamp = $timestamp->getTimestamp();
        }

        if ((int) $timestamp != $timestamp) {
            throw new ResqueScheduler\InvalidTimestampException(
                'The supplied timestamp value could not be converted to an integer.'
            );
        }

        return (int) $timestamp;
    }

    /**
     * Find the first timestamp in the delayed schedule before/including the timestamp.
     *
     * Will find and return the first timestamp upto and including the given
     * timestamp. This is the heart of the ResqueScheduler that will make sure
     * that any jobs scheduled for the past when the worker wasn't running are
     * also queued up.
     *
     * @param DateTime|int $timestamp Instance of DateTime or UNIX timestamp.
     *                                Defaults to now.
     * @return int|false UNIX timestamp, or false if nothing to run.
     */
    public static function nextDelayedTimestamp($at = null)
    {
        if ($at === null) {
            $at = time();
        } else {
            $at = self::getTimestamp($at);
        }

        $items = \Resque::redis()->zrangebyscore(self::QUEUE_NAME, '-inf', $at, array('limit', 0, 1));
        if (!empty($items)) {
            return $items[0];
        }

        return false;
    }

    /**
     * Pop a job off the delayed queue for a given timestamp.
     *
     * @param  DateTime|int $timestamp Instance of DateTime or UNIX timestamp.
     * @return array        Matching job at timestamp.
     */
    public static function nextItemForTimestamp($timestamp)
    {
        $timestamp = self::getTimestamp($timestamp);
        $key = self::QUEUE_NAME . ':' . $timestamp;

        $item = json_decode(\Resque::redis()->lpop($key), true);

        self::cleanupTimestamp($key, $timestamp);

        return $item;
    }

    /**
     * Ensure that supplied job class/queue is valid.
     *
     * @param  string           $class Name of job class.
     * @param  string           $queue Name of queue.
     * @throws Resque_Exception
     */
    private static function validateJob($class, $queue)
    {
        if (empty($class)) {
            throw new \Resque_Exception('Jobs must be given a class.');
        } elseif (empty($queue)) {
            throw new \Resque_Exception('Jobs must be put in a queue.');
        }

        return true;
    }
}
