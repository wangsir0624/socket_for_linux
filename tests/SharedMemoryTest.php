<?php
namespace Wangjian\Socket\Test;

use PHPUnit\Framework\TestCase;
use Wangjian\Socket\SharedMemory\SharedMemory;

class SharedMemoryTest extends TestCase {
    protected $sm;

    public function setUp() {
        $key = ftok(__FILE__, substr(__FILE__, strlen(__FILE__)-1));
        $this->sm = new SharedMemory($key);
    }

    public function testTransaction() {
        $this->sm->set('counter', 0);

        $pid = pcntl_fork();

        if($pid == 0) {
            for($i = 0; $i < 10000; $i++) {
                $this->sm->increment('counter');
            }
        } else if($pid > 0) {
            for($i = 0; $i < 10000; $i++) {
                $this->sm->increment('counter');
            }

            pcntl_wait($status);

            $this->assertEqual($this->sm->get('counter'), 20000);
        } else {
            exit('pnctl_fork() failed');
        }
    }

    public function tearDown() {
        $this->sm->remove();
    }
}