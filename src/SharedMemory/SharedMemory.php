<?php
/**
 * concurrency-safe shared memory
 * @Author WangJian
 * @Email 1636801376@qq.com
 */
namespace Wangjian\Socket\SharedMemory;

class SharedMemory {
    /**
     * the shared memory segment identifier
     * @var resource
     */
    protected $shm;

    //the semaphore identifier
    protected $sem;

    //whether runs in transaction
    protected $in_transaction;

    /**
     * constructor
     * @param int $key
     * @param int $memsize
     * @param int $perm
     */
    public function __construct($key, $memsize = 0, $perm = 0666) {
        if(empty($memsize)) {
            $this->shm = shm_attach($key);
        } else {
            $this->shm = shm_attach($key, $memsize, $perm);
        }
        $this->sem = sem_get($key);
        $this->in_transaction = false;
    }

    /**
     * get the stored vlaue
     * @param string $key
     * @return mixed  return the value with the given key or false on failure
     */
    public function get($key) {
        //如果在事务中调用此函数，则锁机制交由事务来处理
        if(!$this->in_transaction) {
            while(1) {
                @sem_acquire($this->sem, true);
                if(@shm_get_var($this->shm, crc32('writing'))) {
                    @sem_release($this->sem);
                    continue;
                }

                break;
            }
        }

        $value = @shm_get_var($this->shm, crc32($key));

        if(!$this->in_transaction) {
            @sem_release($this->sem);
        }

        return $value;
    }

    /**
     * set the value
     * @param string $key
     * @param mixed $value  new value
     * @return bool  return true on success, and false on failure
     */
    public function set($key, $value) {
        //the transaction will acquire a mutex, so needn't acquired the mutex when called in a transaction
        if(!$this->in_transaction) {
            sem_acquire($this->sem);
            shm_put_var($this->shm, crc32('writing'), true);
        }

        $result = shm_put_var($this->shm, crc32($key), $value);

        if(!$this->in_transaction) {
            shm_put_var($this->shm, crc32('writing'), false);
            sem_release($this->sem);
        }

        return $result;
    }

    /**
     * delete the key
     * @param string $key
     * @return bool  return true on success and false on failure
     */
    public function delete($key) {
        //the transaction will acquire a mutex, so needn't acquired the mutex when called in a transaction
        if(!$this->in_transaction) {
            sem_acquire($this->sem);
            shm_put_var($this->shm, crc32('writing'), true);
        }

        $result = @shm_remove_var($this->shm, crc32($key));

        if(!$this->in_transaction) {
            shm_put_var($this->shm, crc32('writing'), false);
            sem_release($this->sem);
        }

        return $result;
    }

    /**
     * begin a transaction. A transaction is atomic and concurrency-safe
     * @param callable $callback
     * @return mixed  return the result of the callback parameter
     */
    public function transction($callback) {
        sem_acquire($this->sem);
        shm_put_var($this->shm, crc32('writing'), true);
        $this->in_transaction = true;

        $result = call_user_func($callback, $this);

        $this->in_transaction = false;
        shm_put_var($this->shm, crc32('writing'), false);
        sem_release($this->sem);

        return $result;
    }

    /**
     * increase the value
     * @param string $key
     * @param int $by  increment, decrease the value when this parameter is negative
     * @return bool  return true on success. when the original value is not a integer, return false
     */
    public function increment($key, $by = 1) {
        return $this->transction(function() use($key, $by) {
            $value = $this->get($key);

            if(!is_int($value)) {
                return false;
            }

            return $this->set($key, $value+(int)$by);
        });
    }

    /**
     * decrease the value
     * @param string $key
     * @param int $by  decrement, increase the value when this parameter is negative
     * @return bool  return true on success. when the original value is not a integer, return false
     */
    public function decrement($key, $by = 1) {
        return $this->transction(function() use($key, $by) {
            $value = $this->get($key);

            if(!is_int($value)) {
                return false;
            }

            return $this->set($key, $value-(int)$by);
        });
    }

    /**
     * release the shared memory
     */
    public function remove() {
        shm_remove($this->shm);
        sem_remove($this->sem);
    }
}