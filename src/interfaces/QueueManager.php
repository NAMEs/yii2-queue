<?php
/**
 *
 */

namespace djeux\queue\interfaces;


use djeux\queue\jobs\BaseJob;

interface QueueManager
{
    /**
     * Push a job directly on to the queue
     *
     * @param object|string $job
     * @param string $data
     * @param null $queue
     * @return mixed
     */
    public function push($job, $data = '', $queue = null);

    /**
     * Push a job on the queue with a delay
     *
     * @param integer $delay delay in seconds
     * @param object $job
     * @param string $data
     * @param null $queue
     * @return string|integer Job ID
     */
    public function later($delay, $job, $data = '', $queue = null);

    /**
     * Return total number of jobs pending in a queue
     *
     * @param string $queue
     * @return mixed
     */
    public function size($queue = 'default');

    /**
     * Pop a job from the queue
     *
     * @param string $queue
     * @return BaseJob
     */
    public function pop($queue = 'default');

}