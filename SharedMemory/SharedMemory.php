<?php
/**
 * 并发安全的共享内存
 * @Author WangJian
 * @Email 1636801376@qq.com
 */
namespace SharedMemory;

class SharedMemory {
    //可以同时读取的最大进程数量
    const MAX_READ_SEM = 10000;

    //初始化的实例数组
    protected static $sms = array();

    //共享内存
    protected $shm;

    //读信号量
    protected $read_sem;

    //写信号量
    protected $write_sem;

    //是否有事务在进行
    protected $in_transaction;

    /**
     * 单例模式的变形
     * @param $key
     * @param null $memsize
     * @param int $perm
     * @return mixed|SharedMemory
     */
    public static function getInstance($key, $memsize = null, $perm = 0666) {
        //如果已经有初始化好的实例，直接返回，如果没有，那就初始化一个实例并返回
        if(!empty(self::$sms[$key])) {
            return self::$sms[$key];
        }

        $sm = new self($key, $memsize, $perm);
        self::$sms[$key] = $sm;
        return $sm;
    }

    /**
     * 构造函数
     * @param $key
     * @param null $memsize
     * @param int $perm
     */
    protected function __construct($key, $memsize = null, $perm = 0666) {
        if(empty($memsize)) {
            $this->shm = shm_attach($key);
        } else {
            $this->shm = shm_attach($key, $memsize, $perm);
        }
        $this->read_sem = sem_get(crc32($key.'read'), self::MAX_READ_SEM);
        $this->write_sem = sem_get(crc32($key.'write'));
        $this->in_transaction = false;
    }

    public function get($key) {
        //如果在事务中调用此函数，则锁机制交由事务来处理
        if(!$this->in_transaction) {
            while(sem_acquire($this->read_sem)) {
                sem_acquire($this->write_sem, true);
                if(shm_get_var($this->shm, crc32('writing'))) {
                    sem_release($this->read_sem);
                    sem_release($this->write_sem);
                    continue;
                }

                break;
            }
        }

        $value = shm_get_var($this->shm, crc32($key));

        if(!$this->in_transaction) {
            sem_release($this->read_sem);
            sem_release($this->write_sem, true);
        }

        return $value;
    }

    public function set($key, $value) {
        //如果在事务中调用此函数，则锁机制交由事务来处理
        if(!$this->in_transaction) {
            sem_acquire($this->write_sem);
            shm_put_var($this->shm, crc32('writing'), true);
        }

        shm_put_var($this->shm, crc32($key), $value);

        if(!$this->in_transaction) {
            shm_put_var($this->shm, crc32('writing'), false);
            sem_release($this->write_sem);
        }
    }

    public function transction($callback) {
        sem_acquire($this->write_sem);
        shm_put_var($this->shm, crc32('writing'), true);
        $this->in_transaction = true;

        $result = call_user_func($callback);

        $this->in_transaction = false;
        shm_put_var($this->shm, crc32('writing'), false);
        sem_release($this->write_sem);

        return $result;
    }

    /**
     * 增加存储的整数值
     * @param $key  键名
     * @param $by  增量，可以为负值，负值表示减少
     * @return bool  成功，返回true；如果存储的不是整数值，则返回false
     */
    public function increment($key, $by) {
        return $this->transction(function() use($key, $by) {
            $value = $this->get($key);

            if(!is_int($value)) {
                return false;
            }

            $this->set($key, $value+(int)$by);
            return true;
        });
    }

    /**
     * 减少存储的整数值
     * @param $key  键名
     * @param $by  增量，可以为负值，负值表示增加
     * @return bool  成功，返回true；如果存储的不是整数值，则返回false
     */
    public function decrement($key, $by) {
        return $this->transction(function() use($key, $by) {
            $value = $this->get($key);

            if(!is_int($value)) {
                return false;
            }

            $this->set($key, $value-(int)$by);
            return true;
        });
    }

    public static function destroy($key) {
        if(!empty(self::$sms[$key])) {
            self::$sms[$key]->remove();
        }
    }

    /**
     * 删除一个共享内存区域
     */
    public function remove() {
        shm_remove($this->shm);
        sem_remove($this->read_sem);
        sem_remove($this->write_sem);
    }
}