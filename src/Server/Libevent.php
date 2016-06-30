<?php

namespace Encore\Redis\Server;

/**
 * libevent eventloop
 */
class Libevent
{
    /**
     * Read event.
     *
     * @var int
     */
    const EV_READ = 1;

    /**
     * Write event.
     *
     * @var int
     */
    const EV_WRITE = 2;

    /**
     * Signal event.
     *
     * @var int
     */
    const EV_SIGNAL = 4;

    /**
     * Timer event.
     *
     * @var int
     */
    const EV_TIMER = 8;

    /**
     * Timer once event.
     *
     * @var int
     */
    const EV_TIMER_ONCE = 16;

    /**
     * Event base.
     *
     * @var resource
     */
    protected $eventBase = null;

    /**
     * All listeners for read/write event.
     *
     * @var array
     */
    protected $allEvents = array();

    /**
     * Event listeners of signal.
     *
     * @var array
     */
    protected $eventSignal = array();

    /**
     * All timer event listeners.
     * [func, args, event, flag, time_interval]
     *
     * @var array
     */
    protected $eventTimer = array();

    /**
     * construct
     */
    public function __construct()
    {
        $this->eventBase = event_base_new();
    }

    /**
     * {@inheritdoc}
     */
    public function add($fd, $flag, $func, $args = array())
    {
        switch ($flag) {
            case self::EV_SIGNAL:
                $fd_key                      = (int)$fd;
                $real_flag                   = EV_SIGNAL | EV_PERSIST;
                $this->eventSignal[$fd_key] = event_new();
                if (!event_set($this->eventSignal[$fd_key], $fd, $real_flag, $func, null)) {
                    return false;
                }
                if (!event_base_set($this->eventSignal[$fd_key], $this->eventBase)) {
                    return false;
                }
                if (!event_add($this->eventSignal[$fd_key])) {
                    return false;
                }
                return true;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                $event    = event_new();
                $timer_id = (int)$event;
                if (!event_set($event, 0, EV_TIMEOUT, array($this, 'timerCallback'), $timer_id)) {
                    return false;
                }

                if (!event_base_set($event, $this->eventBase)) {
                    return false;
                }

                $time_interval = $fd * 1000000;
                if (!event_add($event, $time_interval)) {
                    return false;
                }
                $this->eventTimer[$timer_id] = array($func, (array)$args, $event, $flag, $time_interval);
                return $timer_id;

            default :
                $fd_key    = (int)$fd;
                $real_flag = $flag === self::EV_READ ? EV_READ | EV_PERSIST : EV_WRITE | EV_PERSIST;

                $event = event_new();

                if (!event_set($event, $fd, $real_flag, $func, null)) {
                    return false;
                }

                if (!event_base_set($event, $this->eventBase)) {
                    return false;
                }

                if (!event_add($event)) {
                    return false;
                }

                $this->allEvents[$fd_key][$flag] = $event;

                return true;
        }

    }

    /**
     * {@inheritdoc}
     */
    public function del($fd, $flag)
    {
        switch ($flag) {
            case self::EV_READ:
            case self::EV_WRITE:
                $fd_key = (int)$fd;
                if (isset($this->allEvents[$fd_key][$flag])) {
                    event_del($this->allEvents[$fd_key][$flag]);
                    unset($this->allEvents[$fd_key][$flag]);
                }
                if (empty($this->allEvents[$fd_key])) {
                    unset($this->allEvents[$fd_key]);
                }
                break;
            case  self::EV_SIGNAL:
                $fd_key = (int)$fd;
                if (isset($this->eventSignal[$fd_key])) {
                    event_del($this->eventSignal[$fd_key]);
                    unset($this->eventSignal[$fd_key]);
                }
                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                // 这里 fd 为timerid 
                if (isset($this->eventTimer[$fd])) {
                    event_del($this->eventTimer[$fd][2]);
                    unset($this->eventTimer[$fd]);
                }
                break;
        }
        return true;
    }

    /**
     * Timer callback.
     *
     * @param mixed $_null1
     * @param int   $_null2
     * @param mixed $timer_id
     */
    protected function timerCallback($_null1, $_null2, $timer_id)
    {
        if ($this->eventTimer[$timer_id][3] === self::EV_TIMER) {
            event_add($this->eventTimer[$timer_id][2], $this->eventTimer[$timer_id][4]);
        }
        try {
            call_user_func_array($this->eventTimer[$timer_id][0], $this->eventTimer[$timer_id][1]);
        } catch (\Exception $e) {
            echo $e;
            exit(250);
        }
        if (isset($this->eventTimer[$timer_id]) && $this->eventTimer[$timer_id][3] === self::EV_TIMER_ONCE) {
            $this->del($timer_id, self::EV_TIMER_ONCE);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function clearAllTimer()
    {
        foreach ($this->eventTimer as $task_data) {
            event_del($task_data[2]);
        }
        $this->eventTimer = array();
    }

    /**
     * {@inheritdoc}
     */
    public function loop()
    {
        event_base_loop($this->eventBase);
    }
}
